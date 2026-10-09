/**
 * `statistics.spec.ts` pins the figures. This file pins the only two things
 * the rendering can independently get wrong: which column a number lands in,
 * and what a missing number looks like.
 *
 * Column order matters more than it looks. Every "reduction vs base" figure is
 * read against the column to its left, and a table that silently rendered
 * target-current-inherent would still show three correct numbers.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import StatisticsTab from '../../../resources/js/Components/NodeModal/tabs/StatisticsTab.vue';
import { makeComputedStore, SCALE_LEVELS, SCALES } from './fixture';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { scaleLevels: SCALE_LEVELS, scales: SCALES } }),
}));

function render(uuid: string) {
    return mount(StatisticsTab, {
        props: { store: makeComputedStore(), uuid },
    });
}

function cells(wrapper: ReturnType<typeof render>, row: string): string[] {
    return wrapper
        .findAll(`[data-stat-row="${row}"] td`)
        .map((cell) => cell.text());
}

describe('the three baselines, side by side', () => {
    it('heads the columns with the engine’s own vocabulary, in order', () => {
        const headers = render('root')
            .findAll('thead th')
            .map((th) => th.text())
            .filter((text) => text !== 'Figure');

        expect(headers).toEqual(['Inherent', 'Current', 'Target']);
    });

    it('puts each figure under the baseline it belongs to', () => {
        const wrapper = render('root');
        const columns = wrapper
            .findAll('[data-stat-row="total"] td')
            .map((cell) => cell.attributes('data-baseline'));

        expect(columns).toEqual(['inherent', 'current', 'target']);
        expect(cells(wrapper, 'total').map((text) => text.trim())).toEqual([
            '€200,000',
            '€90,000',
            '€67,500',
        ]);
    });

    it('renders the assessment currency, not a hardcoded one', () => {
        const store = makeComputedStore();
        store.assessment.currency = 'GBP';

        const wrapper = mount(StatisticsTab, {
            props: { store, uuid: 'root' },
        });

        expect(cells(wrapper, 'total')[0]).toContain('£');
    });
});

describe('missing figures', () => {
    it('shows a shelved defence’s share of the reduction as ?, never €0', () => {
        const attribution = cells(render('airgap'), 'attribution');

        expect(attribution.every((text) => text.includes('?'))).toBe(true);
        expect(attribution.some((text) => text.includes('0'))).toBe(false);
    });

    it('leaves an unpriced defence’s value-for-money blank and says why', () => {
        const wrapper = render('review');

        expect(cells(wrapper, 'cbr').every((t) => t.includes('?'))).toBe(true);
        expect(wrapper.text()).toContain('No cost has been estimated');
    });
});

describe('what each type gets', () => {
    it('gives the root its totals and both reductions', () => {
        const rows = render('root')
            .findAll('[data-stat-row]')
            .map((row) => row.attributes('data-stat-row'));

        expect(rows).toEqual(['total', 'reduction', 'reduction-percent']);
    });

    it('gives an attack entered and residual likelihood, side by side', () => {
        const wrapper = render('attack1');

        expect(cells(wrapper, 'aro-base').map((t) => t.trim())).toEqual([
            '2.00/yr',
            '2.00/yr',
            '2.00/yr',
        ]);
        expect(cells(wrapper, 'aro-residual').map((t) => t.trim())).toEqual([
            '2.00/yr',
            '0.90/yr',
            '0.68/yr',
        ]);
    });

    it('lists the attacks a defence applies to, with the strength on each', () => {
        const wrapper = render('mfa');

        expect(wrapper.find('[data-edge="attack1"]').text()).toContain(
            'Phishing',
        );
        expect(wrapper.find('[data-edge="attack1"]').text()).toContain(
            'Reduces likelihood by 50%',
        );
    });

    it('says a defence linked to nothing cannot be evaluated yet', () => {
        const store = makeComputedStore();
        store.attackDefenceLinks.splice(0, store.attackDefenceLinks.length);
        const wrapper = mount(StatisticsTab, {
            props: { store, uuid: 'mfa' },
        });

        expect(wrapper.text()).toContain('Not linked to any attack');
    });

    it('gives a risk no cost section — cost is a defence’s property', () => {
        expect(render('risk1').find('[data-cost-line]').exists()).toBe(false);
    });
});
