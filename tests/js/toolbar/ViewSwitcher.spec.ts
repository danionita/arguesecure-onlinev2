/**
 * W35 — the report as the third position, and the two things that must not
 * follow from it: the report becoming a persisted editor mode, and the
 * switcher being useless to a reader who arrived by share link.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ViewSwitcher from '../../../resources/js/Components/Toolbar/ViewSwitcher.vue';
import { useEditorViewMode } from '../../../resources/js/composables/useEditorViewMode';
import { makeMemoryStorage } from '../composables/support/fixture';

const visit = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    router: {
        visit: (...args: unknown[]) => visit(...args),
    },
}));

const REPORT_HREF = '/assessments/abc/report';
const EDITOR_HREF = '/assessments/abc';

beforeEach(() => {
    visit.mockClear();
    window.localStorage.clear();
});

function editorMode() {
    return useEditorViewMode({
        assessmentUuid: 'abc',
        userId: 7,
        storage: makeMemoryStorage(),
    });
}

describe('ViewSwitcher in the editor', () => {
    it('offers all three surfaces when a report route is available', () => {
        const wrapper = mount(ViewSwitcher, {
            props: { viewMode: editorMode(), reportHref: REPORT_HREF },
        });

        const labels = wrapper.findAll('[role="radio"]').map((b) => b.text());
        expect(labels).toEqual(['Chart', 'List', 'Report Beta']);
    });

    it('marks the report as beta (2.5.1)', () => {
        const wrapper = mount(ViewSwitcher, {
            props: { viewMode: editorMode(), reportHref: REPORT_HREF },
        });

        const badges = wrapper.findAll('[data-testid="option-badge"]');
        expect(badges).toHaveLength(1);
        expect(badges[0].text()).toBe('Beta');
    });

    it('omits Report when there is no report route to go to', () => {
        const wrapper = mount(ViewSwitcher, {
            props: { viewMode: editorMode() },
        });

        expect(wrapper.text()).not.toContain('Report');
    });

    it('switches chart and list client-side, without navigating', async () => {
        const viewMode = editorMode();
        const wrapper = mount(ViewSwitcher, {
            props: { viewMode, reportHref: REPORT_HREF },
        });

        await wrapper.findAll('[role="radio"]')[1].trigger('click');

        expect(viewMode.mode.value).toBe('list');
        expect(visit).not.toHaveBeenCalled();
    });

    it('navigates to the report, and does not touch the persisted mode', async () => {
        const viewMode = editorMode();
        const wrapper = mount(ViewSwitcher, {
            props: { viewMode, reportHref: REPORT_HREF },
        });

        await wrapper.findAll('[role="radio"]')[2].trigger('click');

        expect(visit).toHaveBeenCalledWith(REPORT_HREF);
        // The regression this guards: a reader's detour becoming the mode
        // the editor opens in next time.
        expect(viewMode.mode.value).toBe('chart');
    });
});

describe('ViewSwitcher on the report page', () => {
    function mountOnReport() {
        return mount(ViewSwitcher, {
            props: {
                current: 'report',
                reportHref: REPORT_HREF,
                editorHref: EDITOR_HREF,
                assessmentUuid: 'abc',
                userId: 7,
            },
        });
    }

    it('marks Report as the selected position', () => {
        const wrapper = mountOnReport();
        const report = wrapper.findAll('[role="radio"]')[2];

        expect(report.attributes('aria-checked')).toBe('true');
    });

    it('goes back to the editor in the view that was clicked, not the last one used', async () => {
        const wrapper = mountOnReport();

        await wrapper.findAll('[role="radio"]')[1].trigger('click');

        // Written before navigating: the editor reads this key on mount, so
        // clicking List and landing on the chart is exactly what this
        // prevents.
        expect(window.localStorage.getItem('arguesecure:viewmode:7:abc')).toBe(
            'list',
        );
        expect(visit).toHaveBeenCalledWith(EDITOR_HREF);
    });

    it('works for an anonymous share-link reader, who has no user id', async () => {
        const wrapper = mount(ViewSwitcher, {
            props: {
                current: 'report',
                reportHref: REPORT_HREF,
                editorHref: EDITOR_HREF,
                assessmentUuid: 'abc',
                userId: null,
            },
        });

        await wrapper.findAll('[role="radio"]')[0].trigger('click');

        expect(
            window.localStorage.getItem('arguesecure:viewmode:anon:abc'),
        ).toBe('chart');
        expect(visit).toHaveBeenCalledWith(EDITOR_HREF);
    });

    it('does nothing when the already-selected position is clicked', async () => {
        const wrapper = mountOnReport();

        await wrapper.findAll('[role="radio"]')[2].trigger('click');

        expect(visit).not.toHaveBeenCalled();
    });
});
