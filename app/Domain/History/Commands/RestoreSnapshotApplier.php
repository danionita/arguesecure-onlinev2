<?php

namespace App\Domain\History\Commands;

use App\Domain\History\Exceptions\UndoConflictException;
use App\Domain\Snapshots\SnapshotRestorer;
use App\Models\Assessment;
use App\Models\Snapshot;
use Illuminate\Support\Facades\Auth;

/**
 * Undoes a snapshot restore by restoring the snapshot taken just before it
 * (W61).
 *
 * Every other applier in this package reverses one small structural change.
 * This one reverses a wholesale replacement, and it can only do that because
 * `SnapshotController::restore` takes a **safety snapshot** of the current
 * state before touching anything: the operation's `inverse` points at that
 * row, so "undo the restore" is just "restore the version we saved".
 *
 * The symmetry runs all the way down. Applying this inverse takes its *own*
 * safety snapshot first and returns a command pointing at that, which is what
 * makes the undo redoable — `UndoService` stores whatever an applier returns
 * as the command that reverses what it just did.
 *
 * ## Why the safety snapshots are not cleaned up
 *
 * An undone restore leaves two automatic snapshots behind, and a user who
 * restores three times leaves several. They are deliberately kept: each one is
 * a real, restorable version of work that existed, they are listed with their
 * own names in the snapshot list, and deleting one to tidy up would throw away
 * the only copy of a state somebody might still want. Deleting them is a
 * user's decision, and the list already has a control for it.
 */
final class RestoreSnapshotApplier implements CommandApplier
{
    /**
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>
     */
    public function apply(array $command, int $assessmentId): array
    {
        $snapshotId = (int) ($command['snapshot_id'] ?? 0);

        // D82: only the operation's own assessment may be restored by it.
        if (isset($command['assessment_id']) && (int) $command['assessment_id'] !== $assessmentId) {
            throw new UndoConflictException('Cannot undo: this change belongs to another assessment.');
        }

        $assessment = Assessment::query()->find($assessmentId);
        $snapshot = Snapshot::query()->find($snapshotId);

        if ($assessment === null || $snapshot === null || $snapshot->assessment_id !== $assessment->id) {
            // The usual cause is somebody deleting the automatic snapshot from
            // the list. Reported as a conflict rather than as a crash, and the
            // transaction in UndoService rolls back whatever was written
            // first, so a half-applied restore is not a reachable state.
            throw new UndoConflictException('Cannot undo: the snapshot this change would restore no longer exists.');
        }

        /*
         * Resolved from the container rather than injected: CommandDispatcher
         * builds appliers with `new $applierClass` and this package does not
         * own that file. Every other applier needs nothing but Eloquent, so
         * the dispatcher never had a reason to resolve them, and giving it one
         * for this single case would change the contract every applier is
         * written against.
         */
        $restorer = app(SnapshotRestorer::class);
        $actor = Auth::user();

        $safety = $restorer->safetySnapshot($assessment, $snapshot, $actor);

        /*
         * No operation id to group under. `UndoService` does not tell an
         * applier which row it is applying, and this package does not own that
         * class — so the revisions this undo writes stay ungrouped rather than
         * being folded into some other operation's entry. One timeline entry
         * too many is a smaller lie than one attributed to the wrong action.
         */
        $restorer->restore($assessment, $snapshot, $actor, null);

        return [
            'action' => 'restore_snapshot',
            'assessment_id' => $assessment->id,
            'snapshot_id' => $safety->id,
        ];
    }
}
