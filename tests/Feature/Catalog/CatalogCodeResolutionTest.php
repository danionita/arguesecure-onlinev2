<?php

use App\Domain\Catalog\CatalogInserter;
use App\Domain\Catalog\CatalogInsertionException;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\AttackDefence;
use App\Models\CatalogEntry;
use App\Models\Defence;
use App\Models\Node;
use App\Models\Risk;
use App\Models\Scale;
use App\Models\ScaleLevel;

/*
 * The single most important correctness property in this package
 * (CLAUDE.md's W20 brief): a catalogue entry's `defaults` stores scale
 * level *codes*, never ids (schema.md §3.14), so the same entry must
 * insert correctly into any assessment regardless of that assessment's
 * own calibration. This file proves it end to end — the same catalogue
 * entry, inserted into two assessments whose 'moderate' impact band means
 * two completely different numbers, resolves to each assessment's own
 * level id, never the other's and never a coerced default.
 */

/**
 * Builds an assessment whose impact scale is a single custom band with the
 * given code and representative value — deliberately not a realistic
 * multi-band calibration (ScaleValidator's §3.2 invariants are enforced on
 * save by a service this package does not own and does not call here, so
 * nothing requires it), just enough for the code to resolve to a distinct,
 * assertable level id.
 *
 * @return array{0: Assessment, 1: ScaleLevel}
 */
function assessmentWithImpactLevel(string $code, float $representative): array
{
    $assessment = Assessment::factory()->create();

    $scale = Scale::factory()->forAssessment($assessment->id)->create([
        'kind' => 'impact',
        'unit' => 'currency',
    ]);

    $level = ScaleLevel::factory()->create([
        'scale_id' => $scale->id,
        'ordinal' => 1,
        'code' => $code,
        'label' => ucfirst($code),
        'lower_bound' => 0,
        'upper_bound' => null,
        'representative' => $representative,
    ]);

    $assessment->update(['impact_scale_id' => $scale->id]);

    return [$assessment->fresh(), $level];
}

/** @return array{0: Assessment, 1: ScaleLevel} */
function assessmentWithStrengthLevel(string $code, float $representative): array
{
    $assessment = Assessment::factory()->create();

    $scale = Scale::factory()->forAssessment($assessment->id)->create([
        'kind' => 'strength',
        'unit' => 'fraction',
    ]);

    $level = ScaleLevel::factory()->create([
        'scale_id' => $scale->id,
        'ordinal' => 1,
        'code' => $code,
        'label' => ucfirst($code),
        'lower_bound' => 0,
        'upper_bound' => 1,
        'representative' => $representative,
    ]);

    $assessment->update(['strength_scale_id' => $scale->id]);

    return [$assessment->fresh(), $level];
}

test('the same catalog entry resolves a level code to each target assessment\'s own scale', function () {
    $entry = CatalogEntry::factory()->create([
        'kind' => 'risk',
        'title' => 'Data breach',
        'defaults' => ['impact_mode' => 'qualitative', 'impact_level_code' => 'moderate'],
    ]);

    [$assessmentA, $levelA] = assessmentWithImpactLevel('moderate', 50_000);
    [$assessmentB, $levelB] = assessmentWithImpactLevel('moderate', 9_000_000);

    // The fixture itself must exercise the property under test: two
    // distinct scales, two distinct level ids for the "same" code.
    expect($levelA->id)->not->toBe($levelB->id);

    $inserter = app(CatalogInserter::class);

    $nodeA = $inserter->insert($entry, $assessmentA)['root'];
    $nodeB = $inserter->insert($entry, $assessmentB)['root'];

    expect($nodeA)->toBeInstanceOf(Risk::class)
        ->and($nodeA->impact_mode)->toBe('qualitative')
        ->and($nodeA->impact_level_id)->toBe($levelA->id)
        ->and($nodeB->impact_level_id)->toBe($levelB->id)
        ->and($nodeA->impact_level_id)->not->toBe($nodeB->impact_level_id);
});

test('an unresolvable level code fails loudly instead of falling back to null', function () {
    $entry = CatalogEntry::factory()->create([
        'kind' => 'risk',
        'defaults' => ['impact_mode' => 'qualitative', 'impact_level_code' => 'catastrophic'],
    ]);

    // Assessment::factory() seeds the system impact scale (schema.md §3.2:
    // negligible/minor/moderate/major/severe) — 'catastrophic' is not one
    // of those codes.
    $assessment = Assessment::factory()->create();

    $inserter = app(CatalogInserter::class);

    expect(fn () => $inserter->insert($entry, $assessment))
        ->toThrow(CatalogInsertionException::class);

    // The whole insert rolled back — no half-created node left behind.
    expect(Node::withoutGlobalScopes()->where('assessment_id', $assessment->id)->where('type', 'risk')->count())
        ->toBe(0);
});

test('inserting a bundle creates its pre-linked children with strength resolved against the target scale', function () {
    [$assessment, $strengthLevel] = assessmentWithStrengthLevel('strong', 0.9);
    $risk = Risk::factory()->for($assessment)->create();

    $attackEntry = CatalogEntry::factory()->create(['kind' => 'attack', 'title' => 'Phishing']);
    $defenceEntry = CatalogEntry::factory()->create(['kind' => 'defence', 'title' => 'MFA']);

    $attackEntry->catalogChildren()->attach($defenceEntry->id, [
        'defaults' => json_encode(['strength_level_code' => 'strong']),
    ]);

    $inserter = app(CatalogInserter::class);
    $result = $inserter->insert($attackEntry, $assessment, $risk);

    expect($result['nodes'])->toHaveCount(2)
        ->and($result['riskAttackLinks'])->toHaveCount(1)
        ->and($result['attackDefenceLinks'])->toHaveCount(1);

    $attackNode = $result['nodes'][0];
    $defenceNode = $result['nodes'][1];

    expect($attackNode)->toBeInstanceOf(Attack::class)
        ->and($defenceNode)->toBeInstanceOf(Defence::class);

    /** @var AttackDefence $link */
    $link = $result['attackDefenceLinks'][0];

    expect($link->attack_node_id)->toBe($attackNode->id)
        ->and($link->defence_node_id)->toBe($defenceNode->id)
        ->and($link->strength_mode)->toBe('qualitative')
        ->and($link->strength_level_id)->toBe($strengthLevel->id);
});

test('a bundle child with an unresolvable strength code fails loudly and rolls back the whole insert', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();

    $attackEntry = CatalogEntry::factory()->create(['kind' => 'attack', 'title' => 'Phishing']);
    $defenceEntry = CatalogEntry::factory()->create(['kind' => 'defence', 'title' => 'MFA']);

    $attackEntry->catalogChildren()->attach($defenceEntry->id, [
        'defaults' => json_encode(['strength_level_code' => 'nonexistent_code']),
    ]);

    $inserter = app(CatalogInserter::class);

    expect(fn () => $inserter->insert($attackEntry, $assessment, $risk))
        ->toThrow(CatalogInsertionException::class);

    expect(Node::withoutGlobalScopes()->where('assessment_id', $assessment->id)->where('type', 'attack')->count())
        ->toBe(0);
});

test('an attack entry cannot be inserted without a parent risk', function () {
    $assessment = Assessment::factory()->create();
    $entry = CatalogEntry::factory()->create(['kind' => 'attack']);

    $inserter = app(CatalogInserter::class);

    expect(fn () => $inserter->insert($entry, $assessment))
        ->toThrow(CatalogInsertionException::class);
});

test('a defence entry cannot be attached under a risk', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();
    $entry = CatalogEntry::factory()->create(['kind' => 'defence']);

    $inserter = app(CatalogInserter::class);

    expect(fn () => $inserter->insert($entry, $assessment, $risk))
        ->toThrow(CatalogInsertionException::class);
});

test('the catalog insert HTTP route resolves the code against the target assessment via the real request path', function () {
    $entry = CatalogEntry::factory()->create([
        'kind' => 'risk',
        'defaults' => ['impact_mode' => 'qualitative', 'impact_level_code' => 'moderate'],
    ]);

    [$assessment, $level] = assessmentWithImpactLevel('moderate', 4_200_000);

    $response = $this->actingAs($assessment->owner)
        ->postJson(route('assessments.catalog-entries.insert', ['assessment' => $assessment, 'catalogEntry' => $entry]))
        ->assertCreated();

    $uuid = $response->json('nodes.0.uuid');
    $node = Node::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();

    expect($node->impact_level_id)->toBe($level->id);
});
