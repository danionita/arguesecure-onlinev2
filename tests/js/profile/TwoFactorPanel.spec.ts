/**
 * The profile page's two-factor panel (W116, D77, D78).
 *
 * Three states of one panel, and what each may and may not show:
 *
 * - **Off**: a password box and nothing else — no QR code, no key, no codes.
 * - **Enrolling**: the QR code and the key as text, and a code box. The page
 *   says that nothing is on until a code is entered.
 * - **On**: how many recovery codes are left, never the codes themselves
 *   (they appear only straight after they are made), and the two actions
 *   that need the password.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import TwoFactorPanel, {
    type TwoFactorState,
} from '../../../resources/js/Components/Profile/TwoFactorPanel.vue';

interface Call {
    verb: string;
    url: string;
    data: Record<string, unknown>;
}

const calls: Call[] = [];

vi.mock('@inertiajs/vue3', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/vue3')>(
            '@inertiajs/vue3',
        );

    return {
        ...actual,
        useForm: (data: Record<string, unknown>) => {
            const form = reactive({
                ...data,
                errors: {} as Record<string, string>,
                processing: false,
                reset: vi.fn(),
            }) as Record<string, unknown>;
            for (const verb of ['post', 'delete']) {
                form[verb] = (url: string) => {
                    const { errors, processing, reset, ...rest } = form;
                    void errors;
                    void processing;
                    void reset;
                    calls.push({
                        verb,
                        url,
                        data: Object.fromEntries(
                            Object.entries(rest).filter(
                                ([, v]) => typeof v !== 'function',
                            ),
                        ),
                    });
                };
            }
            return form;
        },
    };
});

const route = (name: string) => `/${name}`;

const OFF: TwoFactorState = {
    enabled: false,
    recovery_codes_remaining: 0,
    required: false,
    pending: null,
};
const ENROLLING: TwoFactorState = {
    ...OFF,
    pending: {
        secret: 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP',
        qr: 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=',
    },
};
const ON: TwoFactorState = {
    ...OFF,
    enabled: true,
    recovery_codes_remaining: 7,
};

function render(
    twoFactor: TwoFactorState,
    recoveryCodes: string[] | null = null,
) {
    return mount(TwoFactorPanel, {
        props: { twoFactor, recoveryCodes },
        global: { config: { globalProperties: { route } } },
    });
}

function text(wrapper: ReturnType<typeof render>): string {
    return wrapper.text().replace(/\s+/g, ' ');
}

beforeEach(() => {
    calls.length = 0;
    // The script calls `route()` as a global; the template reads it as a
    // Vue global property. Both are Ziggy, so both are stubbed.
    (globalThis as unknown as { route: typeof route }).route = route;
});

describe('off', () => {
    it('asks for the password and shows no secret, QR code or codes', () => {
        const wrapper = render(OFF);

        expect(wrapper.find('input[type="password"]').exists()).toBe(true);
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('[data-test="secret"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="recovery-codes"]').exists()).toBe(
            false,
        );
        expect(text(wrapper)).toContain('Set up');
    });

    it('says when an administrator requires it', () => {
        expect(text(render({ ...OFF, required: true }))).toContain(
            'Your administrator requires two-factor authentication',
        );
        expect(text(render(OFF))).not.toContain('administrator requires');
    });

    it('starts an enrolment with the password', async () => {
        const wrapper = render(OFF);
        await wrapper.get('input[type="password"]').setValue('hunter2hunter2');
        await wrapper.get('form').trigger('submit');

        expect(calls).toEqual([
            {
                verb: 'post',
                url: '/profile.two-factor.store',
                data: { password: 'hunter2hunter2' },
            },
        ]);
    });
});

describe('enrolling', () => {
    it('shows the QR code and the key as text, and says nothing is on yet', () => {
        const wrapper = render(ENROLLING);

        expect(wrapper.get('img').attributes('src')).toBe(
            ENROLLING.pending?.qr,
        );
        expect(wrapper.get('[data-test="secret"]').text()).toBe(
            ENROLLING.pending?.secret,
        );
        expect(text(wrapper)).toContain('Nothing is turned on until you do');
    });

    it('confirms with the code and cancels without one', async () => {
        const wrapper = render(ENROLLING);
        await wrapper.get('input').setValue('123456');
        await wrapper.get('form').trigger('submit');
        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'Cancel')!
            .trigger('click');

        expect(calls.map((c) => [c.verb, c.url])).toEqual([
            ['post', '/profile.two-factor.confirm'],
            ['delete', '/profile.two-factor.cancel'],
        ]);
        expect(calls[0].data).toEqual({ code: '123456' });
    });
});

describe('on', () => {
    it('shows how many recovery codes are left, pluralised', () => {
        expect(text(render(ON))).toContain('7 recovery codes left');
        expect(text(render({ ...ON, recovery_codes_remaining: 1 }))).toContain(
            '1 recovery code left',
        );
        expect(text(render(ON))).not.toContain('Set up');
    });

    it('shows recovery codes only when the server has just made them', () => {
        const codes = ['abcde-fghjk', 'mnpqr-stuvw'];
        const wrapper = render(ON, codes);
        const shown = wrapper.get('[data-test="recovery-codes"]').text();

        expect(shown).toContain('abcde-fghjk');
        expect(shown).toContain('mnpqr-stuvw');
        expect(shown).toContain('not shown again');
        expect(render(ON).find('[data-test="recovery-codes"]').exists()).toBe(
            false,
        );
    });

    it('turning off asks for the password and a code', async () => {
        const wrapper = render(ON);
        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'Turn off')!
            .trigger('click');
        const inputs = wrapper.findAll('input');
        await inputs[0].setValue('the-password');
        await inputs[1].setValue('654321');
        await wrapper.get('form').trigger('submit');

        expect(calls).toEqual([
            {
                verb: 'delete',
                url: '/profile.two-factor.destroy',
                data: { password: 'the-password', code: '654321' },
            },
        ]);
    });

    it('new recovery codes ask for the password', async () => {
        const wrapper = render(ON);
        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'New recovery codes')!
            .trigger('click');
        const inputs = wrapper.findAll('input');
        await inputs[0].setValue('the-password');
        await inputs[1].setValue('654321');
        await wrapper.get('form').trigger('submit');

        expect(calls).toEqual([
            {
                verb: 'post',
                url: '/profile.two-factor.recovery-codes',
                data: { password: 'the-password', code: '654321' },
            },
        ]);
    });
});
