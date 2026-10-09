<?php

namespace App\Domain\Calculation;

use App\Models\Assessment;
use Illuminate\Support\Facades\Redis;

/**
 * Redis cache around RiskCalculator, deferred from W7 to W16 because the
 * engine itself must stay pure — no Eloquent, no I/O, no clock, no
 * randomness (RiskCalculator.php's own docblock, enforced by an
 * architecture test) — and a cache is I/O by definition. This class is
 * the only thing that touches Redis for this purpose; RiskCalculator
 * never learns this exists (calculation-engine.md §11.4).
 *
 * **Invalidation is an explicit version bump, never TTL or a fingerprint**
 * (calculation-engine.md §11.4): `assessments.calc_version` is incremented
 * by App\Observers\Concerns\TriggersRecalculation on every node, pivot or
 * scale change, and that new version is part of the cache key itself, so
 * a stale entry is simply never looked up again — it ages out of Redis on
 * its own TTL with nothing left pointing at it. This is deterministic and
 * race-free: two concurrent writers bumping the version means whichever
 * request/job reads afterward asks for a *later* key than either write
 * alone would have produced, and there is no way to observe a version
 * bump without also observing the mutation that caused it, since both
 * happen inside the same request.
 *
 * The engine version is bumped only when RiskCalculator's actual output
 * shape changes (never for this file's own changes), so a deploy that
 * touches only caching code does not need to invalidate every cached
 * result — but *does* guarantee old cache entries are never read by a
 * differently-shaped engine, since they simply fall under a different key.
 */
final class CalculationCache
{
    private const ENGINE_VERSION = 1;

    /**
     * calculation-engine.md §11.4: 6h. A cache entry can only ever be
     * served for the exact (assessment, calc_version, engine_version)
     * triple it was written for, so a generous TTL costs nothing but
     * memory — it is not a correctness knob here the way it would be for a
     * fingerprint- or time-based cache.
     */
    private const TTL_SECONDS = 21600;

    public function get(Assessment $assessment): ?CalculationResults
    {
        $raw = Redis::get(self::key($assessment));

        if ($raw === null || $raw === false) {
            return null;
        }

        $results = @unserialize((string) $raw);

        return $results instanceof CalculationResults ? $results : null;
    }

    public function put(Assessment $assessment, CalculationResults $results): void
    {
        Redis::setex(self::key($assessment), self::TTL_SECONDS, serialize($results));
    }

    /**
     * The read path. Until this existed the cache was write-only —
     * RecalculateAssessment filled it after every debounced mutation and
     * nothing ever looked at it, so every page load recomputed from
     * scratch.
     *
     * A miss fills the cache here rather than waiting for the job, so a
     * cold assessment is warm after its first view instead of only after
     * its first *edit*. Reading is safe because the key contains
     * `calc_version`, which every mutation bumps — including scale
     * recalibration, which did not bump it until ScaleLevelObserver was
     * added alongside this method. A stale entry is therefore never looked
     * up rather than being served: correctness here rests entirely on that
     * bump being complete, which is why
     * tests/Feature/Performance/CalculationCacheInvalidationTest.php walks
     * every mutation type rather than trusting the observers by inspection.
     *
     * @param  callable(): CalculationResults  $compute
     */
    public function remember(Assessment $assessment, callable $compute): CalculationResults
    {
        $cached = $this->get($assessment);

        if ($cached instanceof CalculationResults) {
            return $cached;
        }

        $results = $compute();
        $this->put($assessment, $results);

        return $results;
    }

    /**
     * Public so RecalculateAssessment (app/Jobs, same package) and tests
     * can name the exact key without duplicating the format.
     */
    public static function key(Assessment $assessment): string
    {
        return sprintf(
            'as:calc:%d:v%d:e%d',
            $assessment->id,
            $assessment->calc_version,
            self::ENGINE_VERSION,
        );
    }
}
