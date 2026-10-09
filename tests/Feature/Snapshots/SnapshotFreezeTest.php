<?php

use App\Domain\Calculation\RiskCalculator;
use App\Domain\Graph\AssessmentGraphLoader;
use App\Domain\Snapshots\SnapshotGraphCodec;
use App\Domain\Snapshots\SnapshotService;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Risk;
use App\Models\ScaleLevel;
use App\Models\Snapshot;
use Illuminate\Testing\Fluent\AssertableJson;

/*
 * The single most important property of a snapshot (CLAUDE.md's W19 brief):
 * "the scales must be inlined into the snapshot. If a snapshot referenced
 * scale_levels by id, an admin recalibrating a scale next year would
 * retroactively rewrite the numbers in a report that was already signed
 * off and sent to a client." This file proves it end to end: take a
 * snapshot, mutate the underlying scale level, and assert the snapshot's
 * stored figures — both the frozen `results` and the inlined `graph` —
 * are byte-for-byte unchanged, while a fresh live computation over the
 * same (now-mutated) assessment produces a different number.
 */
function approx(float $expected, float $delta = 1e-6): Closure
{
    return fn ($actual): bool => is_numeric($actual) && abs(((float) $actual) - $expected) < $delta;
}

test("mutating a scale level after the fact never changes an already-taken snapshot's figures", function () {
    $assessment = Assessment::factory()->create(['currency' => 'EUR']);

    /** @var ScaleLevel $impactLevel */
    $impactLevel = $assessment->impactScale->levels()->where('code', 'moderate')->firstOrFail();
    $originalRepresentative = $impactLevel->representative;

    $risk = Risk::factory()->qualitative($impactLevel->id)->create(['assessment_id' => $assessment->id]);
    $attack = Attack::factory()->create([
        'assessment_id' => $assessment->id,
        'likelihood_mode' => 'quantitative',
        'likelihood_aro' => 1.0,
    ]);
    $risk->attacks()->attach($attack->id, ['position' => 0]);

    $snapshot = app(SnapshotService::class)->create($assessment->fresh(), 'Before recalibration', null, $assessment->owner_user_id);

    $frozenCurrentTotal = (float) $snapshot->results['current']['total'];
    expect($frozenCurrentTotal)->toEqual($originalRepresentative);

    // Recalibrate the scale — a real admin action, unrelated to this snapshot.
    $impactLevel->update(['representative' => $originalRepresentative * 100]);

    // Prove the engine really would produce a different number today: a
    // fresh load + compute over the (now-mutated) live assessment.
    $liveGraph = app(AssessmentGraphLoader::class)->load($assessment->fresh());
    $liveResults = app(RiskCalculator::class)->compute($liveGraph);
    expect($liveResults->current->total)->not->toEqual($frozenCurrentTotal);
    expect($liveResults->current->total)->toEqual($originalRepresentative * 100);

    // But the snapshot, re-fetched fresh from the database, is untouched.
    $reloaded = Snapshot::query()->findOrFail($snapshot->id);
    expect((float) $reloaded->results['current']['total'])->toEqual($frozenCurrentTotal);
    expect((float) $reloaded->results['inherent']['total'])->toEqual($originalRepresentative);
    expect((float) $reloaded->results['target']['total'])->toEqual($originalRepresentative);

    // And the inlined scale level inside the frozen graph itself still
    // carries the old representative — the property that makes this a
    // freeze rather than a reference.
    $decodedGraph = app(SnapshotGraphCodec::class)->decode($reloaded->graph);
    $inlinedLevel = collect($decodedGraph['scaleLevels'])->firstWhere('id', $impactLevel->id);
    expect($inlinedLevel)->not->toBeNull();
    expect((float) $inlinedLevel['representative'])->toEqual($originalRepresentative);
});

test('creating a snapshot never recomputes — it stores exactly what RiskCalculator produced at that moment', function () {
    $assessment = Assessment::factory()->create(['currency' => 'EUR']);
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'impact_mode' => 'quantitative', 'impact_amount' => 200000, 'impact_level_id' => null]);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id, 'likelihood_mode' => 'quantitative', 'likelihood_aro' => 0.5, 'likelihood_level_id' => null]);
    $risk->attacks()->attach($attack->id, ['position' => 0]);

    $graph = app(AssessmentGraphLoader::class)->load($assessment->fresh());
    $expected = app(RiskCalculator::class)->compute($graph);

    $snapshot = app(SnapshotService::class)->create($assessment->fresh(), 'Baseline', null, $assessment->owner_user_id);

    expect(approx($expected->inherent->total)($snapshot->results['inherent']['total']))->toBeTrue();
    expect(approx($expected->current->total)($snapshot->results['current']['total']))->toBeTrue();
    expect(approx($expected->target->total)($snapshot->results['target']['total']))->toBeTrue();
});

test('the frozen results carry the completeness block, so a partial assessment is never a bare number even in a snapshot', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'impact_mode' => 'quantitative', 'impact_amount' => 100000]);
    $attackValued = Attack::factory()->create(['assessment_id' => $assessment->id, 'likelihood_mode' => 'quantitative', 'likelihood_aro' => 1.0]);
    $attackMissing = Attack::factory()->withoutValue()->create(['assessment_id' => $assessment->id]);
    $risk->attacks()->attach($attackValued->id, ['position' => 0]);
    $risk->attacks()->attach($attackMissing->id, ['position' => 1]);

    $snapshot = app(SnapshotService::class)->create($assessment->fresh(), 'Partial', null, $assessment->owner_user_id);

    expect($snapshot->results['current']['completeness']['isPartial'])->toBeTrue();
    expect($snapshot->results['current']['completeness']['pairsValued'])->toBe(1);
    expect($snapshot->results['current']['completeness']['pairsTotal'])->toBe(2);
});

/*
 * The tests above prove the stored columns are frozen. This one proves the
 * page actually serves them. The realistic future regression is not someone
 * deleting the freeze — it is someone "helpfully" recomputing on read to pick
 * up a fix, which would silently reintroduce exactly the retroactive rewrite
 * the freeze exists to prevent, while every assertion about the stored
 * columns kept passing.
 */
test('the snapshot page serves the frozen figures, not a fresh computation', function () {
    $assessment = Assessment::factory()->create(['currency' => 'EUR']);

    /** @var ScaleLevel $impactLevel */
    $impactLevel = $assessment->impactScale->levels()->where('code', 'moderate')->firstOrFail();
    $originalRepresentative = (float) $impactLevel->representative;

    $risk = Risk::factory()->qualitative($impactLevel->id)->create(['assessment_id' => $assessment->id]);
    $attack = Attack::factory()->create([
        'assessment_id' => $assessment->id,
        'likelihood_mode' => 'quantitative',
        'likelihood_aro' => 1.0,
    ]);
    $risk->attacks()->attach($attack->id, ['position' => 0]);

    $snapshot = app(SnapshotService::class)->create($assessment->fresh(), 'Signed off', null, $assessment->owner_user_id);

    $impactLevel->update(['representative' => $originalRepresentative * 100]);

    $this->actingAs($assessment->owner)
        ->get(route('assessments.snapshots.show', ['assessment' => $assessment, 'snapshot' => $snapshot]))
        ->assertOk()
        ->assertInertia(
            fn (AssertableJson $page) => $page
                ->where('snapshot.results.current.total', fn ($total) => approx($originalRepresentative)($total))
                ->etc()
        );
});

test('the canonical graph keys nodes by uuid and inlines both pivots', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
    $risk->attacks()->attach($attack->id, ['position' => 0]);

    $snapshot = app(SnapshotService::class)->create($assessment->fresh(), 'Structure check', null, $assessment->owner_user_id);
    $graph = app(SnapshotGraphCodec::class)->decode($snapshot->graph);

    expect($graph['nodes'])->toHaveKey($risk->uuid);
    expect($graph['nodes'])->toHaveKey($attack->uuid);
    expect($graph['nodes'][$risk->uuid]['type'])->toBe('risk');
    expect($graph['riskAttackLinks'])->toHaveCount(1);
    expect($graph['riskAttackLinks'][0]['risk_uuid'])->toBe($risk->uuid);
    expect($graph['riskAttackLinks'][0]['attack_uuid'])->toBe($attack->uuid);
});
