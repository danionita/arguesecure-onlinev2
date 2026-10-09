/**
 * Sidebar width: one persisted preference plus one contextual override
 * (W41, PLAN-2.2.md D16).
 *
 * ## Why an assessment gets a rail
 *
 * The editor is a canvas. The chart view lays nodes out to the width it is
 * given, and someone mid-workshop is usually pointing at it. A nav that can
 * be expanded *in place* would rearrange the picture under their finger, so
 * inside an assessment the sidebar is pinned to a 48px icon rail and
 * expanding it floats a panel **over** the content instead of beside it.
 * Everywhere else — dashboard, lists, profile, report — the page is a
 * document, reflow is harmless, and the sidebar is docked open by default.
 *
 * ## Three states, from two flags
 *
 *   railed  overlayOpen   what the user sees
 *   ------  -----------   ------------------
 *   false   —             docked, 240px gutter, labels visible
 *   true    false         48px rail, icons only
 *   true    true          48px rail *plus* a floating 240px panel
 *
 * The gutter the layout reserves depends only on `railed`, never on
 * `overlayOpen`. That is the whole mechanism behind "expanding the nav must
 * not change the chart's width", and it is what the e2e assertion on
 * `clientWidth` is actually testing.
 */
import { computed, ref, watch, type Ref } from 'vue';

export type SidebarPreference = 'expanded' | 'collapsed';

export const SIDEBAR_STORAGE_KEY = 'arguesecure.sidebar';

/** Tailwind cannot see a computed class name, so both widths are literals. */
export const RAIL_WIDTH_CLASS = 'w-12';
export const PANEL_WIDTH_CLASS = 'w-60';

function readStored(): SidebarPreference {
    if (typeof window === 'undefined') return 'expanded';

    return window.localStorage.getItem(SIDEBAR_STORAGE_KEY) === 'collapsed'
        ? 'collapsed'
        : 'expanded';
}

/**
 * @param forceRail true on an assessment route. Overrides the preference
 *   without overwriting it, so leaving the assessment restores whatever the
 *   user had chosen rather than whatever the editor needed.
 */
export function useSidebar(forceRail: Ref<boolean>) {
    const preference = ref<SidebarPreference>(readStored());
    const overlayOpen = ref(false);

    const railed = computed(
        () => forceRail.value || preference.value === 'collapsed',
    );

    /** Docked panel, or floating panel — either way, labels are on screen. */
    const panelVisible = computed(() => !railed.value || overlayOpen.value);

    /** True only for the floating case, which needs a backdrop and an escape hatch. */
    const isOverlay = computed(() => railed.value && overlayOpen.value);

    watch(preference, (value) => {
        if (typeof window === 'undefined') return;
        window.localStorage.setItem(SIDEBAR_STORAGE_KEY, value);
    });

    // Leaving the assessment (or arriving at one) closes the floating panel.
    // An overlay that survived a navigation would be covering a page that
    // has room for it docked, which reads as a stuck menu.
    watch(forceRail, () => {
        overlayOpen.value = false;
    });

    /**
     * One control, two meanings, and which one applies is visible from the
     * icon rail itself: on an assessment there is no docked state to return
     * to, so it opens and closes the overlay; anywhere else it docks and
     * undocks, and that choice is the one that persists.
     */
    function toggle(): void {
        if (forceRail.value) {
            overlayOpen.value = !overlayOpen.value;

            return;
        }

        preference.value =
            preference.value === 'expanded' ? 'collapsed' : 'expanded';
        overlayOpen.value = false;
    }

    function closeOverlay(): void {
        overlayOpen.value = false;
    }

    return {
        preference,
        railed,
        panelVisible,
        isOverlay,
        overlayOpen,
        toggle,
        closeOverlay,
    };
}
