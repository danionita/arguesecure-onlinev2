<?php

namespace App\Domain\Snapshots;

use App\Models\Assessment;
use App\Models\AssessmentOperation;
use App\Models\NodeRevision;
use App\Models\Snapshot;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Saves versions without anyone having to remember to (2.5.1).
 *
 * Until 2.5.1 a version — a snapshot — existed only if someone opened a page
 * reachable from a footnote on the History page and pressed a button there,
 * so most assessments had none and "go back to an earlier state" had nothing
 * to go back to. Now the scheduler (`arguesecure:save-versions`, every five
 * minutes) saves one for any assessment with unsaved changes when either:
 *
 * - **editing has paused** — nothing has changed for IDLE_MINUTES, which is
 *   the end of a working session in practice; or
 * - **a lot has changed** — MAX_CHANGES changes since the last version, so a
 *   long workshop is not one enormous version.
 *
 * "Changes" are the History page's own entries: rows in the two history logs
 * (`assessment_operations`, `node_revisions`) written after the latest
 * version. An assessment nobody has touched since its last version is never
 * saved again, so an idle instance accumulates nothing.
 *
 * Each save goes through SnapshotService exactly as a manual one does, marked
 * `kind = auto` and owned by nobody.
 */
final class AutoVersioner
{
    public const IDLE_MINUTES = 30;

    public const MAX_CHANGES = 50;

    public function __construct(private readonly SnapshotService $snapshots) {}

    /**
     * @return int how many versions were saved
     */
    public function run(?CarbonInterface $now = null): int
    {
        $now ??= Carbon::now();
        $saved = 0;

        foreach ($this->candidates() as $assessmentId => $since) {
            $pending = $this->pendingChanges($assessmentId, $since);

            if ($pending['count'] === 0 || $pending['last'] === null) {
                continue;
            }

            $idle = $pending['last']->lte($now->copy()->subMinutes(self::IDLE_MINUTES));
            $many = $pending['count'] >= self::MAX_CHANGES;

            if (! $idle && ! $many) {
                continue;
            }

            $assessment = Assessment::query()->find($assessmentId);
            if ($assessment === null) {
                continue; // deleted since the change was recorded
            }

            try {
                $this->snapshots->create(
                    assessment: $assessment,
                    name: $this->nameFor($pending['count'], $many && ! $idle),
                    note: null,
                    createdByUserId: null,
                    kind: 'auto',
                );
                $saved++;
            } catch (Throwable $e) {
                // One assessment that cannot be serialised must not stop the
                // rest from being saved; the next run tries it again.
                Log::warning('AutoVersioner: could not save a version.', [
                    'assessment_id' => $assessmentId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $saved;
    }

    /**
     * Assessments with any history row newer than their latest version,
     * keyed by id, valued by that version's time (or null if they have none).
     *
     * @return array<int, Carbon|null>
     */
    private function candidates(): array
    {
        /** @var array<int, string> $latest */
        $latest = Snapshot::query()
            ->selectRaw('assessment_id, MAX(created_at) AS latest')
            ->groupBy('assessment_id')
            ->pluck('latest', 'assessment_id')
            ->all();

        /** @var array<int, string> $lastChange */
        $lastChange = NodeRevision::query()
            ->selectRaw('assessment_id, MAX(created_at) AS last_change')
            ->groupBy('assessment_id')
            ->pluck('last_change', 'assessment_id')
            ->all();

        foreach (AssessmentOperation::query()
            ->selectRaw('assessment_id, MAX(created_at) AS last_change')
            ->groupBy('assessment_id')
            ->pluck('last_change', 'assessment_id') as $id => $at) {
            if (! isset($lastChange[$id]) || $at > $lastChange[$id]) {
                $lastChange[$id] = $at;
            }
        }

        $candidates = [];

        foreach ($lastChange as $id => $at) {
            $since = isset($latest[$id]) ? Carbon::parse($latest[$id]) : null;

            if ($since === null || Carbon::parse($at)->gt($since)) {
                $candidates[(int) $id] = $since;
            }
        }

        return $candidates;
    }

    /**
     * @return array{count: int, last: Carbon|null}
     */
    public function pendingChanges(int $assessmentId, ?CarbonInterface $since): array
    {
        $revisions = NodeRevision::query()->where('assessment_id', $assessmentId);
        $operations = AssessmentOperation::query()->where('assessment_id', $assessmentId);

        if ($since !== null) {
            $revisions->where('created_at', '>', $since);
            $operations->where('created_at', '>', $since);
        }

        $count = (clone $revisions)->count() + (clone $operations)->count();
        $lastRevision = $revisions->max('created_at');
        $lastOperation = $operations->max('created_at');
        $last = collect([$lastRevision, $lastOperation])->filter()->max();

        return [
            'count' => $count,
            'last' => $last !== null ? Carbon::parse((string) $last) : null,
        ];
    }

    private function nameFor(int $count, bool $becauseOfCount): string
    {
        $changes = $count === 1 ? '1 change' : "{$count} changes";

        return $becauseOfCount
            ? "Saved automatically after {$changes}"
            : "Saved automatically after a pause in editing ({$changes})";
    }
}
