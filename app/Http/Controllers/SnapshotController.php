<?php

namespace App\Http\Controllers;

use App\Domain\History\UndoService;
use App\Domain\Snapshots\RestoreOutcome;
use App\Domain\Snapshots\SnapshotDiffer;
use App\Domain\Snapshots\SnapshotGraphCodec;
use App\Domain\Snapshots\SnapshotNotRestorableException;
use App\Domain\Snapshots\SnapshotRestorer;
use App\Domain\Snapshots\SnapshotService;
use App\Domain\Snapshots\SnapshotViewPresenter;
use App\Models\Assessment;
use App\Models\Snapshot;
use DateTimeInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Snapshots (PLAN.md Phase 8 item 3, schema.md §3.13): freeze a named
 * version of an assessment storing both the frozen graph and the frozen
 * three-baseline results, with the scales inlined so a later recalibration
 * cannot retroactively rewrite a report already sent to a client.
 *
 * Authorization, per the W19 task brief (CLAUDE.md's non-negotiables list
 * every route touching an assessment through AssessmentPolicy, no
 * exceptions): creating and deleting a snapshot need `update` — a snapshot
 * is a write of a new historical record, gated the same as any other
 * assessment mutation; listing, viewing and diffing need only `view`,
 * matching ReportController's read routes, since a snapshot is exactly as
 * visible as the tree it was taken from. **Restoring one** (W61) also needs
 * `update`, and that decision has its own reasoning on restore() below —
 * it is the one place where the obvious answer was the wrong one.
 *
 * No FormRequest class here — this package owns only this controller, not
 * app/Http/Requests/** — matching LockController's own documented reason
 * for doing authorization and validation inline instead.
 *
 * `{snapshot}` binds by numeric id (Snapshot carries no uuid column per
 * schema.md §3.13) and is NOT scoped to `{assessment}` via
 * Route::scopeBindings() — that mechanism guesses a relation name from the
 * route segment, and Assessment has no `snapshot()` singular relation — so
 * every method here re-asserts the snapshot belongs to the route's
 * assessment before touching it, the same cross-assessment IDOR check
 * NodeController and LockController's own docblocks describe.
 */
class SnapshotController extends Controller
{
    /**
     * The version list is the History page since 2.5.1 (VersionTimeline):
     * versions are its structure, with the changes that went into each one.
     * This URL was only ever reachable from a footnote on that page, and is
     * kept as a redirect so an old bookmark still lands somewhere useful.
     */
    public function index(Request $request, Assessment $assessment): RedirectResponse
    {
        $this->authorizeView($request, $assessment);

        return redirect()->route('assessments.history.index', $assessment);
    }

    public function store(Request $request, Assessment $assessment, SnapshotService $service): RedirectResponse
    {
        $this->authorizeUpdate($request, $assessment);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $snapshot = $service->create(
            assessment: $assessment,
            name: $validated['name'],
            note: $validated['note'] ?? null,
            createdByUserId: $request->user()?->id,
        );

        return redirect()
            ->route('assessments.snapshots.show', ['assessment' => $assessment, 'snapshot' => $snapshot])
            ->with('success', 'Version saved.');
    }

    public function show(Request $request, Assessment $assessment, Snapshot $snapshot, SnapshotGraphCodec $codec): Response
    {
        $this->authorizeView($request, $assessment);
        $this->assertBelongsTo($assessment, $snapshot);

        $snapshot->loadMissing('createdBy:id,name');

        return Inertia::render('Snapshots/Show', [
            'assessment' => ['uuid' => $assessment->uuid, 'currency' => $assessment->currency],
            'canRestore' => Gate::forUser($request->user())->allows('update', $assessment),
            'snapshot' => $this->snapshotSummary($snapshot) + [
                'note' => $snapshot->note,
                'graph' => $codec->decode($snapshot->graph),
                'results' => $snapshot->results,
            ],
        ]);
    }

    /**
     * Browsing a frozen version in the editor's own views (W63).
     *
     * `show` answers "what did this version total?"; this answers "what did it
     * look like?". They are different questions and the second is the one
     * people actually have when something has gone wrong, so it gets the chart
     * and the list rather than a page of counts.
     *
     * `view`, matching `show` and `index` — a snapshot is exactly as visible as
     * the assessment it was taken from, and this route reveals nothing `show`
     * does not already serve; it is the same decoded graph, laid out.
     *
     * **It writes nothing, and that is structural rather than a promise.** The
     * page builds its store with every ability false *and* with the store's
     * `frozen` flag set, which turns each mutator into a no-op. Abilities alone
     * would not be enough: they gate what is *offered*, and this graph carries
     * label ids minted for the render that must never reach a `node_tag` row —
     * SnapshotViewPresenter::vocabulary() has the detail.
     */
    public function view(
        Request $request,
        Assessment $assessment,
        Snapshot $snapshot,
        SnapshotViewPresenter $presenter,
    ): Response {
        $this->authorizeView($request, $assessment);
        $this->assertBelongsTo($assessment, $snapshot);

        $snapshot->loadMissing('createdBy:id,name');

        return Inertia::render('Snapshots/View', [
            'snapshot' => $this->snapshotSummary($snapshot) + ['note' => $snapshot->note],
            'canRestore' => Gate::forUser($request->user())->allows('update', $assessment),
            ...$presenter->present($snapshot, $assessment->uuid, $assessment->display_mode),
        ]);
    }

    public function diff(Request $request, Assessment $assessment, SnapshotDiffer $differ): Response
    {
        $this->authorizeView($request, $assessment);

        $validated = $request->validate([
            'from' => ['required', 'integer'],
            'to' => ['required', 'integer', 'different:from'],
        ]);

        $from = $assessment->snapshots()->findOrFail($validated['from']);
        $to = $assessment->snapshots()->findOrFail($validated['to']);

        return Inertia::render('Snapshots/Diff', [
            'assessment' => ['uuid' => $assessment->uuid, 'currency' => $assessment->currency],
            'diff' => $differ->diff($from, $to),
        ]);
    }

    /**
     * Puts a frozen version back, in place (W61).
     *
     * ## Why `update` and not `manage`
     *
     * A restore is the most destructive action in the application, so
     * ownership-level gating was the obvious first answer and it is the wrong
     * one — because undo would have been a hole straight through it. Undo is
     * gated on `update`, an editor can already reach it, and the inverse of a
     * restore is another restore: a `manage`-only restore reachable through an
     * `update`-gated undo is not a guard, it is a longer route to the same
     * place. Beyond that, an editor can already delete every node one at a
     * time, and this is recoverable in one click while that is not.
     *
     * ## Order matters, and the whole thing is one transaction
     *
     * The safety snapshot is taken **first**, because it must capture the
     * state about to be overwritten and because the operation's `inverse`
     * points at its id. The operation row is written **second**, because every
     * revision the restore causes is stamped with its id so the timeline reads
     * as one entry rather than one per node. The restore itself is third. A
     * failure anywhere rolls back all three — including the safety snapshot,
     * which would otherwise be left behind claiming a restore had happened.
     */
    public function restore(
        Request $request,
        Assessment $assessment,
        Snapshot $snapshot,
        SnapshotRestorer $restorer,
        UndoService $undo,
    ): RedirectResponse {
        $this->authorizeUpdate($request, $assessment);
        $this->assertBelongsTo($assessment, $snapshot);

        $actor = $request->user();

        try {
            $outcome = DB::transaction(function () use ($assessment, $snapshot, $actor, $restorer, $undo): RestoreOutcome {
                $safety = $restorer->safetySnapshot($assessment, $snapshot, $actor);

                $operation = $undo->record(
                    assessment: $assessment,
                    user: $actor,
                    kind: 'snapshot.restore',
                    summary: "Restored the snapshot '{$snapshot->name}'",
                    inverse: [
                        'action' => 'restore_snapshot',
                        'assessment_id' => $assessment->id,
                        'snapshot_id' => $safety->id,
                    ],
                );

                $outcome = $restorer->restore($assessment, $snapshot, $actor, $operation->id);

                return new RestoreOutcome(
                    safetySnapshotId: $safety->id,
                    safetySnapshotName: $safety->name,
                    created: $outcome->created,
                    updated: $outcome->updated,
                    deleted: $outcome->deleted,
                    unrestorableLevels: $outcome->unrestorableLevels,
                    unlabelled: $outcome->unlabelled,
                    unassignable: $outcome->unassignable,
                );
            });
        } catch (SnapshotNotRestorableException $e) {
            // A snapshot written by a newer schema version, or one that has
            // moved assessments. Reported to the person who pressed the button
            // rather than as a 500: nothing was written, and the message says
            // which of the two it was.
            //
            // Narrowed from `RuntimeException` in W62, which was not the small
            // difference it looks like. `QueryException` extends
            // `PDOException` extends `RuntimeException`, so the broad catch
            // swallowed every database error in the restore and rendered its
            // message — the failing SQL and its bound values — into this flash.
            // That is the class's whole reason for existing; it argues the case
            // in full.
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('assessments.show', $assessment->uuid)
            ->with('success', $this->restoreSummary($outcome))
            ->with('restore', $outcome->toArray());
    }

    /**
     * What the page says after a restore.
     *
     * Counts first, because "restored" alone does not tell someone whether
     * four nodes went or forty. The two incompleteness counts are appended
     * only when they are non-zero — a sentence about scale levels on every
     * restore trains people to skip the whole message.
     */
    private function restoreSummary(RestoreOutcome $outcome): string
    {
        $parts = ["Restored: {$outcome->created} added, {$outcome->updated} updated, {$outcome->deleted} removed."];

        if ($outcome->unlabelled > 0) {
            $parts[] = "{$outcome->unlabelled} node(s) came back without their labels or assignees — this snapshot predates the format that records them.";
        }

        if ($outcome->unassignable > 0) {
            $parts[] = "{$outcome->unassignable} assignment(s) were not restored: those people no longer have access to this assessment.";
        }

        if ($outcome->unrestorableLevels > 0) {
            $parts[] = "{$outcome->unrestorableLevels} value(s) referred to a scale level that no longer exists and were left unset.";
        }

        $parts[] = "The previous state was saved as '{$outcome->safetySnapshotName}'.";

        return implode(' ', $parts);
    }

    public function destroy(Request $request, Assessment $assessment, Snapshot $snapshot): RedirectResponse
    {
        $this->authorizeUpdate($request, $assessment);
        $this->assertBelongsTo($assessment, $snapshot);

        $snapshot->delete();

        return redirect()
            ->route('assessments.history.index', $assessment)
            ->with('success', 'Version deleted.');
    }

    /**
     * Never serializes `created_by`'s email — an allowlist of `id`/`name`
     * only, matching ReportAssembler's own reasoning for the report cover's
     * owner field: `view` is the broadest ability in the system (a public
     * assessment or a share-token session both grant it with no account),
     * so anything placed in a page prop reachable from this ability is
     * published to that whole audience.
     *
     * `schemaVersion` is here rather than only on the detail page because the
     * restore warning is shown from the list too, and W62 made that warning
     * depend on it: a v1 snapshot cannot bring labels back and a v2 one can,
     * and a dialog that cannot tell which it is has to either overstate or
     * understate what is about to happen.
     *
     * @return array{id: int, name: string, schemaVersion: int, createdBy: ?string, createdAt: ?string}
     */
    private function snapshotSummary(Snapshot $snapshot): array
    {
        return [
            'id' => $snapshot->id,
            'name' => $snapshot->name,
            'schemaVersion' => $snapshot->schema_version,
            'createdBy' => $snapshot->createdBy?->name,
            'createdAt' => $this->isoDate($snapshot->getAttribute('created_at')),
        ];
    }

    /**
     * `created_at` is cast to `datetime` on the model (Snapshot::casts()),
     * but that Laravel-11-style method-based cast declaration isn't picked
     * up by static analysis here (`parseModelCastsMethod` defaults to
     * false in vendor/larastan/larastan/extension.neon, a file this
     * package does not own), so ->created_at reads as `string` to PHPStan
     * even though it is a Carbon instance at runtime. getAttribute()
     * (untyped, `mixed`) plus this runtime check sidesteps that without
     * touching app/Models/** — the same pattern UndoController::toJson()
     * already uses for AssessmentOperation, which has the identical
     * `$timestamps = false` + method-cast() shape.
     */
    private function isoDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof DateTimeInterface
            ? Carbon::instance($value)->toIso8601String()
            : Carbon::parse((string) $value)->toIso8601String();
    }

    private function assertBelongsTo(Assessment $assessment, Snapshot $snapshot): void
    {
        abort_unless($snapshot->assessment_id === $assessment->id, 404);
    }

    private function authorizeView(Request $request, Assessment $assessment): void
    {
        if (Gate::forUser($request->user())->denies('view', $assessment)) {
            abort(403);
        }
    }

    private function authorizeUpdate(Request $request, Assessment $assessment): void
    {
        if (Gate::forUser($request->user())->denies('update', $assessment)) {
            abort(403);
        }
    }
}
