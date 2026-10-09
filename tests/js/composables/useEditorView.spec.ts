/**
 * `createEditorView`'s dual-canvas proxy (W84/D43).
 *
 * `useCanvas.spec.ts` already pins the underlying per-instance behaviour —
 * independent persistence, the two baselines, seeding from the pre-upgrade
 * key. What is specific to this file is the *dispatch*: `view.canvas` has to
 * behave like a single canvas to every caller that does not know which view
 * is mounted (Show.vue, ChartView.vue, the keyboard shortcuts), while
 * actually holding two independent zooms underneath and switching which one
 * responds as `viewMode` changes.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { createEditorView } from '../../../resources/js/composables/useEditorView';
import { makeFixtureStore } from './support/fixture';

beforeEach(() => {
    localStorage.clear();
});

describe('createEditorView canvas proxy', () => {
    it('starts on the chart, at 100% on both, on a fresh assessment', () => {
        const store = makeFixtureStore();
        const view = createEditorView(store, { assessmentUuid: 'ev-1' });

        expect(view.viewMode.mode.value).toBe('chart');
        expect(view.canvas.zoom.value).toBe(1);
        expect(view.canvas.chart.zoom.value).toBe(1);
        expect(view.canvas.list.zoom.value).toBe(1);
    });

    it('zoomIn on the proxy changes the active view only, and switching views changes what the proxy reports', () => {
        const store = makeFixtureStore();
        const view = createEditorView(store, { assessmentUuid: 'ev-2' });

        // Still on the chart (the default). zoomIn through the generic proxy
        // must land on the chart instance.
        view.canvas.zoomIn();
        expect(view.canvas.chart.zoom.value).toBeCloseTo(1.1, 6);
        expect(view.canvas.list.zoom.value).toBe(1);
        expect(view.canvas.zoom.value).toBeCloseTo(1.1, 6);

        // Switch to the list. The proxy's `zoom` now reports the list's own
        // (untouched) value, not the chart's — the two are independent.
        view.viewMode.set('list');
        expect(view.canvas.zoom.value).toBe(1);

        view.canvas.zoomOut();
        expect(view.canvas.list.zoom.value).toBeCloseTo(0.9, 6);
        // The chart is unaffected by an action taken while the list is active.
        expect(view.canvas.chart.zoom.value).toBeCloseTo(1.1, 6);
    });

    it('canvasStyle always reads the chart instance and listStyle always reads the list instance, regardless of which view is active', () => {
        const store = makeFixtureStore();
        const view = createEditorView(store, { assessmentUuid: 'ev-3' });

        view.canvas.chart.setZoom(1.5);
        view.canvas.list.setZoom(0.6);

        // Still on the chart view, but listStyle must already reflect the
        // list's own zoom — a reader switching view sees it correctly on the
        // very first frame, not one tick behind. Expected numbers come from
        // useCanvas.ts's CANVAS_BASE_SCALE (chart 1.1, list 1.35), pinned
        // separately in useCanvas.spec.ts — this test is about *which*
        // instance each style reads from, not the constants themselves.
        expect(view.canvas.canvasStyle.value.transform).toBe(
            `scale(${1.5 * 1.1})`,
        );
        expect(view.canvas.listStyle.value.zoom).toBeCloseTo(0.6 * 1.35, 6);

        // And switching the active view to 'list' does not change either —
        // canvasStyle/listStyle are not "the active view's style", they are
        // each permanently their own view's.
        view.viewMode.set('list');
        expect(view.canvas.canvasStyle.value.transform).toBe(
            `scale(${1.5 * 1.1})`,
        );
        expect(view.canvas.listStyle.value.zoom).toBeCloseTo(0.6 * 1.35, 6);
    });

    it('setZoom/zoomReset through the proxy affect only the active view', () => {
        const store = makeFixtureStore();
        const view = createEditorView(store, { assessmentUuid: 'ev-4' });

        view.canvas.setZoom(1.8);
        expect(view.canvas.chart.zoom.value).toBe(1.8);
        expect(view.canvas.list.zoom.value).toBe(1);

        view.viewMode.set('list');
        view.canvas.setZoom(0.5);
        expect(view.canvas.list.zoom.value).toBe(0.5);
        expect(view.canvas.chart.zoom.value).toBe(1.8);

        view.canvas.zoomReset();
        expect(view.canvas.list.zoom.value).toBe(1);
        expect(view.canvas.chart.zoom.value).toBe(1.8);
    });

    it('persists each view under its own key, surviving a fresh createEditorView call', () => {
        const store = makeFixtureStore();
        const first = createEditorView(store, {
            assessmentUuid: 'ev-5',
            userId: 9,
        });

        first.canvas.chart.setZoom(0.7);
        first.viewMode.set('list');
        first.canvas.setZoom(1.9);

        const second = createEditorView(store, {
            assessmentUuid: 'ev-5',
            userId: 9,
        });

        expect(second.canvas.chart.zoom.value).toBeCloseTo(0.7, 5);
        expect(second.canvas.list.zoom.value).toBeCloseTo(1.9, 5);
    });

    /**
     * D43's own acceptance line: a value stored under the pre-W84 key (no
     * view component) is read once as the seed for *both* views.
     */
    it('seeds both the chart and the list from a zoom stored before this package shipped', () => {
        const store = makeFixtureStore();
        localStorage.setItem('arguesecure:canvas-zoom:anon:ev-6', '0.7');

        const view = createEditorView(store, { assessmentUuid: 'ev-6' });

        expect(view.canvas.chart.zoom.value).toBeCloseTo(0.7, 5);
        expect(view.canvas.list.zoom.value).toBeCloseTo(0.7, 5);
    });
});
