/**
 * W34 — the top bar, as behaviour rather than as a screenshot.
 *
 * Two of these are absence assertions, which CLAUDE.md is rightly sceptical
 * of. They are here because removal *is* the change: the baseline selector no
 * longer drives anything a user can see (PLAN-2.2.md D8), and a well-meaning
 * later commit re-adding it would restore a control that changes nothing.
 * Each names the specific thing that must not come back, not "fewer controls".
 *
 * The one-row requirement is asserted twice, in two places, on purpose. jsdom
 * has no layout engine — every `offsetTop` is 0 — so the geometric test lives
 * in `tests/e2e/16-toolbar-one-row.spec.ts` where there is a real browser.
 * What this file can assert is the *mechanism* that permits wrapping, and
 * `flex-wrap` returning to the row is exactly how a future change would wrap
 * the bar without anyone noticing.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import Toolbar from '../../../resources/js/Components/Toolbar/Toolbar.vue';
import {
    createEditorView,
    EDITOR_VIEW_KEY,
} from '../../../resources/js/composables/useEditorView';
import { makeFixtureStore } from '../composables/support/fixture';

function mountToolbar() {
    const store = makeFixtureStore();
    const view = createEditorView(store, { assessmentUuid: 'a1', userId: 1 });
    const wrapper = mount(Toolbar, {
        // The editor always supplies this — it is what puts Report in the
        // switcher — and the switcher's third position is what W81's
        // ordering assertion below is about.
        props: { reportHref: '/assessments/a1/report' },
        global: {
            provide: { [EDITOR_VIEW_KEY as symbol]: view },
            stubs: { teleport: true },
        },
    });
    return { wrapper, view, store };
}

describe('Toolbar', () => {
    it('holds its controls in a single non-wrapping row', () => {
        const { wrapper } = mountToolbar();
        const row = wrapper.get('[data-testid="toolbar-row"]');

        expect(row.classes()).toContain('flex');
        expect(row.classes()).not.toContain('flex-wrap');
    });

    it('has no baseline selector: nothing on screen depends on a baseline any more', () => {
        const { wrapper } = mountToolbar();

        // The control BaselineControl.vue rendered, by the id it labelled
        // itself with. Named precisely so this fails on the selector coming
        // back and not on the word "Inherent" appearing anywhere — the
        // totals panel below the row legitimately still prints it, until
        // W36 moves those figures onto the ToS card.
        expect(wrapper.find('#baseline-label').exists()).toBe(false);
        expect(
            wrapper.find('[aria-labelledby="baseline-label"]').exists(),
        ).toBe(false);

        const row = wrapper.get('[data-testid="toolbar-row"]');
        expect(row.text()).not.toContain('Inherent');
    });

    it('puts zoom next to the view switcher rather than at the far end of the bar', () => {
        const { wrapper } = mountToolbar();
        const row = wrapper.get('[data-testid="toolbar-row"]');

        // Both live inside the same right-hand group, so they move together
        // and cannot be separated by a control added between them later.
        const group = row.get('.ml-auto');
        expect(group.text()).toContain('View');
        expect(group.find('[role="group"]').exists()).toBe(true);
    });

    /**
     * W81, the horizontal half of ui.md §9's "same screen position in all
     * three views".
     *
     * Through W80 the zoom controls came after the switcher, so the editor's
     * switcher was one zoom-group further from the window's right edge than
     * the report's — and the report had no honest way to match it. Ordering
     * the switcher last makes its inset from the right edge the band's
     * padding and nothing else, whatever the page puts beside it.
     *
     * Asserted as *last child of the right-hand group*, which is the property.
     * A test that measured a distance could not run here at all — jsdom
     * computes no layout — and one that asserted "zoom exists" would pass with
     * the two back in the old order.
     */
    it('makes the view switcher the last control in the row, so its right edge does not depend on its siblings', () => {
        const { wrapper } = mountToolbar();
        const group = wrapper.get('[data-testid="toolbar-row"] .ml-auto');
        const children = Array.from(group.element.children);

        expect(children).toHaveLength(2);
        // Zoom first...
        expect(children[0].textContent).toContain('100%');
        expect(children[0].textContent).not.toContain('View');
        // ...the switcher last, against the row's right edge.
        expect(children[1].textContent).toContain('View');
        expect(children[1].textContent).toContain('Report');
    });

    it('names what zoom does, because it does two different things', async () => {
        const { wrapper, view } = mountToolbar();

        view.viewMode.set('list');
        await wrapper.vm.$nextTick();
        expect(wrapper.html()).toContain('Decrease text size');

        view.viewMode.set('chart');
        await wrapper.vm.$nextTick();
        expect(wrapper.html()).toContain('Decrease chart size');
    });
});

describe('Toolbar filters', () => {
    it('keeps the available filters behind one trigger, and still offers them', async () => {
        const { wrapper } = mountToolbar();
        const trigger = wrapper.get('button[aria-haspopup="true"]');

        expect(trigger.text()).toContain('Filters');
        // Sixteen chips inline were what wrapped the bar.
        expect(wrapper.text()).not.toContain('Implemented');

        // They moved; they did not disappear. Asserting only the absence
        // above would stay green if the dropdown were empty.
        await trigger.trigger('click');
        expect(wrapper.text()).toContain('Implemented');
        expect(wrapper.text()).toContain('Transfer');
    });

    it('shows active filters on the bar without opening anything', async () => {
        const { wrapper, view } = mountToolbar();

        view.filters.toggleType('attack');
        await wrapper.vm.$nextTick();

        const active = wrapper.get('[aria-label="Active filters"]');
        expect(active.text()).toContain('Attack');
        expect(
            active.find('[aria-label="Remove filter: Attack"]').exists(),
        ).toBe(true);
    });

    it('counts a set filter on the trigger, missing-values included', async () => {
        const { wrapper, view } = mountToolbar();

        view.filters.toggleType('attack');
        view.filters.setMissingOnly(true);
        await wrapper.vm.$nextTick();

        expect(wrapper.get('button[aria-haspopup="true"]').text()).toContain(
            '2',
        );
    });

    it('clears everything in one click', async () => {
        const { wrapper, view } = mountToolbar();

        view.filters.toggleType('attack');
        view.filters.setMissingOnly(true);
        await wrapper.vm.$nextTick();

        await wrapper.get('[aria-label="Clear all filters"]').trigger('click');

        expect(view.filters.state.types).toEqual([]);
        expect(view.filters.state.missingOnly).toBe(false);
    });

    /**
     * The dropdown panel is not in the DOM until it is opened, so an
     * assertion about the toggle's label passes vacuously against a closed
     * toolbar — which is how the first version of this test was green while
     * the label still read "Missing values only". Open it first, and assert
     * on the toggle's own subtree rather than on the whole component, where
     * the active-filter chip would also match.
     */
    it('calls the filter "Missing values", not "Missing values only"', async () => {
        const { wrapper } = mountToolbar();

        await wrapper.get('button[aria-haspopup="true"]').trigger('click');

        const toggle = wrapper.get('[aria-label="Filter by defence status"]')
            .element.parentElement!;
        expect(toggle.textContent).toContain('Missing values');
        expect(toggle.textContent).not.toContain('Missing values only');
    });
});
