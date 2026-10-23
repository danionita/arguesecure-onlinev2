/**
 * W115: the generated password reaches the users table, from Inertia's flash
 * data rather than from a prop — so it is delivered with one response and is
 * not in browser history.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import Index from '../../../resources/js/Pages/Admin/Users/Index.vue';

const page = reactive<{ props: object; flash: Record<string, unknown> }>({
    props: {},
    flash: {},
});

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => page,
    router: { get: vi.fn(), delete: vi.fn(), post: vi.fn() },
    useForm: (data: Record<string, unknown>) =>
        reactive({ ...data, errors: {}, processing: false, put: vi.fn() }),
}));

const route = (name: string) => `/${name}`;

beforeEach(() => {
    page.flash = {};
    document.body.innerHTML = '';
});

function render() {
    return mount(Index, {
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

describe('the generated password banner', () => {
    it('shows the exact password the server flashed', () => {
        page.flash = { generatedPassword: 'Xk7-correct-horse-9' };

        const banner = render().get('[data-testid="generated-password"]');

        expect(banner.get('code').text()).toBe('Xk7-correct-horse-9');
    });

    it('is absent when nothing was flashed', () => {
        expect(
            render().find('[data-testid="generated-password"]').exists(),
        ).toBe(false);
    });

    it('is absent for an empty string', () => {
        page.flash = { generatedPassword: '' };

        expect(
            render().find('[data-testid="generated-password"]').exists(),
        ).toBe(false);
    });
});
