/**
 * `Profile/Edit.vue` — the signed-in user's own account page.
 *
 * Four independent forms share one page, so the specs pin what each one sends
 * and where, when the current-password field appears, how server errors are
 * shown, and that deleting an account needs a password and a confirmation
 * rather than a single click.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import { hasUnsavedChanges } from '../../../resources/js/composables/useUnsavedChanges';
import Edit from '../../../resources/js/Pages/Profile/Edit.vue';

type Callbacks = { onSuccess?: () => void; onError?: () => void };

// useForm is called in a fixed order by the page: profile, password, delete.
const forms: Record<string, any>[] = []; // eslint-disable-line @typescript-eslint/no-explicit-any
const routerDelete = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div />' },
    usePage: () => ({
        props: {
            auth: { user: { name: 'Dana', email: 'dana@example.test' } },
        },
    }),
    useForm: (initial: Record<string, unknown>) => {
        const form = reactive({
            ...initial,
            errors: {} as Record<string, string>,
            processing: false,
            patch: vi.fn(),
            put: vi.fn(),
            delete: vi.fn(),
            reset: vi.fn(),
        });
        forms.push(form);
        return form;
    },
    router: {
        delete: (...a: unknown[]) => routerDelete(...a),
        on: () => () => {},
    },
}));

const route = (name: string, param?: string) =>
    param ? `/${name}/${param}` : `/${name}`;

interface Session {
    id: string;
    ip_address: string | null;
    user_agent: string | null;
    last_active_at: string;
    is_current: boolean;
}

function session(over: Partial<Session> = {}): Session {
    return {
        id: 's1',
        ip_address: '10.0.0.1',
        user_agent: 'Firefox',
        last_active_at: '5 minutes ago',
        is_current: false,
        ...over,
    };
}

function render(over: Record<string, unknown> = {}) {
    return mount(Edit, {
        attachTo: document.body,
        props: {
            sessions: [],
            ownedAssessmentCount: 0,
            // W116 (ProfileController) sends these on every visit; the page
            // reads twoFactor.enabled unconditionally. Harmless extra
            // attributes on a branch without W116.
            twoFactor: {
                enabled: false,
                recovery_codes_remaining: 0,
                required: false,
                pending: null,
            },
            recoveryCodes: null,
            ...over,
        },
        global: {
            config: { globalProperties: { route } },
            stubs: {
                AuthenticatedLayout: {
                    template: '<div><slot name="header" /><slot /></div>',
                },
                Modal: {
                    props: ['show', 'title'],
                    template:
                        '<div v-if="show" data-testid="modal"><h3>{{ title }}</h3><slot /><slot name="footer" /></div>',
                },
            },
        },
    });
}

const profile = () => forms[0];
const password = () => forms[1];
const del = () => forms[2];

function section(wrapper: ReturnType<typeof render>, heading: string) {
    return wrapper
        .findAll('section')
        .find((s) => s.get('h3').text() === heading)!;
}

beforeEach(() => {
    forms.length = 0;
    routerDelete.mockReset();
    (globalThis as unknown as { route: typeof route }).route = route;
});

describe('unsaved changes (W142)', () => {
    it('counts a changed name, but not a browser-filled current password', () => {
        const wrapper = render();
        profile().current_password = 'filled by a password manager';
        expect(hasUnsavedChanges()).toBe(false);

        profile().name = 'Dana B.';
        expect(hasUnsavedChanges()).toBe(true);
        wrapper.unmount();
    });

    it('counts a typed new password', () => {
        const wrapper = render();
        password().current_password = 'autofilled';
        expect(hasUnsavedChanges()).toBe(false);
        password().password = 'a new password';
        expect(hasUnsavedChanges()).toBe(true);
        wrapper.unmount();
    });
});

describe('profile details', () => {
    it('starts from the signed-in user and sends name and email to profile.update by PATCH', async () => {
        const wrapper = render();
        expect(profile().name).toBe('Dana');
        expect(profile().email).toBe('dana@example.test');

        await section(wrapper, 'Profile information')
            .get('form')
            .trigger('submit');

        expect(profile().patch).toHaveBeenCalledTimes(1);
        const [url, options] = profile().patch.mock.calls[0];
        expect(url).toBe('/profile.update');
        expect(options.preserveScroll).toBe(true);
    });

    it('asks for the current password only once the email has been changed', async () => {
        const wrapper = render();
        const info = section(wrapper, 'Profile information');
        expect(info.text()).not.toContain('Current password');

        await info.get('input[type="email"]').setValue('new@example.test');

        // Changing the email is the account-takeover step, so the server
        // demands the password; the field must be there to supply it.
        expect(info.text()).toContain('Current password');
        expect(info.text()).toContain('Required to change your email.');

        await info.get('input[type="email"]').setValue('dana@example.test');
        expect(info.text()).not.toContain('Current password');
    });

    it('clears the typed current password after a successful save', () => {
        const wrapper = render();
        section(wrapper, 'Profile information').get('form').trigger('submit');
        const { onSuccess } = profile().patch.mock.calls[0][1] as Callbacks;

        onSuccess!();

        expect(profile().reset).toHaveBeenCalledWith('current_password');
    });

    it('shows server validation errors beside the field they belong to', async () => {
        const wrapper = render();
        profile().errors = { email: 'The email has already been taken.' };
        await wrapper.vm.$nextTick();

        const info = section(wrapper, 'Profile information');
        expect(info.text()).toContain('The email has already been taken.');
        expect(info.text()).not.toContain('The name');
    });

    it('shows the status message only when one is passed', () => {
        expect(render().text()).not.toContain('Profile updated.');
        expect(render({ status: 'Profile updated.' }).text()).toContain(
            'Profile updated.',
        );
    });

    it('disables Save while the request is in flight', async () => {
        const wrapper = render();
        profile().processing = true;
        await wrapper.vm.$nextTick();

        const save = section(wrapper, 'Profile information').get(
            'button[type="submit"]',
        );
        expect(save.attributes('disabled')).toBeDefined();
    });
});

describe('change password', () => {
    it('sends the three fields to password.update by PUT', async () => {
        const wrapper = render();
        await section(wrapper, 'Change password').get('form').trigger('submit');

        expect(password().put).toHaveBeenCalledTimes(1);
        expect(password().put.mock.calls[0][0]).toBe('/password.update');
        expect(password().put.mock.calls[0][1].preserveScroll).toBe(true);
        expect(Object.keys(password()).slice(0, 3)).toEqual([
            'current_password',
            'password',
            'password_confirmation',
        ]);
    });

    it('states the minimum length', () => {
        expect(section(render(), 'Change password').text()).toContain(
            'At least 12 characters.',
        );
    });

    it('wipes every password field after a failed attempt, and the whole form after success', () => {
        const wrapper = render();
        section(wrapper, 'Change password').get('form').trigger('submit');
        const { onSuccess, onError } = password().put.mock
            .calls[0][1] as Callbacks;

        onError!();
        expect(password().reset).toHaveBeenLastCalledWith(
            'current_password',
            'password',
            'password_confirmation',
        );

        onSuccess!();
        expect(password().reset).toHaveBeenLastCalledWith();
    });

    it('shows a wrong-current-password error under that field', async () => {
        const wrapper = render();
        password().errors = { current_password: 'The password is incorrect.' };
        await wrapper.vm.$nextTick();

        expect(section(wrapper, 'Change password').text()).toContain(
            'The password is incorrect.',
        );
    });
});

describe('active sessions', () => {
    it('describes each session and marks the current device', () => {
        const text = section(
            render({
                sessions: [
                    session({ id: 'cur', is_current: true }),
                    session({ id: 'other', ip_address: '10.0.0.2' }),
                ],
            }),
            'Active sessions',
        ).text();

        expect(text).toContain('10.0.0.1');
        expect(text).toContain('10.0.0.2');
        expect(text.match(/this device/g)).toHaveLength(1);
        expect(text).toContain('Firefox');
        expect(text).toContain('last active 5 minutes ago');
    });

    it('writes "Unknown IP" and "Unknown device" when the session recorded neither', () => {
        const text = section(
            render({
                sessions: [session({ ip_address: null, user_agent: null })],
            }),
            'Active sessions',
        ).text();

        expect(text).toContain('Unknown IP');
        expect(text).toContain('Unknown device');
    });

    it('offers Revoke on other sessions only, never on the one in use', () => {
        const wrapper = render({
            sessions: [
                session({ id: 'cur', is_current: true }),
                session({ id: 'other' }),
            ],
        });

        const rows = section(wrapper, 'Active sessions').findAll('li');
        expect(rows[0].find('button').exists()).toBe(false);
        expect(rows[1].get('button').text()).toBe('Revoke');
    });

    it('revokes by DELETE on that session id, keeping scroll position', async () => {
        const wrapper = render({ sessions: [session({ id: 'other' })] });

        await section(wrapper, 'Active sessions')
            .get('button')
            .trigger('click');

        expect(routerDelete).toHaveBeenCalledWith(
            '/profile.sessions.destroy/other',
            { preserveScroll: true },
        );
    });
});

describe('delete account', () => {
    it('says how many owned assessments go with the account', () => {
        const text = section(
            render({ ownedAssessmentCount: 3 }),
            'Delete account',
        ).text();

        expect(text).toContain('and the 3 assessment(s) you own');
        expect(text).not.toContain('you own no assessments');
    });

    it('says the user owns none when the count is zero', () => {
        const text = section(render(), 'Delete account').text();

        expect(text).toContain('you own no assessments');
        expect(text).not.toContain('assessment(s)');
    });

    it('does not delete on the first click: it opens a confirmation that asks for the password', async () => {
        const wrapper = render();
        expect(wrapper.find('[data-testid="modal"]').exists()).toBe(false);

        await section(wrapper, 'Delete account').get('button').trigger('click');

        expect(del().delete).not.toHaveBeenCalled();
        const modal = wrapper.get('[data-testid="modal"]');
        expect(modal.text()).toContain('Delete your account');
        expect(modal.text()).toContain('Confirm your password');
    });

    it('warns in the dialog about the owned assessments too', async () => {
        const wrapper = render({ ownedAssessmentCount: 2 });
        await section(wrapper, 'Delete account').get('button').trigger('click');

        expect(wrapper.get('[data-testid="modal"]').text()).toContain(
            'also delete the 2 assessment(s) you own',
        );
    });

    it('sends the DELETE to profile.destroy only on confirm, then closes the dialog', async () => {
        const wrapper = render();
        await section(wrapper, 'Delete account').get('button').trigger('click');

        const confirm = wrapper
            .get('[data-testid="modal"]')
            .findAll('button')
            .find((b) => b.text() === 'Delete permanently')!;
        await confirm.trigger('click');

        expect(del().delete).toHaveBeenCalledTimes(1);
        expect(del().delete.mock.calls[0][0]).toBe('/profile.destroy');

        (del().delete.mock.calls[0][1] as Callbacks).onSuccess!();
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="modal"]').exists()).toBe(false);
    });

    it('closes the dialog on a failed delete so the error is not hidden behind it', async () => {
        const wrapper = render();
        await section(wrapper, 'Delete account').get('button').trigger('click');
        await wrapper
            .get('[data-testid="modal"]')
            .findAll('button')
            .find((b) => b.text() === 'Delete permanently')!
            .trigger('click');

        (del().delete.mock.calls[0][1] as Callbacks).onError!();
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="modal"]').exists()).toBe(false);
    });

    it('Cancel closes the dialog without sending anything', async () => {
        const wrapper = render();
        await section(wrapper, 'Delete account').get('button').trigger('click');
        await wrapper
            .get('[data-testid="modal"]')
            .findAll('button')
            .find((b) => b.text() === 'Cancel')!
            .trigger('click');

        expect(wrapper.find('[data-testid="modal"]').exists()).toBe(false);
        expect(del().delete).not.toHaveBeenCalled();
    });
});
