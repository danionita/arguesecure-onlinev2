import { describe, expect, it } from 'vitest';
import type {
    ReportData,
    ReportTree,
} from '../../../resources/js/Components/Report/types';
import { useReportSearch } from '../../../resources/js/composables/useReportSearch';

/**
 * W83. Mirrors tests/js/composables/useSearch.spec.ts's shape — this
 * composable exists so SearchBox.vue (reused unmodified) has something
 * `UseSearchReturn`-compatible to bind to on a page with no AssessmentStore.
 * See useReportSearch.ts's file header for why a store is not built just to
 * get one.
 */

function tree(): ReportTree {
    return {
        risks: [
            {
                uuid: 'risk-1',
                title: 'Payment fraud',
                description: 'Card-not-present fraud on checkout',
                impactMode: null,
                impactAmount: null,
                assignees: [],
                inherent: null,
                current: null,
                target: null,
                attacks: [
                    {
                        uuid: 'attack-shared',
                        title: 'Phishing the finance team',
                        description: 'Credential theft via email',
                        likelihoodMode: null,
                        likelihoodAro: null,
                        assignees: [],
                        inherent: null,
                        current: null,
                        target: null,
                        defences: [
                            {
                                uuid: 'defence-1',
                                title: 'MFA on finance accounts',
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
            {
                uuid: 'risk-2',
                title: 'Data breach',
                description: null,
                impactMode: null,
                impactAmount: null,
                assignees: [],
                inherent: null,
                current: null,
                target: null,
                attacks: [
                    {
                        // Same attack, a second edge — AssessmentTreeSection
                        // prints it once per parent risk, not deduplicated.
                        uuid: 'attack-shared',
                        title: 'Phishing the finance team',
                        description: 'Credential theft via email',
                        likelihoodMode: null,
                        likelihoodAro: null,
                        assignees: [],
                        inherent: null,
                        current: null,
                        target: null,
                        defences: [],
                    },
                ],
            },
        ],
        orphanAttacks: [],
        orphanDefences: [],
    };
}

function report(): ReportData {
    return {
        assessment: {
            uuid: 'u',
            currency: 'EUR',
            amortizationYears: 3,
            riskTolerance: null,
            isPublic: false,
        },
        options: {
            baseline: 'current',
            includeShelved: false,
            includeComments: false,
            includeMethodology: true,
            includeWarnings: true,
            omittedSections: [],
            showAssignees: false,
            download: false,
        },
        writtenSummary: { text: null, generated: false },
        cover: {
            title: 'Payment platform',
            description: null,
            owner: null,
            generatedAt: '2026-01-01T00:00:00+00:00',
            scaleCalibration: [],
            completeness: {
                baseline: 'current',
                total: 0,
                isPartial: false,
                pairsValued: 0,
                pairsTotal: 0,
                coverage: 1,
            },
        },
        executiveSummary: {
            inherent: {
                baseline: 'inherent',
                total: 0,
                isPartial: false,
                pairsValued: 0,
                pairsTotal: 0,
                coverage: 1,
            },
            current: {
                baseline: 'current',
                total: 0,
                isPartial: false,
                pairsValued: 0,
                pairsTotal: 0,
                coverage: 1,
            },
            target: {
                baseline: 'target',
                total: 0,
                isPartial: false,
                pairsValued: 0,
                pairsTotal: 0,
                coverage: 1,
            },
            reductionCurrent: null,
            reductionTarget: null,
            annualCostOfControls: {
                priced: 0,
                free: 0,
                unpricedCount: 0,
                implementedCount: 0,
            },
        },
        programme: {
            planned: {
                annualisedCost: 0,
                oneOffCost: 0,
                recurringCost: 0,
                count: 0,
                freeCount: 0,
                unpricedCount: 0,
            },
            totals: { inherent: 0, current: 0, target: 0 },
            riskReduced: {
                inherentToCurrent: 0,
                currentToTarget: 0,
                inherentToTarget: 0,
            },
            returnOnPlanned: null,
        },
        riskRegister: [],
        defencesByImportance: { priced: [], free: [], unpriced: [] },
        defencesByOpportunity: { priced: [], free: [], unpriced: [] },
        shelvedOpportunity: { priced: [], free: [], unpriced: [] },
        transfers: [],
        controlCoverage: { frameworks: [], hiddenShelved: 0 },
        tree: tree(),
        appendices: { references: [], comments: [], warnings: [] },
    };
}

describe('useReportSearch', () => {
    it('finds nothing in a tree the report omits (W139)', () => {
        const omitted = () => {
            const r = report();
            r.options.omittedSections = ['tree'];

            return r;
        };
        const search = useReportSearch(omitted);
        search.query.value = 'payment fraud';
        expect(search.matchingUuids.value.size).toBe(0);
        expect(search.matches.value).toEqual([]);
    });

    it('matches on a risk title', () => {
        const search = useReportSearch(report);
        search.query.value = 'payment fraud';
        expect(search.matchingUuids.value).toEqual(new Set(['risk-1']));
    });

    it('matches on a defence description and an attack description alike', () => {
        const search = useReportSearch(report);
        search.query.value = 'credential theft';
        expect(search.matchingUuids.value.has('attack-shared')).toBe(true);
    });

    it('is case-insensitive and trims whitespace', () => {
        const search = useReportSearch(report);
        search.query.value = '  PHISHING  ';
        expect(search.matchingUuids.value.has('attack-shared')).toBe(true);
    });

    it('produces one match per rendered edge, not per node — matching AssessmentTreeSection', () => {
        const search = useReportSearch(report);
        search.query.value = 'phishing'; // attack-shared, printed under both risks
        expect(search.matches.value).toHaveLength(2);
        expect(search.matches.value.map((m) => m.pathKey)).toEqual([
            'risk-1/attack-shared',
            'risk-2/attack-shared',
        ]);
    });

    it('an empty query matches nothing', () => {
        const search = useReportSearch(report);
        search.query.value = '';
        expect(search.matches.value).toEqual([]);
        expect(search.activeMatch.value).toBeNull();
    });

    it('next()/previous() step through matches and wrap around', () => {
        const search = useReportSearch(report);
        search.query.value = 'phishing';
        expect(search.matches.value).toHaveLength(2);

        expect(search.activeIndex.value).toBe(0);
        search.next();
        expect(search.activeIndex.value).toBe(1);
        search.next();
        expect(search.activeIndex.value).toBe(0);

        search.previous();
        expect(search.activeIndex.value).toBe(1);
    });

    it('resets the active index when the query changes', () => {
        const search = useReportSearch(report);
        search.query.value = 'phishing';
        search.next();
        expect(search.activeIndex.value).toBe(1);

        search.query.value = 'fraud';
        expect(search.activeIndex.value).toBe(0);
    });

    it('clear() empties the query and resets position', () => {
        const search = useReportSearch(report);
        search.query.value = 'phishing';
        search.next();
        search.clear();
        expect(search.query.value).toBe('');
        expect(search.activeIndex.value).toBe(0);
    });

    it('exposes an inputEl ref and a focus() function, like useSearch', () => {
        const search = useReportSearch(report);
        const input = document.createElement('input');
        document.body.appendChild(input);
        search.inputEl.value = input;
        search.focus();
        expect(document.activeElement).toBe(input);
        document.body.removeChild(input);
    });
});
