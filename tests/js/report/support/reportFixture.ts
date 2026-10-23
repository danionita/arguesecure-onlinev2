import type { ReportData } from '../../../../resources/js/Components/Report/types';

/** A minimal assembled report: one of everything the page renders, all empty. */
export function report(): ReportData {
    const total = {
        baseline: 'current' as const,
        total: 0,
        isPartial: false,
        pairsValued: 0,
        pairsTotal: 0,
        coverage: 1,
    };

    return {
        assessment: {
            uuid: 'u',
            currency: 'EUR',
            amortizationYears: 3,
            riskTolerance: null,
            isPublic: false,
        },
        options: {
            baseline: 'both',
            includeShelved: false,
            includeComments: false,
            includeMethodology: false,
            includeWarnings: false,
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
            completeness: total,
        },
        executiveSummary: {
            inherent: { ...total, baseline: 'inherent' },
            current: total,
            target: { ...total, baseline: 'target' },
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
        tree: { risks: [], orphanAttacks: [], orphanDefences: [] },
        appendices: {
            references: [],
            comments: [],
            warnings: [],
            warningsOmitted: 0,
        },
    };
}
