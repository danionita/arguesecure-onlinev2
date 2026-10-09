<?php

use App\Domain\Calculation\RiskCalculator;
use App\Domain\Graph\AssessmentGraph;
use Tests\Unit\Calculation\Support\WorkedExampleGraph;

// §15 item 1: the §14 worked example reproduces exactly: inherent €187,000,
// current €65,500, target €36,700. Independently re-derived by hand in the
// W7 report — these totals match the document exactly.
test('the §14 worked example totals reproduce exactly', function () {
    $results = (new RiskCalculator)->compute(WorkedExampleGraph::build());

    expect($results->inherent->total)->toEqualWithDelta(187000.0, 1e-6);
    expect($results->current->total)->toEqualWithDelta(65500.0, 1e-6);
    expect($results->target->total)->toEqualWithDelta(36700.0, 1e-6);
});

// §15 item 2: risk-view and attack-view subtotals both reconcile to the
// total, for the current baseline (§14 "Reconciliation").
test('risk-view and attack-view subtotals reconcile to the total', function () {
    $results = (new RiskCalculator)->compute(WorkedExampleGraph::build());
    $current = $results->current;

    $riskSum = array_sum(array_map(fn ($r) => $r->ale, $current->risks));
    $attackSum = array_sum(array_map(fn ($a) => $a->ale, $current->attacks));

    expect($riskSum)->toEqualWithDelta($current->total, 1e-6);
    expect($attackSum)->toEqualWithDelta($current->total, 1e-6);

    $byUuid = fn (array $rows, string $uuid) => collect($rows)->first(fn ($r) => $r->uuid === $uuid);

    expect($byUuid($current->risks, 'r1')->ale)->toEqualWithDelta(22000.0, 1e-6);
    expect($byUuid($current->risks, 'r2')->ale)->toEqualWithDelta(43500.0, 1e-6);
    expect($byUuid($current->attacks, 'a1')->ale)->toEqualWithDelta(11500.0, 1e-6);
    expect($byUuid($current->attacks, 'a2')->ale)->toEqualWithDelta(12000.0, 1e-6);
    expect($byUuid($current->attacks, 'a3')->ale)->toEqualWithDelta(42000.0, 1e-6);
});

test('MC(D1, current) and MC(D2, current) match §14', function () {
    $results = (new RiskCalculator)->compute(WorkedExampleGraph::build());
    $defences = collect($results->current->defences)->keyBy('uuid');

    expect($defences['d1']->mc)->toEqualWithDelta(46000.0, 1e-6);
    expect($defences['d2']->mc)->toEqualWithDelta(29500.0, 1e-6);
});

test('MCadd(D3, current) matches §14', function () {
    $results = (new RiskCalculator)->compute(WorkedExampleGraph::build());
    $defences = collect($results->current->defences)->keyBy('uuid');

    expect($defences['d3']->mcAdd)->toEqualWithDelta(28800.0, 1e-6);
});

// §14 shelved-on-demand: MCadd(D4) evaluated against current, even though
// D4 is shelved and never enters any of the three baseline totals.
test('MCadd(D4, current) matches §14 even though D4 is shelved', function () {
    $results = (new RiskCalculator)->compute(WorkedExampleGraph::build());
    $defences = collect($results->current->defences)->keyBy('uuid');

    expect($defences['d4']->mcAdd)->toEqualWithDelta(4600.0, 1e-6);
    expect($defences['d4']->mc)->toBeNull();
});

// §15 item 10: a shelved defence never affects any of the three totals —
// D4 is shelved throughout and inherent/current/target above already
// prove this (they reconcile to the document's totals without D4 folded
// in). This test makes the claim explicit by comparing to a graph with D4
// removed entirely.
test('a shelved defence never affects any baseline total', function () {
    $withD4 = (new RiskCalculator)->compute(WorkedExampleGraph::build());

    $g = WorkedExampleGraph::build();
    $withoutD4 = new AssessmentGraph(
        assessmentUuid: $g->assessmentUuid,
        currency: $g->currency,
        amortizationYears: $g->amortizationYears,
        rootTitle: $g->rootTitle,
        rootDescription: $g->rootDescription,
        risks: $g->risks,
        attacks: $g->attacks,
        defences: collect($g->defences)->except('d4')->all(),
        riskAttackLinks: $g->riskAttackLinks,
        attackDefenceLinks: collect($g->attackDefenceLinks)->reject(fn ($l) => $l['defence_uuid'] === 'd4')->values()->all(),
        scaleLevels: $g->scaleLevels,
    );
    $without = (new RiskCalculator)->compute($withoutD4);

    expect($withD4->inherent->total)->toEqualWithDelta($without->inherent->total, 1e-9);
    expect($withD4->current->total)->toEqualWithDelta($without->current->total, 1e-9);
    expect($withD4->target->total)->toEqualWithDelta($without->target->total, 1e-9);
});

// §15 item 4: log-share attribution sums exactly to Total(inherent) -
// Total(current). This holds regardless of the disagreement with §14's
// printed per-defence split (see the W7 report).
test('log-share attribution sums exactly to the inherent-to-current reduction', function () {
    $results = (new RiskCalculator)->compute(WorkedExampleGraph::build());
    $defences = collect($results->current->defences)->keyBy('uuid');

    $sum = ($defences['d1']->attribution ?? 0.0) + ($defences['d2']->attribution ?? 0.0);
    $expectedReduction = $results->inherent->total - $results->current->total;

    expect($sum)->toEqualWithDelta($expectedReduction, 1e-6);
    expect($expectedReduction)->toEqualWithDelta(121500.0, 1e-6);
});

// Independently re-derived by hand (W7 report): the precise, unrounded
// log-share split is Attr(D1) ~= 72,343.40 and Attr(D2) ~= 49,156.60 — not
// the document's printed 72,347 / 49,153. The document rounds each pair's
// 0.6990/0.3010 share to 4 decimals *before* multiplying and summing,
// which both loses precision and, by the document's own component sums
// (27,090 + 4,064 + 18,000 = 49,154, not 49,153), does not even reproduce
// its own printed total. §11.3 forbids rounding intermediates for exactly
// this reason. This is flagged as a document error, not tuned to match it.
test('log-share attribution: precise values differ from §14\'s rounded printed figures', function () {
    $results = (new RiskCalculator)->compute(WorkedExampleGraph::build());
    $defences = collect($results->current->defences)->keyBy('uuid');

    expect($defences['d1']->attribution)->toEqualWithDelta(72343.395448778, 1e-3);
    expect($defences['d2']->attribution)->toEqualWithDelta(49156.604551222, 1e-3);
});

test('the §14 partial-data variant: blanking A2 likelihood drops current to €53,500 at 75% coverage', function () {
    $results = (new RiskCalculator)->compute(WorkedExampleGraph::buildWithoutA2Likelihood());

    expect($results->current->total)->toEqualWithDelta(53500.0, 1e-6);
    expect($results->current->completeness->pairsValued)->toBe(3);
    expect($results->current->completeness->pairsTotal)->toBe(4);
    expect($results->current->completeness->coverage)->toEqualWithDelta(0.75, 1e-9);
    expect($results->current->completeness->attacksMissingLikelihood)->toBe(['a2']);
});
