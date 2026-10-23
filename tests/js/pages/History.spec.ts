/**
 * The History page as versions (2.5.1).
 *
 * What changed is the page's structure, so that is what is asserted: the
 * current version on top with a way to save it, saved versions below it,
 * numbered and attributed, each with its own changes — and none of the
 * footnote about a "snapshots page" that nobody could find.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import History from '../../../resources/js/Pages/Assessment/History.vue';

const post = vi.fn();
const get = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div><slot /></div>' },
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    usePage: () => ({ props: {}, url: '/assessments/x/history' }),
    useForm: (initial: Record<string, unknown>) => ({
        ...initial,
        errors: {},
        processing: false,
        post,
        reset: vi.fn(),
    }),
    router: {
        on: () => () => {},
        get: (...args: unknown[]) => get(...args),
        post: vi.fn(),
    },
}));

const route = (name: string, params?: unknown) =>
    `/${name}${params ? `?${JSON.stringify(params)}` : ''}`;

function entry(id: string, summary: string) {
    return {
        id,
        source: 'operation',
        action: 'node.create',
        summary,
        user: 'Dana',
        created_at: '2026-09-01T10:00:00Z',
        node_uuid: null,
        undone: false,
        changes: [],
    };
}

function props(over: Record<string, unknown> = {}) {
    return {
        assessment: { uuid: 'a1', title: 'Payment platform' },
        versions: [
            {
                key: 'current',
                number: null,
                snapshot: null,
                entries: [entry('op-3', 'Added Phishing')],
                total: 1,
            },
            {
                key: 'v7',
                number: 2,
                snapshot: {
                    id: 7,
                    name: 'Saved automatically after a pause in editing (4 changes)',
                    note: null,
                    kind: 'auto',
                    schemaVersion: 2,
                    createdBy: null,
                    createdAt: '2026-09-01T09:00:00Z',
                },
                entries: [entry('op-2', 'Added Data breach')],
                total: 4,
            },
            {
                key: 'v3',
                number: 1,
                snapshot: {
                    id: 3,
                    name: 'Workshop one',
                    note: null,
                    kind: 'manual',
                    schemaVersion: 2,
                    createdBy: 'Sam',
                    createdAt: '2026-08-01T09:00:00Z',
                },
                entries: [entry('op-1', 'Created')],
                total: 1,
            },
        ],
        expanded: null,
        filters: { user_id: null, action: null, node_uuid: null },
        actors: [],
        actions: [],
        autoSave: { idleMinutes: 30, maxChanges: 50 },
        abilities: { canSave: true, canRestore: true },
        ...over,
    };
}

function render(over: Record<string, unknown> = {}) {
    return mount(History, {
        global: {
            config: { globalProperties: { route } },
            stubs: {
                AuthenticatedLayout: { template: '<div><slot /></div>' },
                RestoreSnapshotDialog: {
                    props: ['snapshot'],
                    template:
                        '<div data-testid="restore-dialog">{{ snapshot ? snapshot.name : "" }}</div>',
                },
            },
        },
        props: props(over),
    });
}

beforeEach(() => {
    post.mockClear();
    get.mockClear();
    (globalThis as unknown as { route: typeof route }).route = route;
});

describe('the current version', () => {
    it('lists the changes not yet in a saved version', () => {
        const section = render().get('[data-testid="version-current"]');

        expect(section.text()).toContain('Current version');
        expect(section.text()).toContain('1 change not yet in a saved version');
        expect(section.text()).toContain('Added Phishing');
    });

    it('can be saved by hand from here, with an optional name', async () => {
        const wrapper = render();

        await wrapper.get('[data-testid="save-version"]').trigger('click');
        await wrapper
            .get('[data-testid="save-version-form"]')
            .trigger('submit');

        expect(post).toHaveBeenCalledWith(
            '/assessments.history.versions.store?"a1"',
            expect.anything(),
        );
    });

    it('offers no save to someone who cannot edit', () => {
        const wrapper = render({
            abilities: { canSave: false, canRestore: false },
        });

        expect(wrapper.find('[data-testid="save-version"]').exists()).toBe(
            false,
        );
    });
});

describe('saved versions', () => {
    it('are numbered, newest first, and say who or what saved them', () => {
        const wrapper = render();

        const newest = wrapper.get('[data-testid="version-2"]').text();
        expect(newest).toContain('Version 2');
        expect(newest).toContain('Saved automatically');

        const oldest = wrapper.get('[data-testid="version-1"]').text();
        expect(oldest).toContain('Version 1');
        expect(oldest).toContain('Saved by Sam');
    });

    it('open to show the changes that went into them', async () => {
        const wrapper = render();
        const card = wrapper.get('[data-testid="version-2"]');

        expect(card.text()).not.toContain('Added Data breach');
        await card.get('button[aria-expanded]').trigger('click');

        expect(card.text()).toContain('Added Data breach');
        expect(card.text()).toContain('Show all 4 changes');
    });

    it('can be restored through the same dialog as before', async () => {
        const wrapper = render();

        const restore = wrapper
            .get('[data-testid="version-1"]')
            .findAll('button')
            .find((b) => b.text() === 'Restore this version')!;
        await restore.trigger('click');

        expect(wrapper.get('[data-testid="restore-dialog"]').text()).toBe(
            'Workshop one',
        );
    });

    it('compare against the version before them', () => {
        const link = render()
            .get('[data-testid="version-2"]')
            .findAll('a')
            .find((a) => a.text().includes('Compare with version 1'));

        expect(link).toBeDefined();
    });
});

describe('the words on the page', () => {
    it('say "version", never "snapshot", and carry no footnote about another page', () => {
        const text = render().text();

        expect(text.toLowerCase()).not.toContain('snapshot');
        expect(render().find('[data-testid="revert-note"]').exists()).toBe(
            false,
        );
    });
});
