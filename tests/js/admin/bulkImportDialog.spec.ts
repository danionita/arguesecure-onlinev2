/**
 * Bulk import is a dialog on the Users tab (2.5.1), not a tab of its own.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import Index from '../../../resources/js/Pages/Admin/Users/Index.vue';

const post = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: {
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    usePage: () => ({ props: {}, url: '/admin/users' }),
    router: { get: vi.fn(), delete: vi.fn(), post: vi.fn() },
    useForm: (data: Record<string, unknown>) =>
        reactive({
            ...data,
            errors: {},
            processing: false,
            post,
            put: vi.fn(),
            reset: vi.fn(),
        }),
}));

const route = (name: string) => `/${name}`;

beforeEach(() => {
    post.mockClear();
    (globalThis as unknown as { route: typeof route }).route = route;
    document.body.innerHTML = '';
});

function render() {
    return mount(Index, {
        attachTo: document.body,
        props: {
            users: { data: [], links: [], total: 0 },
            filters: { search: '', sort: 'name', direction: 'asc' },
        },
        global: {
            config: { globalProperties: { route } },
            stubs: { AdminLayout: { template: '<div><slot /></div>' } },
        },
    });
}

describe('Bulk import on the Users tab', () => {
    it('opens a dialog rather than navigating to another tab', async () => {
        const wrapper = render();

        expect(
            document.querySelector('[data-testid="bulk-import-dialog"]'),
        ).toBeNull();
        await wrapper.get('[data-testid="bulk-import-open"]').trigger('click');

        const dialog = document.querySelector(
            '[data-testid="bulk-import-dialog"]',
        );
        expect(dialog).not.toBeNull();
        expect(dialog!.textContent).toContain('Paste CSV');
    });

    it('sends the CSV to the preview step, keeping the dialog if it is rejected', async () => {
        const wrapper = render();
        await wrapper.get('[data-testid="bulk-import-open"]').trigger('click');

        const form = document.querySelector(
            '[data-testid="bulk-import-dialog"] form',
        ) as HTMLFormElement;
        form.dispatchEvent(new Event('submit'));

        expect(post).toHaveBeenCalledWith('/admin.users.import.preview', {
            preserveState: true,
        });
    });
});
