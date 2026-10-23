/**
 * D43, wired end to end: `Pages/Report/Show.vue` must actually call
 * `useCanvas.ts` with `view: 'report'`, not just the plain `assessmentUuid`
 * the chart and list use. `reportZoomKey.spec.ts` proves `useCanvas.ts`
 * itself keeps a `report`-view key separate from `chart`/`list`; this file
 * proves the page is the one passing `view: 'report'` — mounting the real
 * page, clicking the real zoom button, and reading the real
 * `window.localStorage` key it wrote to.
 */
import { mount } from '@vue/test-utils';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReportData } from '../../../resources/js/Components/Report/types';
import ReportShow from '../../../resources/js/Pages/Report/Show.vue';

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
        usePage: () => ({
            props: { auth: { user: { id: 1 } }, nav: {} },
        }),
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

beforeEach(() => {
    window.localStorage.clear();
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

function mountReport() {
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

describe('D43 — the report writes zoom to a view-namespaced key', () => {
    it('clicking the report zoom control writes a localStorage key for the "report" view, distinct from the chart/list key', async () => {
        const wrapper = mountReport();

        await wrapper
            .get('[aria-label="Increase report size"]')
            .trigger('click');

        const keys = Object.keys(window.localStorage).filter((k) =>
            k.startsWith('arguesecure:canvas-zoom:'),
        );

        expect(keys).toHaveLength(1);
        expect(keys[0]).toBe('arguesecure:canvas-zoom:report:1:u');
        // Not the key the chart/list read and write.
        expect(keys[0]).not.toBe('arguesecure:canvas-zoom:chart:1:u');
        expect(keys[0]).not.toBe('arguesecure:canvas-zoom:list:1:u');
    });

    it('the report body applies CSS zoom of exactly 1 (baseline 1.0) on first load — not the list baseline of 1.35', async () => {
        const wrapper = mountReport();

        // `ReportDocument.vue` applies `zoomStyle` to its own root `<main>`.
        // Both the authenticated and the guest chrome mount it (D39), but
        // only one branch is actually rendered — find the one that carries
        // a `style` attribute at all. At the default persisted zoom (1) it
        // must be exactly `CANVAS_BASE_SCALE.report` (1.0), not `.list`
        // (1.35) — reading the rendered inline style, not the composable
        // directly, so a wiring regression that pointed the page at the
        // wrong `CANVAS_BASE_SCALE` entry (or the wrong view) would show up
        // here too.
        const main = wrapper
            .findAll('main')
            .find((m) => m.attributes('style') !== undefined);
        expect(main).toBeDefined();
        expect(main!.attributes('style')).toContain('zoom: 1;');
        expect(main!.attributes('style')).not.toContain('zoom: 1.35');
    });
});
