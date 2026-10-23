/**
 * W86 item 9: the settings page no longer edits the assessment's title or
 * description.
 *
 * They live on the root node and are edited there, in the tree — this screen
 * carrying its own copies was the two-editors-for-one-field mistake
 * `UpdateAssessmentSettingsRequest` now guards against server-side
 * (`tests/Feature/Assessment/SettingsTest.php`). This file is the front-end
 * half: the inputs themselves are gone, `useForm` never carries the fields at
 * all, and there is a way back to the assessment now that the tree is the
 * only place left to rename it from.
 */
import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import Settings from '../../../resources/js/Pages/Assessment/Settings.vue';

const route = (name: string, uuid?: string | { assessment: string }) =>
    uuid
        ? `/${name}/${typeof uuid === 'string' ? uuid : uuid.assessment}`
        : `/${name}`;

beforeAll(() => {
    (globalThis as unknown as { route: typeof route }).route = route;
});

const useFormCalls: Array<Record<string, unknown>> = [];

vi.mock('@inertiajs/vue3', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/vue3')>(
            '@inertiajs/vue3',
        );

    return {
        ...actual,
        Head: { template: '<div><slot /></div>' },
        // `reactive`, not a plain object: the real `useForm` returns a
        // reactive form, and a plain stand-in makes every v-model in the page
        // silently inert.
        useForm: (data: Record<string, unknown>) => {
            useFormCalls.push(data);

            return reactive({
                ...data,
                errors: {} as Record<string, string>,
                processing: false,
                transform: vi.fn(() => ({ patch: vi.fn() })),
                patch: vi.fn(),
            });
        },
    };
});

const ASSESSMENT = {
    uuid: '11111111-2222-3333-4444-555555555555',
    currency: 'EUR',
    amortization_years: 3,
    risk_tolerance: null,
};

function page() {
    return mount(Settings, {
        props: {
            assessment: ASSESSMENT,
            currencies: { EUR: 'Euro', USD: 'US Dollar' },
            maxAmortizationYears: 10,
            scales: { impact: null, likelihood: null, strength: null },
            scaleSets: [],
            scaleSetKey: null,
            topBandMultiplier: 5,
            flashSuccess: null,
            errors: {},
        },
        global: {
            stubs: {
                AuthenticatedLayout: { template: '<div><slot /></div>' },
                // Not under test here (W87's own territory) — stubbed so
                // this file cannot fail on a prop shape that block owns.
                ScaleCards: { template: '<div data-testid="scale-cards" />' },
            },
            config: { globalProperties: { route } },
        },
    });
}

describe('no title or description editor on this screen (item 9)', () => {
    it('renders no title input', () => {
        const wrapper = page();

        expect(
            wrapper.find('input[type="text"]').exists() ||
                wrapper
                    .findAll('label')
                    .some((l) => l.text().trim() === 'Title'),
        ).toBe(false);
    });

    it('renders no description textarea', () => {
        expect(page().find('textarea').exists()).toBe(false);
    });

    it('leaves no trace of the old ids in the markup', () => {
        const html = page().html();

        expect(html).not.toMatch(/id="settings-title"/);
        expect(html).not.toMatch(/id="settings-description"/);
    });

    it('never asks useForm to track a title or description key at all', () => {
        useFormCalls.length = 0;
        page();

        expect(useFormCalls).toHaveLength(1);
        expect(Object.keys(useFormCalls[0])).not.toContain('title');
        expect(Object.keys(useFormCalls[0])).not.toContain('description');
    });

    it('the untouched controls survive: currency, risk tolerance and amortisation are still here', () => {
        const wrapper = page();

        expect(wrapper.find('#settings-currency').exists()).toBe(true);
        expect(wrapper.text()).toContain('Risk tolerance');
        expect(wrapper.text()).toContain('Amortisation period');
    });
});

describe('"Return to assessment", top right (item 9)', () => {
    it('links back to the assessment', () => {
        const wrapper = page();
        const link = wrapper
            .findAll('a')
            .find((a) => a.text() === 'Return to assessment');

        expect(link).toBeTruthy();
        expect(link!.attributes('href')).toBe(
            `/assessments.show/${ASSESSMENT.uuid}`,
        );
    });

    it('sits before the form, not after — it is the top-right escape, not a footer link', () => {
        const wrapper = page();
        const html = wrapper.html();

        expect(html.indexOf('Return to assessment')).toBeLessThan(
            html.indexOf('<form'),
        );
    });
});
