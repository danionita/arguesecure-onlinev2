<?php

namespace App\Jobs;

use App\Domain\Calculation\CalculationCache;
use App\Domain\Calculation\RiskCalculator;
use App\Domain\Graph\AssessmentGraphLoader;
use App\Events\TotalsRecomputed;
use App\Models\Assessment;
use App\Observers\Concerns\TriggersRecalculation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PLAN.md Phase 6 item 4 — "a queued RecalculateAssessment job debounced
 * 250ms per assessment, so dragging a slider produces one broadcast rather
 * than forty."
 *
 * The debounce is TriggersRecalculation::debounce()'s job, not this job's:
 * every mutating observer calls it, and it is the Redis `SET NX EX`
 * there — not anything in this class — that collapses a burst of
 * dispatches into a single delayed one. This job's own responsibility
 * starts only once it actually runs: clear the debounce flag *first*, so a
 * mutation landing while this job is mid-computation gets its own future
 * run rather than being silently absorbed by a flag this run is about to
 * delete anyway, then compute and broadcast the freshest possible result.
 */
class RecalculateAssessment implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $assessmentId) {}

    public function handle(
        AssessmentGraphLoader $loader,
        RiskCalculator $calculator,
        CalculationCache $cache,
    ): void {
        TriggersRecalculation::clearDebounce($this->assessmentId);

        $assessment = Assessment::find($this->assessmentId);

        // Deleted between dispatch and execution — nothing left to
        // recompute or broadcast to.
        if ($assessment === null) {
            return;
        }

        // Best-effort, not authoritative: a node delete deliberately leaves
        // its edges in place for undo (NodeController's own docblock,
        // outside this package's ownership — "a soft delete leaves the
        // edge rows in place... which is what makes the inverse a
        // links-intact restore"), which is a real window in which
        // AssessmentGraphLoader can be asked to resolve an edge pointing
        // at a node that query excludes. That window is a pre-existing
        // property of the delete/undo design this job did not create, but
        // it is now the first caller able to observe it asynchronously
        // (every other current call site only ever loads the graph for a
        // live page render, never in the moment right after a delete). A
        // transient miss here costs one stale cache read at worst — the
        // per-node/per-link data was never wrong, only this aggregate —
        // so it is swallowed rather than allowed to fail the job: the
        // *next* mutation (the undo itself, or a manual edge cleanup)
        // re-triggers this same job and succeeds once the graph is
        // consistent again.
        try {
            $graph = $loader->load($assessment);
            $results = $calculator->compute($graph);
        } catch (Throwable $e) {
            Log::warning('RecalculateAssessment: transient graph load/compute failure, will retry on the next mutation.', [
                'assessment_id' => $this->assessmentId,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        $cache->put($assessment, $results);

        event(new TotalsRecomputed($assessment->uuid, $results));
    }
}
