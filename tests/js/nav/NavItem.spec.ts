/**
 * `NavItem`'s own contract, isolated from `Sidebar.vue` (W82 review fix).
 *
 * The first version of this file duplicated its entire interactive element
 * — the `:is`, `:type`, `:href`, `:method`, `:as`, `:aria-current`, the class
 * list, the click guard, the icon and the badge markup — once in a
 * `v-if="compact"` branch wrapped in `Tooltip` and once again, nearly
 * identically, in `v-else`. The fix collapses both into one inner element,
 * wrapped by `Tooltip` or not (see the component's own docblock for why a
 * bare `Fragment` does not work for this and a tiny functional component
 * does). What is asserted here is the property that refactor is *for*: the
 * core interactive behaviour — `href`, `aria-current`, the current/hover
 * classes, the click guard — is identical whether `compact` is true or
 * false, read from one real render of each rather than compared against a
 * second, hand-written description of what each "should" do. A future
 * change that re-duplicates the element and edits only one copy is exactly
 * what this test would catch: the compact and non-compact renders would
 * stop agreeing on something this test compares directly between them.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import NavItem from '../../../resources/js/Components/Nav/NavItem.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        name: 'Link',
        props: ['href', 'method', 'as'],
        template: '<a :href="href" :data-method="method"><slot /></a>',
    },
}));

function render(over: Record<string, unknown> = {}) {
    return mount(NavItem, {
        props: {
            icon: 'layout-grid',
            label: 'Dashboard',
            href: '/dashboard',
            current: false,
            compact: false,
            ...over,
        },
    });
}

describe('the compact and non-compact renders agree on everything but presentation', () => {
    it('resolve to the same element type and href, wrapped or not', () => {
        const panel = render({ compact: false });
        const rail = render({ compact: true });

        const panelLink = panel.get('a');
        const railLink = rail.get('a');

        expect(railLink.attributes('href')).toBe(panelLink.attributes('href'));
        expect(railLink.element.tagName).toBe(panelLink.element.tagName);
    });

    it('carry the same current/hover classes at both widths', () => {
        for (const compact of [false, true]) {
            const current = render({ compact, current: true }).get('a');
            const notCurrent = render({ compact, current: false }).get('a');

            expect(current.classes()).toContain('bg-surface-2');
            expect(current.classes()).toContain('text-text-primary');
            expect(notCurrent.classes()).not.toContain('bg-surface-2');
            expect(notCurrent.classes()).toContain('text-text-secondary');
        }
    });

    it('mark aria-current="page" identically at both widths', () => {
        for (const compact of [false, true]) {
            expect(
                render({ compact, current: true })
                    .get('a')
                    .attributes('aria-current'),
            ).toBe('page');
            expect(
                render({ compact, current: false })
                    .get('a')
                    .attributes('aria-current'),
            ).toBeUndefined();
        }
    });

    it('emit select from a button (no href) the same way at both widths', async () => {
        for (const compact of [false, true]) {
            const wrapper = render({ compact, href: undefined });
            const button = wrapper.get('button');

            expect(button.attributes('type')).toBe('button');
            await button.trigger('click');
            expect(wrapper.emitted('select')).toHaveLength(1);
        }
    });

    it('post as a button through Link identically at both widths', () => {
        for (const compact of [false, true]) {
            const link = render({
                compact,
                href: '/logout',
                method: 'post',
            }).get('a');

            expect(link.attributes('data-method')).toBe('post');
        }
    });
});

describe('the badge, presented differently but counted the same', () => {
    it('folds into the accessible name in the rail, and stays visible text in the panel', () => {
        const panel = render({ compact: false, badge: 3 });
        const rail = render({ compact: true, badge: 3 });

        expect(panel.get('a').text()).toContain('3');
        expect(panel.get('a').attributes('aria-label')).toBeUndefined();

        expect(rail.get('a').attributes('aria-label')).toBe(
            'Dashboard, 3 unread',
        );
        // Decorative dot only — the count already lives in the accessible
        // name above, and printing it as text too would announce it twice.
        expect(rail.get('a').text()).not.toContain('3');
    });
});

/**
 * W82 review, minor: the `Tooltip` component is reachable by keyboard as
 * well as by hover (it toggles on `@focusin`/`@focusout`, per its own
 * docblock), but nothing exercised that path for a compact `NavItem`
 * before this. `Tooltip` is pre-existing and reused rather than built here,
 * so this is closing a coverage gap, not a functional fix.
 */
describe('the rail tooltip is reachable by keyboard, not only by hover', () => {
    it('reveals the label on focus and hides it on blur', async () => {
        const wrapper = render({ compact: true });
        const trigger = wrapper.get('span.relative.inline-flex');

        // Teleported to `body` and placed to the right (2.5.1): above or
        // below, the bubble covered the next icon in the rail.
        const tooltip = () => document.querySelector('[role="tooltip"]');
        expect(tooltip()).toBeNull();

        await trigger.trigger('focusin');
        expect(tooltip()?.textContent?.trim()).toBe('Dashboard');
        expect(tooltip()?.getAttribute('data-placement')).toBe('right');

        await trigger.trigger('focusout');
        expect(tooltip()).toBeNull();
        wrapper.unmount();
    });
});
