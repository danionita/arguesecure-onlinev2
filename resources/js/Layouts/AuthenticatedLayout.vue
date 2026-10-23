<script setup lang="ts">
/**
 * Top-level chrome for authenticated pages (docs/specs/ui.md §2, W41).
 *
 * The top bar is gone. Its only real navigation was a product mark and a user
 * menu; the rest was a search box reading "Search this assessment…" on every
 * page including the ones with no assessment, wired to nothing on the page
 * that had one. What replaces it is a left sidebar that lists actual
 * destinations — see `Components/Nav/Sidebar.vue`.
 *
 * The public interface is unchanged: no props, a `header` slot and a default
 * slot, so every page in `Pages/**` keeps rendering without edits.
 *
 * ## The gutter is decided here, and only here
 *
 * The layout reserves the sidebar's width; the sidebar fills it. When the
 * rail's panel is floating, the nav leaves the flow and the gutter stays at
 * 48px, so the content beside it does not move — which is the whole point of
 * D16 and the reason the chart's width can be asserted across a nav expand.
 *
 * ## Skip link
 *
 * First in DOM order, visible on focus only. Without it a keyboard user tabs
 * through every destination in the sidebar on every page before reaching the
 * thing they came for, which on the editor is a tree they wanted to be in.
 */
import ChromeHeaderBand from '@/Components/Chrome/ChromeHeaderBand.vue';
import { useFlashToasts } from '@/Components/Chrome/flashToasts';
import NotificationPanel from '@/Components/Nav/NotificationPanel.vue';
import Sidebar from '@/Components/Nav/Sidebar.vue';
import {
    PANEL_WIDTH_CLASS,
    RAIL_WIDTH_CLASS,
    useSidebar,
} from '@/Components/Nav/useSidebar';
import ToastContainer from '@/Components/UI/ToastContainer.vue';
import UnsavedChangesDialog from '@/Components/UI/UnsavedChangesDialog.vue';
// W49. Loaded here rather than only in Report/Show.vue so the chart and the
// list print too — same stylesheet, same browser pipeline, no new dependency
// (PLAN-2.2.md D6). Its report rules are scoped to `.report-*` and its editor
// rules to `.editor-print`, so neither view can disturb the other's page
// breaks.
import { useTheme } from '@/Components/UI/useTheme';
import type { VersionProps } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import '../../css/print.css';

useTheme();

// W115: what a controller flashed, shown once, in the `ToastContainer` below.
useFlashToasts();

const page = usePage();

interface NavProps {
    authenticated?: boolean;
    isAdmin?: boolean;
    myAssessments?: { uuid: string; title: string }[];
    sharedWithMe?: { uuid: string; title: string; ownerName?: string | null }[];
    notificationCount?: number;
    version?: VersionProps;
}

// Defaulted rather than asserted: several component tests and the Help pages
// render this layout with a partial page-props object, and a sidebar that
// throws on a missing list would take the page with it.
const nav = computed<NavProps>(
    () => (page.props.nav as NavProps | undefined) ?? {},
);

const currentUrl = computed(() => {
    const url = page.url ?? '/';

    return url.split('?')[0].split('#')[0];
});

/**
 * The contextual override (D16). Matched on the URL rather than on a prop,
 * because every page that renders a canvas is under this prefix and no page
 * outside it is — and a prop would have to be added to each of them by hand,
 * which is the version that gets forgotten on the next page someone adds.
 */
/**
 * An assessment's pages are the ones under a *uuid*. Matching any segment —
 * as this did through 2.5 — also matched the list at `/assessments/shared`,
 * so opening "Shared with me" collapsed the navigation as if it were an
 * editor (2.5.1).
 */
const ASSESSMENT_PAGE =
    /^\/assessments\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?:\/|$)/i;
const ASSESSMENT_EDITOR =
    /^\/assessments\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\/?$/i;

const inAssessment = computed(() => ASSESSMENT_PAGE.test(currentUrl.value));

const sidebar = useSidebar(inAssessment);

/**
 * The notification feed (W47). Opened from the sidebar, rendered here because
 * the sidebar is 48px wide inside an assessment and a feed does not fit in it.
 *
 * `notificationsRead` is a local override rather than a re-fetch of the shared
 * prop: opening the panel marks everything read server-side, and the alternative
 * — a full Inertia visit to refresh one number — would reload the page under a
 * user who has just opened a list to read it.
 */
const notificationsOpen = ref(false);
const notificationsRead = ref(false);

function openNotifications() {
    notificationsOpen.value = true;
}

const badgeCount = computed(() =>
    notificationsRead.value ? 0 : (nav.value.notificationCount ?? 0),
);

/**
 * The running header (W49). Screen-hidden, print-only.
 *
 * A printed chart or list is otherwise a picture of an unnamed thing from an
 * unknown date — exactly the page that ends up in a folder and cannot be
 * trusted six months later. The report already carries a cover page; the two
 * editor views had nothing.
 *
 * The title comes from the page's own props rather than from the shared
 * `nav.currentAssessment` prop, which deliberately carries no title (W44:
 * reading it lazy-loads the root node on every request in the application).
 */
const printTitle = computed(() => {
    const assessment = page.props.assessment as { title?: string } | undefined;

    return assessment?.title ?? null;
});

const printedOn = computed(() => new Date().toLocaleDateString());

/**
 * Print rules apply to the editor only — not to `inAssessment`'s whole
 * prefix. `inAssessment` matches every page under `/assessments/{uuid}`,
 * including the report (`/assessments/{uuid}/report`), which
 * `Pages/Report/Show.vue`'s authenticated branch also mounts inside this
 * layout (D39). W82 gave `editorPrint` that same broad match, so the
 * report — reachable this way whenever a viewer is authenticated rather
 * than holding a bare share link — inherited `.print-header`, the editor's
 * own running header, even though the report has its own cover page and
 * `print.css`'s report rules were written assuming this element is absent
 * (Integration 2.5: `tests/e2e/19-print.spec.ts` caught it printing where
 * the report's rules never accounted for it).
 *
 * So this is its own match, not a reuse of `inAssessment`: the editor's
 * route has no further path segment after the uuid (`routes/web.php`'s
 * `assessments/{assessment:uuid}` → `AssessmentController::show`), and
 * `/report`, `/history` and every other assessment subpage does. Matched on
 * the URL for the same reason `inAssessment` is — a prop would have to be
 * added to each page by hand, and that is the version that gets forgotten
 * on the next page someone adds.
 */
const editorPrint = computed(() => ASSESSMENT_EDITOR.test(currentUrl.value));
</script>

<template>
    <div class="bg-surface-0 min-h-screen">
        <a
            href="#main-content"
            class="bg-surface-1 text-text-primary focus:outline-focus-ring sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:px-3 focus:py-2 focus:outline-2"
        >
            Skip to content
        </a>

        <div class="flex">
            <!-- The reserved gutter. See the header: this is the only place
                 either width is chosen. `no-print` on the gutter rather than
                 on the nav inside it (W49): hiding only the nav would leave
                 240px of blank paper down the left of every printed page. -->
            <div
                class="no-print shrink-0"
                :class="
                    sidebar.railed.value ? RAIL_WIDTH_CLASS : PANEL_WIDTH_CLASS
                "
            >
                <Sidebar
                    :my-assessments="nav.myAssessments ?? []"
                    :shared-with-me="nav.sharedWithMe ?? []"
                    :is-admin="nav.isAdmin ?? false"
                    :notification-count="badgeCount"
                    :version="nav.version"
                    :railed="sidebar.railed.value"
                    :panel-visible="sidebar.panelVisible.value"
                    :is-overlay="sidebar.isOverlay.value"
                    :current-url="currentUrl"
                    @toggle="sidebar.toggle"
                    @close="sidebar.closeOverlay"
                    @notifications="openNotifications"
                />
            </div>

            <div class="min-w-0 flex-1">
                <!-- W81: the same markup this rendered inline, moved into a
                     component the report can render too. The report cannot use
                     this layout (see the file header of Pages/Report/Show.vue)
                     and had reproduced the band by hand, where it drifted. The
                     public interface is unchanged — pages still pass arbitrary
                     content through the `header` slot. -->
                <ChromeHeaderBand v-if="$slots.header">
                    <slot name="header" />
                </ChromeHeaderBand>

                <main
                    id="main-content"
                    tabindex="-1"
                    :class="editorPrint && 'editor-print'"
                >
                    <!--
                        Print-only, and hidden on screen by `hidden` rather
                        than by a print-media class: `print.css` flips it back
                        with `display: block !important`, so a browser with the
                        stylesheet unloaded shows nothing rather than a stray
                        header above every page.
                    -->
                    <div
                        v-if="editorPrint"
                        class="print-header hidden"
                        data-testid="print-header"
                    >
                        <strong>{{ printTitle ?? 'ArgueSecure' }}</strong>
                        — printed {{ printedOn }}
                    </div>

                    <slot />
                </main>
            </div>
        </div>

        <NotificationPanel
            :show="notificationsOpen"
            @close="notificationsOpen = false"
            @read="notificationsRead = true"
        />

        <ToastContainer />
        <UnsavedChangesDialog />
    </div>
</template>
