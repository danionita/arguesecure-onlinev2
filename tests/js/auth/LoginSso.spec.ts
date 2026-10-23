/**
 * The "Sign in with ..." control on the login page (W117, D74).
 *
 * It must be a plain anchor. `Button`'s `href` renders an Inertia `<Link>`,
 * which fetches by XHR; the redirect to the identity provider is another
 * origin, so the browser blocks it and the user is left on the login page.
 * This was found by driving a real Keycloak, not by the unit tests.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Login from '../../../resources/js/Pages/Auth/Login.vue';

vi.mock('@inertiajs/vue3', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/vue3')>(
            '@inertiajs/vue3',
        );

    return {
        ...actual,
        Head: { template: '<div><slot /></div>' },
        Link: {
            name: 'Link',
            props: ['href'],
            template: '<a data-inertia-link :href="href"><slot /></a>',
        },
        usePage: () => ({ props: pageProps }),
        useForm: (data: Record<string, unknown>) => ({
            ...data,
            errors: {},
            processing: false,
            post: vi.fn(),
            reset: vi.fn(),
        }),
    };
});

const route = (name: string) => `/${name}`;
const pageProps: Record<string, unknown> = {};

function render(sso: { label: string; url: string; only: boolean } | null) {
    return mount(Login, {
        props: { sso },
        global: { config: { globalProperties: { route } } },
    });
}

describe('the SSO control', () => {
    it('shows the refusal a provider sign-in redirects back with', () => {
        pageProps.errors = {
            email: 'These credentials do not match our records.',
        };
        const wrapper = render(null);
        pageProps.errors = {};

        expect(wrapper.get('[data-test="login-error"]').text()).toBe(
            'These credentials do not match our records.',
        );
        expect(render(null).find('[data-test="login-error"]').exists()).toBe(
            false,
        );
    });

    it('is a plain anchor to the redirect route, not an Inertia link', () => {
        const link = render({
            label: 'Keycloak',
            url: '/auth/oidc/redirect',
            only: false,
        }).get('[data-test="sso-link"]');

        expect(link.element.tagName).toBe('A');
        expect(link.attributes('href')).toBe('/auth/oidc/redirect');
        expect(link.attributes('data-inertia-link')).toBeUndefined();
        expect(link.text()).toBe('Sign in with Keycloak');
    });

    it('is absent when no provider is configured', () => {
        expect(render(null).find('[data-test="sso-link"]').exists()).toBe(
            false,
        );
    });

    it('says passwords are for administrators in SSO-only mode', () => {
        const text = render({
            label: 'Keycloak',
            url: '/auth/oidc/redirect',
            only: true,
        }).text();

        expect(text).toContain('Password sign-in is for administrators only.');
    });
});
