/**
 * The chrome above the report (rebuilt on the shared chrome): where the
 * switcher sits, and how the options are grouped.
 *
 * jsdom has no layout engine — `getBoundingClientRect()` is all zeroes, so a
 * bar that had drifted 200px would look identical to one that had not. The
 * pixel claim is therefore asserted in a real browser, in
 * `tests/e2e/10-report.spec.ts`. What is guarded here is the *structure* that
 * produces it.
 *
 * That used to mean asserting the report's hand-made copy carried the
 * editor toolbar's classes — which is exactly the check that cannot notice a
 * padding the copy never had, and did not: the report's switcher shipped 6px
 * above the editor's. The copy was then replaced with
 * `Components/Chrome/*`, which both pages render, so the assertion below is a different one: the report's
 * band and row are the *same elements* the editor's toolbar produces, class
 * for class, compared against a real mount of `Toolbar.vue` rather than
 * against a string copied out of it. Re-inlining the markup in either page
 * turns it red.
 */
import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import Toolbar from '../../../resources/js/Components/Toolbar/Toolbar.vue';
import {
    createEditorView,
    EDITOR_VIEW_KEY,
} from '../../../resources/js/composables/useEditorView';
import ReportShow from '../../../resources/js/Pages/Report/Show.vue';
import { makeFixtureStore } from '../composables/support/fixture';
import { routeProperties } from '../support/routeProperties';
import { report } from './support/reportFixture';

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
        usePage: () => ({ props: { auth: { user: { id: 1 } } } }),
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

    // jsdom has no print pipeline, and the page calls window.print() on
    // mount in download mode. Stubbing it keeps the "Not implemented" noise
    // out of the run without hiding anything this file asserts.
    window.print = () => {};
});

function mountReport(options: Record<string, unknown> = {}) {
    const base = report();

    return mount(ReportShow, {
        props: {
            assessment: { uuid: 'u' },
            report: { ...base, options: { ...base.options, ...options } },
        },
        global: {
            config: { globalProperties: routeProperties(route) },
            stubs: { SummaryNarrative: true, teleport: true },
        },
    });
}

/** The editor's toolbar, mounted for comparison rather than described. */
function mountEditorToolbar() {
    const store = makeFixtureStore();
    const view = createEditorView(store, { assessmentUuid: 'a1', userId: 1 });

    return mount(Toolbar, {
        props: { reportHref: '/assessments/a1/report' },
        global: {
            provide: { [EDITOR_VIEW_KEY as symbol]: view },
            stubs: { teleport: true },
        },
    });
}

describe('the report chrome', () => {
    it('puts the switcher in the right-anchored group of a toolbar row, as the editor does', () => {
        const wrapper = mountReport();
        const row = wrapper.get('[data-testid="toolbar-row"]');

        // `ml-auto` on a shrink-0 group is what anchors the switcher to the
        // row's right edge — the only edge the two views share, since the
        // editor's row is inset by the sidebar gutter and this one is not.
        expect(row.classes()).toContain('flex');
        expect(row.classes()).toContain('items-center');

        const group = row.get('.ml-auto');
        expect(group.classes()).toContain('shrink-0');
        expect(group.html()).toContain('Report');
        expect(group.html()).toContain('Chart');
        expect(group.html()).toContain('List');
    });

    /**
     * The vertical position of the switcher is the sum of every padding
     * above it, and the report's old copy of those paddings was missing one. Comparing
     * the report's band and row against a real mount of the editor's toolbar
     * is the only jsdom-reachable statement of "there is one definition":
     * a hand-written class list can be wrong in a way no string assertion
     * about itself will ever catch.
     */
    it('renders the same band and row the editor toolbar does, class for class', () => {
        const report = mountReport();
        const editor = mountEditorToolbar();

        const reportRow = report.get('[data-testid="toolbar-row"]');
        const editorRow = editor.get('[data-testid="toolbar-row"]');

        // The row: the inner padding, which is the one the old copy did have.
        expect(reportRow.classes().sort()).toEqual(editorRow.classes().sort());

        // The band around it: the outer padding, which lived in
        // Pages/Assessment/Show.vue and is the one the copy did not have.
        const reportBand = reportRow.element.parentElement!;
        const editorBand = editorRow.element.parentElement!;
        expect([...reportBand.classList].sort()).toEqual(
            [...editorBand.classList].sort(),
        );

        // Not vacuous: the paddings that have to agree are actually there.
        expect(reportRow.classes()).toContain('py-1.5');
        expect([...reportBand.classList]).toContain('py-3');
    });

    /**
     * The horizontal half of ui.md §9. The report had nothing to zoom and
     * the editor does, so the two switchers sat at different
     * distances from the window's right edge. Making the switcher the last
     * control in the row closes that without reserving an empty spacer the
     * width of the zoom controls — which was rejected, rightly: a spacer
     * asserts the fix, and a test measuring one passes for the wrong reason.
     *
     * The distance itself is measured in a real browser
     * (`tests/e2e/10-report.spec.ts`); what is asserted here is the ordering
     * that makes it independent of the siblings, in both pages at once.
     *
     * The report has its own zoom, so its trailing control is no
     * longer "Close" (the options row no longer opens or closes at all —
     * see ReportOptionsRow.vue) — it is `ReportZoomControls`, reading "100%"
     * the same way the editor's own zoom does. Both rows now carry a zoom
     * percentage; what this test still asserts is that neither is last.
     */
    it('ends both rows with the switcher, whatever each page puts beside it', () => {
        const report = mountReport();
        const editor = mountEditorToolbar();

        const last = (wrapper: {
            get: ReturnType<typeof mountReport>['get'];
        }) => {
            const group = wrapper.get('[data-testid="toolbar-row"] .ml-auto');
            const children = Array.from(group.element.children);

            return children[children.length - 1];
        };

        // Both rows carry a zoom percentage as their other trailing control.
        expect(report.get('.ml-auto').text()).toContain('100%');
        expect(editor.get('.ml-auto').text()).toContain('100%');

        // Neither zoom group is last — the switcher is, in both rows.
        expect(last(report).getAttribute('aria-label')).toBe('View');
        expect(last(report).textContent).not.toContain('100%');
        expect(last(editor).getAttribute('aria-label')).toBe('View');
        expect(last(editor).textContent).not.toContain('100%');
    });

    /*
     * Every report setting sits behind one of four buttons, each group under
     * a heading: Defences (what the numbers are computed from), Sections and
     * Appendices (what is added to the document) and Details (comments and
     * assignees). Nothing is inline, and there is no Filters button.
     */
    it('puts every setting behind a button, under headings', async () => {
        const wrapper = mountReport();
        const ids = [
            'report-content-button',
            'report-sections-button',
            'report-appendices-button',
            'report-details-button',
        ];

        expect(wrapper.text()).not.toContain('Filters');
        for (const t of [
            'option-choices',
            'option-sections',
            'appendices',
            'details',
        ]) {
            expect(wrapper.find(`[data-testid="report-${t}"]`).exists()).toBe(
                false,
            );
        }

        for (const id of ids) {
            await wrapper.get(`[data-testid="${id}"]`).trigger('click');
        }

        const choices = wrapper.get('[data-testid="report-option-choices"]');
        const sections = wrapper.get('[data-testid="report-option-sections"]');
        const appendices = wrapper.get('[data-testid="report-appendices"]');
        const details = wrapper.get('[data-testid="report-details"]');

        expect(choices.text()).toContain('Which defences the report covers');
        expect(choices.text()).toContain('Implemented defences');
        expect(choices.text()).toContain('Planned defences');
        expect(choices.text()).toContain('Shelved defences');
        expect(choices.findAll('[role="radiogroup"]')).toHaveLength(0);
        expect(wrapper.text()).not.toContain('Which state to report');

        // No figures control anywhere on the page.
        expect(wrapper.text()).not.toContain('Full figures');
        expect(wrapper.text()).not.toContain('Qualitative only');

        // Sections hold the body only; each row has a title and a description.
        expect(sections.text()).toContain('Risk register');
        expect(sections.text()).toContain('Every risk, its impact');
        expect(sections.text()).not.toContain('Methodology');
        expect(sections.text()).not.toContain('References');

        expect(appendices.text()).toContain('References');
        expect(appendices.text()).toContain('Methodology');
        expect(appendices.text()).toContain('Model-quality warnings');
        expect(appendices.text()).not.toContain('comments');

        expect(details.text()).toContain('Show comments');
        expect(details.text()).toContain('Show assignees');
        expect(details.findAll('[role="switch"]')).toHaveLength(2);
    });

    it('keeps a settings panel open while switches are flipped', async () => {
        const wrapper = mountReport();

        await wrapper
            .get('[data-testid="report-details-button"]')
            .trigger('click');
        await wrapper
            .get('[data-testid="detail-comments"] button')
            .trigger('click');
        await wrapper
            .get('[data-testid="detail-assignees"] button')
            .trigger('click');

        expect(wrapper.find('[data-testid="report-details"]').exists()).toBe(
            true,
        );
    });

    const ids = [
        'report-content-button',
        'report-sections-button',
        'report-appendices-button',
        'report-details-button',
    ];

    it('shows no count on any button, and the optional appendices start off', () => {
        const defaults = mountReport();
        for (const id of ids) {
            expect(defaults.get(`[data-testid="${id}"]`).text()).not.toMatch(
                /\d/,
            );
        }

        const changed = mountReport({
            baseline: 'current',
            includeShelved: true,
            includeComments: true,
            showAssignees: true,
            omittedSections: ['cover', 'tree'],
        });
        for (const id of ids) {
            expect(changed.get(`[data-testid="${id}"]`).text()).not.toMatch(
                /\d/,
            );
        }
    });

    it('carries one Export menu and no separate download or per-format buttons', () => {
        const wrapper = mountReport();
        const text = wrapper.text();

        expect(text).toContain('Export');

        // The button goes, and its name goes with it. "Print as
        // configured" drew a distinction against a Download button that no
        // longer exists.
        expect(text).not.toContain('Download report');
        expect(text).not.toContain('Print as configured');
        expect(text).not.toContain('Defences CSV');
        expect(text).not.toContain('Export JSON');
        expect(text).toContain('Print');
    });

    it('hides the whole options panel in download mode', () => {
        const data = report();
        data.options.download = true;

        const wrapper = mount(ReportShow, {
            props: { assessment: { uuid: 'u' }, report: data },
            global: {
                config: { globalProperties: routeProperties(route) },
                stubs: { SummaryNarrative: true, teleport: true },
            },
        });

        // Not merely `no-print`: the state it would show is not editable on
        // this path, so showing it would be showing a lie.
        expect(wrapper.find('[data-testid="toolbar-row"]').exists()).toBe(
            false,
        );
        expect(wrapper.text()).not.toContain('Report options');
    });
});
