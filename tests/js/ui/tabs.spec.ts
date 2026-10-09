/**
 * `UI/Tabs.vue` on its own, without the node modal around it (W71 items 1
 * and 16).
 *
 * The panel-laziness assertions that matter to the *product* live in
 * tests/js/nodeModal/shell.spec.ts, where a real modal with eight real tabs
 * is what breaks. What is here is the component contract those rely on: a
 * strip that wraps rather than scrolls, keyboard navigation that survives the
 * wrap, and the ARIA wiring between a tab and the panel it controls.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import Tabs from '../../../resources/js/Components/UI/Tabs.vue';
import type { TabItem } from '../../../resources/js/Components/UI/types';

const TABS: TabItem[] = [
    { key: 'details', label: 'Details' },
    { key: 'values', label: 'Impact' },
    { key: 'statistics', label: 'Statistics' },
];

/**
 * A host that owns `modelValue`, because roving focus is only observable on a
 * component whose selection actually moves when the arrow key is pressed.
 */
const Host = defineComponent({
    setup() {
        const selected = ref('details');

        return () =>
            h(
                Tabs,
                {
                    tabs: TABS,
                    modelValue: selected.value,
                    'onUpdate:modelValue': (key: string) =>
                        (selected.value = key),
                },
                {
                    details: () => h('p', 'details panel'),
                    values: () => h('p', 'impact panel'),
                    statistics: () => h('p', 'statistics panel'),
                },
            );
    },
});

function mountTabs() {
    return mount(Host, { attachTo: document.body });
}

describe('the strip wraps rather than scrolls (item 16, D30)', () => {
    it('lays the tablist out with flex-wrap and no horizontal scroller', () => {
        const wrapper = mountTabs();
        const classes = wrapper.get('[role="tablist"]').classes();

        // The specified values, not "some layout class is present": a strip
        // that quietly went back to scrolling would still be a flex row.
        expect(classes).toContain('flex-wrap');
        expect(classes).not.toContain('overflow-x-auto');
    });

    it('keeps the single bottom edge on the strip container, so two rows read as one edge', () => {
        const wrapper = mountTabs();

        // `border-b` on the container is what draws the line under the whole
        // strip. Each button's own `border-b-2` is the selection marker and
        // sits on its own row; if the container lost its border, a wrapped
        // strip would have a line under row one and nothing under row two.
        expect(wrapper.get('[role="tablist"]').classes()).toContain('border-b');
    });
});

describe('aria-selected and roving focus survive the wrap', () => {
    it('marks exactly one tab selected', () => {
        const wrapper = mountTabs();
        const selected = wrapper
            .findAll('[role="tab"]')
            .filter((tab) => tab.attributes('aria-selected') === 'true');

        expect(selected).toHaveLength(1);
        expect(selected[0].text()).toContain('Details');
    });

    it('makes only the selected tab tabbable', () => {
        const wrapper = mountTabs();

        expect(
            wrapper
                .findAll('[role="tab"]')
                .map((tab) => tab.attributes('tabindex')),
        ).toEqual(['0', '-1', '-1']);
    });

    it('moves selection and focus with ArrowRight, wrapping past the last tab', async () => {
        const wrapper = mountTabs();
        const tabs = () => wrapper.findAll('[role="tab"]');

        await tabs()[0].trigger('keydown', { key: 'ArrowRight' });
        expect(tabs()[1].attributes('aria-selected')).toBe('true');
        expect(document.activeElement).toBe(tabs()[1].element);

        await tabs()[1].trigger('keydown', { key: 'ArrowRight' });
        await tabs()[2].trigger('keydown', { key: 'ArrowRight' });
        // Round the end and back to the first, which is what makes the second
        // row reachable without knowing where the wrap fell.
        expect(tabs()[0].attributes('aria-selected')).toBe('true');

        wrapper.unmount();
    });

    it('moves backwards with ArrowLeft and jumps with Home/End', async () => {
        const wrapper = mountTabs();
        const tabs = () => wrapper.findAll('[role="tab"]');

        await tabs()[0].trigger('keydown', { key: 'End' });
        expect(tabs()[2].attributes('aria-selected')).toBe('true');

        await tabs()[2].trigger('keydown', { key: 'ArrowLeft' });
        expect(tabs()[1].attributes('aria-selected')).toBe('true');

        await tabs()[1].trigger('keydown', { key: 'Home' });
        expect(tabs()[0].attributes('aria-selected')).toBe('true');

        wrapper.unmount();
    });
});

describe('lazy panels', () => {
    it('renders only the panel that has been activated', () => {
        const wrapper = mountTabs();

        expect(wrapper.findAll('[role="tabpanel"]')).toHaveLength(1);
        expect(wrapper.text()).toContain('details panel');
        expect(wrapper.text()).not.toContain('statistics panel');

        wrapper.unmount();
    });

    it('points a tab at its panel by id once that panel exists, and not before', async () => {
        const wrapper = mountTabs();
        const tabs = () => wrapper.findAll('[role="tab"]');

        const controls = tabs()[0].attributes('aria-controls')!;
        expect(document.getElementById(controls)).not.toBeNull();
        // An unopened tab has no panel to control; a dangling id reference
        // would be a worse answer than none.
        expect(tabs()[2].attributes('aria-controls')).toBeUndefined();

        await tabs()[2].trigger('click');
        const opened = tabs()[2].attributes('aria-controls')!;
        expect(document.getElementById(opened)?.getAttribute('role')).toBe(
            'tabpanel',
        );

        wrapper.unmount();
    });

    it('keeps a visited panel mounted but hidden, so it does not lose its state', async () => {
        const wrapper = mountTabs();
        const tabs = () => wrapper.findAll('[role="tab"]');

        await tabs()[2].trigger('click');
        await tabs()[0].trigger('click');

        const panels = wrapper.findAll('[role="tabpanel"]');
        expect(panels).toHaveLength(2);
        expect(
            panels.filter(
                (panel) =>
                    (panel.element as HTMLElement).style.display !== 'none',
            ),
        ).toHaveLength(1);

        wrapper.unmount();
    });
});
