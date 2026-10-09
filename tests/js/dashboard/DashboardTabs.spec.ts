/**
 * `DashboardTabs` (W73 item 21, D32).
 *
 * The tablist is the first interactive thing on the arrival page after the
 * heading, so what is asserted here is almost entirely its contract with a
 * keyboard and a screen reader. `Components/UI/Tabs.vue` — which drives the
 * node modal — has no `aria-controls`, no `aria-labelledby` on its panels and
 * no key handling at all; good enough inside a modal that manages its own
 * focus, and the reason this component exists rather than reusing it.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import DashboardTabs from '../../../resources/js/Components/Dashboard/DashboardTabs.vue';

const tabs = [
    {
        key: 'a',
        label: 'Assigned to me',
        count: 2,
        countLabel: 'assigned to you',
    },
    { key: 'b', label: 'Mentions', count: 0 },
    { key: 'c', label: 'Archive', count: 0 },
];

function render() {
    return mount(DashboardTabs, {
        props: { label: 'Notifications', tabs },
        slots: {
            a: '<p>panel a</p>',
            b: '<p>panel b</p>',
            c: '<p>panel c</p>',
        },
        attachTo: document.body,
    });
}

describe('roles and wiring', () => {
    it('is a named tablist of real tabs', () => {
        const wrapper = render();

        expect(wrapper.get('[role="tablist"]').attributes('aria-label')).toBe(
            'Notifications',
        );
        expect(wrapper.findAll('[role="tab"]')).toHaveLength(3);
        // Real buttons, not divs with click handlers — the mistake this
        // codebase removes rather than repeats.
        for (const tab of wrapper.findAll('[role="tab"]')) {
            expect(tab.element.tagName).toBe('BUTTON');
            expect(tab.attributes('type')).toBe('button');
        }
    });

    it('points every tab at the panel it controls, and back again', () => {
        const wrapper = render();

        for (const tab of wrapper.findAll('[role="tab"]')) {
            const panelId = tab.attributes('aria-controls')!;
            // Matched as an attribute, not as a `#id` selector: `useId()`
            // emits values containing `:`, and jsdom has no `CSS.escape`.
            const panel = wrapper.find(`[id="${panelId}"]`);

            expect(panel.exists()).toBe(true);
            expect(panel.attributes('role')).toBe('tabpanel');
            // The panel names itself by its tab, which is what lets the
            // panel's own DashboardSection drop its heading.
            expect(panel.attributes('aria-labelledby')).toBe(
                tab.attributes('id'),
            );
        }
    });

    it('marks exactly one tab selected', () => {
        const selected = render()
            .findAll('[role="tab"]')
            .filter((t) => t.attributes('aria-selected') === 'true');

        expect(selected).toHaveLength(1);
        expect(selected[0].text()).toContain('Assigned to me');
    });
});

describe('the counts', () => {
    it('renders the number as text inside the button, so it is part of the name', () => {
        // Not an aria-hidden pill: the number is the reason the tab is worth
        // clicking, so it has to survive into the accessible name.
        const tab = render().findAll('[role="tab"]')[0];

        expect(tab.text()).toContain('2');
        expect(tab.text()).toContain('assigned to you');
    });

    it('says nothing at all when the count is zero', () => {
        expect(render().findAll('[role="tab"]')[1].text()).toBe('Mentions');
    });
});

describe('the keyboard', () => {
    it('keeps exactly one tab in the tab order', () => {
        // Roving tabindex, per the APG tabs pattern: Tab reaches the tablist
        // once and the arrow keys move within it, rather than Tab walking
        // every tab on the way to the panel.
        const indexes = render()
            .findAll('[role="tab"]')
            .map((t) => t.attributes('tabindex'));

        expect(indexes).toEqual(['0', '-1', '-1']);
    });

    it('moves selection with the arrow keys', async () => {
        const wrapper = render();

        await wrapper
            .findAll('[role="tab"]')[0]
            .trigger('keydown', { key: 'ArrowRight' });
        expect(
            wrapper.findAll('[role="tab"]')[1].attributes('aria-selected'),
        ).toBe('true');

        await wrapper
            .findAll('[role="tab"]')[1]
            .trigger('keydown', { key: 'ArrowLeft' });
        expect(
            wrapper.findAll('[role="tab"]')[0].attributes('aria-selected'),
        ).toBe('true');
    });

    it('wraps around at both ends', async () => {
        const wrapper = render();

        await wrapper
            .findAll('[role="tab"]')[0]
            .trigger('keydown', { key: 'ArrowLeft' });
        expect(
            wrapper.findAll('[role="tab"]')[2].attributes('aria-selected'),
        ).toBe('true');
    });

    it('jumps to the first and last with Home and End', async () => {
        const wrapper = render();

        await wrapper
            .findAll('[role="tab"]')[0]
            .trigger('keydown', { key: 'End' });
        expect(
            wrapper.findAll('[role="tab"]')[2].attributes('aria-selected'),
        ).toBe('true');

        await wrapper
            .findAll('[role="tab"]')[2]
            .trigger('keydown', { key: 'Home' });
        expect(
            wrapper.findAll('[role="tab"]')[0].attributes('aria-selected'),
        ).toBe('true');
    });

    it('moves focus with selection, so the panel reached is the panel announced', async () => {
        const wrapper = render();

        await wrapper
            .findAll('[role="tab"]')[0]
            .trigger('keydown', { key: 'ArrowRight' });

        expect(document.activeElement?.id).toBe(
            wrapper.findAll('[role="tab"]')[1].attributes('id'),
        );

        wrapper.unmount();
    });
});

describe('the panels', () => {
    it('renders every panel, hiding the inactive ones rather than dropping them', () => {
        // `v-show`, not `v-if`. A panel that is destroyed loses its scroll
        // position and its empty state stops existing for anything that
        // reads the page as a whole.
        const wrapper = render();

        expect(wrapper.text()).toContain('panel a');
        expect(wrapper.text()).toContain('panel b');
        expect(wrapper.text()).toContain('panel c');
    });

    it('shows only the selected one', async () => {
        const wrapper = render();
        const panels = wrapper.findAll('[role="tabpanel"]');

        expect(panels[0].isVisible()).toBe(true);
        expect(panels[1].isVisible()).toBe(false);

        await wrapper.findAll('[role="tab"]')[1].trigger('click');

        expect(wrapper.findAll('[role="tabpanel"]')[0].isVisible()).toBe(false);
        expect(wrapper.findAll('[role="tabpanel"]')[1].isVisible()).toBe(true);
    });
});
