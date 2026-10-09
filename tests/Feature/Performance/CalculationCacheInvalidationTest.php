<?php

use App\Domain\Calculation\CalculationCache;
use App\Domain\Structure\LinkService;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\AttackDefence;
use App\Models\Defence;
use App\Models\Risk;
use App\Models\ScaleLevel;

/*
 * The cache is only safe because its key contains `calc_version`: a stale
 * entry is never looked up rather than being served. That makes the read
 * path's correctness depend entirely on *every* mutation that can change a
 * computed figure bumping that column — and a missed bump is silent, showing
 * a user numbers that may already have gone into a client report.
 *
 * So this walks each mutation type and asserts the key actually moves,
 * rather than trusting the observer registrations by inspection. Writing it
 * found the real gap: scale recalibration changed every figure and bumped
 * nothing, because TriggersRecalculation's own docblock had noted scales as
 * "territory this package does not own a model for". ScaleLevelObserver was
 * added to close it, and the last test here is what holds it closed.
 */
function keyFor(Assessment $assessment): string
{
    return CalculationCache::key($assessment->fresh());
}

test('creating a node changes the cache key', function () {
    $assessment = Assessment::factory()->create();
    $before = keyFor($assessment);

    Risk::factory()->for($assessment)->create();

    expect(keyFor($assessment))->not->toBe($before);
});

test('editing a value on a node changes the cache key', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create(['impact_amount' => 1000]);
    $before = keyFor($assessment);

    $risk->update(['impact_amount' => 50_000]);

    expect(keyFor($assessment))->not->toBe($before);
});

test('deleting a node changes the cache key', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();
    $before = keyFor($assessment);

    $risk->delete();

    expect(keyFor($assessment))->not->toBe($before);
});

test('linking and unlinking a risk to an attack both change the cache key', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();
    $attack = Attack::factory()->for($assessment)->create();
    $links = app(LinkService::class);

    $beforeLink = keyFor($assessment);
    $links->linkRiskToAttack($risk, $attack);
    $afterLink = keyFor($assessment);

    expect($afterLink)->not->toBe($beforeLink);

    $links->unlinkRiskFromAttack($risk, $attack);

    expect(keyFor($assessment))->not->toBe($afterLink);
});

test('changing a defence strength on the link pivot changes the cache key', function () {
    $assessment = Assessment::factory()->create();
    $attack = Attack::factory()->for($assessment)->create();
    $defence = Defence::factory()->for($assessment)->create();
    app(LinkService::class)->linkAttackToDefence($attack, $defence);

    $before = keyFor($assessment);

    // Strength lives on the pivot, not the defence (schema.md): a change
    // here alters the residual figure without touching any node row.
    AttackDefence::query()
        ->where('attack_node_id', $attack->id)
        ->where('defence_node_id', $defence->id)
        ->firstOrFail()
        ->update(['strength_fraction' => 0.75]);

    expect(keyFor($assessment))->not->toBe($before);
});

test('recalibrating a scale level changes the cache key of every assessment using that scale', function () {
    $assessment = Assessment::factory()->create();
    $other = Assessment::factory()->create();

    $before = keyFor($assessment);
    $otherBefore = keyFor($other);

    // The gap this test exists for. `representative` is the number every
    // qualitative value resolves to, so editing it rewrites the computed
    // result of every assessment calibrated against this scale — while
    // touching no node, no edge, and (before ScaleLevelObserver) no
    // calc_version.
    $level = ScaleLevel::query()
        ->where('scale_id', $assessment->impact_scale_id)
        ->firstOrFail();
    $level->update(['representative' => (float) $level->representative + 1234.0]);

    expect(keyFor($assessment))->not->toBe($before);

    // Both assessments here use the same system scale, so the bump must
    // reach the one that was never touched directly.
    if ($other->impact_scale_id === $assessment->impact_scale_id) {
        expect(keyFor($other))->not->toBe($otherBefore);
    }
});
