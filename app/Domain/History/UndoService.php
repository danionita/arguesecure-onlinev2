<?php

namespace App\Domain\History;

use App\Domain\History\Commands\CommandDispatcher;
use App\Domain\History\Exceptions\NothingToUndoException;
use App\Domain\History\Exceptions\UndoConflictException;
use App\Models\Assessment;
use App\Models\AssessmentOperation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The single linear per-assessment undo stack (schema.md §3.10, ui.md
 * §3.6). Only structural changes are recorded — create, delete, move,
 * link, unlink, clone, catalogue insert. Field edits never enter this
 * stack; they are recovered through `node_revisions` "revert this field"
 * instead (W18), which is a normal edit, not an undo.
 *
 * `AssessmentOperation`'s own docblock names this class as the only writer
 * of that table, matching `NodeRevision`'s equivalent note about
 * `History\RevisionRecorder` (a separate, later package).
 */
class UndoService
{
    /** @var list<string> */
    private const KNOWN_KINDS = [
        'node.create',
        'node.delete',
        'node.move',
        'link.create',
        'link.delete',
        'subtree.clone',
        'catalog.insert',
        // W61. The only kind whose inverse replaces the whole graph rather
        // than one part of it — see Commands\RestoreSnapshotApplier.
        'snapshot.restore',
    ];

    public function __construct(private readonly CommandDispatcher $dispatcher) {}

    /**
     * Pushes a structural change onto the stack. Called immediately after
     * the change that produced it commits — by NodeController, LinkService,
     * ReorderService or AssessmentCloner callers, or by this package's own
     * reorder/link routes (routes/web.php's W14 block).
     *
     * @param  array<string, mixed>  $inverse
     */
    public function record(
        Assessment $assessment,
        ?User $user,
        string $kind,
        string $summary,
        array $inverse,
    ): AssessmentOperation {
        if (! in_array($kind, self::KNOWN_KINDS, true)) {
            // Whitelisted, fixed set — never a kind string built from
            // request input (CLAUDE.md, security.md §7 defect #7).
            throw new \InvalidArgumentException("Unknown operation kind: {$kind}");
        }

        return DB::transaction(function () use ($assessment, $user, $kind, $summary, $inverse): AssessmentOperation {
            // D83: new work ends the redo stack. redo() pushes its row through
            // create() directly, so a redo does not clear what is left of it.
            // One transaction, so a failed create() cannot leave the stack
            // ended with no new operation to show for it.
            AssessmentOperation::query()
                ->where('assessment_id', $assessment->id)
                ->whereNotNull('undone_at')
                ->whereNull('redone_at')
                ->whereNull('superseded_at')
                ->update(['superseded_at' => now()]);

            return AssessmentOperation::create([
                'assessment_id' => $assessment->id,
                'user_id' => $user?->id,
                'kind' => $kind,
                'summary' => $summary,
                'inverse' => $inverse,
                // The model has $timestamps = false (it has no updated_at), so
                // Eloquent will not populate this NOT NULL column on its own.
                'created_at' => now(),
            ]);
        });
    }

    /**
     * The newest not-yet-undone operation, or null. Used to render the
     * "Undo Dana's deletion of 'Phishing'?" prompt before the user commits
     * (ui.md §3.6) — a separate read from the mutation itself so the client
     * can show who and what before asking for confirmation.
     */
    public function peek(Assessment $assessment): ?AssessmentOperation
    {
        return $this->latestUndoable($assessment);
    }

    /**
     * Undoes the newest not-yet-undone operation. Any editor may call this,
     * not only the operation's author (ui.md §3.6) — authorization is the
     * caller's job (UndoController's FormRequest), not this service's.
     *
     * Runs entirely inside one transaction: either the whole inverse
     * commits, or nothing does. This is the mechanism behind "refuse rather
     * than half-apply" — an Applier can perform several writes before
     * discovering a later precondition fails, and the transaction rollback
     * erases all of them along with the (never-persisted) undone_at.
     *
     * @throws NothingToUndoException the stack is empty, or the operation was already undone
     * @throws UndoConflictException the inverse cannot be applied cleanly against current state
     */
    public function undo(Assessment $assessment, ?User $user): AssessmentOperation
    {
        $operation = $this->latestUndoable($assessment);

        if ($operation === null) {
            throw new NothingToUndoException('Nothing to undo.');
        }

        return DB::transaction(function () use ($operation, $user): AssessmentOperation {
            // Re-select and lock inside the transaction: two concurrent
            // undo requests racing against the same row must not both
            // succeed — the second one must see undone_at already set.
            /** @var AssessmentOperation $locked */
            $locked = AssessmentOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();

            if ($locked->undone_at !== null) {
                throw new NothingToUndoException('This change was already undone.');
            }

            // May itself throw UndoConflictException, which propagates out
            // of the transaction and rolls back any partial writes the
            // Applier made first.
            $redoCommand = $this->dispatcher->apply($this->inverseOf($locked), $locked->assessment_id);

            $locked->undone_at = now();
            $locked->undone_by = $user?->id;
            // The inverse is replaced with the command that would redo this
            // undo — see redo()'s docblock for why no second stored payload
            // is needed. setAttribute(), not the ->inverse magic-property
            // syntax: see inverseOf()'s docblock for why.
            $locked->setAttribute('inverse', $redoCommand);
            $locked->save();

            return $locked;
        });
    }

    /**
     * Redoes the most recently undone operation by re-applying the command
     * `undo()` left behind, then pushing the result as a *new* operation
     * (schema.md §3.10: "Redo is the undo of an undo, pushed as a new
     * operation"). This keeps "only the newest not-yet-undone operation is
     * undoable" simple: after a redo, the pushed row is what Ctrl+Z targets
     * next, exactly like any other structural change.
     *
     * The consumed row keeps its `undone_at` and gains a `redone_at` (W108),
     * which takes it off both stacks: `latestUndoable()` already skips it
     * because it is undone, and the query below skips it because it is
     * redone. Clearing `undone_at` instead, as this did before W108, made the
     * row undoable again while its `inverse` still held the redo command, so
     * undo, redo, undo, undo re-applied the change instead of undoing the
     * operation before it.
     *
     * @throws NothingToUndoException nothing has been undone
     * @throws UndoConflictException the redo cannot be applied cleanly against current state
     */
    public function redo(Assessment $assessment, ?User $user): AssessmentOperation
    {
        $undone = AssessmentOperation::query()
            ->where('assessment_id', $assessment->id)
            ->whereNotNull('undone_at')
            ->whereNull('redone_at')
            ->whereNull('superseded_at')
            // Microseconds since W108, so two undos in one second (an
            // ordinary Ctrl+Z, Ctrl+Z) no longer tie on the timestamp.
            ->orderByDesc('undone_at')
            ->orderByDesc('id')
            ->first();

        if ($undone === null) {
            throw new NothingToUndoException('Nothing to redo.');
        }

        return DB::transaction(function () use ($undone, $user): AssessmentOperation {
            /** @var AssessmentOperation $locked */
            $locked = AssessmentOperation::query()->whereKey($undone->id)->lockForUpdate()->firstOrFail();

            if ($locked->undone_at === null || $locked->redone_at !== null) {
                throw new NothingToUndoException('Nothing to redo.');
            }

            // A new operation can stamp the row between the select above and
            // this lock. Its own message, so a test can tell this re-check
            // from the query's `superseded_at` filter, which answers first.
            if ($locked->superseded_at !== null) {
                throw new NothingToUndoException('This change can no longer be redone.');
            }

            $undoCommand = $this->dispatcher->apply($this->inverseOf($locked), $locked->assessment_id);

            $locked->redone_at = now();
            $locked->save();

            return AssessmentOperation::create([
                'assessment_id' => $locked->assessment_id,
                'user_id' => $user?->id,
                'kind' => $locked->kind,
                'summary' => $locked->summary,
                'inverse' => $undoCommand,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * `inverse` is cast to `array` on the model (AssessmentOperation::
     * casts()), but that Laravel-11-style method-based cast declaration
     * isn't picked up by static analysis here, so ->inverse reads as
     * `string` to PHPStan even though it is already an array at runtime.
     * getAttribute() (untyped) plus this cast sidesteps that without
     * touching app/Models/** (outside this package's ownership).
     *
     * @return array<string, mixed>
     */
    private function inverseOf(AssessmentOperation $operation): array
    {
        /** @var array<string, mixed> $inverse */
        $inverse = $operation->getAttribute('inverse');

        return $inverse;
    }

    private function latestUndoable(Assessment $assessment): ?AssessmentOperation
    {
        return AssessmentOperation::query()
            ->where('assessment_id', $assessment->id)
            ->whereNull('undone_at')
            ->orderByDesc('id')
            ->first();
    }
}
