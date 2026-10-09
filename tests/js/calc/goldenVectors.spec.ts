import { describe, test } from 'vitest';
import type {
    CalculationResult,
    CalculationResults,
    ScaleLevel,
} from '../../../resources/js/calc';
import { RiskCalculator, ScaleMapper } from '../../../resources/js/calc';
import {
    loadFixture,
    resolveVectorInput,
    vectorsByName,
} from './support/fixture';
import { GoldenVectorChecker } from './support/goldenVectorChecker';
import {
    buildGraph,
    buildScaleLevels,
} from './support/goldenVectorGraphAdapter';

/**
 * W11 mirror of tests/Unit/Calculation/GoldenVectorTest.php: runs every
 * vector in tests/fixtures/calculation-vectors.json — derived independently
 * from docs/specs/calculation-engine.md — against the TypeScript
 * RiskCalculator.
 *
 * THIS FILE MUST NOT BE TUNED TO MAKE THE ENGINE AND THE FIXTURE AGREE. A
 * failing vector means the engine and an independent reading of the spec
 * disagree, and that disagreement needs a human to adjudicate, not a quiet
 * edit (see the W11 report for the resolution of any failure at the time
 * this file was written).
 *
 * Two vectors don't fit the generic "build a graph, run RiskCalculator,
 * check `expected`" shape and get dedicated tests below, mirroring the PHP
 * harness's own special-casing.
 */

function assertGoldenBaseline(
    checker: GoldenVectorChecker,
    label: string,
    expected: Record<string, any>,
    result: CalculationResult,
): void {
    if ('total' in expected) {
        checker.numeric(result.total, expected.total, `${label}.total`);
    }

    for (const expPair of expected.pairs ?? []) {
        const actual = result.pairs.find(
            (p) =>
                p.riskUuid === expPair.risk_uuid &&
                p.attackUuid === expPair.attack_uuid,
        );
        const pairLabel = `${label}.pairs[${expPair.risk_uuid},${expPair.attack_uuid}]`;

        if (actual === undefined) {
            checker.fail(`${pairLabel}: pair not found in engine output`);
            continue;
        }

        if ('aro_residual' in expPair)
            checker.numeric(
                actual.aroResidual,
                expPair.aro_residual,
                `${pairLabel}.aro_residual`,
            );
        if ('impact_residual' in expPair)
            checker.numeric(
                actual.impactResidual,
                expPair.impact_residual,
                `${pairLabel}.impact_residual`,
            );
        if ('ale' in expPair)
            checker.numeric(actual.ale, expPair.ale, `${pairLabel}.ale`);
        if ('valued' in expPair)
            checker.same(actual.valued, expPair.valued, `${pairLabel}.valued`);
    }

    for (const expRisk of expected.risks ?? []) {
        const actual = result.risks.find((r) => r.uuid === expRisk.uuid);
        const riskLabel = `${label}.risks[${expRisk.uuid}]`;

        if (actual === undefined) {
            checker.fail(`${riskLabel}: risk not found in engine output`);
            continue;
        }

        if ('ale' in expRisk)
            checker.numeric(actual.ale, expRisk.ale, `${riskLabel}.ale`);
        if ('impact_value' in expRisk)
            checker.numeric(
                actual.impactValue,
                expRisk.impact_value,
                `${riskLabel}.impact_value`,
            );
        if ('impact_level_code' in expRisk)
            checker.same(
                actual.impactLevelCode,
                expRisk.impact_level_code,
                `${riskLabel}.impact_level_code`,
            );
        if ('annual_probability' in expRisk)
            checker.numeric(
                actual.annualProbability,
                expRisk.annual_probability,
                `${riskLabel}.annual_probability`,
            );
        if ('pairs_valued' in expRisk)
            checker.same(
                actual.pairsValued,
                expRisk.pairs_valued,
                `${riskLabel}.pairs_valued`,
            );
        if ('pairs_total' in expRisk)
            checker.same(
                actual.pairsTotal,
                expRisk.pairs_total,
                `${riskLabel}.pairs_total`,
            );
    }

    for (const expAttack of expected.attacks ?? []) {
        const actual = result.attacks.find((a) => a.uuid === expAttack.uuid);
        const attackLabel = `${label}.attacks[${expAttack.uuid}]`;

        if (actual === undefined) {
            checker.fail(`${attackLabel}: attack not found in engine output`);
            continue;
        }

        if ('ale' in expAttack)
            checker.numeric(actual.ale, expAttack.ale, `${attackLabel}.ale`);
        if ('factor' in expAttack)
            checker.numeric(
                actual.factor,
                expAttack.factor,
                `${attackLabel}.factor`,
            );
        if ('aro_base' in expAttack)
            checker.numeric(
                actual.aroBase,
                expAttack.aro_base,
                `${attackLabel}.aro_base`,
            );
        if ('aro_residual' in expAttack)
            checker.numeric(
                actual.aroResidual,
                expAttack.aro_residual,
                `${attackLabel}.aro_residual`,
            );
        if ('aro_level_code' in expAttack)
            checker.same(
                actual.aroLevelCode,
                expAttack.aro_level_code,
                `${attackLabel}.aro_level_code`,
            );
        if ('risk_count' in expAttack)
            checker.same(
                actual.riskCount,
                expAttack.risk_count,
                `${attackLabel}.risk_count`,
            );
    }

    if (expected.reconciliation) {
        const riskSum = result.risks.reduce((sum, r) => sum + r.ale, 0);
        const attackSum = result.attacks.reduce((sum, a) => sum + a.ale, 0);

        if ('risk_view_sum' in expected.reconciliation) {
            checker.numeric(
                riskSum,
                expected.reconciliation.risk_view_sum,
                `${label}.reconciliation.risk_view_sum`,
            );
        }
        if ('attack_view_sum' in expected.reconciliation) {
            checker.numeric(
                attackSum,
                expected.reconciliation.attack_view_sum,
                `${label}.reconciliation.attack_view_sum`,
            );
        }
    }

    if (expected.warnings) {
        for (const w of expected.warnings) {
            checker.warningPresent(result.warnings, w, `${label}.warnings`);
        }
    }
}

function assertGoldenCompleteness(
    checker: GoldenVectorChecker,
    vectorName: string,
    expected: Record<string, any>,
    current: CalculationResult,
): void {
    const c = current.completeness;
    const label = `${vectorName}.completeness`;

    if ('total' in expected)
        checker.numeric(
            current.total,
            expected.total,
            `${label}.total (current baseline)`,
        );
    if ('is_partial' in expected)
        checker.same(c.isPartial, expected.is_partial, `${label}.is_partial`);
    if ('pairs_total' in expected)
        checker.same(
            c.pairsTotal,
            expected.pairs_total,
            `${label}.pairs_total`,
        );
    if ('pairs_valued' in expected)
        checker.same(
            c.pairsValued,
            expected.pairs_valued,
            `${label}.pairs_valued`,
        );
    if ('coverage' in expected)
        checker.numeric(c.coverage, expected.coverage, `${label}.coverage`);
    if ('risks_missing_impact' in expected)
        checker.set(
            c.risksMissingImpact,
            expected.risks_missing_impact,
            `${label}.risks_missing_impact`,
        );
    if ('attacks_missing_likelihood' in expected)
        checker.set(
            c.attacksMissingLikelihood,
            expected.attacks_missing_likelihood,
            `${label}.attacks_missing_likelihood`,
        );
    if ('links_missing_strength' in expected)
        checker.rowSet(
            c.linksMissingStrength.map((l) => ({
                attack_uuid: l.attackUuid,
                defence_uuid: l.defenceUuid,
            })),
            expected.links_missing_strength,
            `${label}.links_missing_strength`,
        );
    if ('risks_without_attacks' in expected)
        checker.set(
            c.risksWithoutAttacks,
            expected.risks_without_attacks,
            `${label}.risks_without_attacks`,
        );
    if ('orphan_attacks' in expected)
        checker.set(
            c.orphanAttacks,
            expected.orphan_attacks,
            `${label}.orphan_attacks`,
        );
    if ('orphan_defences' in expected)
        checker.set(
            c.orphanDefences,
            expected.orphan_defences,
            `${label}.orphan_defences`,
        );
    if ('shared_attacks' in expected)
        checker.rowSet(
            c.sharedAttacks.map((s) => ({
                uuid: s.uuid,
                risk_count: s.riskCount,
            })),
            expected.shared_attacks,
            `${label}.shared_attacks`,
        );
    if ('implausible_strength' in expected)
        checker.rowSet(
            c.implausibleStrength.map((l) => ({
                attack_uuid: l.attackUuid,
                defence_uuid: l.defenceUuid,
            })),
            expected.implausible_strength,
            `${label}.implausible_strength`,
        );
}

function assertGoldenDefenceRankings(
    checker: GoldenVectorChecker,
    vectorName: string,
    expected: Record<string, any>,
    current: CalculationResult,
    inherent: CalculationResult,
): void {
    const byUuid = new Map(current.defences.map((d) => [d.uuid, d]));
    const label = `${vectorName}.defence_rankings`;

    const importanceUuids: string[] = [];
    for (const row of expected.importance_against_current ?? []) {
        const uuid = row.defence_uuid;
        importanceUuids.push(uuid);
        const d = byUuid.get(uuid);

        if (d === undefined) {
            checker.fail(
                `${label}.importance_against_current[${uuid}]: defence not found`,
            );
            continue;
        }

        if ('mc' in row)
            checker.numeric(
                d.mc,
                row.mc,
                `${label}.importance_against_current[${uuid}].mc`,
            );
    }

    for (const row of expected.opportunity_against_current ?? []) {
        const uuid = row.defence_uuid;
        const d = byUuid.get(uuid);

        if (d === undefined) {
            checker.fail(
                `${label}.opportunity_against_current[${uuid}]: defence not found`,
            );
            continue;
        }

        if ('mc_add' in row)
            checker.numeric(
                d.mcAdd,
                row.mc_add,
                `${label}.opportunity_against_current[${uuid}].mc_add`,
            );
    }

    for (const row of expected.shelved_on_demand ?? []) {
        const uuid = row.defence_uuid;
        const d = byUuid.get(uuid);

        if (d === undefined) {
            checker.fail(
                `${label}.shelved_on_demand[${uuid}]: defence not found`,
            );
            continue;
        }

        if ('mc_add' in row)
            checker.numeric(
                d.mcAdd,
                row.mc_add,
                `${label}.shelved_on_demand[${uuid}].mc_add`,
            );
    }

    if (expected.non_additivity_demo && importanceUuids.length > 0) {
        const demo = expected.non_additivity_demo;
        let sumMc = 0.0;
        for (const uuid of importanceUuids) {
            sumMc += byUuid.get(uuid)?.mc ?? 0.0;
        }

        if ('sum_of_mc_d1_d2' in demo)
            checker.numeric(
                sumMc,
                demo.sum_of_mc_d1_d2,
                `${label}.non_additivity_demo.sum_of_mc`,
            );

        const jointReduction = inherent.total - current.total;
        if ('joint_inherent_to_current_reduction' in demo)
            checker.numeric(
                jointReduction,
                demo.joint_inherent_to_current_reduction,
                `${label}.non_additivity_demo.joint_reduction`,
            );
        if ('understatement' in demo)
            checker.numeric(
                jointReduction - sumMc,
                demo.understatement,
                `${label}.non_additivity_demo.understatement`,
            );
    }
}

function assertGoldenLogShareAttribution(
    checker: GoldenVectorChecker,
    vectorName: string,
    expected: Record<string, any>,
    results: CalculationResults,
): void {
    const baselineName = expected.baseline ?? 'current';
    const baseline =
        baselineName === 'inherent'
            ? results.inherent
            : baselineName === 'target'
              ? results.target
              : results.current;
    const byUuid = new Map(baseline.defences.map((d) => [d.uuid, d]));
    const label = `${vectorName}.log_share_attribution`;

    for (const [uuid, expVal] of Object.entries(expected.attribution ?? {})) {
        const d = byUuid.get(uuid);

        if (d === undefined) {
            checker.fail(`${label}.attribution[${uuid}]: defence not found`);
            continue;
        }

        checker.numeric(d.attribution, expVal, `${label}.attribution[${uuid}]`);
    }

    if ('target_sum' in expected) {
        let sum = 0.0;
        for (const d of baseline.defences) {
            sum += d.attribution ?? 0.0;
        }

        checker.numeric(
            sum,
            expected.target_sum,
            `${label}.target_sum (sum over defences with non-null attribution)`,
        );
    }
}

function assertGoldenCostBenefit(
    checker: GoldenVectorChecker,
    vectorName: string,
    expected: Record<string, any>,
    current: CalculationResult,
): void {
    const label = `${vectorName}.cost_benefit`;

    const buckets: Record<
        'priced' | 'free' | 'unpriced',
        CalculationResult['defences']
    > = { priced: [], free: [], unpriced: [] };
    for (const d of current.defences) {
        buckets[d.costBucket].push(d);
    }

    const mcOf = (d: CalculationResult['defences'][number]) =>
        d.mc ?? d.mcAdd ?? 0.0;

    buckets.priced.sort(
        (a, b) =>
            (b.cbr ?? 0) - (a.cbr ?? 0) ||
            (b.netBenefit ?? 0) - (a.netBenefit ?? 0),
    );
    buckets.free.sort((a, b) => mcOf(b) - mcOf(a));
    buckets.unpriced.sort((a, b) => mcOf(b) - mcOf(a));

    for (const bucketName of ['priced', 'free', 'unpriced'] as const) {
        if (!(bucketName in expected)) continue;

        const expRows = expected[bucketName];
        const actRows = buckets[bucketName];

        if (expRows.length !== actRows.length) {
            const actUuids = actRows.map((d) => d.uuid).join(',');
            checker.fail(
                `${label}.${bucketName}: expected ${expRows.length} row(s), got ${actRows.length} ([${actUuids}])`,
            );
            continue;
        }

        expRows.forEach((expRow: Record<string, any>, i: number) => {
            const act = actRows[i];
            const rowLabel = `${label}.${bucketName}[${i}](${expRow.defence_uuid})`;

            checker.same(
                act.uuid,
                expRow.defence_uuid,
                `${rowLabel}.defence_uuid (bucket order)`,
            );

            if ('status' in expRow)
                checker.same(act.status, expRow.status, `${rowLabel}.status`);
            if ('mc_or_mc_add' in expRow)
                checker.numeric(
                    mcOf(act),
                    expRow.mc_or_mc_add,
                    `${rowLabel}.mc_or_mc_add`,
                );
            if ('annual_cost' in expRow)
                checker.numeric(
                    act.annualCost,
                    expRow.annual_cost,
                    `${rowLabel}.annual_cost`,
                );
            if ('cbr' in expRow)
                checker.numeric(act.cbr, expRow.cbr, `${rowLabel}.cbr`);
            if ('rosi' in expRow)
                checker.numeric(act.rosi, expRow.rosi, `${rowLabel}.rosi`);
            if ('net_benefit' in expRow)
                checker.numeric(
                    act.netBenefit,
                    expRow.net_benefit,
                    `${rowLabel}.net_benefit`,
                );
        });
    }
}

function assertGoldenOnDemand(
    checker: GoldenVectorChecker,
    vectorName: string,
    onDemand: Record<string, any>,
    results: CalculationResults,
): void {
    const label = `${vectorName}.on_demand`;

    for (const [key, value] of Object.entries(onDemand)) {
        const m = key.match(/^mc_add_(.+)_against_(inherent|current|target)$/);
        if (!m) continue;

        const [, uuid, baselineName] = m;
        const baseline =
            baselineName === 'inherent'
                ? results.inherent
                : baselineName === 'target'
                  ? results.target
                  : results.current;

        // Case-insensitive: see PHP GoldenVectorTest's assertGoldenOnDemand
        // docblock — this vector's on_demand key names are lowercase while
        // its defence uuids are uppercase, a cosmetic casing slip.
        const d = baseline.defences.find(
            (x) => x.uuid.toLowerCase() === uuid.toLowerCase(),
        );

        if (d === undefined) {
            checker.fail(`${label}.${key}: defence '${uuid}' not found`);
            continue;
        }

        checker.numeric(d.mcAdd, value, `${label}.${key}`);
    }
}

const specialCased = new Set([
    'qualitative_roundtrip_and_boundaries',
    'reduction_target_invariance',
]);

describe('golden vectors', () => {
    for (const vector of loadFixture().vectors) {
        if (specialCased.has(vector.name)) continue;

        test(`golden vector: ${vector.name}`, () => {
            const input = resolveVectorInput(vector.input);
            const graph = buildGraph(input);
            const results = new RiskCalculator().compute(graph);

            const checker = new GoldenVectorChecker();
            const expected = vector.expected;
            const vectorName = vector.name;

            if (expected.baselines) {
                for (const [baselineName, expBaseline] of Object.entries(
                    expected.baselines,
                ) as [string, Record<string, any>][]) {
                    const result =
                        baselineName === 'inherent'
                            ? results.inherent
                            : baselineName === 'current'
                              ? results.current
                              : baselineName === 'target'
                                ? results.target
                                : null;

                    if (result === null) {
                        checker.fail(
                            `${vectorName}: unknown baseline '${baselineName}' in fixture`,
                        );
                        continue;
                    }

                    assertGoldenBaseline(
                        checker,
                        `${vectorName}.${baselineName}`,
                        expBaseline,
                        result,
                    );
                }
            }

            if (expected.completeness) {
                assertGoldenCompleteness(
                    checker,
                    vectorName,
                    expected.completeness,
                    results.current,
                );
            }

            if (expected.warnings) {
                if (expected.warnings.length === 0) {
                    checker.warningAbsentAll(
                        results.current.warnings,
                        `${vectorName}.warnings (expected none)`,
                    );
                } else {
                    for (const w of expected.warnings) {
                        checker.warningPresent(
                            results.current.warnings,
                            w,
                            `${vectorName}.warnings`,
                        );
                    }
                }
            }

            if (expected.defence_rankings) {
                assertGoldenDefenceRankings(
                    checker,
                    vectorName,
                    expected.defence_rankings,
                    results.current,
                    results.inherent,
                );
            }

            if (expected.log_share_attribution) {
                assertGoldenLogShareAttribution(
                    checker,
                    vectorName,
                    expected.log_share_attribution,
                    results,
                );
            }

            if (expected.cost_benefit) {
                assertGoldenCostBenefit(
                    checker,
                    vectorName,
                    expected.cost_benefit,
                    results.current,
                );
            }

            if (expected.on_demand) {
                assertGoldenOnDemand(
                    checker,
                    vectorName,
                    expected.on_demand,
                    results,
                );
            }

            checker.assertClean();
        });
    }

    // `reduction_target_invariance` is shaped as one "common" graph plus
    // three "scenarios" that each swap in a different attack_defence link —
    // not a single "input" — so it gets its own loop instead of the generic
    // one above.
    test('golden vector: reduction_target_invariance', () => {
        const vector = vectorsByName().reduction_target_invariance;
        const input = vector.input;
        const expected = vector.expected;
        const checker = new GoldenVectorChecker();

        for (const scenario of input.scenarios) {
            const scenarioInput = {
                assessment: input.assessment,
                scales: input.scales,
                risks: input.common.risks,
                attacks: input.common.attacks,
                defences: input.common.defences,
                risk_attack: input.common.risk_attack,
                attack_defence: scenario.attack_defence,
            };

            const graph = buildGraph(scenarioInput);
            const results = new RiskCalculator().compute(graph);
            const pair = results.current.pairs[0];

            const expScenario = (
                expected.scenarios as Record<string, any>[]
            ).find((s) => s.name === scenario.name);
            const label = `reduction_target_invariance.${scenario.name}`;

            if ('invariant_total_current' in expected) {
                checker.numeric(
                    results.current.total,
                    expected.invariant_total_current,
                    `${label}.total`,
                );
            }
            if (expScenario && 'aro_residual' in expScenario)
                checker.numeric(
                    pair.aroResidual,
                    expScenario.aro_residual,
                    `${label}.aro_residual`,
                );
            if (expScenario && 'impact_residual' in expScenario)
                checker.numeric(
                    pair.impactResidual,
                    expScenario.impact_residual,
                    `${label}.impact_residual`,
                );
            if (expScenario && 'ale' in expScenario)
                checker.numeric(pair.ale, expScenario.ale, `${label}.ale`);
        }

        checker.assertClean();
    });

    // `qualitative_roundtrip_and_boundaries` is pure ScaleMapper coverage
    // (§8) — it has no risks/attacks/defences at all, so it never goes
    // through RiskCalculator, only the adapter's scale-level construction.
    test('golden vector: qualitative_roundtrip_and_boundaries', () => {
        const vector = vectorsByName().qualitative_roundtrip_and_boundaries;
        const expected = vector.expected;
        const checker = new GoldenVectorChecker();
        const mapper = new ScaleMapper();

        const [scaleLevels] = buildScaleLevels(vector.input.scales);

        const levelsByKind: Record<string, ScaleLevel[]> = {
            impact: [],
            likelihood: [],
            strength: [],
        };
        for (const lvl of Object.values(scaleLevels)) {
            levelsByKind[lvl.kind].push(lvl);
        }

        const levelByCode = (kind: string, code: string) =>
            levelsByKind[kind].find((l) => l.code === code);
        const unitOf = (kind: string) =>
            kind === 'likelihood' ? 'rate_per_year' : 'fraction';

        for (const row of expected.to_number ?? []) {
            const level = levelByCode(row.scale, row.level_code);
            if (level === undefined) {
                checker.fail(
                    `to_number(${row.scale}.${row.level_code}): level not found`,
                );
                continue;
            }
            checker.numeric(
                mapper.toNumber(level),
                row.expected,
                `to_number(${row.scale}.${row.level_code})`,
            );
        }

        for (const row of expected.to_level ?? []) {
            const result = mapper.toLevel(
                Number(row.input),
                levelsByKind[row.scale],
                unitOf(row.scale),
            );
            const rowLabel = `to_level(${row.scale}, ${row.input})`;

            if (result === null) {
                checker.fail(
                    `${rowLabel}: expected level '${row.expected_level_code}', got null`,
                );
            } else {
                checker.same(
                    result.code,
                    row.expected_level_code,
                    `${rowLabel}.code`,
                );
            }
        }

        for (const row of expected.round_trip ?? []) {
            const level = levelByCode(row.scale, row.level_code);
            if (level === undefined) {
                checker.fail(
                    `round_trip(${row.scale}.${row.level_code}): level not found`,
                );
                continue;
            }

            const number = mapper.toNumber(level);
            const roundTripped = mapper.toLevel(
                number,
                levelsByKind[row.scale],
                unitOf(row.scale),
            );
            const rowLabel = `round_trip(${row.scale}.${row.level_code})`;

            if (roundTripped === null) {
                checker.fail(
                    `${rowLabel}: toLevel(toNumber(level)) returned null`,
                );
            } else {
                checker.same(roundTripped.code, row.level_code, rowLabel);
            }
        }

        for (const row of expected.unit_mismatch_throws ?? []) {
            try {
                mapper.toLevel(
                    Number(row.input),
                    levelsByKind[row.scale],
                    row.wrong_unit,
                );
                checker.fail(
                    `unit_mismatch_throws(${row.scale}, wrong_unit=${row.wrong_unit}): expected an exception, none was thrown`,
                );
            } catch {
                // expected
            }
        }

        if (expected.missing_input) {
            const row = expected.missing_input;
            const result = mapper.toLevel(
                null,
                levelsByKind[row.scale],
                unitOf(row.scale),
            );
            checker.isNull(result, 'missing_input');
        }

        checker.assertClean();
    });
});
