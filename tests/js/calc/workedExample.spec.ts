import { describe, expect, test } from 'vitest';
import { RiskCalculator } from '../../../resources/js/calc';
import {
    buildWorkedExampleGraph,
    buildWorkedExampleGraphWithoutA2Likelihood,
} from './support/workedExampleGraph';

/**
 * W11 mirror of tests/Unit/Calculation/WorkedExampleTest.php — the §14
 * worked example, built directly (not via the JSON fixture adapter) so this
 * suite and goldenVectors.spec.ts corroborate each other from two different
 * graph-construction paths.
 */

function closeTo(
    actual: number | null | undefined,
    expected: number,
    tolerance = 1e-6,
): void {
    expect(actual).not.toBeNull();
    expect(actual).not.toBeUndefined();
    expect(Math.abs((actual as number) - expected)).toBeLessThanOrEqual(
        tolerance,
    );
}

describe('§14 worked example', () => {
    // §15 item 1: the §14 worked example reproduces exactly: inherent
    // €187,000, current €65,500, target €36,700.
    test('the §14 worked example totals reproduce exactly', () => {
        const results = new RiskCalculator().compute(buildWorkedExampleGraph());

        closeTo(results.inherent.total, 187000.0);
        closeTo(results.current.total, 65500.0);
        closeTo(results.target.total, 36700.0);
    });

    // §15 item 2: risk-view and attack-view subtotals both reconcile to the
    // total, for the current baseline (§14 "Reconciliation").
    test('risk-view and attack-view subtotals reconcile to the total', () => {
        const results = new RiskCalculator().compute(buildWorkedExampleGraph());
        const current = results.current;

        const riskSum = current.risks.reduce((s, r) => s + r.ale, 0);
        const attackSum = current.attacks.reduce((s, a) => s + a.ale, 0);

        closeTo(riskSum, current.total);
        closeTo(attackSum, current.total);

        const byUuid = <T extends { uuid: string }>(rows: T[], uuid: string) =>
            rows.find((r) => r.uuid === uuid);

        closeTo(byUuid(current.risks, 'r1')?.ale, 22000.0);
        closeTo(byUuid(current.risks, 'r2')?.ale, 43500.0);
        closeTo(byUuid(current.attacks, 'a1')?.ale, 11500.0);
        closeTo(byUuid(current.attacks, 'a2')?.ale, 12000.0);
        closeTo(byUuid(current.attacks, 'a3')?.ale, 42000.0);
    });

    test('MC(D1, current) and MC(D2, current) match §14', () => {
        const results = new RiskCalculator().compute(buildWorkedExampleGraph());
        const byUuid = new Map(
            results.current.defences.map((d) => [d.uuid, d]),
        );

        closeTo(byUuid.get('d1')?.mc, 46000.0);
        closeTo(byUuid.get('d2')?.mc, 29500.0);
    });

    test('MCadd(D3, current) matches §14', () => {
        const results = new RiskCalculator().compute(buildWorkedExampleGraph());
        const byUuid = new Map(
            results.current.defences.map((d) => [d.uuid, d]),
        );

        closeTo(byUuid.get('d3')?.mcAdd, 28800.0);
    });

    // §14 shelved-on-demand: MCadd(D4) evaluated against current, even
    // though D4 is shelved and never enters any of the three baseline totals.
    test('MCadd(D4, current) matches §14 even though D4 is shelved', () => {
        const results = new RiskCalculator().compute(buildWorkedExampleGraph());
        const byUuid = new Map(
            results.current.defences.map((d) => [d.uuid, d]),
        );

        closeTo(byUuid.get('d4')?.mcAdd, 4600.0);
        expect(byUuid.get('d4')?.mc).toBeNull();
    });

    // §15 item 10: a shelved defence never affects any of the three totals
    // — compared here against a graph with D4 removed entirely.
    test('a shelved defence never affects any baseline total', () => {
        const withD4 = new RiskCalculator().compute(buildWorkedExampleGraph());

        const g = buildWorkedExampleGraph();
        const { d4: _omit, ...defencesWithoutD4 } = g.defences;
        const withoutD4 = new RiskCalculator().compute({
            ...g,
            defences: defencesWithoutD4,
            attackDefenceLinks: g.attackDefenceLinks.filter(
                (l) => l.defenceUuid !== 'd4',
            ),
        });

        closeTo(withD4.inherent.total, withoutD4.inherent.total, 1e-9);
        closeTo(withD4.current.total, withoutD4.current.total, 1e-9);
        closeTo(withD4.target.total, withoutD4.target.total, 1e-9);
    });

    // §15 item 4: log-share attribution sums exactly to Total(inherent) -
    // Total(current).
    test('log-share attribution sums exactly to the inherent-to-current reduction', () => {
        const results = new RiskCalculator().compute(buildWorkedExampleGraph());
        const byUuid = new Map(
            results.current.defences.map((d) => [d.uuid, d]),
        );

        const sum =
            (byUuid.get('d1')?.attribution ?? 0.0) +
            (byUuid.get('d2')?.attribution ?? 0.0);
        const expectedReduction =
            results.inherent.total - results.current.total;

        closeTo(sum, expectedReduction);
        closeTo(expectedReduction, 121500.0);
    });

    // Independently re-derived precise log-share split: Attr(D1) ~=
    // 72,343.40, Attr(D2) ~= 49,156.60 — not the document's printed
    // 72,347/49,153, which rounds intermediate shares before multiplying
    // (§11.3 forbids rounding intermediates for exactly this reason).
    test("log-share attribution: precise values differ from §14's rounded printed figures", () => {
        const results = new RiskCalculator().compute(buildWorkedExampleGraph());
        const byUuid = new Map(
            results.current.defences.map((d) => [d.uuid, d]),
        );

        closeTo(byUuid.get('d1')?.attribution, 72343.395448778, 1e-3);
        closeTo(byUuid.get('d2')?.attribution, 49156.604551222, 1e-3);
    });

    test('the §14 partial-data variant: blanking A2 likelihood drops current to €53,500 at 75% coverage', () => {
        const results = new RiskCalculator().compute(
            buildWorkedExampleGraphWithoutA2Likelihood(),
        );

        closeTo(results.current.total, 53500.0);
        expect(results.current.completeness.pairsValued).toBe(3);
        expect(results.current.completeness.pairsTotal).toBe(4);
        closeTo(results.current.completeness.coverage, 0.75, 1e-9);
        expect(results.current.completeness.attacksMissingLikelihood).toEqual([
            'a2',
        ]);
    });
});
