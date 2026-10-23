/**
 * W153, D120. The report's Content button is now "Defences": Implemented
 * (always on), Planned (adds the target figures) and Shelved (listed only),
 * three rows that each say they are about defences. Planned maps onto the
 * baseline on the wire, Shelved onto `include_shelved`.
 */
import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import ReportOptionsRow from '../../../resources/js/Components/Report/ReportOptionsRow.vue';
import type { ReportOptions } from '../../../resources/js/Components/Report/types';
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

async function mountRow(patch: Partial<ReportOptions> = {}) {
    const wrapper = mount(ReportOptionsRow, {
        props: {
            options: { ...report().options, ...patch },
            search: {} as never,
            zoom: 1,
            reportHref: '/r',
            editorHref: '/e',
            assessmentUuid: 'u',
            userId: 1,
        },
        global: {
            config: { globalProperties: { route } },
            stubs: { SearchBox: true, ViewSwitcher: true },
        },
    });
    await wrapper.get('[data-testid="report-content-button"]').trigger('click');

    return wrapper;
}

const sw = (w: Awaited<ReturnType<typeof mountRow>>, id: string) =>
    w.get(`[data-testid="${id}"] button[role="switch"]`);

describe('the Defences control', () => {
    it('shows implemented fixed on, planned on and shelved off by default', async () => {
        const w = await mountRow();

        expect(
            sw(w, 'defences-implemented').attributes('disabled'),
        ).toBeDefined();
        expect(sw(w, 'defences-implemented').attributes('aria-checked')).toBe(
            'true',
        );
        expect(sw(w, 'defences-planned').attributes('aria-checked')).toBe(
            'true',
        );
        expect(sw(w, 'defences-shelved').attributes('aria-checked')).toBe(
            'false',
        );
        expect(w.get('[data-testid="report-content-button"]').text()).toContain(
            'Defences',
        );
    });

    it('turns Planned off into baseline current, and on into both', async () => {
        const w = await mountRow({ baseline: 'both' });
        await sw(w, 'defences-planned').trigger('click');
        expect(w.emitted('update-options')?.at(-1)).toEqual([
            { baseline: 'current' },
        ]);

        const c = await mountRow({ baseline: 'current' });
        expect(sw(c, 'defences-planned').attributes('aria-checked')).toBe(
            'false',
        );
        await sw(c, 'defences-planned').trigger('click');
        expect(c.emitted('update-options')?.at(-1)).toEqual([
            { baseline: 'both' },
        ]);
    });

    it('reads a hand-built target baseline as planned on', async () => {
        const w = await mountRow({ baseline: 'target' });
        expect(sw(w, 'defences-planned').attributes('aria-checked')).toBe(
            'true',
        );
    });

    it('sends Shelved as includeShelved', async () => {
        const w = await mountRow();
        await sw(w, 'defences-shelved').trigger('click');
        expect(w.emitted('update-options')?.at(-1)).toEqual([
            { includeShelved: true },
        ]);
    });
});
