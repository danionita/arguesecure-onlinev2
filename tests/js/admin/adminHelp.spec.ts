/**
 * The admin help index: its pages, and the search results in plain text.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Help from '../../../resources/js/Pages/Admin/Help.vue';

vi.mock('@inertiajs/vue3', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/vue3')>(
            '@inertiajs/vue3',
        );

    return {
        ...actual,
        Head: { template: '<div><slot /></div>' },
        Link: { template: '<a><slot /></a>' },
        router: { get: vi.fn() },
    };
});

const pages = [
    { slug: 'users', title: 'Users' },
    { slug: 'email', title: 'Email' },
];

function page(props: Record<string, unknown> = {}) {
    return mount(Help, {
        props: {
            pages,
            query: '',
            results: [],
            minQuery: 2,
            ...props,
        } as never,
        global: {
            stubs: { AdminLayout: { template: '<div><slot /></div>' } },
            mocks: { route: (name: string) => `/${name}` },
        },
    });
}

describe('the admin help index', () => {
    it('lists the pages when there is no query', () => {
        const text = page().text();

        expect(text).toContain('Users');
        expect(text).toContain('Email');
        expect(page().find('[data-testid="results"]').exists()).toBe(false);
    });

    it('shows results with their snippet as text, never as markup', () => {
        const wrapper = page({
            query: 'smtp',
            results: [
                {
                    slug: 'email',
                    title: 'Email',
                    snippet: '… <b>SMTP</b> server …',
                },
            ],
        });

        expect(wrapper.get('[data-testid="results"]').text()).toContain(
            '<b>SMTP</b>',
        );
        expect(wrapper.find('b').exists()).toBe(false);
    });

    it('says so when nothing matches, and asks for more when the query is short', () => {
        expect(
            page({ query: 'zzz' }).get('[data-testid="no-results"]').text(),
        ).toContain('zzz');
        expect(page({ query: 's' }).text()).toContain('at least 2 characters');
    });
});
