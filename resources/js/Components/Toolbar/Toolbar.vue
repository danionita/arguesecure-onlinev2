<script setup lang="ts">
/**
 * Composes the toolbar pieces — search, filters, view, zoom — into the
 * single bar ui.md §5 describes sitting over the canvas.
 *
 * Takes its view state from the shared editor view provided by
 * Pages/Assessment/Show.vue rather than constructing its own. Each of these
 * composables owns reactive state, so a second instance here would leave the
 * toolbar setting a filter that the tree never sees — the two would silently
 * disagree. See composables/useEditorView.ts.
 *
 * ## One row, and what came off it
 *
 * The baseline selector is **gone**. It existed so that every card and the
 * totals panel could never show different baselines; the disagreement is now
 * removed at its source, by taking baseline-dependent figures off the cards
 * entirely. A control that changes nothing visible
 * is worse than no control, so it goes rather than lingering as decoration.
 *
 * `TotalsPanel` is gone: the three totals live on the ToS
 * card as Base / Current / Target ALE. Two surfaces rendering the same three
 * numbers is one too many, and the docked strip cost a permanent row of
 * vertical space above the tree for figures that belong on the node they
 * describe.
 *
 * The bar must hold **one row at 1280px**. That is not cosmetic: every pixel
 * of toolbar height is a pixel the chart view does not have for its two title
 * lines and three description lines at 1080p (the chart view spends the budget this
 * creates). `tests/e2e/16-toolbar-one-row.spec.ts` asserts the
 * geometry in a real browser; the unit spec guards the `flex-wrap` that would
 * let it wrap again.
 *
 * ## The band is not this file's
 *
 * The row, its two paddings and the right-hand group now live in
 * `Components/Chrome/ChromeToolbarBand.vue`, which the report page renders
 * too. Read that file for why — in one sentence, the report cannot use
 * `AuthenticatedLayout` and so had a hand-made copy of this geometry, which
 * drifted by 6px before anyone ran the test that measures it.
 *
 * What remains here is which controls go in the bar, which is the only part
 * of it the report does not share.
 *
 * **The zoom controls moved to the left of the view switcher.** They used to sit
 * after it, which put the editor's switcher one zoom-group
 * further from the window's right edge than the report's — the horizontal
 * half of ui.md §9's "same screen position in all three views". The switcher goes in the
 * band's `anchor` slot, which is always last, so its distance from the right
 * edge no longer depends on what sits beside it. The two stay adjacent inside
 * the one group, which is what keeps them together.
 */
import ChromeToolbarBand from '@/Components/Chrome/ChromeToolbarBand.vue';
import { EDITOR_VIEW_KEY } from '@/composables/useEditorView';
import { inject } from 'vue';
import FilterBar from './FilterBar.vue';
import FocusBreadcrumb from './FocusBreadcrumb.vue';
import SearchBox from './SearchBox.vue';
import ViewSwitcher from './ViewSwitcher.vue';
import ZoomControls from './ZoomControls.vue';

defineProps<{
    /**
     * All label names in this assessment. Optional because the toolbar was built before anything
     * could supply them; supplied from the store by
     * Pages/Assessment/Show.vue. Still optional so the several tests that
     * mount this without a store keep working.
     */
    availableTags?: string[];
    /** The report route, which becomes the switcher's third position. */
    reportHref?: string;
}>();

const view = inject(EDITOR_VIEW_KEY)!;
const { viewMode, canvas, search, filters, focus } = view;

defineExpose({ viewMode, canvas, search, filters, focus });
</script>

<template>
    <div class="flex flex-col">
        <ChromeToolbarBand>
            <!-- The search box, the view switcher and the zoom controls
                 are controls, not content, and do not print. The filter bar
                 stays: its active-filter chips are the only on-screen
                 statement of what the reader is looking at, and a printed
                 tree with half its risks dimmed is misleading without them.
                 The two that are inside the band's right-hand group are
                 hidden by the `data-print-hide` it carries. -->
            <SearchBox :search="search" data-print-hide />
            <FilterBar :filters="filters" :available-tags="availableTags" />

            <!-- View and zoom still sit together on the right: zoom is a
                 property of the view you are in, and it does two
                 different things depending on which one that is. Zoom is in
                 `trailing` and the switcher in `anchor` so that the switcher
                 is last — see ChromeToolbarBand.vue. -->
            <template #trailing>
                <ZoomControls
                    :zoom="canvas.zoom.value"
                    :view-mode="viewMode.mode.value"
                    @zoom-in="canvas.zoomIn"
                    @zoom-out="canvas.zoomOut"
                    @zoom-reset="canvas.zoomReset"
                />
            </template>
            <template #anchor>
                <ViewSwitcher :view-mode="viewMode" :report-href="reportHref" />
            </template>
        </ChromeToolbarBand>

        <!-- Outside the band rather than in its `below` slot: the breadcrumb
             is `sticky top-0` with its own surface and border, so the band's
             horizontal padding would inset a strip that is meant to span the
             window. -->
        <FocusBreadcrumb
            :entries="focus.breadcrumb.value"
            :focus-active="focus.isActive.value"
            @select="focus.enter"
            @exit-focus="focus.exit"
        />
    </div>
</template>
