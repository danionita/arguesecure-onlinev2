import { describe, expect, test } from 'vitest';
import type { AssessmentGraph, ScaleLevel } from '../../../resources/js/calc';
import { RiskCalculator } from '../../../resources/js/calc';

/**
 * W11 mirror of tests/Unit/Calculation/CalculationRulesTest.php — the §15
 * checklist items that don't need the full §14 fixture. Uses the same
 * minimal one-risk/one-attack/one-defence graph shape, parameterised the
 * same way, so a diff between the two files reads as "same rule, same
 * numbers, different language".
 */

function closeTo(
    actual: number | null,
    expected: number,
    tolerance = 1e-9,
): void {
    expect(actual).not.toBeNull();
    expect(Math.abs((actual as number) - expected)).toBeLessThanOrEqual(
        tolerance,
    );
}

function minimalScaleLevels(): Record<number, ScaleLevel> {
    return {
        100: {
            id: 100,
            scaleId: 1,
            kind: 'impact',
            unit: 'currency',
            code: 'any',
            label: 'Any',
            ordinal: 1,
            lowerBound: 0.0,
            upperBound: null,
            representative: 0.0,
        },
        200: {
            id: 200,
            scaleId: 2,
            kind: 'likelihood',
            unit: 'rate_per_year',
            code: 'any',
            label: 'Any',
            ordinal: 1,
            lowerBound: 0.0,
            upperBound: null,
            representative: 0.0,
        },
        300: {
            id: 300,
            scaleId: 3,
            kind: 'strength',
            unit: 'fraction',
            code: 'any',
            label: 'Any',
            ordinal: 1,
            lowerBound: 0.0,
            upperBound: 1.0,
            representative: 0.0,
        },
    };
}

function minimalGraph(
    impactAmount: number | null = 100.0,
    aro: number | null = 2.0,
    defenceStatus: 'implemented' | 'todo' | 'shelved' = 'implemented',
    strengthFraction: number | null = 0.5,
    reductionTarget: 'likelihood' | 'impact' | null = null,
    defencePosition = 0,
): AssessmentGraph {
    return {
        assessmentUuid: 'a',
        currency: 'EUR',
        amortizationYears: 3,
        rootTitle: null,
        rootDescription: null,
        risks: {
            r: {
                uuid: 'r',
                title: 'R',
                impactMode: impactAmount === null ? null : 'quantitative',
                impactAmount,
                impactLevelId: null,
            },
        },
        attacks: {
            a: {
                uuid: 'a',
                title: 'A',
                likelihoodMode: aro === null ? null : 'quantitative',
                likelihoodAro: aro,
                likelihoodLevelId: null,
            },
        },
        defences: {
            d: {
                uuid: 'd',
                title: 'D',
                isTransfer: false,
                status: defenceStatus,
                capexAmount: null,
                opexAnnualAmount: 1000.0,
            },
        },
        riskAttackLinks: [{ riskUuid: 'r', attackUuid: 'a', position: 0 }],
        attackDefenceLinks: [
            {
                attackUuid: 'a',
                defenceUuid: 'd',
                position: defencePosition,
                strengthMode: strengthFraction === null ? null : 'quantitative',
                strengthFraction,
                strengthLevelId: null,
                reductionTarget,
            },
        ],
        scaleLevels: minimalScaleLevels(),
        scales: {},
    };
}

describe('calculation rules (§15 checklist)', () => {
    // §15 item 6: blanking a risk's impact drops exactly its pairs from the
    // total and raises the correct completeness flags — never silently zero.
    test('a missing impact excludes the pair from the total, not coerces it to zero', () => {
        const withImpact = new RiskCalculator().compute(minimalGraph(100.0));
        const withoutImpact = new RiskCalculator().compute(minimalGraph(null));

        expect(withImpact.current.total).toBeGreaterThan(0.0);
        expect(withoutImpact.current.total).toBe(0.0);
        expect(withoutImpact.current.completeness.pairsValued).toBe(0);
        expect(withoutImpact.current.completeness.pairsTotal).toBe(1);
        expect(withoutImpact.current.completeness.risksMissingImpact).toEqual([
            'r',
        ]);
        expect(withoutImpact.current.pairs[0].valued).toBe(false);
        expect(withoutImpact.current.pairs[0].ale).toBeNull();
    });

    // §15 item 7: blanking a link's strength leaves the pair in the total
    // with s = 0 (no reduction) and flags links_missing_strength.
    test('a missing link strength coerces to s=0 and keeps the pair in the total', () => {
        const withStrength = new RiskCalculator().compute(
            minimalGraph(100.0, 2.0, 'implemented', 0.5),
        );
        const withoutStrength = new RiskCalculator().compute(
            minimalGraph(100.0, 2.0, 'implemented', null),
        );

        closeTo(withStrength.current.total, 100.0);
        closeTo(withoutStrength.current.total, 200.0);
        expect(
            withoutStrength.current.completeness.linksMissingStrength,
        ).toEqual([{ attackUuid: 'a', defenceUuid: 'd' }]);
        expect(withoutStrength.current.pairs[0].valued).toBe(true);
    });

    // §15 item 8: changing reduction_target changes ARO'/Imp' but leaves the
    // total bit-identical, because the total comes from factor(a,S) alone.
    test('changing reduction_target leaves the total bit-identical', () => {
        const likelihoodTarget = new RiskCalculator().compute(
            minimalGraph(100.0, 2.0, 'implemented', 0.5, 'likelihood'),
        );
        const impactTarget = new RiskCalculator().compute(
            minimalGraph(100.0, 2.0, 'implemented', 0.5, 'impact'),
        );
        const combined = new RiskCalculator().compute(
            minimalGraph(100.0, 2.0, 'implemented', 0.5, null),
        );

        expect(likelihoodTarget.current.total).toBe(impactTarget.current.total);
        expect(likelihoodTarget.current.total).toBe(combined.current.total);

        // But the displayed residuals genuinely differ by target (§5.1 point 1).
        expect(likelihoodTarget.current.pairs[0].aroResidual).not.toBe(
            impactTarget.current.pairs[0].aroResidual,
        );
        expect(likelihoodTarget.current.pairs[0].impactResidual).not.toBe(
            impactTarget.current.pairs[0].impactResidual,
        );
    });

    // §15 item 9: reordering defences on an attack leaves every number
    // identical — position is display ordering only, not part of the formula.
    test('reordering defences on an attack leaves every number identical', () => {
        const graph: AssessmentGraph = {
            assessmentUuid: 'a',
            currency: 'EUR',
            amortizationYears: 3,
            rootTitle: null,
            rootDescription: null,
            risks: {
                r: {
                    uuid: 'r',
                    title: 'R',
                    impactMode: 'quantitative',
                    impactAmount: 100.0,
                    impactLevelId: null,
                },
            },
            attacks: {
                a: {
                    uuid: 'a',
                    title: 'A',
                    likelihoodMode: 'quantitative',
                    likelihoodAro: 2.0,
                    likelihoodLevelId: null,
                },
            },
            defences: {
                d1: {
                    uuid: 'd1',
                    title: 'D1',
                    isTransfer: false,
                    status: 'implemented',
                    capexAmount: null,
                    opexAnnualAmount: 1000.0,
                },
                d2: {
                    uuid: 'd2',
                    title: 'D2',
                    isTransfer: false,
                    status: 'implemented',
                    capexAmount: null,
                    opexAnnualAmount: 1000.0,
                },
            },
            riskAttackLinks: [{ riskUuid: 'r', attackUuid: 'a', position: 0 }],
            attackDefenceLinks: [
                {
                    attackUuid: 'a',
                    defenceUuid: 'd1',
                    position: 0,
                    strengthMode: 'quantitative',
                    strengthFraction: 0.5,
                    strengthLevelId: null,
                    reductionTarget: null,
                },
                {
                    attackUuid: 'a',
                    defenceUuid: 'd2',
                    position: 1,
                    strengthMode: 'quantitative',
                    strengthFraction: 0.3,
                    strengthLevelId: null,
                    reductionTarget: null,
                },
            ],
            scaleLevels: minimalScaleLevels(),
            scales: {},
        };

        const reordered: AssessmentGraph = {
            ...graph,
            attackDefenceLinks: [
                {
                    attackUuid: 'a',
                    defenceUuid: 'd2',
                    position: 0,
                    strengthMode: 'quantitative',
                    strengthFraction: 0.3,
                    strengthLevelId: null,
                    reductionTarget: null,
                },
                {
                    attackUuid: 'a',
                    defenceUuid: 'd1',
                    position: 1,
                    strengthMode: 'quantitative',
                    strengthFraction: 0.5,
                    strengthLevelId: null,
                    reductionTarget: null,
                },
            ],
        };

        const a = new RiskCalculator().compute(graph);
        const b = new RiskCalculator().compute(reordered);

        closeTo(a.current.total, b.current.total, 1e-12);
        closeTo(
            a.current.pairs[0].ale,
            b.current.pairs[0].ale as number,
            1e-12,
        );
    });

    // §15 item 11: ARO = 0 is a known value (contributes 0, not flagged
    // missing); ARO = null is flagged and excluded.
    test('ARO=0 is known and contributes 0; ARO=null is missing and excluded', () => {
        const zero = new RiskCalculator().compute(minimalGraph(100.0, 0.0));
        const missing = new RiskCalculator().compute(minimalGraph(100.0, null));

        expect(zero.current.total).toBe(0.0);
        expect(zero.current.completeness.pairsValued).toBe(1);
        expect(zero.current.completeness.attacksMissingLikelihood).toEqual([]);
        expect(zero.current.pairs[0].valued).toBe(true);

        expect(missing.current.total).toBe(0.0);
        expect(missing.current.completeness.pairsValued).toBe(0);
        expect(missing.current.completeness.attacksMissingLikelihood).toEqual([
            'a',
        ]);
        expect(missing.current.pairs[0].valued).toBe(false);
    });

    // §15 item 3: MC by factor-exclusion equals the analytic
    // Σ ALE(r,a,S) × s/(1-s) identity for s < 1 ...
    test('MC by factor-exclusion equals the analytic division identity for s < 1', () => {
        const results = new RiskCalculator().compute(
            minimalGraph(100.0, 2.0, 'implemented', 0.8),
        );
        const mc = results.current.defences.find((d) => d.uuid === 'd')
            ?.mc as number;

        const aleAtS = results.current.pairs[0].ale as number; // 2 * 100 * (1-0.8) = 40
        const analytic = aleAtS * (0.8 / (1 - 0.8));

        closeTo(mc, analytic);
        closeTo(mc, 160.0); // 2*100*0.2*4 = 160
    });

    // ... and does not divide by zero (or blow up) at s = 1.
    test('MC does not divide by zero at s = 1', () => {
        const results = new RiskCalculator().compute(
            minimalGraph(100.0, 2.0, 'implemented', 1.0),
        );

        const mc = results.current.defences.find((d) => d.uuid === 'd')
            ?.mc as number;
        const aleAtS = results.current.pairs[0].ale;

        expect(aleAtS).toBe(0.0); // fully blocked
        closeTo(mc, 200.0); // removing it restores the full 2*100
        expect(Number.isFinite(mc)).toBe(true);
        expect(results.current.completeness.implausibleStrength).toEqual([
            { attackUuid: 'a', defenceUuid: 'd' },
        ]);
    });
});
