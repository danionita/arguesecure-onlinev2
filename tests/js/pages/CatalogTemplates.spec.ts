/**
 * The templates page's empty/populated pairing (W86 item 15).
 *
 * `EmptyState.vue` carries its own padding (`px-6 py-10`); this page used to
 * nest it inside the bordered panel `TemplateCard` rows share, which is the
 * one place in the codebase that put a self-padded `EmptyState` inside a
 * second container assumed to supply its own wrapper and never did — "an
 * unstyled container … a wrapper was assumed and never applied"
 * (PLAN-2.5.md), applied to a panel rather than a dialog. `Pages/Assessment
 * /Index.vue` is the sibling that shows the fix: it renders `EmptyState` bare
 * and gives its populated rows no shared group border at all.
 *
 * jsdom has no layout engine, so this asserts the *structure* the fix
 * depends on rather than pixels: the empty state is not a descendant of the
 * bordered panel at all (so there is nothing for it to sit flush against),
 * and the panel itself carries no padding utility that would double up
 * against `TemplateCard`'s own `px-4 py-3` once populated.
 */
import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import type { TemplateSummary } from '../../../resources/js/Components/Catalog/types';
import Templates from '../../../resources/js/Pages/Catalog/Templates.vue';

const route = () => '/templates.use/x';

const router = vi.hoisted(() => ({
    visit: vi.fn(),
    post: vi.fn(),
    delete: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div><slot /></div>' },
    Link: { name: 'Link', template: '<a><slot /></a>' },
    router,
}));

beforeAll(() => {
    (globalThis as unknown as { route: typeof route }).route = route;
});

function template(over: Partial<TemplateSummary> = {}): TemplateSummary {
    return {
        uuid: 't1',
        title: 'ISO 27001 baseline',
        description: 'A starting point for a management-system audit.',
        currency: 'EUR',
        updatedAt: null,
        ...over,
    };
}

function page(templates: TemplateSummary[], canManage = false) {
    return mount(Templates, {
        props: { templates, canManage },
        global: {
            mocks: { route },
            stubs: {
                AuthenticatedLayout: {
                    template: '<div><slot name="header" /><slot /></div>',
                },
                ConfirmDialog: {
                    props: ['show', 'title'],
                    emits: ['confirm', 'cancel'],
                    template:
                        '<div v-if="show" data-testid="confirm"><button data-testid="ok" @click="$emit(\'confirm\')" /></div>',
                },
            },
        },
    });
}

describe('no templates: the empty state stands alone (item 15)', () => {
    it('renders EmptyState with no bordered panel wrapping it', () => {
        const wrapper = page([]);

        // The panel that exists purely for populated rows must not exist at
        // all here — not present with a padding class, not present without
        // one. Its absence is what guarantees EmptyState cannot end up flush
        // against a border it does not share a parent with.
        expect(wrapper.find('[data-testid="templates-panel"]').exists()).toBe(
            false,
        );
        expect(wrapper.text()).toContain('No templates yet');
    });

    it('EmptyState keeps its own padding, unmodified by this page', () => {
        const wrapper = page([]);
        const emptyState = wrapper.find(
            '.flex.flex-col.items-center.gap-2.px-6.py-10',
        );

        expect(emptyState.exists()).toBe(true);
    });
});

describe('with templates: the panel wraps rows only, not double-padded (item 15)', () => {
    it('renders the bordered panel with no padding utility of its own', () => {
        const wrapper = page([template()]);
        const panel = wrapper.get('[data-testid="templates-panel"]');

        // Border and background, never a padding class: TemplateCard's own
        // `px-4 py-3` is the only padding a row gets. A `p-*`/`px-*`/`py-*`
        // class here would double it up.
        expect(panel.classes()).toContain('border-border-subtle');
        expect(panel.classes()).toContain('rounded-lg');
        expect(panel.classes()).toContain('border');
        expect(panel.classes().some((c) => /^p[xy]?-/.test(c))).toBe(false);
    });

    it('renders no EmptyState when there is at least one template', () => {
        const wrapper = page([template()]);

        expect(wrapper.text()).not.toContain('No templates yet');
    });

    it('each row keeps its own padding regardless of the panel', () => {
        const wrapper = page([
            template({ uuid: 't1' }),
            template({ uuid: 't2' }),
        ]);
        const rows = wrapper.findAll('[data-testid="templates-panel"] > div');

        expect(rows).toHaveLength(2);
        for (const row of rows) {
            expect(row.classes()).toContain('px-4');
            expect(row.classes()).toContain('py-3');
        }
    });
});

describe('managing templates (W141)', () => {
    it('shows an admin New, Edit and Delete, and a non-admin none of them', () => {
        const admin = page([template()], true);
        expect(admin.text()).toContain('New template');
        expect(admin.text()).toContain('Edit');
        expect(admin.text()).toContain('Delete');

        const member = page([template()], false);
        expect(member.text()).not.toContain('New template');
        expect(member.text()).not.toContain('Edit');
        expect(member.text()).not.toContain('Delete');
        expect(member.text()).toContain('Use this template');
    });

    it('creates a template by posting to the create route', async () => {
        router.post.mockClear();
        const wrapper = page([], true);

        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'New template')!
            .trigger('click');

        expect(router.post).toHaveBeenCalledWith('/templates.use/x');
    });

    it('asks before deleting and deletes that template only on confirm', async () => {
        router.delete.mockClear();
        const wrapper = page(
            [template({ uuid: 't1' }), template({ uuid: 't2' })],
            true,
        );
        expect(wrapper.find('[data-testid="confirm"]').exists()).toBe(false);

        await wrapper
            .findAll('button')
            .filter((b) => b.text() === 'Delete')[1]
            .trigger('click');

        expect(router.delete).not.toHaveBeenCalled();
        expect(wrapper.find('[data-testid="confirm"]').exists()).toBe(true);

        await wrapper.get('[data-testid="ok"]').trigger('click');

        expect(router.delete).toHaveBeenCalledTimes(1);
        expect(router.delete.mock.calls[0][0]).toBe('/templates.use/x');
    });

    it('offers an admin to create one from the empty state', () => {
        expect(page([], true).text()).toContain('Create one here');
        expect(page([], false).text()).not.toContain('Create one here');
    });
});

describe('W140: the "Use this template" label stays on one line', () => {
    it('the button cannot be squeezed or wrapped by a long description', () => {
        const wrapper = page([template()]);
        const button = wrapper
            .findAll('button')
            .find((b) => b.text().includes('Use this template'));

        expect(button).toBeDefined();
        expect(button!.classes()).toContain('shrink-0');
        expect(button!.classes()).toContain('whitespace-nowrap');
    });
});
