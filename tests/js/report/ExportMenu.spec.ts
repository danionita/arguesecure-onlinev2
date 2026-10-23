/**
 * The one export menu (D34, D35, W74 item 15).
 *
 * jsdom has no layout engine, so nothing here asserts geometry — that is
 * `tests/e2e/10-report.spec.ts`'s job. What is asserted here is the part that
 * regresses silently: that five formats are offered rather than three, that
 * every link carries the panel's current options, and that the forced-full
 * item still passes `download=1` after the button that used to has gone.
 */
import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it } from 'vitest';
import ExportMenu from '../../../resources/js/Components/Report/ExportMenu.vue';
import type { ReportOptions } from '../../../resources/js/Components/Report/types';

const UUID = '11111111-2222-3333-4444-555555555555';

beforeAll(() => {
    // Ziggy's `route()` is a global the app installs at boot. A stub that
    // echoes the route name keeps the assertions below about *which* route
    // each item points at, which is the thing that can drift.
    (globalThis as unknown as { route: unknown }).route = (
        name: string,
        uuid: string,
    ) => `/assessments/${uuid}/${name}`;
});

function options(overrides: Partial<ReportOptions> = {}): ReportOptions {
    return {
        baseline: 'both',
        includeShelved: false,
        includeComments: false,
        includeMethodology: true,
        includeWarnings: true,
        omittedSections: [],
        showAssignees: false,
        download: false,
        ...overrides,
    };
}

async function openMenu(props: { options: ReportOptions }) {
    const wrapper = mount(ExportMenu, {
        props: { assessmentUuid: UUID, options: props.options },
    });

    await wrapper.get('button').trigger('click');

    return wrapper;
}

describe('ExportMenu', () => {
    it('offers exactly the five formats D34 names, behind one trigger', async () => {
        const wrapper = await openMenu({ options: options() });

        const labels = wrapper
            .findAll('[role="menuitem"]')
            .map((item) => item.text());

        // Five formats plus the forced-full item. PDF is deliberately absent
        // — it is the browser print pipeline (D6), which is why dompdf stays
        // gone — and its absence is asserted rather than assumed.
        expect(labels).toHaveLength(6);
        expect(labels.join(' ')).toContain('Web page');
        expect(labels.join(' ')).toContain('Word document');
        expect(labels.join(' ')).toContain('Excel workbook');
        expect(labels.join(' ')).toContain('Spreadsheet data');
        expect(labels.join(' ')).toContain('Data interchange');
        expect(labels.join(' ')).not.toContain('PDF');
    });

    it('points each format at its own route', async () => {
        const wrapper = await openMenu({ options: options() });

        const hrefs = wrapper
            .findAll('[role="menuitem"]')
            .map((item) => item.attributes('href') ?? '');

        expect(hrefs.join(' ')).toContain('assessments.report.export-html');
        expect(hrefs.join(' ')).toContain('assessments.report.export-word');
        expect(hrefs.join(' ')).toContain('assessments.report.export-excel');
        expect(hrefs.join(' ')).toContain('assessments.report.defences-csv');
        expect(hrefs.join(' ')).toContain('assessments.report.export-json');
    });

    it('carries the panel’s current options into every export link', async () => {
        const wrapper = await openMenu({
            options: options({
                baseline: 'target',
                includeShelved: true,
                showAssignees: true,
            }),
        });

        // W33's finding about the old download button, applied to all five:
        // the file a reader takes away has to be the document they were
        // looking at, not a differently-configured one. The parameter names
        // are ReportOptions::fromQuery()'s, so a rename breaks here rather
        // than silently producing a default-configured file.
        const items = wrapper.findAll('[role="menuitem"]');
        const formats = items.slice(0, 5);

        for (const item of formats) {
            const href = item.attributes('href') ?? '';
            expect(href).toContain('baseline=target');
            expect(href).toContain('include_shelved=1');
            expect(href).toContain('show_assignees=1');

            // W80: and no `figures`. It was forwarded to all five formats
            // and read by none of them; a reappearance here is the dead
            // control coming back, since nothing server-side parses it any
            // more (see ReportOptions' docblock — computed results render as
            // currency and are never band-mapped, so it could never have
            // meant what it said).
            expect(href).not.toContain('figures');
        }
    });

    it('keeps the forced-full path W33 defined, after the button that used to carry it', async () => {
        const wrapper = await openMenu({
            options: options({ baseline: 'current', includeShelved: false }),
        });

        const expanded = wrapper
            .findAll('[role="menuitem"]')
            .find((item) => item.text().includes('Everything expanded'));

        expect(expanded).toBeDefined();

        // download=1 and *only* download=1 — merging it with the panel's
        // state would let a stale "baseline=current" produce a document that
        // looks complete while omitting the target state, which is exactly
        // what ReportOptions::forDownload() exists to prevent.
        const href = expanded!.attributes('href') ?? '';
        expect(href).toContain('?download=1');
        expect(href).not.toContain('baseline=current');
    });

    it('renders plain anchors, never Inertia links', async () => {
        const wrapper = await openMenu({ options: options() });

        // An Inertia <Link> performs an XHR visit expecting an Inertia
        // response. These routes return a file with Content-Disposition:
        // attachment, so the visit ends in nothing happening and no error —
        // a failure mode with no symptom to report.
        for (const item of wrapper.findAll('[role="menuitem"]')) {
            expect(item.element.tagName).toBe('A');
        }
    });
});
