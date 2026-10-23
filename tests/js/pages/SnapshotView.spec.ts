/**
 * `Snapshots/View.vue` — a frozen version read in the editor's own views (W63).
 *
 * The page's whole safety story is that nothing on it can be mistaken for, or
 * act on, the live assessment. These specs pin that: the banner and its
 * links, the store being built `frozen` with every ability false, a
 * snapshot-scoped persistence key, no Report mode, and the restore button
 * appearing only for someone allowed to restore.
 */
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, inject } from 'vue';
import SnapshotView from '../../../resources/js/Pages/Snapshots/View.vue';
import {
    ASSESSMENT_STORE_KEY,
    type AssessmentStore,
} from '../../../resources/js/stores/assessmentStore';
import { makeFixtureInit } from '../composables/support/fixture';

vi.mock('@inertiajs/vue3', () => ({
    Head: {
        name: 'Head',
        props: ['title'],
        template: '<div data-testid="head" :data-title="title" />',
    },
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
}));

const route = (name: string, param?: unknown) =>
    `/${name}/${Array.isArray(param) ? param.join('/') : (param ?? '')}`;

// The store the page provides, captured by the stand-in for the chart so the
// spec can ask the store itself rather than infer from the markup.
let injectedStore: AssessmentStore | undefined;

const ChartStub = defineComponent({
    name: 'ChartView',
    setup() {
        injectedStore = inject(ASSESSMENT_STORE_KEY);
        return () => h('div', { 'data-testid': 'chart-view' });
    },
});

function snapshot(over: Record<string, unknown> = {}) {
    return {
        id: 7,
        name: 'Workshop one',
        schemaVersion: 2,
        createdBy: 'Sam',
        createdAt: '2026-08-01T09:00:00Z',
        note: null,
        ...over,
    };
}

function props(over: Record<string, unknown> = {}) {
    const init = makeFixtureInit();
    return {
        snapshot: snapshot(),
        canRestore: true,
        assessment: init.assessment,
        nodes: init.nodes,
        riskAttackLinks: init.riskAttackLinks,
        attackDefenceLinks: init.attackDefenceLinks,
        scales: [],
        scaleLevels: [],
        tags: [],
        results: init.results,
        ...over,
    };
}

function render(over: Record<string, unknown> = {}) {
    return mount(SnapshotView, {
        props: props(over),
        global: {
            config: { globalProperties: { route } },
            stubs: {
                ChartView: ChartStub,
                TreeNode: {
                    props: ['uuid'],
                    template: '<li data-testid="tree-node">{{ uuid }}</li>',
                },
                ZoomControls: true,
                RestoreSnapshotDialog: {
                    props: ['snapshot', 'assessmentUuid'],
                    template:
                        '<div data-testid="restore-dialog">{{ snapshot ? snapshot.name : "closed" }}</div>',
                },
            },
        },
    });
}

beforeEach(() => {
    injectedStore = undefined;
    localStorage.clear();
    (globalThis as unknown as { route: typeof route }).route = route;
});

describe('the frozen banner', () => {
    it('says it is a saved version and names it, its author and the read-only state', () => {
        const banner = render().get('[data-testid="frozen-banner"]');

        // Everything below the banner looks exactly like the live assessment,
        // so the banner is the only thing telling a reader it is not.
        expect(banner.text()).toContain('Viewing a saved version');
        expect(banner.text()).toContain('Workshop one');
        expect(banner.text()).toContain('Sam');
        expect(banner.text()).toContain('Read-only');
    });

    it('falls back to an unknown date and author rather than rendering "Invalid Date"', () => {
        const banner = render({
            snapshot: snapshot({ createdAt: null, createdBy: null }),
        }).get('[data-testid="frozen-banner"]');

        expect(banner.text()).toContain('an unknown date');
        expect(banner.text()).toContain('Unknown');
        expect(banner.text()).not.toContain('Invalid');
    });

    it('links to the figures, the history and back to the current version', () => {
        const hrefs = render()
            .get('[data-testid="frozen-banner"]')
            .findAll('a')
            .map((a) => [a.text(), a.attributes('href')]);

        expect(hrefs).toEqual([
            ['Figures', '/assessments.snapshots.show/assessment-uuid/7'],
            ['All versions', '/assessments.history.index/assessment-uuid'],
            [
                'Back to the current version',
                '/assessments.show/assessment-uuid',
            ],
        ]);
    });

    it('states that comments, presence and locks are not part of a saved version', () => {
        expect(render().get('[data-testid="frozen-note"]').text()).toContain(
            'nothing on this page is recalculated',
        );
    });
});

describe('the restore control', () => {
    it('is offered to someone who can restore, and opens the dialog on this snapshot', async () => {
        const wrapper = render();
        expect(wrapper.get('[data-testid="restore-dialog"]').text()).toBe(
            'closed',
        );

        const restore = wrapper
            .findAll('button')
            .find((b) => b.text() === 'Restore this version');
        await restore!.trigger('click');

        expect(wrapper.get('[data-testid="restore-dialog"]').text()).toBe(
            'Workshop one',
        );
    });

    it('is absent for someone who cannot restore', () => {
        const wrapper = render({ canRestore: false });

        expect(wrapper.text()).not.toContain('Restore this version');
    });
});

describe('the title', () => {
    it('is the root node title, and the head title leads with the version name', () => {
        const wrapper = render();

        expect(wrapper.get('h1').text()).toBe('Assessment');
        expect(
            wrapper.get('[data-testid="head"]').attributes('data-title'),
        ).toBe('Workshop one — Assessment');
    });

    it('falls back to "Untitled assessment" when the root has no title', () => {
        const init = makeFixtureInit();
        const nodes = init.nodes.map((n) =>
            n.type === 'root' ? { ...n, title: '' } : n,
        );

        expect(render({ nodes }).get('h1').text()).toBe('Untitled assessment');
    });

    it('shows the snapshot note only when there is one', () => {
        expect(render().text()).not.toContain('Before the audit');
        expect(
            render({ snapshot: snapshot({ note: 'Before the audit' }) }).text(),
        ).toContain('Before the audit');
    });
});

describe('read-only, structurally', () => {
    it('builds the store frozen with every ability false, including comment', () => {
        render();

        // `frozen` is the real guard: abilities only hide buttons, while
        // frozen stops `loadUnresolvedCommentCounts()` reaching the network
        // from onMounted with no ability check.
        expect(injectedStore).toBeDefined();
        expect({ ...injectedStore!.abilities }).toEqual({
            comment: false,
            update: false,
            manage: false,
            transfer: false,
        });
    });

    it('does not fetch live comment counts', async () => {
        const get = vi.fn().mockResolvedValue({ data: {} });
        (window as unknown as { axios: unknown }).axios = { get };
        render();
        await injectedStore!.loadUnresolvedCommentCounts();

        expect(get).not.toHaveBeenCalled();
    });

    it('does not delete on the server when asked to delete a node', async () => {
        const del = vi.fn().mockResolvedValue({ data: {} });
        (window as unknown as { axios: unknown }).axios = { delete: del };
        render();
        await injectedStore!.deleteNode('riskA');

        expect(del).not.toHaveBeenCalled();
        expect(injectedStore!.nodes['riskA']).toBeDefined();
    });
});

describe('view modes', () => {
    it('offers Chart and List only, never the Report that serves live figures', () => {
        const options = render()
            .findAll('[role="radio"]')
            .map((b) => b.text());

        expect(options).toEqual(['Chart', 'List']);
    });

    it('shows the chart by default and a tree rooted at the root node in list mode', async () => {
        const wrapper = render();
        expect(wrapper.find('[data-testid="chart-view"]').exists()).toBe(true);
        expect(wrapper.find('[role="tree"]').exists()).toBe(false);

        const list = wrapper
            .findAll('[role="radio"]')
            .find((b) => b.text() === 'List');
        await list!.trigger('click');

        expect(wrapper.find('[data-testid="chart-view"]').exists()).toBe(false);
        expect(wrapper.get('[role="tree"]').attributes('aria-label')).toBe(
            'Assessment',
        );
        expect(wrapper.get('[data-testid="tree-node"]').text()).toBe('root');
    });

    it('persists the chosen mode under the snapshot id, never the assessment uuid', async () => {
        const wrapper = render();
        await wrapper
            .findAll('[role="radio"]')
            .find((b) => b.text() === 'List')!
            .trigger('click');
        await flushPromises();

        // Keyed by the live uuid, reading an old version would rewrite the
        // reader's mode in the live editor.
        expect(
            localStorage.getItem('arguesecure:viewmode:anon:snapshot:7'),
        ).toBe('list');
        expect(
            localStorage.getItem('arguesecure:viewmode:anon:assessment-uuid'),
        ).toBeNull();
    });
});
