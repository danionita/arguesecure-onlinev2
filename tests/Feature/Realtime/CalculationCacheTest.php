<?php

use App\Domain\Calculation\CalculationCache;
use App\Domain\Calculation\RiskCalculator;
use App\Domain\Graph\AssessmentGraphLoader;
use App\Models\Assessment;
use App\Models\Risk;
use Illuminate\Support\Facades\Redis;

/*
 * calculation-engine.md §11.4 — deferred from W7 to W16 because
 * RiskCalculator must stay pure (no Eloquent, no I/O). "Cache the full
 * result... invalidate by explicit version bump, not TTL or fingerprint."
 * Required by the W16 brief: "the cache is invalidated by a version bump
 * rather than by time."
 */
afterEach(function () {
    $keys = Redis::keys('as:calc:*');
    if ($keys !== []) {
        Redis::del($keys);
    }
});

test('put then get round-trips the full CalculationResults', function () {
    $assessment = Assessment::factory()->create();
    $graph = app(AssessmentGraphLoader::class)->load($assessment);
    $results = app(RiskCalculator::class)->compute($graph);
    $cache = new CalculationCache;

    $cache->put($assessment, $results);
    $fromCache = $cache->get($assessment);

    expect($fromCache)->not->toBeNull()
        ->and($fromCache->inherent->total)->toBe($results->inherent->total)
        ->and($fromCache->current->total)->toBe($results->current->total)
        ->and($fromCache->target->total)->toBe($results->target->total);
});

test('get is a miss for an assessment nothing has been cached for', function () {
    $assessment = Assessment::factory()->create();

    expect((new CalculationCache)->get($assessment))->toBeNull();
});

test('a calc_version bump invalidates the cache — the *old* version is never read again', function () {
    $assessment = Assessment::factory()->create();
    $graph = app(AssessmentGraphLoader::class)->load($assessment);
    $results = app(RiskCalculator::class)->compute($graph);
    $cache = new CalculationCache;

    $cache->put($assessment, $results);
    expect($cache->get($assessment))->not->toBeNull();

    // The bump a mutation causes (App\Observers\Concerns\TriggersRecalculation)
    // — simulated directly here since this test is about the cache
    // primitive, not the observer wiring (covered in
    // RecalculateAssessmentDebounceTest.php).
    Assessment::query()->where('id', $assessment->id)->increment('calc_version');
    $assessment->refresh();

    // No TTL passed, no time elapsed — the *only* thing that changed is
    // the version baked into the key, and that alone is enough to miss.
    expect($cache->get($assessment))->toBeNull();
});

test('the cache key changes when and only when calc_version changes', function () {
    $assessment = Assessment::factory()->create(['calc_version' => 5]);
    $keyBefore = CalculationCache::key($assessment);

    $assessment->calc_version = 5;
    expect(CalculationCache::key($assessment))->toBe($keyBefore);

    $assessment->calc_version = 6;
    expect(CalculationCache::key($assessment))->not->toBe($keyBefore);
});

test('creating a risk under an assessment bumps calc_version via the observer', function () {
    $assessment = Assessment::factory()->create(['calc_version' => 0]);

    Risk::factory()->create(['assessment_id' => $assessment->id]);

    expect($assessment->fresh()->calc_version)->toBeGreaterThan(0);
});
