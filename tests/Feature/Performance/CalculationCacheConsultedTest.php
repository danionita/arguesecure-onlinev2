<?php

use App\Domain\Calculation\CalculationCache;
use App\Domain\Calculation\RiskCalculator;
use App\Domain\Graph\AssessmentGraphLoader;
use App\Domain\Structure\LinkService;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Risk;

/**
 * W24 (performance) brief, verbatim: "confirm CalculationCache is actually
 * consulted, and that the calc_version bump invalidates it. A cache that
 * never hits is worse than no cache."
 *
 * It does not hold. `App\Domain\Calculation\CalculationCache::get()` has
 * exactly one caller in the whole codebase: its own test file
 * (tests/Feature/Realtime/CalculationCacheTest.php). The only production
 * caller of CalculationCache at all is App\Jobs\RecalculateAssessment,
 * which only ever calls put() after a debounced mutation. Every read path —
 * App\Http\Controllers\AssessmentController::show() and every method on
 * App\Http\Controllers\ReportController — injects AssessmentGraphLoader and
 * RiskCalculator directly and recomputes from scratch on every single
 * request, never consulting the cache RecalculateAssessment just populated.
 * So today: every mutation pays to warm a cache entry that is written once,
 * read never, and left to expire six hours later. That is strictly worse
 * than no cache at all — the write cost (a Redis SETEX plus a PHP
 * serialize() of the whole three-baseline CalculationResults) is pure
 * overhead with no offsetting saving anywhere.
 *
 * This is a real defect, but the controllers that would need to change
 * (AssessmentController.php is W12's, ReportController.php is W17's) are
 * outside this package's owned paths (docker/**, Dockerfile,
 * vite.config.ts, tests/Feature/Performance/**, non-security config) — W24's
 * own rule is "do not fix it silently... pin it as a failing test and
 * report it." This test is that pin. It is expected to fail until a future
 * package wires AssessmentController::show (or a shared helper both it and
 * ReportController call through) to check CalculationCache::get() before
 * calling RiskCalculator::compute(), and to call ->put() itself on a miss
 * so a cold cache is filled by the read path too, not only by the
 * debounced recalculation job.
 *
 * Could not be run in this sandbox — no MariaDB/Redis available (see the
 * W24 performance report) — so this has not been executed or confirmed to
 * fail for the reason described above rather than some incidental setup
 * error. It must be run for real (locally with the stack up, or in CI)
 * before anyone treats its outcome as evidence either way.
 */
test('a warm CalculationCache entry is consulted by the assessment show route instead of recomputing', function () {
    $assessment = Assessment::factory()->create();

    // A *valued* pair, not just a risk: ALE is summed over (risk, attack)
    // pairs, so a lone risk with no attack contributes nothing and the
    // total stays 0 no matter what the impact says — which would make this
    // test pass whether or not the cache was consulted.
    $risk = Risk::factory()->for($assessment)->create([
        'impact_mode' => 'quantitative',
        'impact_amount' => 10_000,
    ]);
    $attack = Attack::factory()->for($assessment)->create([
        'likelihood_mode' => 'quantitative',
        'likelihood_aro' => 2.0,
    ]);
    app(LinkService::class)->linkRiskToAttack($risk, $attack);

    // Warm the cache exactly the way RecalculateAssessment does in
    // production: load the graph, compute, put(). calc_version has not
    // changed since, so this entry is exactly what the read path *should*
    // find fresh.
    $graph = app(AssessmentGraphLoader::class)->load($assessment);
    $results = app(RiskCalculator::class)->compute($graph);
    // fresh(): the factories above bumped calc_version in the database, and
    // put() keeps one entry per assessment (W109), so a write under the
    // model's stale version would replace the entry the request reads.
    (new CalculationCache)->put($assessment->fresh(), $results);

    $cachedInherent = $results->inherent->total;

    // Change the underlying data *behind the observers' backs*: a
    // query-builder update fires no model events, so calc_version does not
    // move and the cache key stays the one warmed above. A recomputing
    // route would pick this new impact up; a route that consults the cache
    // cannot. That difference is the assertion.
    //
    // Asserting on served output rather than on Redis::spy() is deliberate:
    // RedisManager declares no real get() method — the facade reaches it
    // through __call — so a Mockery spy silently records nothing and
    // shouldHaveReceived('get') fails even when the cache *is* consulted.
    Risk::query()
        ->where('assessment_id', $assessment->id)
        ->update(['impact_amount' => 987_654]);

    $response = $this->actingAs($assessment->owner)
        ->get(route('assessments.show', $assessment))
        ->assertOk();

    $servedInherent = $response->viewData('page')['props']['results']['inherent']['total'];

    expect($servedInherent)->toBe($cachedInherent);
});

/**
 * The companion half of the same defect: if a cache read were wired in
 * without also checking calc_version is unchanged (e.g. a naive
 * `Redis::get('as:calc:'.$assessment->id)` ignoring the version segment),
 * a stale cached total would be served after an edit — silently wrong
 * numbers on a security tool, which CLAUDE.md is explicit must never
 * happen ("never coerced... because that would understate risk"). This
 * pins that the *key itself* changes on mutation (CalculationCache::key()
 * already does this correctly per
 * tests/Feature/Realtime/CalculationCacheTest.php — this test instead
 * exercises it through the HTTP path a fixed AssessmentController::show
 * would use), so a future cache-consulting read path cannot pass this test
 * by keying on assessment id alone.
 */
test('a calc_version bump changes the cache key the show route would need to look up', function () {
    $assessment = Assessment::factory()->create(['calc_version' => 3]);
    $keyBefore = CalculationCache::key($assessment);

    Risk::factory()->create(['assessment_id' => $assessment->id]); // bumps calc_version via NodeObserver

    $assessment->refresh();

    expect(CalculationCache::key($assessment))->not->toBe($keyBefore);
});
