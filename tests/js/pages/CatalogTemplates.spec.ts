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

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div><slot /></div>' },
    router: { visit: vi.fn() },
}));

beforeAll(() => {
    (globalThis as unknown as { route: typeof route }).route = route;
});

function template(over: Partial<TemplateSummary> = {}): TemplateSummary {
    return {
        uuid: 't1',
        title: 'ISO 27001 baseline',
        description: 'A starting point for a management-system audit.',
        owner: 'Dana',
        currency: 'EUR',
        updatedAt: null,
        ...over,
    };
}

function page(templates: TemplateSummary[]) {
    return mount(Templates, {
        props: { templates },
        global: {
            stubs: {
                AuthenticatedLayout: { template: '<div><slot /></div>' },
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
