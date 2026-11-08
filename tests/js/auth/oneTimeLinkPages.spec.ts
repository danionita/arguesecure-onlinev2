/**
 * The pages emailed one-time links open: a form that spends the link only on
 * submit, and one plain message for a link that is no good.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive, type DefineComponent } from 'vue';
import SetPassword from '../../../resources/js/Pages/Auth/SetPassword.vue';
import Unsubscribe from '../../../resources/js/Pages/Auth/Unsubscribe.vue';
import VerifyEmail from '../../../resources/js/Pages/Auth/VerifyEmail.vue';

vi.mock('@inertiajs/vue3', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/vue3')>(
            '@inertiajs/vue3',
        );

    return {
        ...actual,
        Head: { template: '<div><slot /></div>' },
        Link: { template: '<a><slot /></a>' },
        useForm: (data: Record<string, unknown>) =>
            reactive({
                ...data,
                errors: {},
                processing: false,
                post: vi.fn(),
                reset: vi.fn(),
            }),
    };
});

const mountPage = (component: object, valid: boolean) =>
    mount(component as unknown as DefineComponent, {
        props: { valid, token: 'abc' },
        global: {
            stubs: { GuestLayout: { template: '<div><slot /></div>' } },
            mocks: { route: (name: string) => `/${name}` },
        },
    });

describe('SetPassword', () => {
    it('shows the form for a good link', () => {
        const wrapper = mountPage(SetPassword, true);

        expect(wrapper.find('input[type="password"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="invalid-link"]').exists()).toBe(
            false,
        );
    });

    it('shows one message, and no form, for a link that is no good', () => {
        const wrapper = mountPage(SetPassword, false);

        expect(wrapper.find('input').exists()).toBe(false);
        expect(wrapper.get('[data-testid="invalid-link"]').text()).toContain(
            'used, has expired, or was replaced',
        );
    });
});

describe('VerifyEmail', () => {
    it('shows a button for a good link, and spends nothing until it is pressed', () => {
        const wrapper = mountPage(VerifyEmail, true);

        expect(wrapper.find('[data-testid="confirm"]').exists()).toBe(true);
    });

    it('shows a message and no button for a link that is no good', () => {
        const wrapper = mountPage(VerifyEmail, false);

        expect(wrapper.find('[data-testid="confirm"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="invalid-link"]').text()).toContain(
            'used or has expired',
        );
    });
});

describe('Unsubscribe', () => {
    it('names the kind and offers a button, which is the only thing that changes anything', () => {
        const wrapper = mount(Unsubscribe as unknown as DefineComponent, {
            props: { label: 'Someone mentions you in a comment', action: '/x' },
            global: {
                stubs: { GuestLayout: { template: '<div><slot /></div>' } },
                mocks: { route: (name: string) => `/${name}` },
            },
        });

        expect(wrapper.text()).toContain('Someone mentions you in a comment');
        expect(wrapper.find('[data-testid="unsubscribe"]').exists()).toBe(true);
    });
});

describe('ResetPassword', () => {
    it('shows the form for a good link and one message for a bad one', async () => {
        const ResetPassword = (
            await import('../../../resources/js/Pages/Auth/ResetPassword.vue')
        ).default;

        expect(
            mountPage(ResetPassword, true)
                .find('input[type="password"]')
                .exists(),
        ).toBe(true);
        const bad = mountPage(ResetPassword, false);
        expect(bad.find('input').exists()).toBe(false);
        expect(bad.get('[data-testid="invalid-link"]').text()).toContain(
            'used, has expired, or was replaced',
        );
    });
});
