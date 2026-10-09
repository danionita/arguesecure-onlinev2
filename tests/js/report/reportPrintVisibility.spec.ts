/**
 * Code-review finding, W83 fix round: the authenticated branch's options
 * row (search, baseline/shelved, comments/methodology/assignees, zoom) was
 * hidden at print time only through `data-print-hide`, which `print.css`
 * scopes as `.editor-print [data-print-hide]` — a class
 * `AuthenticatedLayout.vue`'s `inAssessment` regex (`/^\/assessments\/[^/]+/`)
 * happens to also match the report route. That made the hiding work by an
 * accident of two files agreeing, with nothing asserting the dependency: a
 * future tightening of `inAssessment` to exclude non-editor assessment
 * routes would silently reprint the whole row onto every authenticated
 * user's printed report (ui.md §9: print hides all navigation, actions and
 * the options row).
 *
 * This test does not evaluate `@media print` — jsdom cannot — it asserts
 * the structural fact `print.css` actually depends on: the DOM element
 * wrapping the options row carries `no-print` **directly**, so hiding it
 * does not need `.editor-print` (or any other ancestor-scoped class) to be
 * present at all. `.closest('.no-print')` from the toolbar row finds the
 * *nearest* such ancestor, which is this wrapper specifically — not the
 * header band, which is a sibling of it, not an ancestor.
 */
import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import type { ReportData } from '../../../resources/js/Components/Report/types';

let mockUser: { id: number; name: string; email: string } | null = {
    id: 1,
    name: 'Dana',
    email: 'dana@example.com',
};

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Head: defineComponent({ setup: () => () => null }),
        Link: defineComponent({
            props: { href: { type: String, default: '' } },
            setup:
                (props, { slots }) =>
                () =>
                    h('a', { href: props.href }, slots.default?.()),
        }),
        router: { get: vi.fn(), visit: vi.fn() },
        usePage: () => ({ props: { auth: { user: mockUser }, nav: {} } }),
        useForm: () => ({}),
    };
});

const route = (name: string, uuid: string) => `/assessments/${uuid}/${name}`;

beforeAll(() => {
    (globalThis as unknown as { route: typeof route }).route = route;

    (globalThis as unknown as { matchMedia: unknown }).matchMedia = () => ({
        matches: false,
        addEventListener: () => {},
        removeEventListener: () => {},
    });

    window.print = () => {};
});

function report(): ReportData {
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
            includeMethodology: true,
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
        appendices: { references: [], comments: [], warnings: [] },
    };
}

async function mountReport() {
    const { default: ReportShow } =
        await import('../../../resources/js/Pages/Report/Show.vue');

    return mount(ReportShow, {
        props: {
            assessment: { uuid: 'u' },
            abilities: { manage: true },
            report: report(),
        },
        global: {
            config: { globalProperties: { route } },
            stubs: { SummaryNarrative: true, teleport: true },
        },
    });
}

describe('print-time visibility of the authenticated options row', () => {
    it('the toolbar row has a no-print ancestor that carries the class directly, not through .editor-print', async () => {
        mockUser = { id: 1, name: 'Dana', email: 'dana@example.com' };
        const wrapper = await mountReport();

        const row = wrapper.get('[data-testid="toolbar-row"]').element;
        const noPrintAncestor = row.closest('.no-print');

        expect(noPrintAncestor).not.toBeNull();
        // It is a direct structural ancestor carrying the class itself —
        // not the header band (a sibling, not an ancestor, of the toolbar
        // row) and not dependent on any `.editor-print` wrapper existing.
        expect(noPrintAncestor?.classList.contains('no-print')).toBe(true);
    });

    it('the same holds for a guest (already explicit before this fix, unchanged)', async () => {
        mockUser = null;
        const wrapper = await mountReport();

        const row = wrapper.get('[data-testid="toolbar-row"]').element;
        expect(row.closest('.no-print')).not.toBeNull();
    });
});
