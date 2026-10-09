/**
 * Sidebar width: one persisted preference, one contextual override (W41, D16).
 *
 * The property that matters most is not tested here but in the layout spec
 * next door — that the reserved gutter depends on `railed` alone and never on
 * `overlayOpen`, which is what stops an expanding nav from re-laying-out the
 * chart. What this file pins is the state machine that produces `railed`.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { nextTick, ref } from 'vue';
import {
    SIDEBAR_STORAGE_KEY,
    useSidebar,
} from '../../../resources/js/Components/Nav/useSidebar';

beforeEach(() => {
    window.localStorage.clear();
});

describe('outside an assessment', () => {
    it('is docked open by default', () => {
        const sidebar = useSidebar(ref(false));

        expect(sidebar.railed.value).toBe(false);
        expect(sidebar.panelVisible.value).toBe(true);
        expect(sidebar.isOverlay.value).toBe(false);
    });

    it('collapses and re-expands in place, and remembers which', async () => {
        const sidebar = useSidebar(ref(false));

        sidebar.toggle();
        await nextTick();
        expect(sidebar.railed.value).toBe(true);
        // Collapsed in place, not floating: there is a docked state to
        // return to here, so the control means "undock", not "peek".
        expect(sidebar.isOverlay.value).toBe(false);
        expect(window.localStorage.getItem(SIDEBAR_STORAGE_KEY)).toBe(
            'collapsed',
        );

        sidebar.toggle();
        await nextTick();
        expect(sidebar.railed.value).toBe(false);
        expect(window.localStorage.getItem(SIDEBAR_STORAGE_KEY)).toBe(
            'expanded',
        );
    });

    it('starts collapsed when that is what the user last chose', () => {
        window.localStorage.setItem(SIDEBAR_STORAGE_KEY, 'collapsed');

        expect(useSidebar(ref(false)).railed.value).toBe(true);
    });
});

describe('inside an assessment', () => {
    it('is a rail regardless of the stored preference', () => {
        window.localStorage.setItem(SIDEBAR_STORAGE_KEY, 'expanded');

        expect(useSidebar(ref(true)).railed.value).toBe(true);
    });

    it('expands as an overlay rather than docking', () => {
        const sidebar = useSidebar(ref(true));
        sidebar.toggle();

        expect(sidebar.isOverlay.value).toBe(true);
        expect(sidebar.panelVisible.value).toBe(true);
        // Still railed: the gutter the layout reserves has not changed, which
        // is the entire reason the overlay exists.
        expect(sidebar.railed.value).toBe(true);
    });

    it('never overwrites the preference it is overriding', async () => {
        const sidebar = useSidebar(ref(true));
        sidebar.toggle();
        await nextTick();

        // Storing 'collapsed' here would make the rail follow the user out of
        // the assessment and onto every document page afterwards.
        expect(window.localStorage.getItem(SIDEBAR_STORAGE_KEY)).toBeNull();
        expect(sidebar.preference.value).toBe('expanded');
    });
});

describe('leaving the assessment', () => {
    it('restores the docked sidebar and drops the overlay', async () => {
        const inAssessment = ref(true);
        const sidebar = useSidebar(inAssessment);

        sidebar.toggle();
        expect(sidebar.isOverlay.value).toBe(true);

        inAssessment.value = false;
        await nextTick();

        // An overlay that survived the navigation would be a floating panel
        // covering a page with room for it docked, which reads as stuck.
        expect(sidebar.isOverlay.value).toBe(false);
        expect(sidebar.railed.value).toBe(false);
    });

    it('restores a collapsed preference rather than forcing it open', async () => {
        window.localStorage.setItem(SIDEBAR_STORAGE_KEY, 'collapsed');
        const inAssessment = ref(true);
        const sidebar = useSidebar(inAssessment);

        inAssessment.value = false;
        await nextTick();

        expect(sidebar.railed.value).toBe(true);
    });
});
