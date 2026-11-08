/**
 * The admin **Email** tab.
 *
 * - A field the environment sets is read-only and says so.
 * - The password is write-only: the page shows whether one is set, never a value.
 * - Plain, unencrypted SMTP to a remote host carries a warning.
 * - Sending a test needs a saved configuration and no unsaved edits.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import Mail from '../../../resources/js/Pages/Admin/Mail.vue';

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
                isDirty: false,
                put: vi.fn(),
            }),
    };
});

const NONE = { value: '', source: 'none' };

function page(overrides: Record<string, unknown> = {}) {
    const props = {
        mail: {
            host: NONE,
            port: NONE,
            encryption: { value: 'starttls', source: 'none' },
            username: NONE,
            from_address: NONE,
            from_name: NONE,
            password: { set: false, source: 'none' },
        },
        notifications: [],
        notificationTitles: false,
        templates: [],
        limits: { subject: 200, body: 5000 },
        configured: false,
        enabled: false,
        ...overrides,
    };

    return mount(Mail, {
        props: props as never,
        global: {
            stubs: { AdminLayout: { template: '<div><slot /></div>' } },
            mocks: { route: (name: string) => `/${name}` },
        },
    });
}

describe('the Email tab', () => {
    it('says mail is off when nothing is configured', () => {
        const wrapper = page();

        expect(wrapper.get('[data-testid="mail-status"]').text()).toBe(
            'off, not configured',
        );
        expect(
            wrapper.get('[data-testid="send-test"]').attributes('disabled'),
        ).toBeDefined();
    });

    it('says what it is waiting for once configured, and on once tested', () => {
        expect(
            page({ configured: true })
                .get('[data-testid="mail-status"]')
                .text(),
        ).toBe('off, send a test message to turn it on');
        expect(
            page({ configured: true, enabled: true })
                .get('[data-testid="mail-status"]')
                .text(),
        ).toBe('on');
    });

    it('marks an environment-fixed field and disables it', () => {
        const wrapper = page({
            mail: {
                host: { value: 'smtp.env.test', source: 'environment' },
                port: NONE,
                encryption: { value: 'starttls', source: 'none' },
                username: NONE,
                from_address: NONE,
                from_name: NONE,
                password: { set: true, source: 'environment' },
            },
        });

        expect(wrapper.find('[data-testid="env-host"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="env-password"]').exists()).toBe(
            true,
        );
        expect(
            wrapper
                .get('input[placeholder="smtp.example.org"]')
                .attributes('disabled'),
        ).toBeDefined();
    });

    it('never holds a stored password, only whether one is set', () => {
        const wrapper = page({
            mail: {
                host: NONE,
                port: NONE,
                encryption: { value: 'starttls', source: 'none' },
                username: NONE,
                from_address: NONE,
                from_name: NONE,
                password: { set: true, source: 'stored' },
            },
        });
        const input = wrapper.get('input[type="password"]');

        expect((input.element as HTMLInputElement).value).toBe('');
        expect(input.attributes('placeholder')).toBe(
            'Set. Type to replace it.',
        );
    });

    it('warns about no encryption to a remote host, and not to this machine', async () => {
        const remote = page({
            mail: {
                host: { value: 'smtp.example.org', source: 'stored' },
                port: NONE,
                encryption: { value: 'none', source: 'stored' },
                username: NONE,
                from_address: NONE,
                from_name: NONE,
                password: { set: false, source: 'none' },
            },
        });
        expect(remote.find('[data-testid="plain-warning"]').exists()).toBe(
            true,
        );

        const local = page({
            mail: {
                host: { value: 'localhost', source: 'stored' },
                port: NONE,
                encryption: { value: 'none', source: 'stored' },
                username: NONE,
                from_address: NONE,
                from_name: NONE,
                password: { set: false, source: 'none' },
            },
        });
        expect(local.find('[data-testid="plain-warning"]').exists()).toBe(
            false,
        );
    });
});
