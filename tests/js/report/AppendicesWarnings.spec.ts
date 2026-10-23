import AppendicesSection from '@/Components/Report/AppendicesSection.vue';
import type {
    ReportAppendices,
    ReportOptions,
} from '@/Components/Report/types';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { report } from './support/reportFixture';

/*
 * W136, D105. Leaving the warnings out is a choice, and a report without the
 * appendix must not read as "the model raised none": it says how many it left
 * out, and carries none of their text.
 */
const warning = {
    code: 'shared_attack',
    nodeUuid: 'n1',
    nodeTitle: 'Phishing',
    message: 'Contributes to 2 risks.',
};

function mountAppendices(
    options: Partial<ReportOptions>,
    appendices: Partial<ReportAppendices>,
) {
    return mount(AppendicesSection, {
        props: {
            options: { ...report().options, ...options },
            appendices: {
                references: [],
                comments: [],
                warnings: [],
                warningsOmitted: 0,
                ...appendices,
            },
        },
    });
}

describe('the warnings appendix', () => {
    it('lists the warnings under their own letter when included', () => {
        const wrapper = mountAppendices(
            { includeWarnings: true },
            { warnings: [warning] },
        );

        expect(wrapper.text()).toContain('Model-quality warnings');
        expect(wrapper.text()).toContain('Contributes to 2 risks.');
        expect(wrapper.find('[data-testid="warnings-omitted"]').exists()).toBe(
            false,
        );
    });

    it('states how many it left out, and nothing of them, when switched off', () => {
        const wrapper = mountAppendices(
            { includeWarnings: false },
            { warningsOmitted: 3 },
        );

        expect(wrapper.get('[data-testid="warnings-omitted"]').text()).toBe(
            '3 model-quality warnings were left out of this report.',
        );
        expect(wrapper.text()).not.toContain('Model-quality warnings');
    });

    it('says nothing when it is off and the engine raised none', () => {
        const wrapper = mountAppendices(
            { includeWarnings: false },
            { warningsOmitted: 0 },
        );

        expect(wrapper.find('[data-testid="warnings-omitted"]').exists()).toBe(
            false,
        );
    });

    it('keeps the appendix letters contiguous when warnings are off', () => {
        const wrapper = mountAppendices(
            {
                includeWarnings: false,
                includeComments: true,
                includeMethodology: true,
            },
            { warningsOmitted: 1 },
        );

        expect(wrapper.text()).toContain('A. References');
        expect(wrapper.text()).toContain('B. Comments');
        expect(wrapper.text()).toContain('C. Methodology and formulas');
        expect(wrapper.text()).toContain(
            '1 model-quality warning was left out of this report.',
        );
    });
});

describe('the methodology note', () => {
    it('says the appendix is not included when it is switched off, and only then', () => {
        const off = mountAppendices({ includeMethodology: false }, {});
        expect(off.get('[data-testid="methodology-omitted"]').text()).toBe(
            'The methodology appendix is not included in this report.',
        );

        const on = mountAppendices({ includeMethodology: true }, {});
        expect(on.find('[data-testid="methodology-omitted"]').exists()).toBe(
            false,
        );
    });
});
