import type { AssessmentStore } from '@/stores/assessmentStore';
import { computed, type InjectionKey } from 'vue';
import { useCanvas, type UseCanvasReturn } from './useCanvas';
import { useCollapseState } from './useCollapseState';
import { useDuplicateHighlight } from './useDuplicateHighlight';
import { useEditorViewMode } from './useEditorViewMode';
import { useFilters } from './useFilters';
import { useFocusMode } from './useFocusMode';
import { useSearch } from './useSearch';

/**
 * The one place W15's view-state composables are instantiated (W12–W15
 * integration).
 *
 * Each of zoom, search, filters, focus, duplicate highlighting and collapse
 * persistence holds its own reactive state, so calling the composable twice
 * yields two independent copies. The toolbar and the tree both need to read
 * the same state — the toolbar sets a filter, the tree dims to match — so
 * they must share one instance rather than each constructing its own. This
 * factory builds that instance once in Pages/Assessment/Show.vue and hands it
 * down through provide/inject.
 *
 * Injected optionally everywhere it is consumed (`inject(EDITOR_VIEW_KEY,
 * null)`): TreeNode and NodeCard are mounted directly by several existing
 * tests with only the store provided, and a hard requirement here would break
 * them for no benefit — with no editor view present they simply render
 * undimmed and unhighlighted, which is the correct degraded behaviour.
 */
export interface EditorViewOptions {
    assessmentUuid: string;
    userId?: string | number | null;
}

/**
 * W84/D43: `useCanvas.ts` now persists and scales each view's zoom
 * separately, so this builds *two* instances (`chartCanvas`/`listCanvas`)
 * rather than one shared one.
 *
 * Every consumer of `view.canvas` outside this file (Show.vue, ChartView.vue,
 * Snapshots/View.vue, Toolbar.vue, the keyboard shortcuts) calls it
 * generically — `canvas.zoom.value`, `canvas.zoomIn()`, `canvas.handleWheel`
 * — with no idea which view is mounted, because before D43 there was only
 * one to mean. Rather than push "which canvas?" onto every one of those call
 * sites, this function returns a proxy with the exact same shape that
 * forwards each generic member to whichever instance matches the current
 * `viewMode.mode.value`. `canvasStyle` and `listStyle` are the one exception:
 * they are already view-specific by name, so they read straight from the
 * matching instance rather than the active one — `listStyle` while the chart
 * is mounted would be read by nothing, but it must still reflect the list's
 * own zoom for when the reader switches to it.
 */
function useDualCanvas(options: {
    assessmentUuid: string;
    userId?: string | number | null;
    activeView: () => 'chart' | 'list';
}) {
    const chartCanvas = useCanvas({
        assessmentUuid: options.assessmentUuid,
        userId: options.userId,
        view: 'chart',
    });
    const listCanvas = useCanvas({
        assessmentUuid: options.assessmentUuid,
        userId: options.userId,
        view: 'list',
    });

    function active(): UseCanvasReturn {
        return options.activeView() === 'list' ? listCanvas : chartCanvas;
    }

    return {
        chart: chartCanvas,
        list: listCanvas,
        zoom: computed(() => active().zoom.value),
        canvasStyle: computed(() => chartCanvas.canvasStyle.value),
        listStyle: computed(() => listCanvas.listStyle.value),
        spaceHeld: computed(() => active().spaceHeld.value),
        isPanning: computed(() => active().isPanning.value),
        setZoom: (next: number) => active().setZoom(next),
        zoomIn: () => active().zoomIn(),
        zoomOut: () => active().zoomOut(),
        zoomReset: () => active().zoomReset(),
        handleWheel: (event: WheelEvent) => active().handleWheel(event),
        handleContainerKeydown: (event: KeyboardEvent) =>
            active().handleContainerKeydown(event),
        handleContainerKeyup: (event: KeyboardEvent) =>
            active().handleContainerKeyup(event),
        handlePointerDown: (event: PointerEvent, container: HTMLElement) =>
            active().handlePointerDown(event, container),
        handlePointerMove: (event: PointerEvent, container: HTMLElement) =>
            active().handlePointerMove(event, container),
        handlePointerUp: () => active().handlePointerUp(),
    };
}

export function createEditorView(
    store: AssessmentStore,
    options: EditorViewOptions,
) {
    // W32: chart or list. The same one-instance argument as everything else
    // built here — the toolbar switch and the page rendering one of the two
    // views have to be reading the same ref.
    const viewMode = useEditorViewMode({
        assessmentUuid: options.assessmentUuid,
        userId: options.userId,
    });
    const canvas = useDualCanvas({
        assessmentUuid: options.assessmentUuid,
        userId: options.userId,
        activeView: () => viewMode.mode.value,
    });
    const search = useSearch(store);
    // W43 closed the tag gap useFilters.ts documents: `NodeRecord` now
    // carries labels, so the injected accessor is the store's real one
    // rather than the default "no tags on any node". The filter itself is
    // unchanged — it has been fully implemented and tested since W15 with
    // nothing to filter.
    const filters = useFilters(store, { tagsForNode: store.tagNamesFor });
    const focus = useFocusMode(store);
    const duplicates = useDuplicateHighlight(store);
    const collapse = useCollapseState(store, {
        assessmentUuid: options.assessmentUuid,
        userId: options.userId,
    });

    collapse.restore();

    return {
        viewMode,
        canvas,
        search,
        filters,
        focus,
        duplicates,
        collapse,
    };
}

export type EditorView = ReturnType<typeof createEditorView>;

export const EDITOR_VIEW_KEY: InjectionKey<EditorView> = Symbol(
    'arguesecure.editorView',
);
