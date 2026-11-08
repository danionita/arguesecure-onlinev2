/**
 * One message kind's editor: it lists the placeholders the kind offers, shows
 * the footer that cannot be removed, and only offers a reset once the wording
 * has been edited.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import MailTemplateEditor from '../../../resources/js/Pages/Admin/MailTemplateEditor.vue';

vi.mock('@inertiajs/vue3', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/vue3')>(
            '@inertiajs/vue3',
        );

    return {
        ...actual,
        useForm: (data: Record<string, unknown>) =>
            reactive({
                ...data,
                errors: {},
                processing: false,
                isDirty: false,
                put: vi.fn(),
                defaults: vi.fn(),
            }),
        router: { delete: vi.fn(), on: vi.fn(() => () => {}) },
    };
});

function editor(customised: boolean) {
    return mount(MailTemplateEditor, {
        props: {
            template: {
                kind: 'invitation',
                label: 'Invitation',
                placeholders: {
                    name: "the person's name",
                    set_password_link: 'the one-time link',
                },
                footer: 'You are receiving this because an administrator made an account.',
                defaults: { subject: 'Hello', body: 'Body' },
                subject: 'Hello',
                body: 'Body',
                customised,
            },
            limits: { subject: 200, body: 5000 },
        },
        global: { mocks: { route: (name: string) => `/${name}` } },
    });
}

describe('the template editor', () => {
    it('lists the placeholders and the fixed footer', () => {
        const text = editor(false).text().replace(/\s+/g, ' ');

        expect(text).toContain('{{name}}');
        expect(text).toContain('{{set_password_link}}');
        expect(text).toContain('cannot be removed');
        expect(text).toContain('because an administrator made an account');
    });

    it('offers a reset only for wording that has been edited', () => {
        expect(
            editor(false).get('[data-testid="reset"]').attributes('disabled'),
        ).toBeDefined();
        expect(
            editor(true).get('[data-testid="reset"]').attributes('disabled'),
        ).toBeUndefined();
        expect(editor(true).text()).toContain('(edited)');
    });
});
