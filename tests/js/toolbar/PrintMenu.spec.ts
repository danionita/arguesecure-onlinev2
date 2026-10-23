/**
 * W138 (D109, D110): Print asks which view to print.
 *
 * jsdom has no print pipeline, so `window.print` is a spy. What is asserted
 * is the order of events, which is what regresses silently: a print that
 * fires before the chosen view has rendered prints the wrong page.
 */
import { flushPromises, mount } from '@vue/test-utils';
import {
    afterEach,
    beforeAll,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';
import { defineComponent, h, nextTick, ref } from 'vue';
import AssessmentActions from '../../../resources/js/Components/Toolbar/AssessmentActions.vue';
import {
    usePrintOnArrival,
    withPrintToken,
} from '../../../resources/js/composables/usePrintOnArrival';

const visit = vi.fn();
vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        router: { visit: (...a: unknown[]) => visit(...a) },
        Link: defineComponent({
            props: { href: { type: String, default: '' } },
            setup:
                (props, { slots }) =>
                () =>
                    h('a', { href: props.href }, slots.default?.()),
        }),
    };
});

const route = (name: string, uuid: string) => `/${name}/${uuid}`;
const UUID = '11111111-2222-3333-4444-555555555555';

beforeAll(() => {
    (globalThis as unknown as { route: typeof route }).route = route;
});

const print = vi.fn();

beforeEach(() => {
    vi.useFakeTimers();
    visit.mockReset();
    print.mockReset();
    window.print = print;
    window.localStorage.clear();
    window.sessionStorage.clear();
});
afterEach(() => vi.useRealTimers());

function mountActions(
    viewMode: 'chart' | 'list' | 'report',
    setViewMode?: (m: 'chart' | 'list') => void,
) {
    return mount(AssessmentActions, {
        attachTo: document.body,
        props: {
            assessment: { uuid: UUID },
            abilities: { manage: false },
            viewMode,
            setViewMode,
            userId: 7,
        },
        global: { config: { globalProperties: { route } } },
    });
}

async function open(wrapper: ReturnType<typeof mountActions>) {
    await wrapper.get('[data-testid="print-trigger"]').trigger('click');
}

describe('the Print menu', () => {
    it('opens a Chart / List / Report menu with the current view first, and does not print yet', async () => {
        const wrapper = mountActions('list');
        await open(wrapper);

        const items = wrapper.findAll('[role="menuitem"]');
        expect(items.map((i) => i.attributes('aria-label'))).toEqual([
            'Print list',
            'Print chart',
            'Print report',
        ]);
        expect(items[0]!.text()).toContain('current view');
        expect(items[1]!.text()).not.toContain('current view');
        expect(print).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('prints the current view in place, without navigating', async () => {
        const wrapper = mountActions('chart');
        await open(wrapper);
        await wrapper.get('[data-testid="print-chart"]').trigger('click');

        expect(print).toHaveBeenCalledTimes(1);
        expect(visit).not.toHaveBeenCalled();
        expect(wrapper.emitted('print')).toBeUndefined();
        wrapper.unmount();
    });

    it('switches the editor view first and prints only after it has rendered', async () => {
        const order: string[] = [];
        const mode = ref<'chart' | 'list'>('chart');
        print.mockImplementation(() => order.push(`print:${mode.value}`));
        const wrapper = mountActions('chart', (m) => {
            order.push(`set:${m}`);
            mode.value = m;
        });
        await open(wrapper);
        await wrapper.get('[data-testid="print-list"]').trigger('click');

        expect(order).toEqual(['set:list']);
        expect(print).not.toHaveBeenCalled();

        vi.advanceTimersByTime(100);
        await nextTick();
        expect(order).toEqual(['set:list', 'print:list']);
        wrapper.unmount();
    });

    it('visits the report with a one-shot token and emits print for the hint', async () => {
        const wrapper = mountActions('chart', () => {});
        await open(wrapper);
        await wrapper.get('[data-testid="print-report"]').trigger('click');

        expect(wrapper.emitted('print')).toHaveLength(1);
        expect(print).not.toHaveBeenCalled();
        expect(visit).toHaveBeenCalledTimes(1);
        expect(visit.mock.calls[0]![0]).toMatch(
            new RegExp(`^/assessments\\.report\\.show/${UUID}\\?print=\\d+$`),
        );
        wrapper.unmount();
    });

    it('from the report, visits the editor in the chosen mode and remembers it', async () => {
        const wrapper = mountActions('report');
        await open(wrapper);
        await wrapper.get('[data-testid="print-list"]').trigger('click');

        expect(visit.mock.calls[0]![0]).toMatch(
            new RegExp(`^/assessments\\.show/${UUID}\\?print=\\d+$`),
        );
        expect(
            window.localStorage.getItem(`arguesecure:viewmode:7:${UUID}`),
        ).toBe('list');
        wrapper.unmount();
    });
});

describe('usePrintOnArrival', () => {
    function mountHost(before?: () => void) {
        return mount(
            defineComponent({
                setup() {
                    usePrintOnArrival(before);
                    return () => h('div');
                },
            }),
        );
    }

    function at(search: string) {
        window.history.replaceState({}, '', `/assessments/x${search}`);
    }

    it('prints once for a token and not again for the same token', async () => {
        const before = vi.fn();
        at('?print=123');
        mountHost(before).unmount();
        vi.advanceTimersByTime(100);
        await flushPromises();
        expect(print).toHaveBeenCalledTimes(1);
        expect(before).toHaveBeenCalledTimes(1);

        // Back-navigation onto the same URL: the token is spent.
        mountHost(before).unmount();
        vi.advanceTimersByTime(100);
        expect(print).toHaveBeenCalledTimes(1);

        // A fresh click is a fresh token.
        at('?print=124');
        mountHost().unmount();
        vi.advanceTimersByTime(100);
        expect(print).toHaveBeenCalledTimes(2);
    });

    it('does nothing without the parameter', () => {
        at('');
        mountHost().unmount();
        vi.advanceTimersByTime(100);
        expect(print).not.toHaveBeenCalled();
    });

    it('keeps existing query parameters when adding the token', () => {
        expect(withPrintToken('/r?baseline=both', '9')).toBe(
            '/r?baseline=both&print=9',
        );
    });
});
