import { describe, expect, it } from 'vitest';
import {
    CANVAS_BASE_SCALE,
    CANVAS_ZOOM_DEFAULT,
    CANVAS_ZOOM_MAX,
    CANVAS_ZOOM_MIN,
    clampZoom,
    useCanvas,
} from '../../../resources/js/composables/useCanvas';
import { makeMemoryStorage } from './support/fixture';

describe('clampZoom', () => {
    it('clamps to the 50%-200% range from ui.md §5', () => {
        expect(clampZoom(0.1)).toBe(CANVAS_ZOOM_MIN);
        expect(clampZoom(10)).toBe(CANVAS_ZOOM_MAX);
        expect(clampZoom(1.25)).toBe(1.25);
    });
});

/**
 * W84/D43: `view` is now required, since each instance is one view's zoom.
 * The behaviour asserted in this block (clamping, stepping, panning) does not
 * depend on which view, so every call here fixes `view: 'chart'` — the
 * per-view-specific behaviour (independent persistence, the two baselines)
 * is asserted separately below.
 */
describe('useCanvas', () => {
    it('starts at 100% when nothing is persisted', () => {
        const canvas = useCanvas({
            assessmentUuid: 'a1',
            view: 'chart',
            storage: makeMemoryStorage(),
        });
        expect(canvas.zoom.value).toBe(CANVAS_ZOOM_DEFAULT);
    });

    it('zoomIn/zoomOut/zoomReset step and clamp', () => {
        const canvas = useCanvas({
            assessmentUuid: 'a1',
            view: 'chart',
            storage: makeMemoryStorage(),
        });
        canvas.setZoom(1.95);
        canvas.zoomIn();
        expect(canvas.zoom.value).toBe(CANVAS_ZOOM_MAX); // clamped, not 2.05

        canvas.setZoom(0.55);
        canvas.zoomOut();
        expect(canvas.zoom.value).toBeCloseTo(CANVAS_ZOOM_MIN, 5);

        canvas.zoomIn();
        canvas.zoomReset();
        expect(canvas.zoom.value).toBe(CANVAS_ZOOM_DEFAULT);
    });

    it('exposes a CSS transform, not a layout-affecting property, so text stays crisp per ui.md §5', () => {
        const canvas = useCanvas({
            assessmentUuid: 'a1',
            view: 'chart',
            storage: makeMemoryStorage(),
        });
        canvas.setZoom(1.5);
        expect(canvas.canvasStyle.value.transform).toMatch(/^scale\(/);
    });

    it('persists per assessment: two assessments in the same storage do not clobber each other', () => {
        const storage = makeMemoryStorage();
        const a = useCanvas({
            assessmentUuid: 'assessment-a',
            view: 'chart',
            storage,
        });
        const b = useCanvas({
            assessmentUuid: 'assessment-b',
            view: 'chart',
            storage,
        });

        a.setZoom(1.8);
        b.setZoom(0.6);

        const aReloaded = useCanvas({
            assessmentUuid: 'assessment-a',
            view: 'chart',
            storage,
        });
        const bReloaded = useCanvas({
            assessmentUuid: 'assessment-b',
            view: 'chart',
            storage,
        });
        expect(aReloaded.zoom.value).toBe(1.8);
        expect(bReloaded.zoom.value).toBeCloseTo(0.6, 5);
    });

    it('persists per user: two users on the same assessment do not clobber each other', () => {
        const storage = makeMemoryStorage();
        const dana = useCanvas({
            assessmentUuid: 'a1',
            view: 'chart',
            userId: 'dana',
            storage,
        });
        const sam = useCanvas({
            assessmentUuid: 'a1',
            view: 'chart',
            userId: 'sam',
            storage,
        });

        dana.setZoom(2);
        sam.setZoom(0.5);

        const danaReloaded = useCanvas({
            assessmentUuid: 'a1',
            view: 'chart',
            userId: 'dana',
            storage,
        });
        expect(danaReloaded.zoom.value).toBe(2);
    });

    it('handleWheel only zooms when Ctrl is held, and prevents the page from scrolling when it does', () => {
        const canvas = useCanvas({
            assessmentUuid: 'a1',
            view: 'chart',
            storage: makeMemoryStorage(),
        });
        let prevented = false;
        const plainScroll = {
            ctrlKey: false,
            deltaY: 100,
            preventDefault: () => {
                prevented = true;
            },
        } as unknown as WheelEvent;
        canvas.handleWheel(plainScroll);
        expect(canvas.zoom.value).toBe(CANVAS_ZOOM_DEFAULT);
        expect(prevented).toBe(false);

        const ctrlScrollUp = {
            ctrlKey: true,
            deltaY: -100,
            preventDefault: () => {
                prevented = true;
            },
        } as unknown as WheelEvent;
        canvas.handleWheel(ctrlScrollUp);
        expect(canvas.zoom.value).toBeGreaterThan(CANVAS_ZOOM_DEFAULT);
        expect(prevented).toBe(true);
    });

    it('tracks space-held state from container-scoped key events, ignoring typing targets', () => {
        const canvas = useCanvas({
            assessmentUuid: 'a1',
            view: 'chart',
            storage: makeMemoryStorage(),
        });
        const input = document.createElement('input');

        canvas.handleContainerKeydown({
            code: 'Space',
            target: input,
        } as unknown as KeyboardEvent);
        expect(canvas.spaceHeld.value).toBe(false);

        canvas.handleContainerKeydown({
            code: 'Space',
            target: document.createElement('div'),
        } as unknown as KeyboardEvent);
        expect(canvas.spaceHeld.value).toBe(true);

        canvas.handleContainerKeyup({ code: 'Space' } as KeyboardEvent);
        expect(canvas.spaceHeld.value).toBe(false);
    });

    it('pans the container by dragging while space is held', () => {
        const canvas = useCanvas({
            assessmentUuid: 'a1',
            view: 'chart',
            storage: makeMemoryStorage(),
        });
        const container = document.createElement('div');
        Object.defineProperty(container, 'scrollLeft', {
            value: 0,
            writable: true,
        });
        Object.defineProperty(container, 'scrollTop', {
            value: 0,
            writable: true,
        });

        canvas.handleContainerKeydown({
            code: 'Space',
            target: document.createElement('div'),
        } as unknown as KeyboardEvent);

        canvas.handlePointerDown(
            {
                button: 0,
                clientX: 100,
                clientY: 100,
                pointerId: 1,
                preventDefault: () => {},
            } as unknown as PointerEvent,
            container,
        );
        expect(canvas.isPanning.value).toBe(true);

        canvas.handlePointerMove(
            { clientX: 60, clientY: 130 } as unknown as PointerEvent,
            container,
        );
        expect(container.scrollLeft).toBe(40);
        expect(container.scrollTop).toBe(-30);

        canvas.handlePointerUp();
        expect(canvas.isPanning.value).toBe(false);
    });

    it('always pans on the middle mouse button, regardless of space', () => {
        const canvas = useCanvas({
            assessmentUuid: 'a1',
            view: 'chart',
            storage: makeMemoryStorage(),
        });
        const container = document.createElement('div');
        canvas.handlePointerDown(
            {
                button: 1,
                clientX: 0,
                clientY: 0,
                pointerId: 1,
                preventDefault: () => {},
            } as unknown as PointerEvent,
            container,
        );
        expect(canvas.isPanning.value).toBe(true);
    });

    it('ignores a plain left-click drag when space is not held', () => {
        const canvas = useCanvas({
            assessmentUuid: 'a1',
            view: 'chart',
            storage: makeMemoryStorage(),
        });
        const container = document.createElement('div');
        canvas.handlePointerDown(
            {
                button: 0,
                clientX: 0,
                clientY: 0,
                pointerId: 1,
            } as unknown as PointerEvent,
            container,
        );
        expect(canvas.isPanning.value).toBe(false);
    });
});

/**
 * W34 / PLAN-2.2.md D9: the two views spend the *same-shaped* zoom number
 * differently. Since W84/D43 the number itself is no longer shared (see the
 * "independent persistence" block below); these assertions are about the
 * *properties chosen* — a `transform` on the list is the bug that was
 * reported, so a regression to it has to turn one of these red rather than
 * sliding through as "a style is applied".
 */
describe('useCanvas zoom, per view', () => {
    function canvasAt(zoom: number, view: 'chart' | 'list' = 'chart') {
        const canvas = useCanvas({
            assessmentUuid: 'a1',
            view,
            storage: makeMemoryStorage(),
        });
        canvas.setZoom(zoom);
        return canvas;
    }

    /** The number inside `scale(...)`, so the assertions can be arithmetic. */
    function chartScale(style: { transform?: unknown }): number {
        const match = /^scale\(([\d.]+)\)$/.exec(String(style.transform));
        expect(match).not.toBeNull();

        return Number(match![1]);
    }

    it('scales the chart geometrically, both axes, from the top-left', () => {
        const canvas = canvasAt(1.5, 'chart');
        expect(chartScale(canvas.canvasStyle.value)).toBeCloseTo(
            1.5 * CANVAS_BASE_SCALE.chart,
            6,
        );
        expect(canvas.canvasStyle.value.transformOrigin).toBe('0 0');
    });

    it('scales the list with CSS zoom, which keeps the container width', () => {
        const canvas = canvasAt(1.5, 'list');
        expect(canvas.listStyle.value.zoom).toBeCloseTo(
            1.5 * CANVAS_BASE_SCALE.list,
            6,
        );
    });

    it('never puts a transform on the list: that is what pushed it past the viewport', () => {
        const canvas = canvasAt(2, 'list');
        expect(canvas.listStyle.value.transform).toBeUndefined();
    });

    it('never puts a font-size on the list: Tailwind text-* is rem, so it would not move', () => {
        const canvas = canvasAt(2, 'list');
        expect(canvas.listStyle.value.fontSize).toBeUndefined();
    });

    it('zooming out below 100% is expressed the same way, so the list refills the viewport', () => {
        const canvas = canvasAt(0.75, 'list');
        expect(canvas.listStyle.value.zoom).toBeCloseTo(
            0.75 * CANVAS_BASE_SCALE.list,
            6,
        );
    });

    /**
     * W84/D51. Chart and list get different, specified baselines — the list's
     * is higher, because D51 says the list opens more zoomed in. Both are
     * pinned as exact values, not merely "list > chart", so a future change
     * to either constant has to come back and update this test deliberately.
     */
    describe('the per-view baseline (W69, made per-view by W84/D51)', () => {
        it('is exactly 1.1 for the chart, unchanged from W69', () => {
            expect(CANVAS_BASE_SCALE.chart).toBe(1.1);
        });

        it('is exactly 1.35 for the list — higher than the chart, per D51', () => {
            expect(CANVAS_BASE_SCALE.list).toBe(1.35);
            expect(CANVAS_BASE_SCALE.list).toBeGreaterThan(
                CANVAS_BASE_SCALE.chart,
            );
        });

        it('leaves the control reading 100% on a first visit, for both views', () => {
            const chart = useCanvas({
                assessmentUuid: 'fresh',
                view: 'chart',
                storage: makeMemoryStorage(),
            });
            const list = useCanvas({
                assessmentUuid: 'fresh',
                view: 'list',
                storage: makeMemoryStorage(),
            });

            // What ZoomControls.vue prints. The rejected alternative — moving
            // CANVAS_ZOOM_DEFAULT — would make either of these read something
            // other than 100%.
            expect(Math.round(chart.zoom.value * 100)).toBe(100);
            expect(Math.round(list.zoom.value * 100)).toBe(100);
            expect(CANVAS_ZOOM_DEFAULT).toBe(1);
        });

        it('leaves the range and the step alone, so 50%-200% still means that', () => {
            expect(CANVAS_ZOOM_MIN).toBe(0.5);
            expect(CANVAS_ZOOM_MAX).toBe(2);

            const canvas = canvasAt(1, 'list');
            canvas.zoomIn();
            expect(canvas.zoom.value).toBeCloseTo(1.1, 6);
        });
    });

    /**
     * Integration 2.5. The report (`Pages/Report/Show.vue`, W83) is a third
     * `useCanvas.ts` caller with `view: 'report'` — a `CanvasView`, not an
     * `EditorViewMode` (see `CanvasView`'s docblock). Unlike the chart and
     * the list, the report must NOT open pre-zoomed: it is a document, and
     * its baseline is exactly `1.0`, pinned as a specific value rather than
     * merely "not 1.1 or 1.35" so a future change to either of those
     * constants cannot accidentally make this pass by coincidence.
     */
    describe("the report's own baseline (Integration 2.5)", () => {
        it('is exactly 1.0 for the report — a document, unlike the chart (1.1) or the list (1.35)', () => {
            expect(CANVAS_BASE_SCALE.report).toBe(1.0);
            expect(CANVAS_BASE_SCALE.report).not.toBe(CANVAS_BASE_SCALE.chart);
            expect(CANVAS_BASE_SCALE.report).not.toBe(CANVAS_BASE_SCALE.list);
        });

        it("applies CSS zoom (the listStyle mechanism), scaled by CANVAS_BASE_SCALE.report and not by another view's baseline", () => {
            const report = useCanvas({
                assessmentUuid: 'a1',
                view: 'report',
                storage: makeMemoryStorage(),
            });

            // 100% control reading -> exactly the report baseline, 1.0 -
            // not 1.1 (chart) and not 1.35 (list).
            expect(report.listStyle.value.zoom).toBe(1.0);

            report.setZoom(1.5);
            expect(report.listStyle.value.zoom).toBeCloseTo(
                1.5 * CANVAS_BASE_SCALE.report,
                6,
            );
            expect(report.listStyle.value.zoom).toBeCloseTo(1.5, 6);
        });

        it('never puts a transform on the report: it is a document, like the list', () => {
            const report = useCanvas({
                assessmentUuid: 'a1',
                view: 'report',
                storage: makeMemoryStorage(),
            });
            report.setZoom(2);
            expect(report.listStyle.value.transform).toBeUndefined();
        });
    });

    /**
     * W84/D43: the storage key now carries the view, so the two views'
     * persisted zoom is independent — setting the list to 70% must not touch
     * the chart's stored value or vice versa.
     */
    describe('independent persistence per view (W84/D43)', () => {
        it('persists the chart and the list separately, in the same assessment for the same user', () => {
            const storage = makeMemoryStorage();
            const chart = useCanvas({
                assessmentUuid: 'a1',
                view: 'chart',
                storage,
            });
            const list = useCanvas({
                assessmentUuid: 'a1',
                view: 'list',
                storage,
            });

            chart.setZoom(0.7);
            list.setZoom(1.8);

            const chartReloaded = useCanvas({
                assessmentUuid: 'a1',
                view: 'chart',
                storage,
            });
            const listReloaded = useCanvas({
                assessmentUuid: 'a1',
                view: 'list',
                storage,
            });

            expect(chartReloaded.zoom.value).toBeCloseTo(0.7, 5);
            expect(listReloaded.zoom.value).toBe(1.8);
        });

        it('changing one view leaves the other view unaffected even mid-session', () => {
            const storage = makeMemoryStorage();
            const chart = useCanvas({
                assessmentUuid: 'a1',
                view: 'chart',
                storage,
            });
            const list = useCanvas({
                assessmentUuid: 'a1',
                view: 'list',
                storage,
            });

            expect(chart.zoom.value).toBe(CANVAS_ZOOM_DEFAULT);
            expect(list.zoom.value).toBe(CANVAS_ZOOM_DEFAULT);

            list.zoomIn();
            list.zoomIn();

            expect(list.zoom.value).toBeCloseTo(1.2, 6);
            expect(chart.zoom.value).toBe(CANVAS_ZOOM_DEFAULT);
        });

        /**
         * The pre-W84 key had no view component. A user who set 70% before
         * this package shipped should not silently find 100% in either view
         * after the upgrade — both views read that legacy value once, as
         * their seed, and diverge from there. This is the "read once... then
         * diverge" behaviour the brief specifies, not merely "the old value
         * survives somewhere".
         */
        describe('seeding from the pre-upgrade key', () => {
            it('seeds a fresh chart instance from the legacy key when no per-view key exists', () => {
                const storage = makeMemoryStorage();
                storage.setItem('arguesecure:canvas-zoom:anon:a1', '1.4');

                const chart = useCanvas({
                    assessmentUuid: 'a1',
                    view: 'chart',
                    storage,
                });
                expect(chart.zoom.value).toBe(1.4);
            });

            it('seeds a fresh list instance from the SAME legacy key too', () => {
                const storage = makeMemoryStorage();
                storage.setItem('arguesecure:canvas-zoom:anon:a1', '1.4');

                const list = useCanvas({
                    assessmentUuid: 'a1',
                    view: 'list',
                    storage,
                });
                expect(list.zoom.value).toBe(1.4);
            });

            it('diverges the moment either view changes, without touching the legacy key', () => {
                const storage = makeMemoryStorage();
                storage.setItem('arguesecure:canvas-zoom:anon:a1', '1.4');

                const chart = useCanvas({
                    assessmentUuid: 'a1',
                    view: 'chart',
                    storage,
                });
                const list = useCanvas({
                    assessmentUuid: 'a1',
                    view: 'list',
                    storage,
                });
                expect(chart.zoom.value).toBe(1.4);
                expect(list.zoom.value).toBe(1.4);

                // The user zooms the list only.
                list.setZoom(0.8);

                // A fresh chart instance still reads 1.4 from the legacy key
                // — the list's write did not touch it, and the chart never
                // wrote its own key.
                const chartAgain = useCanvas({
                    assessmentUuid: 'a1',
                    view: 'chart',
                    storage,
                });
                expect(chartAgain.zoom.value).toBe(1.4);

                // A fresh list instance now reads its own persisted 0.8,
                // ignoring the legacy key entirely.
                const listAgain = useCanvas({
                    assessmentUuid: 'a1',
                    view: 'list',
                    storage,
                });
                expect(listAgain.zoom.value).toBeCloseTo(0.8, 5);
            });

            it('prefers the per-view key over the legacy one once it exists', () => {
                const storage = makeMemoryStorage();
                storage.setItem('arguesecure:canvas-zoom:anon:a1', '1.4');
                storage.setItem('arguesecure:canvas-zoom:list:anon:a1', '0.6');

                const list = useCanvas({
                    assessmentUuid: 'a1',
                    view: 'list',
                    storage,
                });
                expect(list.zoom.value).toBeCloseTo(0.6, 5);
            });
        });
    });
});
