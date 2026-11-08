/**
 * The Admin tab strip marks the section the URL is in, and only that one.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import AdminLayout from '../../../resources/js/Pages/Admin/AdminLayout.vue';

const url = vi.hoisted(() => ({ value: '/admin/help/email?x=1' }));

vi.mock('@inertiajs/vue3', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/vue3')>(
            '@inertiajs/vue3',
        );

    return {
        ...actual,
        Link: {
            props: ['href'],
            template: '<a :href="href"><slot /></a>',
        },
        usePage: () => ({ url: url.value }),
    };
});

function tabs(current: string) {
    url.value = current;
    // The tab links call the global route() from the script, not a mock.
    (globalThis as { route?: unknown }).route = (name: string) =>
        `/${name.replace('.index', '').replace('.edit', '').replace('.', '/')}`;

    return mount(AdminLayout, {
        props: { title: 'x' },
        global: {
            stubs: {
                AuthenticatedLayout: {
                    template: '<div><slot name="header" /><slot /></div>',
                },
            },
        },
    });
}

describe('the Admin tab strip', () => {
    it('marks only the Help tab on an admin help page', () => {
        const current = tabs('/admin/help/email?x=1').findAll(
            '[aria-current="page"]',
        );

        expect(current).toHaveLength(1);
        expect(current[0].text()).toBe('Help');
    });

    it('marks the Email tab, and not Help, on the email settings page', () => {
        const current = tabs('/admin/mail').findAll('[aria-current="page"]');

        expect(current).toHaveLength(1);
        expect(current[0].text()).toBe('Email');
    });
});
