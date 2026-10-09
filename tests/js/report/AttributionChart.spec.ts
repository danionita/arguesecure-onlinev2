/**
 * The "share of reduction" chart must be built from log-share attribution,
 * never marginal contribution (calculation-engine.md §9.5, ui.md §9): MC
 * removal deltas are non-additive and would not sum to 100% of the bar.
 * These assertions exercise the component's own segment math — share
 * proportions summing to 1, and defences outside the baseline (attribution
 * === null) being excluded rather than treated as a zero share.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import AttributionChart from '../../../resources/js/Components/Report/AttributionChart.vue';
import type { DefenceRankingRow } from '../../../resources/js/Components/Report/types';

function row(overrides: Partial<DefenceRankingRow> = {}): DefenceRankingRow {
    return {
        uuid: crypto.randomUUID(),
        title: 'Defence',
        isTransfer: false,
        status: 'implemented',
        benefit: null,
        mc: null,
        mcAdd: null,
        attribution: null,
        annualCost: null,
        cbr: null,
        rosi: null,
        netBenefit: null,
        costBucket: 'unpriced',
        ...overrides,
    };
}

describe('AttributionChart', () => {
    it('renders one segment per attributed defence, proportioned to its share of the total reduction', () => {
        // Mirrors calculation-engine.md §14's golden vector: D1 72,343.40,
        // D2 49,156.60, summing to the 121,500 inherent-to-current reduction.
        //
        // Written to the precision a double actually keeps. The vector was
        // originally pasted with three more digits than IEEE-754 can hold, so
        // the literals claimed an accuracy the runtime silently discarded —
        // harmless here, because the assertion is on the rounded percentages,
        // and worth removing anyway in a file whose job is to mirror the
        // engine's reference figures exactly. They still sum to 121,500.
        const rows = [
            row({
                title: 'MFA on admin accounts',
                attribution: 72343.39544877793,
            }),
            row({ title: 'Cyber insurance', attribution: 49156.604551222066 }),
        ];

        const wrapper = mount(AttributionChart, { props: { rows } });

        expect(wrapper.text()).toContain('MFA on admin accounts');
        expect(wrapper.text()).toContain('Cyber insurance');
        // 72343.4 / 121500 ≈ 59.5%, 49156.6 / 121500 ≈ 40.5%
        expect(wrapper.text()).toContain('60%');
        expect(wrapper.text()).toContain('40%');
    });

    it('excludes a defence with null attribution rather than charting it as a zero share', () => {
        const rows = [
            row({ title: 'Implemented, attributed', attribution: 10000 }),
            // null === "not eligible" (todo/shelved against this baseline),
            // never a silent 0 (calculation-engine.md §9.5).
            row({
                title: 'Todo, not attributed',
                attribution: null,
                status: 'todo',
            }),
        ];

        const wrapper = mount(AttributionChart, { props: { rows } });

        expect(wrapper.text()).toContain('Implemented, attributed');
        expect(wrapper.text()).not.toContain('Todo, not attributed');
    });

    it('shows a fallback message when nothing in the baseline attributes a measured reduction', () => {
        const wrapper = mount(AttributionChart, {
            props: { rows: [row({ attribution: null })] },
        });

        expect(wrapper.text()).toContain(
            'No implemented defences currently attribute a measured reduction.',
        );
    });
});
