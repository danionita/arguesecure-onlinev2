/**
 * The admin **Authentication** tab (W133, D105-D108).
 *
 * - A field the environment sets is read-only and says so: editing a value
 *   that would be ignored is a control that appears to do something and does
 *   not.
 * - The client secret is write-only: the page shows whether one is set and
 *   never a value.
 * - SSO only cannot be switched on without a provider.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import Authentication from '../../../resources/js/Pages/Admin/Authentication.vue';

vi.mock('@inertiajs/vue3', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/vue3')>(
            '@inertiajs/vue3',
        );

    return {
        ...actual,
        Head: { template: '<div><slot /></div>' },
        useForm: (data: Record<string, unknown>) =>
            reactive({
                ...data,
                errors: {},
                processing: false,
                put: vi.fn(),
            }),
    };
});

const NONE = { value: '', source: 'none' };

function page(overrides: Record<string, unknown> = {}) {
    const props = {
        settings: { require_two_factor: false, sso_only: false },
        oidc: {
            issuer: NONE,
            client_id: NONE,
            button_label: NONE,
            allowed_domains: NONE,
            trust_email: { value: false, source: 'none' },
            client_secret: { set: false, source: 'none' },
        },
        enabled: false,
        redirectUri: 'https://app.example.test/auth/oidc/callback',
        ...overrides,
    };

    return mount(Authentication, {
        props: props as never,
        global: {
            stubs: { AdminLayout: { template: '<div><slot /></div>' } },
            mocks: { route: (name: string) => `/${name}` },
        },
    });
}

describe('the Authentication tab', () => {
    it('carries both sign-in switches and shows the redirect URI to register', () => {
        const wrapper = page();
        const text = wrapper.text().replace(/\s+/g, ' ');

        expect(text).toContain(
            'Require two-factor authentication for password sign-in',
        );
        expect(text).toContain('Sign in with SSO only');
        expect(wrapper.get('[data-testid="redirect-uri"]').text()).toBe(
            'https://app.example.test/auth/oidc/callback',
        );
        expect(wrapper.get('[data-testid="sso-status"]').text()).toBe(
            'not configured',
        );
    });

    it('marks an environment-fixed field and disables it', () => {
        const wrapper = page({
            oidc: {
                issuer: {
                    value: 'https://env.example.test',
                    source: 'environment',
                },
                client_id: NONE,
                button_label: NONE,
                allowed_domains: NONE,
                trust_email: { value: false, source: 'none' },
                client_secret: { set: true, source: 'environment' },
            },
            enabled: true,
        });

        expect(wrapper.get('[data-testid="env-issuer"]').text()).toContain(
            "server's environment",
        );
        expect(wrapper.get('[data-testid="env-secret"]').exists()).toBe(true);
        const inputs = wrapper.findAll('input');
        const issuer = inputs.find(
            (i) =>
                (i.element as HTMLInputElement).value ===
                'https://env.example.test',
        );
        expect(issuer?.attributes('disabled')).toBeDefined();
        expect(
            wrapper.get('input[type="password"]').attributes('disabled'),
        ).toBeDefined();
    });

    it('never puts a stored secret in the markup, only that it is set', () => {
        const wrapper = page({
            oidc: {
                issuer: { value: 'https://idp.example.test', source: 'stored' },
                client_id: { value: 'abc', source: 'stored' },
                button_label: NONE,
                allowed_domains: NONE,
                trust_email: { value: false, source: 'none' },
                client_secret: { set: true, source: 'stored' },
            },
            enabled: true,
        });
        const secret = wrapper.get('input[type="password"]');

        expect((secret.element as HTMLInputElement).value).toBe('');
        expect(secret.attributes('placeholder')).toBe(
            'Set. Type to replace it.',
        );
        expect(wrapper.text()).toContain('Remove the stored secret');
    });

    it('does not offer SSO only until a provider is configured', () => {
        const without = page();
        const toggle = without
            .findAll('button[role="switch"], button')
            .find(
                (b) =>
                    b.attributes('aria-checked') !== undefined &&
                    b.attributes('disabled') !== undefined,
            );

        expect(toggle).toBeDefined();
        expect(without.text()).toContain('Needs a provider below first.');
        expect(page({ enabled: true }).text()).not.toContain(
            'Needs a provider below first.',
        );
    });
});
