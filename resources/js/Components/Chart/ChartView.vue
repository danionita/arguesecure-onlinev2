<script setup lang="ts">
/**
 * The chart view (W32, ui.md §3.7): the assessment as an org chart, four
 * tiers deep. At fit-to-width it scrolls horizontally only; zoomed in past
 * the viewport it scrolls both ways (W34, PLAN-2.2.md D9).
 *
 * This is the default view because it is what the product is *for*. The
 * indented list is the better reading surface and it is what reports are
 * built from, but ArgueSecure's claim is that a room full of people can
 * build a threat model together in an afternoon, and a room needs a picture.
 * The depth being fixed at four by the schema is what makes the picture
 * possible: the vertical axis is bounded, so the only dimension a reader has
 * to manage is the horizontal one, and collapsing a risk is how they manage
 * it.
 *
 * All the arithmetic lives in composables/useTreeLayout.ts, which is pure and
 * unit-tested. This component decides only *which* occurrences are visible
 * (collapse and focus mode), hands them over, and paints the result.
 *
 * ## Centring (W84, item 11)
 *
 * The sized wrapper below (`:style="scaledSize"`) carries `mx-auto` rather
 * than any conditional class. That is deliberate, not an oversight: CSS
 * gives a block element with an explicit width and `margin: auto` exactly
 * the behaviour ui.md §5 asks for — centred when the content is narrower
 * than its scroll container, and a computed left margin of `0` (i.e.
 * left-aligned, starting at the scroll origin) the moment it is wider. A
 * chart wider than the viewport that started centred would put the root
 * off-screen to the left, which is the one thing this must never do. No
 * `ResizeObserver` or breakpoint logic is needed because the browser already
 * does this arithmetic on every layout pass.
 */
import Button from '@/Components/UI/Button.vue';
import { enumerateOccurrences } from '@/composables/occurrences';
import { CANVAS_BASE_SCALE } from '@/composables/useCanvas';
import { EDITOR_VIEW_KEY } from '@/composables/useEditorView';
import {
    computeTreeLayout,
    type LayoutOccurrence,
} from '@/composables/useTreeLayout';
import { ASSESSMENT_STORE_KEY } from '@/stores/assessmentStore';
import { computed, inject, onMounted, provide, ref } from 'vue';
import ChartNode from './ChartNode.vue';
import { CHART_CONTEXT_KEY } from './chartContext';

const store = inject(ASSESSMENT_STORE_KEY)!;
const view = inject(EDITOR_VIEW_KEY, null);

const rootUuid = computed(() => store.rootUuid());

onMounted(() => {
    void store.loadUnresolvedCommentCounts();
});

/**
 * Visible occurrences: everything the DAG walk produces, minus what focus
 * mode hides and minus the children of a collapsed occurrence. A collapsed
 * parent keeps its own box and loses its subtree — which is exactly how the
 * chart is made to fit a screen.
 */
const visibleOccurrences = computed<LayoutOccurrence[]>(() => {
    const all = enumerateOccurrences(store);
    const shown = all.filter(
        (o) => !view?.focus.isActive.value || view.focus.isVisible(o.pathKey),
    );
    const shownKeys = new Set(shown.map((o) => o.pathKey));

    return shown.map((o) => ({
        pathKey: o.pathKey,
        parentPathKey: o.parentPathKey,
        tier: o.depth - 1,
        childPathKeys: store.isExpanded(o.pathKey)
            ? shown
                  .filter(
                      (child) =>
                          child.parentPathKey === o.pathKey &&
                          shownKeys.has(child.pathKey),
                  )
                  .map((child) => child.pathKey)
            : [],
    }));
});

const layout = computed(() => computeTreeLayout(visibleOccurrences.value));

const selectedPathKey = ref<string | null>(null);
function select(pathKey: string) {
    selectedPathKey.value = pathKey;
}

provide(CHART_CONTEXT_KEY, { layout, selectedPathKey, select });

const container = ref<HTMLElement | null>(null);

/**
 * Zoom so the widest tier fits the viewport. The chart's whole promise is
 * "the shape of the assessment, at a glance", and past a dozen defences that
 * needs one button rather than a hunt through the zoom steps.
 */
function fitToWidth() {
    if (!container.value || !view) return;
    const available = container.value.clientWidth;
    if (available <= 0 || layout.value.width <= 0) return;
    // Divided by the *painted* width, not the layout width: `zoom` is a
    // multiplier on CANVAS_BASE_SCALE.chart (W69, made per-view by W84), so
    // the chart is already 1.1× its layout units before this button touches
    // anything.
    view.canvas.setZoom(
        available / (layout.value.width * CANVAS_BASE_SCALE.chart),
    );
}

/**
 * The scroll container's view of a zoomed chart. `canvasStyle` is a
 * `transform`, which paints at a different size but lays out at the original
 * one — so without this the container would offer scrollbars for the 100%
 * chart no matter how far in the user had zoomed, and the bottom tier would
 * simply be unreachable. See useCanvas.ts's `canvasStyle`.
 */
const scaledSize = computed(() => {
    const scale = (view?.canvas.zoom.value ?? 1) * CANVAS_BASE_SCALE.chart;
    return {
        width: `${layout.value.width * scale}px`,
        height: `${layout.value.height * scale}px`,
    };
});

defineExpose({ fitToWidth });
</script>

<template>
    <div class="relative">
        <!--
            W49: landscape, suggested only while the chart is the mounted
            view. `@page` cannot be scoped by a selector, so it is teleported
            into <head> here and removed with the component rather than living
            in print.css, where it would also rotate the list view — which is
            taller than it is wide and wants portrait.

            A chart wider than one page tiles horizontally across several. That
            is the browser's own behaviour and it is stated (ui.md §9) rather
            than silently produced: the alternative, scaling a whole assessment
            down to one sheet, prints text nobody can read.
        -->
        <Teleport to="head">
            <component :is="'style'">
                @media print { @page { size: landscape; } }
            </component>
        </Teleport>

        <div class="mb-2 flex items-center justify-end gap-2" data-print-hide>
            <Button
                size="sm"
                variant="ghost"
                icon="layout-grid"
                @click="fitToWidth"
            >
                Fit to width
            </Button>
        </div>

        <!-- W34 / PLAN-2.2.md D9. W32 scrolled horizontally only, on the
             grounds that four tiers always fit the height — true at
             fit-to-width, which is still the default and still does not
             scroll vertically. It stops being true the moment someone zooms
             in to read a dense branch, and refusing to scroll to the bottom
             tier makes zooming in useless. Both axes, with a viewport-bounded
             height so the scrolling happens here rather than dragging the
             whole page down. -->
        <div
            ref="container"
            class="max-h-[calc(100vh-13rem)] overflow-auto pb-4"
            @wheel="view?.canvas.handleWheel"
        >
            <!-- A transform does not resize the layout box, so the scroll
                 container cannot see a zoomed chart and would clip it. This
                 sizer carries the scaled dimensions; the transformed content
                 is painted inside it. `mx-auto` is the centring — see the
                 docblock above for why no conditional logic is needed. -->
            <div class="mx-auto" :style="scaledSize">
                <!--
                    W37 deleted the tier gutter. It cost 84px of horizontal
                    space, permanently, to name four things that are fixed by
                    the schema and never change — and horizontal space is the
                    one resource this view is short of. The information it
                    carried is not lost: every card's accessible name begins
                    with its type ("Risk: Customer data breach"), so a screen
                    reader still announces the tier, and `aria-level` on each
                    treeitem still gives the depth. That is a deliberate trade
                    of a sighted redundancy for width, not a free deletion.
                -->
                <div
                    class="chart-canvas relative flex"
                    :style="view?.canvas.canvasStyle.value"
                >
                    <div
                        class="relative"
                        :style="{
                            width: `${layout.width}px`,
                            height: `${layout.height}px`,
                        }"
                    >
                        <svg
                            class="pointer-events-none absolute inset-0"
                            :width="layout.width"
                            :height="layout.height"
                            aria-hidden="true"
                        >
                            <path
                                v-for="connector in layout.connectors"
                                :key="`${connector.fromPathKey}->${connector.toPathKey}`"
                                :d="`M ${connector.x1} ${connector.y1} C ${connector.x1} ${(connector.y1 + connector.y2) / 2}, ${connector.x2} ${(connector.y1 + connector.y2) / 2}, ${connector.x2} ${connector.y2}`"
                                fill="none"
                                class="stroke-border-default"
                                stroke-width="1"
                            />
                        </svg>

                        <!--
                            `absolute inset-0` rather than a bare `<ul>`: every
                            ChartNode's card is absolutely positioned, so the
                            list itself collapsed to a zero-height box that sat
                            above the chart it labels. Semantically harmless —
                            a screen reader reads the tree either way — but it
                            makes the accessible container not overlap the
                            region it describes, and anything that measures the
                            tree (Playwright's visibility check, scenarios 3–16)
                            saw a hidden element. Filling the sized wrapper also
                            makes the `<ul>` the positioning ancestor for those
                            cards, which has the identical geometry it replaces.
                        -->
                        <ul
                            v-if="rootUuid"
                            role="tree"
                            class="absolute inset-0"
                            :aria-label="
                                store.nodes[rootUuid]?.title || 'Assessment'
                            "
                        >
                            <ChartNode :uuid="rootUuid" :ancestor-path="[]" />
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
