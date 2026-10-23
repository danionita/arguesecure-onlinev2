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
 * a stale entry is simply never looked up again, and put() deletes it
 * (D72, below). This is deterministic and
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

    /**
     * Writes this version's entry and deletes whichever entry the previous
     * put() wrote, so each assessment holds one entry, not one per edit
     * (PLAN-2.7.md D72). Before this, every edit left its predecessor to
     * linger for the full TTL, and that growth is what a Redis running
     * `noeviction` would eventually refuse writes over, the queue's
     * included.
     *
     * The previous version cannot be derived from the current one: nothing
     * guarantees consecutive versions (W64 bumps once per coalesced block, and
     * the debounced job skips the versions in between), so a per-assessment
     * pointer records the key last written. One script makes the swap atomic,
     * so two concurrent writers cannot both read the same pointer and leave one
     * entry orphaned. A writer holding an older version than the pointer's (a
     * request that read the assessment before an edit landed) replaces the
     * newer entry; the next read misses and writes it back, so the bound still
     * holds and the cost is one recompute.
     *
     * phpredis applies REDIS_PREFIX to KEYS but not to ARGV, so the pointer
     * holds the prefixed key name the script received, and DEL reads it back
     * as-is: the key the earlier write made, not a second prefix on top.
     */
    public function put(Assessment $assessment, CalculationResults $results): void
    {
        // ->command() reaches phpredis' native eval($script, $args, $numKeys),
        // as LockManager does for set(): the Laravel connection's own eval()
        // takes ($script, $numKeys, ...$args) and phpredis' stubs disagree.
        Redis::connection()->command('eval', [
            <<<'LUA'
            local previous = redis.call('GET', KEYS[2])
            redis.call('SETEX', KEYS[1], ARGV[1], ARGV[2])
            redis.call('SETEX', KEYS[2], ARGV[1], KEYS[1])
            if previous and previous ~= KEYS[1] then
                redis.call('DEL', previous)
            end
            return 1
            LUA,
            [self::key($assessment), self::latestKey($assessment), self::TTL_SECONDS, serialize($results)],
            2,
        ]);
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

    /**
     * The pointer put() keeps to the entry it wrote last. Its name shares the
     * `as:calc:{id}:` prefix so a sweep of `as:calc:*` clears it with the
     * entries, but not the `v` that every entry key carries next.
     */
    public static function latestKey(Assessment $assessment): string
    {
        return sprintf('as:calc:%d:latest', $assessment->id);
    }
}
