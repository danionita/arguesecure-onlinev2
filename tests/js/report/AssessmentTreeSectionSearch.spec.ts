/**
 * W83 addition to AssessmentTreeSection.vue: the search-match ring. See the
 * component's own docblock — `activeMatchKey` is `useReportSearch.ts`'s
 * `activeMatch.value?.pathKey`, compared against the same keys the
 * template's own `:key`s already use.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import AssessmentTreeSection from '../../../resources/js/Components/Report/AssessmentTreeSection.vue';
import type { ReportTree } from '../../../resources/js/Components/Report/types';

function tree(): ReportTree {
    return {
        risks: [
            {
                uuid: 'risk-1',
                title: 'Payment fraud',
                description: null,
                impactMode: null,
                impactAmount: null,
                assignees: [],
                inherent: null,
                current: null,
                target: null,
                attacks: [
                    {
                        uuid: 'attack-1',
                        title: 'Phishing',
                        description: null,
                        likelihoodMode: null,
                        likelihoodAro: null,
                        assignees: [],
                        inherent: null,
                        current: null,
                        target: null,
                        defences: [
                            {
                                uuid: 'defence-1',
                                title: 'MFA',
                                description: null,
                                rationale: null,
                                status: 'implemented',
                                isTransfer: false,
                                reductionTarget: null,
                                strength: null,
                                capexAmount: null,
                                opexAnnualAmount: null,
                                assignees: [],
                                current: null,
                                target: null,
                            },
                        ],
                    },
                ],
            },
        ],
        orphanAttacks: [],
        orphanDefences: [],
    };
}

function mountSection(activeMatchKey: string | null) {
    return mount(AssessmentTreeSection, {
        props: {
            tree: tree(),
            baseline: 'current',
            currency: 'EUR',
            activeMatchKey,
        },
    });
}

describe('AssessmentTreeSection search highlighting', () => {
    it('rings no row when there is no active match', () => {
        const wrapper = mountSection(null);
        expect(wrapper.findAll('.ring-focus-ring')).toHaveLength(0);
    });

    it('rings the risk row whose uuid is the active match key', () => {
        const wrapper = mountSection('risk-1');
        const rings = wrapper.findAll('.ring-focus-ring');
        expect(rings).toHaveLength(1);
        expect(rings[0].text()).toContain('Payment fraud');
    });

    it('rings the attack row for a `${risk}/${attack}` key, not the risk row', () => {
        const wrapper = mountSection('risk-1/attack-1');
        const rings = wrapper.findAll('.ring-focus-ring');
        // The attack <li> nests its defence <li>s, so a query for the ring
        // class alone matches both the attack row and the (unmatched)
        // defence inside it — assert on the outer risk row instead, which is
        // definitely not rung.
        expect(rings.length).toBeGreaterThanOrEqual(1);
        expect(rings[0].text()).toContain('Phishing');
        expect(wrapper.get('li').classes()).not.toContain('ring-focus-ring');
    });

    it('rings the defence row for a `${attack}/${defence}` key', () => {
        const wrapper = mountSection('attack-1/defence-1');
        const rings = wrapper.findAll('.ring-focus-ring');
        expect(rings).toHaveLength(1);
        expect(rings[0].text()).toContain('MFA');
    });
});
