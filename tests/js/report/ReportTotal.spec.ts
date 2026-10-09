/**
 * ReportTotal is where "never render a bare number for a partial
 * assessment" (calculation-engine.md §6.2.3, ui.md §9) actually gets
 * enforced on the frontend — asserting its behaviour directly, rather than
 * only through the PHP payload shape, is what would catch a future edit
 * that quietly drops the qualifier or the "≥" prefix.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ReportTotal from '../../../resources/js/Components/Report/ReportTotal.vue';
import type { TotalBlock } from '../../../resources/js/Components/Report/types';

function block(overrides: Partial<TotalBlock> = {}): TotalBlock {
    return {
        baseline: 'current',
        total: 65500,
        isPartial: false,
        pairsValued: 4,
        pairsTotal: 4,
        coverage: 1,
        ...overrides,
    };
}

describe('ReportTotal', () => {
    it('renders a fully-valued total plainly, with no coverage qualifier and no "at least" prefix', () => {
        const wrapper = mount(ReportTotal, { props: { block: block() } });

        expect(wrapper.text()).toContain('€65,500');
        expect(wrapper.text()).not.toContain('≥');
        expect(wrapper.text()).not.toContain('of');
    });

    it('prefixes a partial total with "≥" and states exact coverage', () => {
        const wrapper = mount(ReportTotal, {
            props: {
                block: block({
                    total: 53500,
                    isPartial: true,
                    pairsValued: 3,
                    pairsTotal: 4,
                    coverage: 0.75,
                }),
            },
        });

        expect(wrapper.text()).toContain('≥');
        expect(wrapper.text()).toContain('€53,500');
        expect(wrapper.text()).toContain('3 of 4 paths valued (75%)');
    });

    it('shows the baseline label only when asked to', () => {
        const withLabel = mount(ReportTotal, {
            props: {
                block: block({ baseline: 'target' }),
                showBaselineLabel: true,
            },
        });
        const withoutLabel = mount(ReportTotal, {
            props: { block: block({ baseline: 'target' }) },
        });

        expect(withLabel.text()).toContain('Target');
        expect(withoutLabel.text()).not.toContain('Target');
    });
});
