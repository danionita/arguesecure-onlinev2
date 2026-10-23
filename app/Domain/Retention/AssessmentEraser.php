<?php

namespace App\Domain\Retention;

use App\Models\Assessment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Erases an assessment for good: the row and everything that hangs off it
 * (W113, security.md §12). What *Erase permanently* and the daily purge both
 * call; deleting an assessment is otherwise always soft.
 *
 * Mostly the schema's own `ON DELETE CASCADE` (schema.md §3.4, "Deletion
 * semantics"), with two steps done by hand because the cascade alone
 * cannot take them, and one consequence worth stating:
 *
 * 1. **The assessment is pointed at system scales before it is deleted**,
 *    when it owns scales of its own. A calibrated assessment owns its scales
 *    (`scales.assessment_id`, cascading) *and* references them
 *    (`assessments.*_scale_id`, `RESTRICT`), so the cascade refuses its own
 *    row: measured on MariaDB 11, a plain DELETE of such an assessment fails
 *    with 1451. Repointing the three columns at a system scale of each kind,
 *    in the same transaction, breaks the cycle on a row that is gone a
 *    statement later.
 * 2. **History rows go with the assessment** through
 *    `node_revisions.assessment_id`'s cascade, never left with a null
 *    `node_id`: erasing is the one case where history must not survive.
 *    EraseTest asserts none is left.
 * 3. **The row is locked and must still be deleted.** A restore that lands
 *    between the purger's snapshot and this call wins: the erase finds no
 *    soft-deleted row and does nothing.
 *
 * Notifications are the one place the assessment's content lives outside
 * every foreign key: `notifications.data` carries its uuid, its title and a
 * comment excerpt as JSON (App\Domain\Notifications). They are deleted by
 * that uuid, or the title would outlive the erase in someone's feed.
 *
 * Query-builder deletes throughout, so no model observer runs: an erased
 * assessment has nobody left to broadcast to, and a NodeObserver firing per
 * node would queue a recalculation for an assessment about to vanish.
 */
final class AssessmentEraser
{
    /** @return bool false when the assessment was no longer deleted, so nothing was erased */
    public function erase(Assessment $assessment): bool
    {
        $id = $assessment->id;
        $uuid = $assessment->uuid;

        return DB::transaction(function () use ($id, $uuid): bool {
            $row = DB::table('assessments')->where('id', $id)->whereNotNull('deleted_at')->lockForUpdate()->first();
            if ($row === null) {
                return false;
            }

            if (DB::table('scales')->where('assessment_id', $id)->exists()) {
                $update = [];
                foreach (['impact', 'likelihood', 'strength'] as $kind) {
                    // The default set first (W126): with several sets, an unordered
                    // pick would be arbitrary. The rows are erased with the
                    // assessment either way; falling back keeps a database
                    // whose system scales carry no set_key erasable.
                    $system = DB::table('scales')->whereNull('assessment_id')->where('kind', $kind)
                        ->orderByRaw('set_key = ? desc', ['default'])->orderBy('id')->value('id');
                    if ($system === null) {
                        throw new RuntimeException(
                            "No system {$kind} scale exists to release the assessment's own scales from, so it cannot be erased. ".
                            'Run `php artisan db:seed --class=Database\\Seeders\\SystemScaleSeeder`.'
                        );
                    }
                    $update["{$kind}_scale_id"] = $system;
                }
                DB::table('assessments')->where('id', $id)->update($update);
            }

            DB::table('assessments')->where('id', $id)->delete();

            // Laravel stores `data` as compact json_encode() output, and a
            // uuid needs no escaping inside it.
            DB::table('notifications')
                ->where('data', 'like', '%"assessment_uuid":"'.$uuid.'"%')
                ->delete();

            return true;
        });
    }
}
