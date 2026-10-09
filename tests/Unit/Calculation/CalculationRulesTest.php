<?php

use App\Domain\Calculation\RiskCalculator;
use App\Domain\Graph\AssessmentGraph;

/**
 * A minimal one-risk/one-attack/one-defence graph, parameterised on the
 * defence's status/strength/reduction_target and the risk/attack values,
 * for the §15 checklist items that don't need the full §14 fixture.
 */
function minimalGraph(
    ?float $impactAmount = 100.0,
    ?float $aro = 2.0,
    string $defenceStatus = 'implemented',
    ?float $strengthFraction = 0.5,
    ?string $reductionTarget = null,
    int $defencePosition = 0,
): AssessmentGraph {
    return new AssessmentGraph(
        assessmentUuid: 'a',
        currency: 'EUR',
        amortizationYears: 3,
        rootTitle: null,
        rootDescription: null,
        risks: [
            'r' => ['uuid' => 'r', 'title' => 'R', 'impact_mode' => $impactAmount === null ? null : 'quantitative', 'impact_amount' => $impactAmount, 'impact_level_id' => null],
        ],
        attacks: [
            'a' => ['uuid' => 'a', 'title' => 'A', 'likelihood_mode' => $aro === null ? null : 'quantitative', 'likelihood_aro' => $aro, 'likelihood_level_id' => null],
        ],
        defences: [
            'd' => ['uuid' => 'd', 'title' => 'D', 'is_transfer' => false, 'status' => $defenceStatus, 'capex_amount' => null, 'opex_annual_amount' => 1000.0],
        ],
        riskAttackLinks: [
            ['risk_uuid' => 'r', 'attack_uuid' => 'a', 'position' => 0],
        ],
        attackDefenceLinks: [
            ['attack_uuid' => 'a', 'defence_uuid' => 'd', 'position' => $defencePosition, 'strength_mode' => $strengthFraction === null ? null : 'quantitative', 'strength_fraction' => $strengthFraction, 'strength_level_id' => null, 'reduction_target' => $reductionTarget],
        ],
        scaleLevels: minimalScaleLevels(),
    );
}

/**
 * A single open-ended band per kind, just enough that band-mapping
 * (RiskCalculator step 8) has somewhere to land — these tests are about
 * the arithmetic, not the qualitative mapping.
 *
 * @return array<int, array{id: int, scale_id: int, kind: string, unit: string, code: string, label: string, ordinal: int, lower_bound: float, upper_bound: ?float, representative: float}>
 */
function minimalScaleLevels(): array
{
    return [
        100 => ['id' => 100, 'scale_id' => 1, 'kind' => 'impact', 'unit' => 'currency', 'code' => 'any', 'label' => 'Any', 'ordinal' => 1, 'lower_bound' => 0.0, 'upper_bound' => null, 'representative' => 0.0],
        200 => ['id' => 200, 'scale_id' => 2, 'kind' => 'likelihood', 'unit' => 'rate_per_year', 'code' => 'any', 'label' => 'Any', 'ordinal' => 1, 'lower_bound' => 0.0, 'upper_bound' => null, 'representative' => 0.0],
        300 => ['id' => 300, 'scale_id' => 3, 'kind' => 'strength', 'unit' => 'fraction', 'code' => 'any', 'label' => 'Any', 'ordinal' => 1, 'lower_bound' => 0.0, 'upper_bound' => 1.0, 'representative' => 0.0],
    ];
}

// §15 item 6: blanking a risk's impact drops exactly its pairs from the
// total and raises the correct completeness flags — never silently zero.
test('a missing impact excludes the pair from the total, not coerces it to zero', function () {
    $withImpact = (new RiskCalculator)->compute(minimalGraph(impactAmount: 100.0));
    $withoutImpact = (new RiskCalculator)->compute(minimalGraph(impactAmount: null));

    expect($withImpact->current->total)->toBeGreaterThan(0.0);
    expect($withoutImpact->current->total)->toBe(0.0);
    expect($withoutImpact->current->completeness->pairsValued)->toBe(0);
    expect($withoutImpact->current->completeness->pairsTotal)->toBe(1);
    expect($withoutImpact->current->completeness->risksMissingImpact)->toBe(['r']);
    expect($withoutImpact->current->pairs[0]->valued)->toBeFalse();
    expect($withoutImpact->current->pairs[0]->ale)->toBeNull();
});

// §15 item 7: blanking a link's strength leaves the pair in the total with
// s = 0 (no reduction) and flags links_missing_strength — it does not drop
// the pair the way a missing impact/likelihood does.
test('a missing link strength coerces to s=0 and keeps the pair in the total', function () {
    $withStrength = (new RiskCalculator)->compute(minimalGraph(strengthFraction: 0.5));
    $withoutStrength = (new RiskCalculator)->compute(minimalGraph(strengthFraction: null));

    // ARO=2, Imp=100 => inherent ALE = 200. s=0.5 => current ALE = 100.
    // Missing strength coerces to s=0 => current ALE = 200 (same as inherent).
    expect($withStrength->current->total)->toEqualWithDelta(100.0, 1e-9);
    expect($withoutStrength->current->total)->toEqualWithDelta(200.0, 1e-9);
    expect($withoutStrength->current->completeness->linksMissingStrength)->toBe([
        ['attack_uuid' => 'a', 'defence_uuid' => 'd'],
    ]);
    expect($withoutStrength->current->pairs[0]->valued)->toBeTrue();
});

// §15 item 8: changing reduction_target changes ARO'/Imp' but leaves the
// total bit-identical, because the total is computed from the single
// factor(a,S) product and never from the L/I/C sub-products.
test('changing reduction_target leaves the total bit-identical', function () {
    $likelihoodTarget = (new RiskCalculator)->compute(minimalGraph(reductionTarget: 'likelihood'));
    $impactTarget = (new RiskCalculator)->compute(minimalGraph(reductionTarget: 'impact'));
    $combined = (new RiskCalculator)->compute(minimalGraph(reductionTarget: null));

    expect($likelihoodTarget->current->total)->toBe($impactTarget->current->total);
    expect($likelihoodTarget->current->total)->toBe($combined->current->total);

    // But the displayed residuals genuinely differ by target (§5.1 point 1).
    expect($likelihoodTarget->current->pairs[0]->aroResidual)->not->toBe($impactTarget->current->pairs[0]->aroResidual);
    expect($likelihoodTarget->current->pairs[0]->impactResidual)->not->toBe($impactTarget->current->pairs[0]->impactResidual);
});

// §15 item 9: reordering defences on an attack leaves every number
// identical — position is display ordering only, not part of the formula.
test('reordering defences on an attack leaves every number identical', function () {
    $graph = new AssessmentGraph(
        assessmentUuid: 'a',
        currency: 'EUR',
        amortizationYears: 3,
        rootTitle: null,
        rootDescription: null,
        risks: ['r' => ['uuid' => 'r', 'title' => 'R', 'impact_mode' => 'quantitative', 'impact_amount' => 100.0, 'impact_level_id' => null]],
        attacks: ['a' => ['uuid' => 'a', 'title' => 'A', 'likelihood_mode' => 'quantitative', 'likelihood_aro' => 2.0, 'likelihood_level_id' => null]],
        defences: [
            'd1' => ['uuid' => 'd1', 'title' => 'D1', 'is_transfer' => false, 'status' => 'implemented', 'capex_amount' => null, 'opex_annual_amount' => 1000.0],
            'd2' => ['uuid' => 'd2', 'title' => 'D2', 'is_transfer' => false, 'status' => 'implemented', 'capex_amount' => null, 'opex_annual_amount' => 1000.0],
        ],
        riskAttackLinks: [['risk_uuid' => 'r', 'attack_uuid' => 'a', 'position' => 0]],
        attackDefenceLinks: [
            ['attack_uuid' => 'a', 'defence_uuid' => 'd1', 'position' => 0, 'strength_mode' => 'quantitative', 'strength_fraction' => 0.5, 'strength_level_id' => null, 'reduction_target' => null],
            ['attack_uuid' => 'a', 'defence_uuid' => 'd2', 'position' => 1, 'strength_mode' => 'quantitative', 'strength_fraction' => 0.3, 'strength_level_id' => null, 'reduction_target' => null],
        ],
        scaleLevels: minimalScaleLevels(),
    );

    $reordered = new AssessmentGraph(
        assessmentUuid: $graph->assessmentUuid,
        currency: $graph->currency,
        amortizationYears: $graph->amortizationYears,
        rootTitle: $graph->rootTitle,
        rootDescription: $graph->rootDescription,
        risks: $graph->risks,
        attacks: $graph->attacks,
        defences: $graph->defences,
        riskAttackLinks: $graph->riskAttackLinks,
        attackDefenceLinks: [
            ['attack_uuid' => 'a', 'defence_uuid' => 'd2', 'position' => 0, 'strength_mode' => 'quantitative', 'strength_fraction' => 0.3, 'strength_level_id' => null, 'reduction_target' => null],
            ['attack_uuid' => 'a', 'defence_uuid' => 'd1', 'position' => 1, 'strength_mode' => 'quantitative', 'strength_fraction' => 0.5, 'strength_level_id' => null, 'reduction_target' => null],
        ],
        scaleLevels: $graph->scaleLevels,
    );

    $a = (new RiskCalculator)->compute($graph);
    $b = (new RiskCalculator)->compute($reordered);

    expect($a->current->total)->toEqualWithDelta($b->current->total, 1e-12);
    expect($a->current->pairs[0]->ale)->toEqualWithDelta($b->current->pairs[0]->ale, 1e-12);
});

// §15 item 11: ARO = 0 is a known value (contributes 0, not flagged
// missing); ARO = NULL is flagged and excluded.
test('ARO=0 is known and contributes 0; ARO=null is missing and excluded', function () {
    $zero = (new RiskCalculator)->compute(minimalGraph(aro: 0.0));
    $missing = (new RiskCalculator)->compute(minimalGraph(aro: null));

    expect($zero->current->total)->toBe(0.0);
    expect($zero->current->completeness->pairsValued)->toBe(1);
    expect($zero->current->completeness->attacksMissingLikelihood)->toBe([]);
    expect($zero->current->pairs[0]->valued)->toBeTrue();

    expect($missing->current->total)->toBe(0.0);
    expect($missing->current->completeness->pairsValued)->toBe(0);
    expect($missing->current->completeness->attacksMissingLikelihood)->toBe(['a']);
    expect($missing->current->pairs[0]->valued)->toBeFalse();
});

// §15 item 3: MC by factor-exclusion equals the analytic
// Σ ALE(r,a,S) × s/(1-s) identity for s < 1 ...
test('MC by factor-exclusion equals the analytic division identity for s < 1', function () {
    $results = (new RiskCalculator)->compute(minimalGraph(impactAmount: 100.0, aro: 2.0, strengthFraction: 0.8));
    $mc = collect($results->current->defences)->firstWhere('uuid', 'd')->mc;

    $aleAtS = $results->current->pairs[0]->ale; // 2 * 100 * (1-0.8) = 40
    $analytic = $aleAtS * (0.8 / (1 - 0.8));

    expect($mc)->toEqualWithDelta($analytic, 1e-9);
    expect($mc)->toEqualWithDelta(160.0, 1e-9); // 2*100*0.2*4 = 160
});

// ... and does not divide by zero (or blow up) at s = 1.
test('MC does not divide by zero at s = 1', function () {
    $results = (new RiskCalculator)->compute(minimalGraph(impactAmount: 100.0, aro: 2.0, strengthFraction: 1.0));

    $mc = collect($results->current->defences)->firstWhere('uuid', 'd')->mc;
    $aleAtS = $results->current->pairs[0]->ale;

    expect($aleAtS)->toBe(0.0); // fully blocked
    expect($mc)->toEqualWithDelta(200.0, 1e-9); // removing it restores the full 2*100
    expect(is_finite($mc))->toBeTrue();
    expect($results->current->completeness->implausibleStrength)->toBe([
        ['attack_uuid' => 'a', 'defence_uuid' => 'd'],
    ]);
});
