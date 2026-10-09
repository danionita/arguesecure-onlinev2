<?php

use App\Domain\Calculation\CalculationResult;
use App\Domain\Calculation\CalculationResults;
use App\Domain\Calculation\RiskCalculator;
use App\Domain\Calculation\ScaleMapper;
use Tests\Unit\Calculation\Support\GoldenVectorChecker;
use Tests\Unit\Calculation\Support\GoldenVectorGraphAdapter;

/**
 * W7 reconciliation (see docs/PLAN.md and the W7 task brief): runs every
 * vector in tests/fixtures/calculation-vectors.json — derived independently
 * from docs/specs/calculation-engine.md, without ever reading this engine —
 * against App\Domain\Calculation\RiskCalculator.
 *
 * THIS FILE MUST NOT BE TUNED TO MAKE THE ENGINE AND THE FIXTURE AGREE. A
 * failing vector here is the single most valuable output of the exercise:
 * it means the engine and an independent reading of the spec disagree, and
 * that disagreement needs a human to adjudicate, not a quiet edit. See the
 * W7 reconciliation report for the resolution of every failure at the time
 * this file was written.
 *
 * Two vectors don't fit the "build a graph, run RiskCalculator, check
 * `expected`" shape and are handled by dedicated tests below instead of the
 * generic loop: `qualitative_roundtrip_and_boundaries` exercises ScaleMapper
 * directly (it has no risks/attacks at all), and `reduction_target_invariance`
 * runs the *same* risk/attack/defence graph through three different
 * `reduction_target` values (a "common" graph plus three "scenarios", not a
 * single "input").
 */
function goldenVectorFixture(): array
{
    static $fixture = null;

    if ($fixture === null) {
        $path = __DIR__.'/../../fixtures/calculation-vectors.json';
        $fixture = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    return $fixture;
}

/**
 * @return array<string, array<string, mixed>>
 */
function goldenVectorsByName(): array
{
    return collect(goldenVectorFixture()['vectors'])->keyBy('name')->all();
}

/**
 * Resolves the `{"$ref_note": "..."}` shorthand some vectors use for their
 * `input.scales` block (they mean "identical to another vector's scales" —
 * see tests/fixtures/README.md and the vectors' own $ref_note text).
 *
 * @param  array<string, mixed>  $input
 * @return array<string, mixed>
 */
function resolveGoldenVectorInput(array $input): array
{
    if (isset($input['scales']['$ref_note'])) {
        $input['scales'] = goldenVectorsByName()['worked_example_section14']['input']['scales'];
    }

    return $input;
}

/**
 * Checks every field the vector's `expected.baselines.<name>` block
 * specifies against one CalculationResult. Only fields actually present in
 * the fixture are asserted — absence is not a claim either way (README).
 *
 * @param  array<string, mixed>  $expected
 */
function assertGoldenBaseline(GoldenVectorChecker $checker, string $label, array $expected, CalculationResult $result): void
{
    if (array_key_exists('total', $expected)) {
        $checker->numeric($result->total, $expected['total'], "{$label}.total");
    }

    foreach ($expected['pairs'] ?? [] as $expPair) {
        $actual = collect($result->pairs)->first(
            fn ($p) => $p->riskUuid === $expPair['risk_uuid'] && $p->attackUuid === $expPair['attack_uuid']
        );
        $pairLabel = "{$label}.pairs[{$expPair['risk_uuid']},{$expPair['attack_uuid']}]";

        if ($actual === null) {
            $checker->fail("{$pairLabel}: pair not found in engine output");

            continue;
        }

        if (array_key_exists('aro_residual', $expPair)) {
            $checker->numeric($actual->aroResidual, $expPair['aro_residual'], "{$pairLabel}.aro_residual");
        }
        if (array_key_exists('impact_residual', $expPair)) {
            $checker->numeric($actual->impactResidual, $expPair['impact_residual'], "{$pairLabel}.impact_residual");
        }
        if (array_key_exists('ale', $expPair)) {
            $checker->numeric($actual->ale, $expPair['ale'], "{$pairLabel}.ale");
        }
        if (array_key_exists('valued', $expPair)) {
            $checker->same($actual->valued, $expPair['valued'], "{$pairLabel}.valued");
        }
    }

    foreach ($expected['risks'] ?? [] as $expRisk) {
        $actual = collect($result->risks)->firstWhere('uuid', $expRisk['uuid']);
        $riskLabel = "{$label}.risks[{$expRisk['uuid']}]";

        if ($actual === null) {
            $checker->fail("{$riskLabel}: risk not found in engine output");

            continue;
        }

        if (array_key_exists('ale', $expRisk)) {
            $checker->numeric($actual->ale, $expRisk['ale'], "{$riskLabel}.ale");
        }
        if (array_key_exists('impact_value', $expRisk)) {
            $checker->numeric($actual->impactValue, $expRisk['impact_value'], "{$riskLabel}.impact_value");
        }
        if (array_key_exists('impact_level_code', $expRisk)) {
            $checker->same($actual->impactLevelCode, $expRisk['impact_level_code'], "{$riskLabel}.impact_level_code");
        }
        if (array_key_exists('annual_probability', $expRisk)) {
            $checker->numeric($actual->annualProbability, $expRisk['annual_probability'], "{$riskLabel}.annual_probability");
        }
        if (array_key_exists('pairs_valued', $expRisk)) {
            $checker->same($actual->pairsValued, $expRisk['pairs_valued'], "{$riskLabel}.pairs_valued");
        }
        if (array_key_exists('pairs_total', $expRisk)) {
            $checker->same($actual->pairsTotal, $expRisk['pairs_total'], "{$riskLabel}.pairs_total");
        }
    }

    foreach ($expected['attacks'] ?? [] as $expAttack) {
        $actual = collect($result->attacks)->firstWhere('uuid', $expAttack['uuid']);
        $attackLabel = "{$label}.attacks[{$expAttack['uuid']}]";

        if ($actual === null) {
            $checker->fail("{$attackLabel}: attack not found in engine output");

            continue;
        }

        if (array_key_exists('ale', $expAttack)) {
            $checker->numeric($actual->ale, $expAttack['ale'], "{$attackLabel}.ale");
        }
        if (array_key_exists('factor', $expAttack)) {
            $checker->numeric($actual->factor, $expAttack['factor'], "{$attackLabel}.factor");
        }
        if (array_key_exists('aro_base', $expAttack)) {
            $checker->numeric($actual->aroBase, $expAttack['aro_base'], "{$attackLabel}.aro_base");
        }
        if (array_key_exists('aro_residual', $expAttack)) {
            $checker->numeric($actual->aroResidual, $expAttack['aro_residual'], "{$attackLabel}.aro_residual");
        }
        if (array_key_exists('aro_level_code', $expAttack)) {
            $checker->same($actual->aroLevelCode, $expAttack['aro_level_code'], "{$attackLabel}.aro_level_code");
        }
        if (array_key_exists('risk_count', $expAttack)) {
            $checker->same($actual->riskCount, $expAttack['risk_count'], "{$attackLabel}.risk_count");
        }
    }

    if (isset($expected['reconciliation'])) {
        $riskSum = array_sum(array_map(fn ($r) => $r->ale, $result->risks));
        $attackSum = array_sum(array_map(fn ($a) => $a->ale, $result->attacks));

        if (array_key_exists('risk_view_sum', $expected['reconciliation'])) {
            $checker->numeric($riskSum, $expected['reconciliation']['risk_view_sum'], "{$label}.reconciliation.risk_view_sum");
        }
        if (array_key_exists('attack_view_sum', $expected['reconciliation'])) {
            $checker->numeric($attackSum, $expected['reconciliation']['attack_view_sum'], "{$label}.reconciliation.attack_view_sum");
        }
    }

    if (isset($expected['warnings'])) {
        foreach ($expected['warnings'] as $w) {
            $checker->warningPresent($result->warnings, $w, "{$label}.warnings");
        }
    }
}

/**
 * Checks `expected.completeness` against `current`'s completeness block.
 * Per the Completeness class docblock, every field except
 * `links_missing_strength`/`implausible_strength` is structural (identical
 * across all three baselines), and the vectors that DO pin down those two
 * baseline-dependent fields (`strength_one_no_division`) do so against a
 * defence that is active in `current` — so checking against `current`
 * throughout is not a baseline-blind simplification, it is the vector's own
 * intent.
 *
 * @param  array<string, mixed>  $expected
 */
function assertGoldenCompleteness(GoldenVectorChecker $checker, string $vectorName, array $expected, CalculationResult $current): void
{
    $c = $current->completeness;
    $label = "{$vectorName}.completeness";

    if (array_key_exists('total', $expected)) {
        $checker->numeric($current->total, $expected['total'], "{$label}.total (current baseline)");
    }
    if (array_key_exists('is_partial', $expected)) {
        $checker->same($c->isPartial, $expected['is_partial'], "{$label}.is_partial");
    }
    if (array_key_exists('pairs_total', $expected)) {
        $checker->same($c->pairsTotal, $expected['pairs_total'], "{$label}.pairs_total");
    }
    if (array_key_exists('pairs_valued', $expected)) {
        $checker->same($c->pairsValued, $expected['pairs_valued'], "{$label}.pairs_valued");
    }
    if (array_key_exists('coverage', $expected)) {
        $checker->numeric($c->coverage, $expected['coverage'], "{$label}.coverage");
    }
    if (array_key_exists('risks_missing_impact', $expected)) {
        $checker->set($c->risksMissingImpact, $expected['risks_missing_impact'], "{$label}.risks_missing_impact");
    }
    if (array_key_exists('attacks_missing_likelihood', $expected)) {
        $checker->set($c->attacksMissingLikelihood, $expected['attacks_missing_likelihood'], "{$label}.attacks_missing_likelihood");
    }
    if (array_key_exists('links_missing_strength', $expected)) {
        $checker->rowSet($c->linksMissingStrength, $expected['links_missing_strength'], "{$label}.links_missing_strength");
    }
    if (array_key_exists('risks_without_attacks', $expected)) {
        $checker->set($c->risksWithoutAttacks, $expected['risks_without_attacks'], "{$label}.risks_without_attacks");
    }
    if (array_key_exists('orphan_attacks', $expected)) {
        $checker->set($c->orphanAttacks, $expected['orphan_attacks'], "{$label}.orphan_attacks");
    }
    if (array_key_exists('orphan_defences', $expected)) {
        $checker->set($c->orphanDefences, $expected['orphan_defences'], "{$label}.orphan_defences");
    }
    if (array_key_exists('shared_attacks', $expected)) {
        $checker->rowSet($c->sharedAttacks, $expected['shared_attacks'], "{$label}.shared_attacks");
    }
    if (array_key_exists('implausible_strength', $expected)) {
        $checker->rowSet($c->implausibleStrength, $expected['implausible_strength'], "{$label}.implausible_strength");
    }
}

/**
 * §9.1-9.3: MC (importance, active defences), MCadd (opportunity/shelved,
 * inactive defences), and the §9.4 non-additivity demonstration — all
 * against the `current` baseline, matching every vector that populates this
 * block.
 *
 * @param  array<string, mixed>  $expected
 */
function assertGoldenDefenceRankings(GoldenVectorChecker $checker, string $vectorName, array $expected, CalculationResult $current, CalculationResult $inherent): void
{
    $byUuid = collect($current->defences)->keyBy('uuid');
    $label = "{$vectorName}.defence_rankings";

    $importanceUuids = [];
    foreach ($expected['importance_against_current'] ?? [] as $row) {
        $uuid = $row['defence_uuid'];
        $importanceUuids[] = $uuid;
        $d = $byUuid[$uuid] ?? null;

        if ($d === null) {
            $checker->fail("{$label}.importance_against_current[{$uuid}]: defence not found");

            continue;
        }

        if (array_key_exists('mc', $row)) {
            $checker->numeric($d->mc, $row['mc'], "{$label}.importance_against_current[{$uuid}].mc");
        }
    }

    foreach ($expected['opportunity_against_current'] ?? [] as $row) {
        $uuid = $row['defence_uuid'];
        $d = $byUuid[$uuid] ?? null;

        if ($d === null) {
            $checker->fail("{$label}.opportunity_against_current[{$uuid}]: defence not found");

            continue;
        }

        if (array_key_exists('mc_add', $row)) {
            $checker->numeric($d->mcAdd, $row['mc_add'], "{$label}.opportunity_against_current[{$uuid}].mc_add");
        }
    }

    foreach ($expected['shelved_on_demand'] ?? [] as $row) {
        $uuid = $row['defence_uuid'];
        $d = $byUuid[$uuid] ?? null;

        if ($d === null) {
            $checker->fail("{$label}.shelved_on_demand[{$uuid}]: defence not found");

            continue;
        }

        if (array_key_exists('mc_add', $row)) {
            $checker->numeric($d->mcAdd, $row['mc_add'], "{$label}.shelved_on_demand[{$uuid}].mc_add");
        }
    }

    if (isset($expected['non_additivity_demo']) && $importanceUuids !== []) {
        $demo = $expected['non_additivity_demo'];
        $sumMc = 0.0;
        foreach ($importanceUuids as $uuid) {
            $sumMc += $byUuid[$uuid]->mc ?? 0.0;
        }

        if (array_key_exists('sum_of_mc_d1_d2', $demo)) {
            $checker->numeric($sumMc, $demo['sum_of_mc_d1_d2'], "{$label}.non_additivity_demo.sum_of_mc");
        }

        $jointReduction = $inherent->total - $current->total;
        if (array_key_exists('joint_inherent_to_current_reduction', $demo)) {
            $checker->numeric($jointReduction, $demo['joint_inherent_to_current_reduction'], "{$label}.non_additivity_demo.joint_reduction");
        }
        if (array_key_exists('understatement', $demo)) {
            $checker->numeric($jointReduction - $sumMc, $demo['understatement'], "{$label}.non_additivity_demo.understatement");
        }
    }
}

/**
 * §9.5. `Attr(d)` is only populated by the engine for defences active in the
 * chosen baseline (DefenceResult docblock) — every vector here uses
 * `baseline: "current"`.
 *
 * @param  array<string, mixed>  $expected
 */
function assertGoldenLogShareAttribution(GoldenVectorChecker $checker, string $vectorName, array $expected, CalculationResults $results): void
{
    $baselineName = $expected['baseline'] ?? 'current';
    $baseline = match ($baselineName) {
        'inherent' => $results->inherent,
        'target' => $results->target,
        default => $results->current,
    };
    $byUuid = collect($baseline->defences)->keyBy('uuid');
    $label = "{$vectorName}.log_share_attribution";

    foreach ($expected['attribution'] ?? [] as $uuid => $expVal) {
        $d = $byUuid[$uuid] ?? null;

        if ($d === null) {
            $checker->fail("{$label}.attribution[{$uuid}]: defence not found");

            continue;
        }

        $checker->numeric($d->attribution, $expVal, "{$label}.attribution[{$uuid}]");
    }

    if (array_key_exists('target_sum', $expected)) {
        $sum = 0.0;
        foreach ($baseline->defences as $d) {
            $sum += $d->attribution ?? 0.0;
        }

        $checker->numeric($sum, $expected['target_sum'], "{$label}.target_sum (sum over defences with non-null attribution)");
    }
}

/**
 * §10. RiskCalculator/DefenceRanker return one flat, unsorted list of
 * DefenceResult (each tagged with its costBucket) — the three-bucket,
 * per-bucket-sorted presentation §10 describes is reconstructed here from
 * that flat list, the way a serializer/UI layer would, rather than being a
 * distinct engine output. See the W7 report for whether that split of
 * responsibility is a finding.
 *
 * @param  array<string, mixed>  $expected
 */
function assertGoldenCostBenefit(GoldenVectorChecker $checker, string $vectorName, array $expected, CalculationResult $current): void
{
    $label = "{$vectorName}.cost_benefit";

    $buckets = ['priced' => [], 'free' => [], 'unpriced' => []];
    foreach ($current->defences as $d) {
        $buckets[$d->costBucket][] = $d;
    }

    $mcOf = fn ($d) => $d->mc ?? $d->mcAdd ?? 0.0;

    usort($buckets['priced'], fn ($a, $b) => ($b->cbr <=> $a->cbr) ?: ($b->netBenefit <=> $a->netBenefit));
    usort($buckets['free'], fn ($a, $b) => $mcOf($b) <=> $mcOf($a));
    usort($buckets['unpriced'], fn ($a, $b) => $mcOf($b) <=> $mcOf($a));

    foreach (['priced', 'free', 'unpriced'] as $bucketName) {
        if (! array_key_exists($bucketName, $expected)) {
            continue;
        }

        $expRows = $expected[$bucketName];
        $actRows = $buckets[$bucketName];

        if (count($expRows) !== count($actRows)) {
            $actUuids = implode(',', array_map(fn ($d) => $d->uuid, $actRows));
            $checker->fail("{$label}.{$bucketName}: expected ".count($expRows).' row(s), got '.count($actRows)." ([{$actUuids}])");

            continue;
        }

        foreach ($expRows as $i => $expRow) {
            $act = $actRows[$i];
            $rowLabel = "{$label}.{$bucketName}[{$i}]({$expRow['defence_uuid']})";

            $checker->same($act->uuid, $expRow['defence_uuid'], "{$rowLabel}.defence_uuid (bucket order)");

            if (array_key_exists('status', $expRow)) {
                $checker->same($act->status, $expRow['status'], "{$rowLabel}.status");
            }
            if (array_key_exists('mc_or_mc_add', $expRow)) {
                $checker->numeric($mcOf($act), $expRow['mc_or_mc_add'], "{$rowLabel}.mc_or_mc_add");
            }
            if (array_key_exists('annual_cost', $expRow)) {
                $checker->numeric($act->annualCost, $expRow['annual_cost'], "{$rowLabel}.annual_cost");
            }
            if (array_key_exists('cbr', $expRow)) {
                $checker->numeric($act->cbr, $expRow['cbr'], "{$rowLabel}.cbr");
            }
            if (array_key_exists('rosi', $expRow)) {
                $checker->numeric($act->rosi, $expRow['rosi'], "{$rowLabel}.rosi");
            }
            if (array_key_exists('net_benefit', $expRow)) {
                $checker->numeric($act->netBenefit, $expRow['net_benefit'], "{$rowLabel}.net_benefit");
            }
        }
    }
}

/**
 * `expected.on_demand` keys follow the pattern `mc_add_<defence_uuid>_against_<baseline>`
 * (e.g. `mc_add_d1_against_current`) — a defence not active in that baseline
 * gets `mcAdd` for free from the engine, exactly as for todo/shelved
 * defences elsewhere, so no extra graph is needed to answer "what did we
 * give up?" (§9.3).
 *
 * @param  array<string, mixed>  $onDemand
 */
function assertGoldenOnDemand(GoldenVectorChecker $checker, string $vectorName, array $onDemand, CalculationResults $results): void
{
    $label = "{$vectorName}.on_demand";

    foreach ($onDemand as $key => $value) {
        if (! preg_match('/^mc_add_(.+)_against_(inherent|current|target)$/', $key, $m)) {
            continue;
        }

        [, $uuid, $baselineName] = $m;
        $baseline = match ($baselineName) {
            'inherent' => $results->inherent,
            'target' => $results->target,
            default => $results->current,
        };

        // Case-insensitive: this vector's `on_demand` key names (e.g.
        // `mc_add_d1_against_current`) are lowercase while its defence
        // uuids are uppercase ("D1") — a cosmetic casing slip in the
        // fixture's informal key naming, not a value disagreement, since
        // the same row's "note" field independently states the expected
        // arithmetic in prose.
        $d = collect($baseline->defences)->first(fn ($x) => strcasecmp($x->uuid, $uuid) === 0);

        if ($d === null) {
            $checker->fail("{$label}.{$key}: defence '{$uuid}' not found");

            continue;
        }

        $checker->numeric($d->mcAdd, $value, "{$label}.{$key}");
    }
}

$specialCased = ['qualitative_roundtrip_and_boundaries', 'reduction_target_invariance'];

foreach (goldenVectorFixture()['vectors'] as $vector) {
    if (in_array($vector['name'], $specialCased, true)) {
        continue;
    }

    test("golden vector: {$vector['name']}", function () use ($vector) {
        $input = resolveGoldenVectorInput($vector['input']);
        $graph = GoldenVectorGraphAdapter::build($input);
        $results = (new RiskCalculator)->compute($graph);

        $checker = new GoldenVectorChecker;
        $expected = $vector['expected'];
        $vectorName = $vector['name'];

        if (isset($expected['baselines'])) {
            foreach ($expected['baselines'] as $baselineName => $expBaseline) {
                $result = match ($baselineName) {
                    'inherent' => $results->inherent,
                    'current' => $results->current,
                    'target' => $results->target,
                    default => null,
                };

                if ($result === null) {
                    $checker->fail("{$vectorName}: unknown baseline '{$baselineName}' in fixture");

                    continue;
                }

                assertGoldenBaseline($checker, "{$vectorName}.{$baselineName}", $expBaseline, $result);
            }
        }

        if (isset($expected['completeness'])) {
            assertGoldenCompleteness($checker, $vectorName, $expected['completeness'], $results->current);
        }

        if (isset($expected['warnings'])) {
            if ($expected['warnings'] === []) {
                $checker->warningAbsentAll($results->current->warnings, "{$vectorName}.warnings (expected none)");
            } else {
                foreach ($expected['warnings'] as $w) {
                    $checker->warningPresent($results->current->warnings, $w, "{$vectorName}.warnings");
                }
            }
        }

        if (isset($expected['defence_rankings'])) {
            assertGoldenDefenceRankings($checker, $vectorName, $expected['defence_rankings'], $results->current, $results->inherent);
        }

        if (isset($expected['log_share_attribution'])) {
            assertGoldenLogShareAttribution($checker, $vectorName, $expected['log_share_attribution'], $results);
        }

        if (isset($expected['cost_benefit'])) {
            assertGoldenCostBenefit($checker, $vectorName, $expected['cost_benefit'], $results->current);
        }

        if (isset($expected['on_demand'])) {
            assertGoldenOnDemand($checker, $vectorName, $expected['on_demand'], $results);
        }

        $checker->assertClean();
    });
}

// `reduction_target_invariance` is shaped as one "common" graph plus three
// "scenarios" that each swap in a different attack_defence link — not a
// single "input" — so it gets its own loop instead of the generic one above.
test('golden vector: reduction_target_invariance', function () {
    $vector = goldenVectorsByName()['reduction_target_invariance'];
    $input = $vector['input'];
    $expected = $vector['expected'];
    $checker = new GoldenVectorChecker;

    foreach ($input['scenarios'] as $scenario) {
        $scenarioInput = [
            'assessment' => $input['assessment'],
            'scales' => $input['scales'],
            'risks' => $input['common']['risks'],
            'attacks' => $input['common']['attacks'],
            'defences' => $input['common']['defences'],
            'risk_attack' => $input['common']['risk_attack'],
            'attack_defence' => $scenario['attack_defence'],
        ];

        $graph = GoldenVectorGraphAdapter::build($scenarioInput);
        $results = (new RiskCalculator)->compute($graph);
        $pair = $results->current->pairs[0];

        $expScenario = collect($expected['scenarios'])->firstWhere('name', $scenario['name']);
        $label = "reduction_target_invariance.{$scenario['name']}";

        if (array_key_exists('invariant_total_current', $expected)) {
            $checker->numeric($results->current->total, $expected['invariant_total_current'], "{$label}.total");
        }
        if ($expScenario !== null && array_key_exists('aro_residual', $expScenario)) {
            $checker->numeric($pair->aroResidual, $expScenario['aro_residual'], "{$label}.aro_residual");
        }
        if ($expScenario !== null && array_key_exists('impact_residual', $expScenario)) {
            $checker->numeric($pair->impactResidual, $expScenario['impact_residual'], "{$label}.impact_residual");
        }
        if ($expScenario !== null && array_key_exists('ale', $expScenario)) {
            $checker->numeric($pair->ale, $expScenario['ale'], "{$label}.ale");
        }
    }

    $checker->assertClean();
});

// `qualitative_roundtrip_and_boundaries` is pure ScaleMapper coverage (§8) —
// it has no risks/attacks/defences at all, so it never goes through
// RiskCalculator or the graph adapter's risk/attack/link resolution, only
// its scale-level construction.
test('golden vector: qualitative_roundtrip_and_boundaries', function () {
    $vector = goldenVectorsByName()['qualitative_roundtrip_and_boundaries'];
    $expected = $vector['expected'];
    $checker = new GoldenVectorChecker;
    $mapper = new ScaleMapper;

    $scaleLevels = GoldenVectorGraphAdapter::buildScaleLevels($vector['input']['scales'])[0];

    $levelsByKind = ['impact' => [], 'likelihood' => [], 'strength' => []];
    foreach ($scaleLevels as $lvl) {
        $levelsByKind[$lvl['kind']][] = $lvl;
    }

    $levelByCode = fn (string $kind, string $code) => collect($levelsByKind[$kind])->firstWhere('code', $code);
    $unitOf = fn (string $kind) => $kind === 'likelihood' ? 'rate_per_year' : 'fraction';

    foreach ($expected['to_number'] ?? [] as $row) {
        $level = $levelByCode($row['scale'], $row['level_code']);
        if ($level === null) {
            $checker->fail("to_number({$row['scale']}.{$row['level_code']}): level not found");

            continue;
        }
        $checker->numeric($mapper->toNumber($level), $row['expected'], "to_number({$row['scale']}.{$row['level_code']})");
    }

    foreach ($expected['to_level'] ?? [] as $row) {
        $result = $mapper->toLevel((float) $row['input'], $levelsByKind[$row['scale']], $unitOf($row['scale']));
        $rowLabel = "to_level({$row['scale']}, {$row['input']})";

        if ($result === null) {
            $checker->fail("{$rowLabel}: expected level '{$row['expected_level_code']}', got null");
        } else {
            $checker->same($result['code'], $row['expected_level_code'], "{$rowLabel}.code");
        }
    }

    foreach ($expected['round_trip'] ?? [] as $row) {
        $level = $levelByCode($row['scale'], $row['level_code']);
        if ($level === null) {
            $checker->fail("round_trip({$row['scale']}.{$row['level_code']}): level not found");

            continue;
        }

        $number = $mapper->toNumber($level);
        $roundTripped = $mapper->toLevel($number, $levelsByKind[$row['scale']], $unitOf($row['scale']));
        $rowLabel = "round_trip({$row['scale']}.{$row['level_code']})";

        if ($roundTripped === null) {
            $checker->fail("{$rowLabel}: toLevel(toNumber(level)) returned null");
        } else {
            $checker->same($roundTripped['code'], $row['level_code'], $rowLabel);
        }
    }

    foreach ($expected['unit_mismatch_throws'] ?? [] as $row) {
        try {
            $mapper->toLevel((float) $row['input'], $levelsByKind[$row['scale']], $row['wrong_unit']);
            $checker->fail("unit_mismatch_throws({$row['scale']}, wrong_unit={$row['wrong_unit']}): expected an exception, none was thrown");
        } catch (InvalidArgumentException) {
            // expected
        }
    }

    if (isset($expected['missing_input'])) {
        $row = $expected['missing_input'];
        $result = $mapper->toLevel(null, $levelsByKind[$row['scale']], $unitOf($row['scale']));
        $checker->isNull($result, 'missing_input');
    }

    $checker->assertClean();
});
