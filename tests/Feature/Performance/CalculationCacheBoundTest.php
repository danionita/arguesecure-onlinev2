<?php

use App\Domain\Calculation\CalculationCache;
use App\Domain\Calculation\CalculationResults;
use App\Domain\Calculation\RiskCalculator;
use App\Domain\Graph\AssessmentGraphLoader;
use App\Events\TotalsRecomputed;
use App\Jobs\RecalculateAssessment;
use App\Models\Assessment;
use App\Models\Risk;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;

/*
 * W109 (PLAN-2.7.md D72): CalculationCache::put() leaves one entry per
 * assessment. Every edit bumps calc_version and so writes a new key; before
 * this, each predecessor lingered for the six-hour TTL, and with Redis set to
 * `noeviction` that growth would end in refused writes for the queue and the
 * locks too.
 */
beforeEach(fn () => Event::fake([TotalsRecomputed::class]));

/** Cached results for this assessment, the pointer excluded. */
function cacheBoundEntries(Assessment $assessment): int
{
    return count(Redis::keys("as:calc:{$assessment->id}:v*"));
}

function cacheBoundRecalculate(Assessment $assessment): void
{
    (new RecalculateAssessment($assessment->id))->handle(
        app(AssessmentGraphLoader::class),
        app(RiskCalculator::class),
        app(CalculationCache::class),
    );
}

function cacheBoundResults(Assessment $assessment): CalculationResults
{
    return app(RiskCalculator::class)->compute(app(AssessmentGraphLoader::class)->load($assessment));
}

test('several edits leave one cached entry, the current one', function () {
    $assessment = Assessment::factory()->create();

    foreach (range(1, 4) as $amount) {
        Risk::factory()->for($assessment)->create(['impact_mode' => 'quantitative', 'impact_amount' => $amount * 1000]);
        cacheBoundRecalculate($assessment);
    }

    $current = $assessment->fresh();

    expect(cacheBoundEntries($current))->toBe(1)
        ->and(Redis::exists(CalculationCache::key($current)))->toBe(1)
        ->and((new CalculationCache)->get($current))->not->toBeNull();
});

test('versions skipped between two writes are no obstacle: the write before is still deleted', function () {
    // The debounced job and W64's coalescing both mean consecutive writes
    // need not be consecutive versions, so deleting key(calc_version - 1)
    // would leave this one behind.
    $assessment = Assessment::factory()->create(['calc_version' => 10]);
    $cache = new CalculationCache;
    $results = cacheBoundResults($assessment);

    $cache->put($assessment, $results);
    $assessment->calc_version = 17;
    $cache->put($assessment, $results);

    expect(cacheBoundEntries($assessment))->toBe(1)
        ->and(Redis::exists(CalculationCache::key($assessment)))->toBe(1);
});

test('a write for an older version than the last one still leaves one entry', function () {
    // A request that read the assessment before an edit landed writes behind
    // the job's newer entry.
    $assessment = Assessment::factory()->create(['calc_version' => 5]);
    $stale = $assessment->fresh();
    $cache = new CalculationCache;
    $results = cacheBoundResults($assessment);

    $assessment->calc_version = 6;
    $cache->put($assessment, $results);
    $cache->put($stale, $results);

    expect(cacheBoundEntries($assessment))->toBe(1)
        ->and(Redis::exists(CalculationCache::key($stale)))->toBe(1);
});

test('writing one assessment leaves every other assessment\'s entry alone', function () {
    $one = Assessment::factory()->create();
    $other = Assessment::factory()->create();
    $cache = new CalculationCache;

    $cache->put($other, cacheBoundResults($other));
    $cache->put($one, cacheBoundResults($one));
    $one->calc_version++;
    $cache->put($one, cacheBoundResults($one));

    expect(cacheBoundEntries($other))->toBe(1)
        ->and($cache->get($other))->not->toBeNull()
        ->and(cacheBoundEntries($one))->toBe(1);
});

test('the pointer expires with the entry it names', function () {
    $assessment = Assessment::factory()->create();

    (new CalculationCache)->put($assessment, cacheBoundResults($assessment));

    expect(Redis::ttl(CalculationCache::latestKey($assessment)))->toBeGreaterThan(21000)->toBeLessThanOrEqual(21600)
        ->and(Redis::ttl(CalculationCache::key($assessment)))->toBeGreaterThan(21000)->toBeLessThanOrEqual(21600);
});
