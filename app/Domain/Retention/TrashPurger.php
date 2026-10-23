<?php

namespace App\Domain\Retention;

use App\Models\Assessment;
use Carbon\CarbonInterface;
use Throwable;

/**
 * The scheduled purge security.md §12 promised and nothing ran until W113:
 * erases every assessment deleted longer ago than `trash_retention_days`.
 *
 * One transaction per assessment, not one for the run: a purge that fails on
 * one assessment still erases the others, and the next run retries the one
 * that failed.
 */
final class TrashPurger
{
    public function __construct(private readonly AssessmentEraser $eraser) {}

    /** The moment before which a deletion is old enough to purge. */
    public static function cutoff(?CarbonInterface $now = null): CarbonInterface
    {
        return ($now ?? now())->copy()->subDays(self::retentionDays());
    }

    public static function retentionDays(): int
    {
        return (int) config('arguesecure.trash_retention_days');
    }

    /** @return int how many assessments were erased */
    public function purge(?CarbonInterface $now = null): int
    {
        // Ids first, then erase: chunking a query while deleting the rows it
        // pages over skips every other chunk.
        $due = Assessment::onlyTrashed()
            ->where('deleted_at', '<', self::cutoff($now))
            ->orderBy('id')
            ->get(['id', 'uuid']);

        $erased = 0;
        foreach ($due as $assessment) {
            try {
                if ($this->eraser->erase($assessment)) {
                    $erased++;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $erased;
    }
}
