/**
 * The self-registration page and its link on the login page. The form is shown
 * until a request has been made; the message after it is the same in every case.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive, type DefineComponent } from 'vue';
import Login from '../../../resources/js/Pages/Auth/Login.vue';
import Register from '../../../resources/js/Pages/Auth/Register.vue';

vi.mock('@inertiajs/vue3', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/vue3')>(
            '@inertiajs/vue3',
        );

    return {
        ...actual,
        Head: { template: '<div><slot /></div>' },
        Link: { template: '<a><slot /></a>' },
        usePage: () => ({ props: {} }),
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

const options = {
    global: {
        stubs: { GuestLayout: { template: '<div><slot /></div>' } },
        mocks: { route: (name: string) => `/${name}` },
    },
};

describe('Register', () => {
    it('shows the form and the allowed domains', () => {
        const wrapper = mount(Register as unknown as DefineComponent, {
            props: { domains: ['corp.example'] },
            ...options,
        });

        expect(wrapper.find('input[type="email"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('corp.example');
        expect(wrapper.find('[data-test="registration-sent"]').exists()).toBe(
            false,
        );
    });

    it('shows one message, and no form, once a request was made', () => {
        const wrapper = mount(Register as unknown as DefineComponent, {
            props: { domains: [], sent: true },
            ...options,
        });

        expect(wrapper.find('input').exists()).toBe(false);
        expect(wrapper.get('[data-test="registration-sent"]').text()).toContain(
            'If the address can be registered',
        );
    });
});

describe('Login registration link', () => {
    it('appears only when registration is open', () => {
        const closed = mount(Login as unknown as DefineComponent, {
            props: {},
            ...options,
        });
        const open = mount(Login as unknown as DefineComponent, {
            props: { canRegister: true },
            ...options,
        });

        expect(closed.find('[data-test="register-link"]').exists()).toBe(false);
        expect(open.find('[data-test="register-link"]').exists()).toBe(true);
    });
});
