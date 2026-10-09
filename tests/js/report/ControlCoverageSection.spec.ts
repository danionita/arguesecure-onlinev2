/**
 * The coverage section on the report page (PLAN-2.7 D65, D66). The rule for
 * a control's status is the server's (ReportAssembler::controlCoverage);
 * what this pins is that the page says what the payload says and nothing
 * more: no section when nothing is cited, no "compliant", and a count of
 * what `include_shelved` left out instead of a silent gap.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ControlCoverageSection from '../../../resources/js/Components/Report/ControlCoverageSection.vue';
import type {
    ControlCoverage,
    CoverageFramework,
} from '../../../resources/js/Components/Report/types';

function nis2(): CoverageFramework {
    return {
        framework: 'NIS2',
        label: 'NIS2',
        url: 'https://eur-lex.europa.eu/eli/dir/2022/2555/oj',
        controls: [
            {
                identifier: 'NIS2:ART.21(2)(D)',
                label: 'Art. 21(2)(d)',
                status: 'implemented',
                defences: [
                    {
                        uuid: 'd1',
                        title: 'Supplier security clauses',
                        status: 'implemented',
                        isTransfer: false,
                        attackCount: 2,
                    },
                ],
            },
            {
                identifier: 'NIS2:ART.21(2)(J)',
                label: 'Art. 21(2)(j)',
                status: 'planned',
                defences: [
                    {
                        uuid: 'd2',
                        title: 'MFA for remote access',
                        status: 'todo',
                        isTransfer: false,
                        attackCount: 0,
                    },
                ],
            },
        ],
    };
}

function coverage(
    frameworks: CoverageFramework[],
    hiddenShelved = 0,
): ControlCoverage {
    return { frameworks, hiddenShelved };
}

describe('ControlCoverageSection', () => {
    it('renders nothing when no defence cites a control', () => {
        const wrapper = mount(ControlCoverageSection, {
            props: { coverage: coverage([]) },
        });

        expect(wrapper.find('section').exists()).toBe(false);
    });

    it('lists each cited control with its status and the defences citing it', () => {
        const wrapper = mount(ControlCoverageSection, {
            props: { coverage: coverage([nis2()]) },
        });
        const text = wrapper.text();

        expect(text).toContain('Control coverage');
        expect(text).toContain('2 cited · 1 implemented · 1 planned');
        expect(text).toContain('Art. 21(2)(d)');
        expect(text).toContain('Supplier security clauses');
        expect(text).toContain('Planned');
        expect(text).toContain('counters no attack');
        expect(text).toContain('not a statement of compliance');
        expect(text).not.toMatch(/\bcompliant\b/i);
        expect(wrapper.find('a').attributes('href')).toBe(
            'https://eur-lex.europa.eu/eli/dir/2022/2555/oj',
        );
    });

    it('says how many controls were left out with the shelved defences', () => {
        const wrapper = mount(ControlCoverageSection, {
            props: { coverage: coverage([nis2()], 2) },
        });

        expect(wrapper.text()).toContain(
            '2 controls cited only by shelved defences are not listed',
        );
    });

    it('still says so when every cited control was left out', () => {
        const wrapper = mount(ControlCoverageSection, {
            props: { coverage: coverage([], 1) },
        });

        expect(wrapper.find('section').exists()).toBe(true);
        expect(wrapper.find('article').exists()).toBe(false);
        expect(wrapper.text()).toContain(
            '1 control cited only by shelved defences is not listed',
        );
    });
});
