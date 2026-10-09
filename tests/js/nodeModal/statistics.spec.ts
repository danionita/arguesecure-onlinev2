/**
 * The Statistics tab's claim is narrow and worth pinning precisely: *these
 * are the engine's numbers, all three baselines, nothing coerced.*
 *
 * So the tests below check three separable things, and no more:
 *
 * 1. The arithmetic the fixture was built to make checkable by hand — if the
 *    tab silently read `mcAdd` where it meant `mc`, the fixture's todo
 *    defence would show it.
 * 2. Every row is read from `store.results`, i.e. the row values are
 *    identical to the engine fields they claim to come from. Asserting
 *    against a hand-typed expected object would pass just as happily if the
 *    tab and the test made the *same* mistake.
 * 3. Nothing missing is rendered as zero. This is the rule CLAUDE.md states
 *    for the engine, applied at the display layer, and it has three distinct
 *    carriers here: a shelved defence's attribution, an unpriced defence's
 *    CBR, and the inherent column's reduction-against-itself.
 */
import { describe, expect, it } from 'vitest';
import {
    attackStatRows,
    completenessNote,
    defenceCostBucketNote,
    defenceCostLines,
    defenceStatRows,
    plannedImprovementNote,
    riskStatRows,
    rootStatRows,
    type StatFormatters,
    type StatRow,
} from '../../../resources/js/Components/NodeModal/tabs/statistics';
import { makeComputedStore } from './fixture';

const fmt: StatFormatters = {
    money: (value) =>
        value === null || value === undefined ? '—' : `€${value}`,
    percent: (value) =>
        value === null || value === undefined
            ? '—'
            : `${Math.round(value * 100)}%`,
};

function row(rows: StatRow[], key: string): StatRow {
    const found = rows.find((r) => r.key === key);
    expect(found, `no row keyed "${key}"`).toBeDefined();

    return found!;
}

function values(rows: StatRow[], key: string) {
    return row(rows, key).cells.map((cell) => cell.value);
}

describe('the fixture the rest of this file leans on', () => {
    it('computes the three totals the arithmetic in fixture.ts predicts', () => {
        const results = makeComputedStore().results.value;

        // 2.0/yr x 100,000, then x0.5 x0.9 (implemented), then x0.75 (todo).
        expect(results.inherent.total).toBe(200000);
        expect(results.current.total).toBe(90000);
        expect(results.target.total).toBe(67500);
    });
});

describe('root statistics', () => {
    it('puts the three totals in inherent, current, target order', () => {
        const rows = rootStatRows(makeComputedStore().results.value, fmt);

        expect(values(rows, 'total')).toEqual([200000, 90000, 67500]);
    });

    it('reads the totals from the engine rather than recomputing them', () => {
        const results = makeComputedStore().results.value;
        const rows = rootStatRows(results, fmt);

        expect(values(rows, 'total')).toEqual([
            results.inherent.total,
            results.current.total,
            results.target.total,
        ]);
    });

    it('shows a dash, never a zero, for the base column reducing against itself', () => {
        const rows = rootStatRows(makeComputedStore().results.value, fmt);

        expect(row(rows, 'reduction').cells[0]).toEqual({
            kind: 'text',
            value: '—',
        });
        expect(row(rows, 'reduction-percent').cells[0]).toEqual({
            kind: 'text',
            value: '—',
        });
    });

    it('states both reductions against the base, in currency and in percent', () => {
        const rows = rootStatRows(makeComputedStore().results.value, fmt);

        expect(values(rows, 'reduction').slice(1)).toEqual([110000, 132500]);
        expect(values(rows, 'reduction-percent').slice(1)).toEqual([
            110000 / 200000,
            132500 / 200000,
        ]);
    });

    it('says what completing the plan would buy, since no column can show it', () => {
        const note = plannedImprovementNote(
            makeComputedStore().results.value,
            fmt,
        );

        expect(note).toContain('€22500');
        expect(note).toContain('25%');
    });

    it('says nothing about the plan when there is nothing planned to gain', () => {
        const results = makeComputedStore().results.value;
        results.target.total = results.current.total;

        expect(plannedImprovementNote(results, fmt)).toBeNull();
    });

    it('reports coverage as entered pairs out of total pairs', () => {
        const note = completenessNote(makeComputedStore().results.value);

        expect(note).toContain('1 of 1');
    });
});

describe('risk statistics', () => {
    it('gives the risk its ALE per baseline, from the engine', () => {
        const results = makeComputedStore().results.value;
        const rows = riskStatRows(results, 'risk1', fmt);

        expect(values(rows, 'ale')).toEqual([
            results.inherent.risks.find((r) => r.uuid === 'risk1')!.ale,
            results.current.risks.find((r) => r.uuid === 'risk1')!.ale,
            results.target.risks.find((r) => r.uuid === 'risk1')!.ale,
        ]);
        expect(values(rows, 'ale')).toEqual([200000, 90000, 67500]);
    });

    it('is the whole assessment, so its share is 100% in every baseline', () => {
        const rows = riskStatRows(
            makeComputedStore().results.value,
            'risk1',
            fmt,
        );

        expect(values(rows, 'share')).toEqual([1, 1, 1]);
    });

    it('leaves the share blank rather than 0% when there is no total', () => {
        const results = makeComputedStore().results.value;
        results.current.total = 0;

        const rows = riskStatRows(results, 'risk1', fmt);
        expect(row(rows, 'share').cells[1].value).toBeNull();
    });
});

describe('attack statistics', () => {
    it('shows the entered likelihood beside the residual it becomes', () => {
        const rows = attackStatRows(
            makeComputedStore().results.value,
            'attack1',
        );

        // Entered is baseline-independent and repeats down the row on
        // purpose: the comparison that matters is vertical, within a column.
        expect(values(rows, 'aro-base')).toEqual([2, 2, 2]);
        expect(values(rows, 'aro-residual')).toEqual([2, 0.9, 0.675]);
    });

    it('takes the residual from the engine, not from a re-multiplication', () => {
        const results = makeComputedStore().results.value;
        const rows = attackStatRows(results, 'attack1');

        expect(values(rows, 'aro-residual')).toEqual([
            results.inherent.attacks.find((a) => a.uuid === 'attack1')!
                .aroResidual,
            results.current.attacks.find((a) => a.uuid === 'attack1')!
                .aroResidual,
            results.target.attacks.find((a) => a.uuid === 'attack1')!
                .aroResidual,
        ]);
    });
});

describe('defence statistics', () => {
    const results = () => makeComputedStore().results.value;

    it('separates "if switched off" from "if done" instead of collapsing them', () => {
        const implemented = defenceStatRows(results(), 'mfa', 'implemented');
        const planned = defenceStatRows(results(), 'training', 'todo');

        // An implemented defence has a removal delta and no addition delta;
        // a todo one has the reverse against current. The node card's old
        // `mc ?? mcAdd` chip answered whichever applied without saying which.
        expect(row(implemented, 'mc').cells[1].value).not.toBeNull();
        expect(row(implemented, 'mc-add').cells[1].value).toBeNull();

        expect(row(planned, 'mc').cells[1].value).toBeNull();
        expect(row(planned, 'mc-add').cells[1].value).not.toBeNull();
    });

    it('explains, per column, why a planned defence is not counting yet', () => {
        const rows = defenceStatRows(results(), 'training', 'todo');
        const [inherent, current, target] = row(rows, 'mc').cells;

        expect(inherent.note).toContain('before any defence');
        expect(current.note).toContain('Planned, not implemented');
        expect(target.note).toBeUndefined();
    });

    it('credits a shelved defence with nothing at all, in every baseline', () => {
        const rows = defenceStatRows(results(), 'airgap', 'shelved');

        // null, not 0: "was credited nothing" and "was never eligible" are
        // different answers (calculation-engine.md §9.5).
        expect(values(rows, 'attribution')).toEqual([null, null, null]);
        expect(row(rows, 'mc').cells[1].note).toContain('Shelved');
    });

    it('leaves value-for-money blank for an unpriced defence, never 0x or infinity', () => {
        const rows = defenceStatRows(results(), 'review', 'implemented');

        expect(values(rows, 'cbr')).toEqual([null, null, null]);
        expect(defenceCostBucketNote(results(), 'review')).toContain(
            'No cost has been estimated',
        );
    });

    it('amortises the one-off cost over the assessment period', () => {
        const store = makeComputedStore();
        const lines = defenceCostLines(
            store.results.value,
            'mfa',
            store.nodes.mfa,
            store.assessment.amortization_years,
        );

        expect(lines.map((l) => [l.key, l.cell.value])).toEqual([
            ['opex', 1000],
            ['capex', 3000],
            // 1,000 recurring + 3,000 spread over three years.
            ['annual', 2000],
        ]);
        expect(lines[2].hint).toContain('3 years');
    });

    it('renders a cost-benefit ratio as a ratio, not as money or a percentage', () => {
        const rows = defenceStatRows(results(), 'mfa', 'implemented');
        const cbr = row(rows, 'cbr').cells[1];

        expect(cbr.kind).toBe('text');
        expect(cbr.value).toMatch(/^\d+\.\d\d×$/);
    });
});
