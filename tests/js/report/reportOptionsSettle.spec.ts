/**
 * W110 (D70). Report options changed in quick succession become one request.
 *
 * Every option toggle re-requests the report page, and the page is rate
 * limited. Before W110 each click was its own request, so a few quick toggles
 * answered 429 (the owner's report). The server half — the page's own
 * 60/min limiter — is guarded in tests/Feature/Report/ReportViewRateLimitTest.php;
 * this is the client half: the clicks are merged, and the row shows each one
 * at once rather than waiting for the response.
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
import ReportOptionsRow from '../../../resources/js/Components/Report/ReportOptionsRow.vue';
import AssessmentActions from '../../../resources/js/Components/Toolbar/AssessmentActions.vue';
import ReportShow from '../../../resources/js/Pages/Report/Show.vue';
import { report } from './support/reportFixture';

const { routerGet } = vi.hoisted(() => ({ routerGet: vi.fn() }));

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
        router: { get: routerGet, visit: vi.fn() },
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
    window.print = () => {};
});

beforeEach(() => {
    vi.useFakeTimers();
    routerGet.mockReset();
});

afterEach(() => {
    vi.useRealTimers();
});

function mountReport() {
    return mount(ReportShow, {
        props: { assessment: { uuid: 'u' }, report: report() },
        global: {
            config: { globalProperties: { route } },
            stubs: { SummaryNarrative: true, teleport: true },
        },
    });
}

describe('report option toggles', () => {
    it('sends one request for toggles made in quick succession, carrying all of them', async () => {
        const wrapper = mountReport();
        const row = wrapper.getComponent(ReportOptionsRow);

        row.vm.$emit('update-options', { includeMethodology: false });
        vi.advanceTimersByTime(100);
        row.vm.$emit('update-options', { includeComments: true });
        vi.advanceTimersByTime(100);
        row.vm.$emit('update-options', { includeMethodology: true });
        vi.advanceTimersByTime(100);
        row.vm.$emit('update-options', { baseline: 'target' });

        expect(routerGet).not.toHaveBeenCalled();

        vi.advanceTimersByTime(300);

        expect(routerGet).toHaveBeenCalledTimes(1);
        expect(routerGet.mock.calls[0][1]).toEqual({
            baseline: 'target',
            include_shelved: '0',
            include_comments: '1',
            include_methodology: '1',
            include_warnings: '0',
            show_assignees: '0',
            omit_sections: '',
        });
    });

    it('shows each toggle in the options row at once, before the request is sent', async () => {
        const wrapper = mountReport();
        const row = wrapper.getComponent(ReportOptionsRow);

        expect(row.props('options').includeComments).toBe(false);

        row.vm.$emit('update-options', { includeComments: true });
        await flushPromises();

        expect(routerGet).not.toHaveBeenCalled();
        expect(row.props('options').includeComments).toBe(true);
    });

    it("falls back to the server's options once the newest request finishes", async () => {
        const wrapper = mountReport();
        const row = wrapper.getComponent(ReportOptionsRow);

        row.vm.$emit('update-options', { includeComments: true });
        vi.advanceTimersByTime(300);
        expect(routerGet).toHaveBeenCalledTimes(1);

        // The request failed (or the server answered with its own options):
        // the row shows what the server last assembled, not a stale click.
        routerGet.mock.calls[0][2].onFinish();
        await flushPromises();

        expect(row.props('options').includeComments).toBe(false);
    });

    it('keeps a toggle pending when its request is cancelled rather than answered', async () => {
        const wrapper = mountReport();
        const row = wrapper.getComponent(ReportOptionsRow);

        row.vm.$emit('update-options', { includeComments: true });
        vi.advanceTimersByTime(300);

        const visit = routerGet.mock.calls[0][2];
        visit.onCancel();
        visit.onFinish();
        await flushPromises();

        expect(row.props('options').includeComments).toBe(true);
    });

    it('builds export links from the options the row shows, not the last response', async () => {
        const wrapper = mountReport();
        const actions = wrapper.getComponent(AssessmentActions);

        wrapper
            .getComponent(ReportOptionsRow)
            .vm.$emit('update-options', { includeComments: true });
        await flushPromises();

        expect(actions.props('exportOptions')?.includeComments).toBe(true);
    });
});
