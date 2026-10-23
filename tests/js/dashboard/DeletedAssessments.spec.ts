/**
 * *Deleted* on My assessments (W113): Restore for every row, Erase
 * permanently only where the server said `can_erase`, and the retention
 * period stated wherever a delete or an erase is confirmed.
 *
 * Which rows reach the page, and the 403s behind both buttons, are asserted
 * on the server in tests/Feature/Retention/RestoreEraseTest.php — a button
 * hidden here is a courtesy, the route is the guard.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Index from '../../../resources/js/Pages/Assessment/Index.vue';

const post = vi.fn();
const destroy = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div><slot /></div>' },
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    usePage: () => ({ props: {}, url: '/assessments' }),
    useForm: () => ({
        title: '',
        description: '',
        errors: {},
        processing: false,
        post: vi.fn(),
        reset: vi.fn(),
    }),
    router: {
        get post() {
            return post;
        },
        get delete() {
            return destroy;
        },
    },
}));

const route = (name: string, param?: string) =>
    param ? `/${name}/${param}` : `/${name}`;

/** Renders the dialog's text and a button that confirms it, without Teleport. */
const ConfirmDialogStub = {
    name: 'ConfirmDialog',
    props: ['show', 'title', 'message', 'confirmLabel'],
    emits: ['confirm', 'cancel'],
    template: `<div v-if="show" data-testid="confirm">
        <p>{{ title }}</p><p>{{ message }}</p>
        <button data-testid="confirm-button" @click="$emit('confirm')">{{ confirmLabel }}</button>
    </div>`,
};

function deletedRow(over: Record<string, unknown> = {}) {
    return {
        uuid: 'd1',
        title: 'Old engagement',
        is_owner: true,
        owner_name: 'Dana',
        deleted_at: '2026-10-01T10:00:00.000000Z',
        erase_after: '2026-10-31T10:00:00.000000Z',
        can_erase: true,
        ...over,
    };
}

function liveRow() {
    return {
        uuid: 'a1',
        title: 'Payment platform',
        description: null,
        is_public: false,
        is_owner: true,
        can_manage: true,
        can_delete: true,
        owner_name: null,
        updated_at: null,
        tags: [],
    };
}

function render(props: Record<string, unknown> = {}) {
    return mount(Index, {
        global: {
            config: { globalProperties: { route } },
            stubs: {
                AuthenticatedLayout: { template: '<div><slot /></div>' },
                ConfirmDialog: ConfirmDialogStub,
                ExportMenu: true,
            },
        },
        props: { assessments: [liveRow()], ...props },
    });
}

beforeEach(() => {
    post.mockClear();
    destroy.mockClear();
    (globalThis as unknown as { route: typeof route }).route = route;
});

describe('the Deleted section', () => {
    it('is absent when nothing is deleted', () => {
        expect(
            render({ deleted: [] })
                .find('[data-testid="deleted-assessments"]')
                .exists(),
        ).toBe(false);
    });

    it('is present, and closed, when something is deleted', () => {
        const section = render({ deleted: [deletedRow()] }).get(
            '[data-testid="deleted-assessments"]',
        );

        expect(section.text()).toContain('Deleted (1)');
        expect(section.attributes('open')).toBeUndefined();
    });

    it('opens itself when the URL asks for it, even when empty', () => {
        const section = render({ deleted: [], show: 'deleted' }).get(
            '[data-testid="deleted-assessments"]',
        );

        expect(section.attributes('open')).toBeDefined();
        expect(section.text()).toContain('Nothing is waiting to be erased.');
    });

    it('states the retention period the server sent', () => {
        const section = render({
            deleted: [deletedRow()],
            retentionDays: 7,
        }).get('[data-testid="deleted-assessments"]');

        expect(section.text()).toContain(
            'Deleted assessments are kept for 7 days, then erased for good.',
        );
    });

    it('names the owner on a row the caller does not own', () => {
        const wrapper = render({
            deleted: [deletedRow({ is_owner: false, owner_name: 'Sam' })],
        });

        expect(wrapper.get('[data-deleted-assessment="d1"]').text()).toContain(
            'owned by Sam',
        );
    });
});

describe('the actions', () => {
    function buttons(wrapper: ReturnType<typeof render>, uuid = 'd1') {
        return wrapper
            .get(`[data-deleted-assessment="${uuid}"]`)
            .findAll('button')
            .map((button) => button.text());
    }

    it('offers Restore on every row, and Erase only where the server allows it', () => {
        const wrapper = render({
            deleted: [
                deletedRow(),
                deletedRow({ uuid: 'd2', is_owner: false, can_erase: false }),
            ],
        });

        expect(buttons(wrapper, 'd1')).toEqual([
            'Restore',
            'Erase permanently',
        ]);
        expect(buttons(wrapper, 'd2')).toEqual(['Restore']);
    });

    it('restores through the restore route', async () => {
        const wrapper = render({ deleted: [deletedRow()] });

        await wrapper
            .get('[data-deleted-assessment="d1"]')
            .findAll('button')[0]!
            .trigger('click');

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0]![0]).toBe('/assessments.restore/d1');
    });

    it('erases only after a confirmation that says it cannot be undone', async () => {
        const wrapper = render({ deleted: [deletedRow()] });

        await wrapper
            .get('[data-deleted-assessment="d1"]')
            .findAll('button')[1]!
            .trigger('click');

        expect(destroy).not.toHaveBeenCalled();
        const dialog = wrapper.get('[data-testid="confirm"]');
        expect(dialog.text()).toContain('Old engagement');
        expect(dialog.text()).toContain('This cannot be undone.');

        await dialog.get('[data-testid="confirm-button"]').trigger('click');

        expect(destroy).toHaveBeenCalledTimes(1);
        expect(destroy.mock.calls[0]![0]).toBe('/assessments.erase/d1');
    });
});

describe('deleting a live assessment', () => {
    it('says where it goes and for how long', async () => {
        const wrapper = render({ retentionDays: 30 });

        const remove = wrapper
            .findAll('button')
            .find((button) => button.attributes('aria-label') === 'Delete');
        await remove!.trigger('click');

        expect(wrapper.get('[data-testid="confirm"]').text()).toContain(
            '“Payment platform” moves to Deleted on My assessments, where it can be restored for 30 days.',
        );
    });
});
