/**
 * W139, D118. Every section of the report can be left out: the page omits it,
 * the Sections pop-up carries one switch per section and sends the whole list,
 * and a report that leaves sections out names them.
 */
import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import AppendicesSection from '../../../resources/js/Components/Report/AppendicesSection.vue';
import ReportDocument from '../../../resources/js/Components/Report/ReportDocument.vue';
import { REPORT_SECTIONS } from '../../../resources/js/Components/Report/reportSections';
import ReportShow from '../../../resources/js/Pages/Report/Show.vue';
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
});

function mountDocument(omitted: string[]) {
    const base = report();

    return mount(ReportDocument, {
        props: {
            assessment: { uuid: 'u' },
            report: {
                ...base,
                options: { ...base.options, omittedSections: omitted },
            },
        },
        global: {
            config: { globalProperties: { route } },
            stubs: { SummaryNarrative: true },
        },
    });
}

describe('the report document', () => {
    it('shows every section and no note by default', () => {
        const wrapper = mountDocument([]);

        expect(wrapper.text()).toContain('Defences ranked by cost benefit');
        expect(wrapper.text()).toContain('Share of risk reduction');
        expect(wrapper.text()).toContain('Defences ranked by opportunity');
        expect(wrapper.find('[data-testid="sections-omitted"]').exists()).toBe(
            false,
        );
    });

    it('leaves out the cost-benefit ranking together with its chart, and names what it left out', () => {
        const wrapper = mountDocument(['defences-importance', 'transfers']);

        const note = wrapper.get('[data-testid="sections-omitted"]').text();
        const body = wrapper.text().replace(note, '');

        expect(body).not.toContain('Defences ranked by cost benefit');
        expect(body).not.toContain('Share of risk reduction');
        expect(body).toContain('Defences ranked by opportunity');
        expect(note).toBe(
            'Sections left out of this report: Defences ranked by cost benefit, Transfers.',
        );
    });
});

describe('the appendices', () => {
    const appendices = {
        references: [],
        comments: [],
        warnings: [],
        warningsOmitted: 0,
    };
    const mountAppendices = (omitted: string[], extra = {}) =>
        mount(AppendicesSection, {
            props: {
                options: {
                    ...report().options,
                    omittedSections: omitted,
                    ...extra,
                },
                appendices,
            },
        });

    it('drops the references and re-letters the rest', () => {
        const text = mountAppendices(['references'], {
            includeMethodology: true,
        }).text();

        expect(text).not.toContain('References');
        expect(text).toContain('A. Methodology');
    });

    it('drops the whole heading when no appendix is left', () => {
        const wrapper = mountAppendices(['references'], {
            includeMethodology: false,
            includeWarnings: false,
        });

        expect(wrapper.find('#appendices-heading').exists()).toBe(false);
    });
});

describe('the Sections pop-up', () => {
    async function open() {
        const base = report();
        const wrapper = mount(ReportShow, {
            props: { assessment: { uuid: 'u' }, report: base },
            global: {
                config: { globalProperties: { route } },
                stubs: { SummaryNarrative: true, teleport: true },
            },
        });
        await wrapper
            .get('[data-testid="report-sections-button"]')
            .trigger('click');

        return wrapper;
    }

    it('has one switch per section, in document order', async () => {
        const wrapper = await open();
        const ids = wrapper
            .findAll('[data-testid^="section-toggle-"]')
            .map((el) =>
                el.attributes('data-testid')!.replace('section-toggle-', ''),
            );

        expect(ids).toEqual(REPORT_SECTIONS.map((s) => s.id));
    });
});

describe('what an omitted section takes with it', () => {
    it('names the assessment in the left-out line when the cover is omitted', () => {
        const wrapper = mountDocument(['cover']);

        expect(wrapper.get('[data-testid="sections-omitted"]').text()).toMatch(
            /^Report on .+\.\s+Sections left out of this report: Cover\.$/,
        );
    });

    it('does not print the machine-drafted disclaimer for a summary that is not there', () => {
        const base = report();
        const mountWith = (omitted: string[]) =>
            mount(ReportDocument, {
                props: {
                    assessment: {
                        uuid: 'u',
                        summaryNarrative: 'Drafted.',
                        summaryNarrativeGenerated: true,
                    },
                    report: {
                        ...base,
                        options: {
                            ...base.options,
                            includeMethodology: true,
                            omittedSections: omitted,
                        },
                    },
                },
                global: {
                    config: { globalProperties: { route } },
                    stubs: { SummaryNarrative: true },
                },
            });

        expect(
            mountWith([]).find('[data-testid="generated-disclaimer"]').exists(),
        ).toBe(true);
        expect(
            mountWith(['written-summary'])
                .find('[data-testid="generated-disclaimer"]')
                .exists(),
        ).toBe(false);
    });
});
