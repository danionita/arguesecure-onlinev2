/**
 * Canvas zoom and pan (ui.md §5 / PLAN.md W15).
 *
 * Zoom is a clamped, persisted number (50%-200%, per assessment per user
 * **per view** since W84/D43) that the two views spend differently — W34 /
 * PLAN-2.2.md D9. The chart scales geometrically (`canvasStyle`); the list
 * scales type size and keeps its width (`listStyle`). See each computed
 * below for why.
 *
 * One `useCanvas()` instance is one view's zoom. `useEditorView.ts` builds
 * two — `chart` and `list` — and exposes a proxy that dispatches every
 * generic call (`zoom`, `setZoom`, `zoomIn`/`Out`/`Reset`, `handleWheel`, the
 * pan handlers) to whichever one matches the current `viewMode`, so callers
 * that do not care which view they are in (Show.vue, the keyboard shortcuts,
 * ZoomControls) keep working unchanged. `canvasStyle` and `listStyle` need no
 * such dispatch — they are already view-specific by name.
 * "Per user" needs a user id; the only place one is available to a
 * client-only package is Inertia's globally shared `auth.user` prop
 * (app/Http/Middleware/HandleInertiaRequests.php shares it on every
 * request), so callers pass it in rather than this composable importing
 * `usePage()` itself — that keeps this file framework-router-agnostic and
 * trivially unit-testable outside a mounted Inertia page. See the W15
 * report for what a durable, cross-device server-side version would need.
 *
 * Pan (middle-drag or space-drag) is implemented as scroll-container
 * manipulation, not a second transform, so native two-axis scrolling
 * (ui.md §5: "Deep trees are wide; the legacy layout only scrolled
 * vertically") keeps working for wheel/trackpad/keyboard scrolling — pan
 * is just a faster way to move the same scroll position.
 *
 * The space-hold detector below is a *local* keydown/keyup listener scoped
 * to whatever container element the caller attaches it to (see
 * `bindContainer`), not a global document/window listener and not an entry
 * in W14's `useKeyboard*.ts` command registry. ui.md §4's keyboard map
 * assigns `Ctrl/Cmd + +/-/0` to zoom as an app-wide shortcut — W14 owns
 * wiring that binding and calls `zoomIn`/`zoomOut`/`zoomReset` below to do
 * it (see the W15 report for the exact exported surface).
 */
import { computed, ref, type CSSProperties } from 'vue';
import type { EditorViewMode } from './useEditorViewMode';

export const CANVAS_ZOOM_MIN = 0.5;
export const CANVAS_ZOOM_MAX = 2;
export const CANVAS_ZOOM_STEP = 0.1;
export const CANVAS_ZOOM_DEFAULT = 1;

/**
 * `useCanvas.ts`'s own scope, wider than the editor's `EditorViewMode`
 * (Integration 2.5). The report (`Pages/Report/Show.vue`, W83) is a third
 * caller of this composable with its own persisted zoom and its own
 * baseline, but it is not a selectable editor view: `EditorViewMode` also
 * drives `ViewSwitcher.vue` and `useEditorViewMode.ts`'s `VALID` array and
 * stored-preference validation, and adding `'report'` there would make it
 * choosable in the editor's view switcher and a persistable editor
 * preference, which it is not. So the wider type lives here, at the one
 * composable that needs it, instead of widening `EditorViewMode` for every
 * caller that does not.
 */
export type CanvasView = EditorViewMode | 'report';

/**
 * The scale `zoom.value` is measured against, **per view** since W84
 * (originally W69, PLAN-2.4.md D28; made per-view by PLAN-2.5.md D43+D51).
 *
 * Both views rendered too small at 100%, and the two obvious fixes are not
 * interchangeable. Moving `CANVAS_ZOOM_DEFAULT` to 1.1 would make the control
 * read **110%** on first load, which is precisely the complaint: a user who
 * has never touched zoom should not be told they are zoomed in. So the
 * *baseline* moves instead and the number does not. 100% now renders what
 * 110% rendered, the range is still 50%–200% of that baseline, and every
 * persisted preference keeps the meaning it had when it was stored — nobody's
 * saved zoom silently changes because the constant did.
 *
 * **Read this whole docblock before editing either constant — moving
 * `CANVAS_ZOOM_DEFAULT` instead of this one is the rejected alternative, not
 * a suggestion.**
 *
 * D51 ("the list breathes, and opens larger") is two separate fixes. Row
 * spacing and indent (`Components/List/TreeNode.vue`) fix *density* — zoom
 * cannot, because it scales the gaps along with the text. This baseline
 * fixes *starting size*, and D43 makes each view's zoom independent, so the
 * list's baseline can be raised without moving the chart's. The list opens
 * more zoomed in than the chart because it is the reading surface; the chart
 * wants to show the whole shape of the assessment first.
 *
 * `CANVAS_BASE_SCALE.chart` multiplies into `canvasStyle`; `.list` into
 * `listStyle`. Anything that converts between layout units and painted
 * pixels must multiply by the matching one — ChartView.vue's `scaledSize`
 * and `fitToWidth` are the two such places, and both are chart-only, so both
 * use `.chart`.
 *
 * `.report` (Integration 2.5) is `1.0`, not one of the other two. The report
 * is a printed/downloaded document, not a reading surface someone has
 * already opted into a larger baseline for (`.list` is 1.35 for exactly that
 * reason, per D51) — a report that opened pre-zoomed while its own control
 * still read "100%" would be lying about its own state. `Pages/Report/Show.vue`
 * reads `listStyle` (the CSS-`zoom` mechanism, not the chart's transform —
 * the report is document-shaped) with `view: 'report'`, so this entry is what
 * that computed multiplies by.
 */
export const CANVAS_BASE_SCALE: Record<CanvasView, number> = {
    chart: 1.1,
    list: 1.35,
    report: 1.0,
} as const;

export interface UseCanvasOptions {
    assessmentUuid: string;
    /** The current user's id, for per-user persistence. Pass `usePage().props.auth.user?.id` from the caller. */
    userId?: string | number | null;
    /**
     * Which view this instance's zoom is persisted and scaled for (D43).
     * Required rather than defaulted: a caller that forgets it would silently
     * share one view's zoom with the other again, which is the bug D43 fixes.
     *
     * Typed `CanvasView`, not `EditorViewMode`, because the report
     * (`Pages/Report/Show.vue`) is a third scope with its own persisted zoom
     * and its own baseline (`CANVAS_BASE_SCALE.report`) but is not a
     * selectable editor view — see `CanvasView`'s own docblock above.
     */
    view: CanvasView;
    /** Injectable for tests; defaults to `window.localStorage`. */
    storage?: Pick<Storage, 'getItem' | 'setItem'>;
}

export function clampZoom(zoom: number): number {
    return Math.min(CANVAS_ZOOM_MAX, Math.max(CANVAS_ZOOM_MIN, zoom));
}

function resolveStorage(
    injected: UseCanvasOptions['storage'],
): Pick<Storage, 'getItem' | 'setItem'> | undefined {
    if (injected) return injected;
    return typeof localStorage !== 'undefined' ? localStorage : undefined;
}

/**
 * The pre-W84 key, with no view component. A fresh instance of either view
 * reads it once as a seed — see `loadPersisted` below — so a zoom set before
 * this package shipped still means something on first load after the
 * upgrade, for *both* views, rather than being silently discarded.
 */
function legacyStorageKey(assessmentUuid: string, userId: unknown): string {
    return `arguesecure:canvas-zoom:${userId ?? 'anon'}:${assessmentUuid}`;
}

function storageKey(
    assessmentUuid: string,
    userId: unknown,
    view: CanvasView,
): string {
    return `arguesecure:canvas-zoom:${view}:${userId ?? 'anon'}:${assessmentUuid}`;
}

interface PanStart {
    clientX: number;
    clientY: number;
    scrollLeft: number;
    scrollTop: number;
}

function isTypingTarget(target: EventTarget | null): boolean {
    const el = target as HTMLElement | null;
    if (!el) return false;
    return (
        el.tagName === 'INPUT' ||
        el.tagName === 'TEXTAREA' ||
        el.isContentEditable
    );
}

export function useCanvas(options: UseCanvasOptions) {
    const storage = resolveStorage(options.storage);
    const key = storageKey(
        options.assessmentUuid,
        options.userId,
        options.view,
    );
    const legacyKey = legacyStorageKey(options.assessmentUuid, options.userId);

    function parseStored(raw: string | null): number | null {
        if (raw === null) return null;
        const parsed = Number(raw);
        return Number.isFinite(parsed) ? clampZoom(parsed) : null;
    }

    /**
     * D43: this view's own key first; failing that, the pre-upgrade key as a
     * one-time seed. Both views read the same legacy value the first time
     * either is mounted after the upgrade — neither writes it, so it stays
     * there for the other view to seed from too — and they diverge the
     * moment either one's zoom actually changes and `persist()` writes to
     * *this* view's own key.
     */
    function loadPersisted(): number {
        if (!storage) return CANVAS_ZOOM_DEFAULT;
        return (
            parseStored(storage.getItem(key)) ??
            parseStored(storage.getItem(legacyKey)) ??
            CANVAS_ZOOM_DEFAULT
        );
    }

    const zoom = ref(loadPersisted());
    const spaceHeld = ref(false);
    const isPanning = ref(false);

    function persist() {
        storage?.setItem(key, String(zoom.value));
    }

    function setZoom(next: number) {
        zoom.value = clampZoom(next);
        persist();
    }

    function zoomIn() {
        setZoom(zoom.value + CANVAS_ZOOM_STEP);
    }

    function zoomOut() {
        setZoom(zoom.value - CANVAS_ZOOM_STEP);
    }

    function zoomReset() {
        setZoom(CANVAS_ZOOM_DEFAULT);
    }

    /** Attach to the canvas element's `@wheel`. Only Ctrl+scroll zooms; plain scroll is left to the browser for two-axis panning. */
    function handleWheel(event: WheelEvent) {
        if (!event.ctrlKey) return;
        event.preventDefault();
        setZoom(
            zoom.value +
                (event.deltaY > 0 ? -CANVAS_ZOOM_STEP : CANVAS_ZOOM_STEP),
        );
    }

    /**
     * The chart's zoom: a CSS transform, so the picture scales in both axes
     * and the connectors (SVG) scale with it.
     *
     * A transform does not change the element's layout box, so a scaled chart
     * is invisible to the scroll container. ChartView.vue therefore sizes an
     * outer element by `layout × zoom` — see `scaledSize()` there. Without
     * that, zooming in past the viewport height would crop the bottom tier
     * with no way to reach it.
     */
    const canvasStyle = computed<CSSProperties>(() => ({
        transform: `scale(${zoom.value * CANVAS_BASE_SCALE[options.view]})`,
        transformOrigin: '0 0',
    }));

    /**
     * The list's zoom: type size, not geometry (PLAN-2.2.md D9).
     *
     * The list used `canvasStyle` too, and a transform on a document-shaped
     * view is the wrong tool: scaling up pushed the list wider than the
     * viewport so every row needed horizontal scrolling to read, and scaling
     * down shrank it away from the right edge leaving dead space. Neither is
     * what someone asking for bigger text wants.
     *
     * The CSS `zoom` property is the primitive that does what was actually
     * wanted, and the two obvious alternatives do not:
     *
     * - a `transform: scale()` scales the painted result, so the layout box
     *   keeps its old size and the content overflows the viewport — the bug
     *   being fixed;
     * - a `font-size` on the container is inherited only by elements that do
     *   not set their own, and every card element carries a Tailwind
     *   `text-xs`/`text-sm` class. Those are `rem`, i.e. relative to the root
     *   element, not to this container, so they would not move at all. Making
     *   them move would mean scaling `<html>` — which would drag the toolbar
     *   and the navigation along with it.
     *
     * `zoom` gives the element its own coordinate space: width stays whatever
     * the container gives it, everything inside gets bigger, and the rows
     * reflow to suit. Zooming out fills the viewport instead of leaving a
     * gutter, which is the other half of the complaint.
     */
    const listStyle = computed<CSSProperties>(() => ({
        zoom: zoom.value * CANVAS_BASE_SCALE[options.view],
    }));

    let panStart: PanStart | null = null;

    /** Attach to the scroll container's `@keydown`. */
    function handleContainerKeydown(event: KeyboardEvent) {
        if (event.code === 'Space' && !isTypingTarget(event.target)) {
            spaceHeld.value = true;
        }
    }

    /** Attach to the scroll container's `@keyup`. */
    function handleContainerKeyup(event: KeyboardEvent) {
        if (event.code === 'Space') spaceHeld.value = false;
    }

    /** Attach to the scroll container's `@pointerdown`. Middle-button always pans; left-button pans only while space is held. */
    function handlePointerDown(event: PointerEvent, container: HTMLElement) {
        const eligible =
            event.button === 1 || (event.button === 0 && spaceHeld.value);
        if (!eligible) return;
        event.preventDefault();
        isPanning.value = true;
        panStart = {
            clientX: event.clientX,
            clientY: event.clientY,
            scrollLeft: container.scrollLeft,
            scrollTop: container.scrollTop,
        };
        container.setPointerCapture?.(event.pointerId);
    }

    /** Attach to the scroll container's `@pointermove`. */
    function handlePointerMove(event: PointerEvent, container: HTMLElement) {
        if (!isPanning.value || !panStart) return;
        container.scrollLeft =
            panStart.scrollLeft - (event.clientX - panStart.clientX);
        container.scrollTop =
            panStart.scrollTop - (event.clientY - panStart.clientY);
    }

    /** Attach to the scroll container's `@pointerup`/`@pointercancel`. */
    function handlePointerUp() {
        isPanning.value = false;
        panStart = null;
    }

    return {
        zoom,
        canvasStyle,
        listStyle,
        spaceHeld,
        isPanning,
        setZoom,
        zoomIn,
        zoomOut,
        zoomReset,
        handleWheel,
        handleContainerKeydown,
        handleContainerKeyup,
        handlePointerDown,
        handlePointerMove,
        handlePointerUp,
    };
}

export type UseCanvasReturn = ReturnType<typeof useCanvas>;
