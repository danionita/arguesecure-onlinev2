/**
 * D39 — the report keeps the sidebar for an authenticated user and renders
 * bare for a share-link guest, chosen on whether `auth()->user()` exists.
 * `Pages/Report/Show.vue`'s file header explains why: that layout requires
 * a `{name,email}` user, and the report's audience includes anonymous
 * guests it has none for.
 *
 * Both branches are asserted here by the presence/absence of the sidebar's
 * own landmark (`nav[aria-label="Main"]`, `Components/Nav/Sidebar.vue`) —
 * the brief calls out the guest path as "the one that will be forgotten...
 * invisible until a share link is opened by someone outside the
 * organisation," so it gets its own assertion rather than being inferred
 * from something else passing.
 *
 * The same branch governs `AssessmentActions` (D40): mounted for an
 * authenticated viewer, not mounted at all for a guest. See Show.vue's file
 * header for why — `History` has no ability gate and sits behind `auth`
 * middleware, so offering it to a guest would be a broken link, not a
 * degraded one.
 */
import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import type { ReportData } from '../../../resources/js/Components/Report/types';

let mockUser: { id: number; name: string; email: string } | null = null;

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

describe('D39 — the report chrome by auth state', () => {
    it('renders the sidebar landmark for an authenticated user', async () => {
        mockUser = { id: 1, name: 'Dana', email: 'dana@example.com' };
        const wrapper = await mountReport();

        expect(wrapper.find('nav[aria-label="Main"]').exists()).toBe(true);
    });

    it('renders no sidebar landmark for a share-link guest', async () => {
        mockUser = null;
        const wrapper = await mountReport();

        expect(wrapper.find('nav[aria-label="Main"]').exists()).toBe(false);
    });

    it('mounts AssessmentActions for an authenticated user', async () => {
        mockUser = { id: 1, name: 'Dana', email: 'dana@example.com' };
        const wrapper = await mountReport();

        expect(
            wrapper.find('[data-testid="assessment-actions"]').exists(),
        ).toBe(true);
        // History has no ability gate anywhere it is rendered (D41) — it is
        // reachable for the authenticated viewer this bar was mounted for.
        expect(wrapper.text()).toContain('History');
    });

    it('does not mount AssessmentActions for a guest, but keeps Export and Print reachable', async () => {
        mockUser = null;
        const wrapper = await mountReport();

        expect(
            wrapper.find('[data-testid="assessment-actions"]').exists(),
        ).toBe(false);
        // assessments.history.index sits behind `auth` middleware
        // (routes/web.php) — a guest offered that link would be bounced to
        // a login page for an assessment they were never asked to sign in
        // to see, so it must not appear anywhere on a guest's page.
        expect(wrapper.text()).not.toContain('History');
        expect(wrapper.text()).toContain('Export');
        expect(wrapper.text()).toContain('Print');
    });
});
