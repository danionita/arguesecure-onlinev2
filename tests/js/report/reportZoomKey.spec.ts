/**
 * D43 — zoom is persisted per view; the storage key gains the view mode.
 * Integration 2.5: `Pages/Report/Show.vue` now passes `view: 'report'`
 * straight through to `useCanvas.ts` (a `CanvasView`, widened there rather
 * than on `EditorViewMode` — see that file's header) instead of folding
 * `::report` into the *assessmentUuid* argument. This test asserts the
 * property the brief calls for directly, against the same `useCanvas.ts`
 * the chart and list views already read and write, without mounting the
 * whole page: the report's key differs from the chart/list key, and writing
 * through it never touches the plain `assessmentUuid`/`chart`/`list` keys
 * (D9 — through W83 chart and list still share one zoom number by design;
 * only the report gets its own).
 */
import { describe, expect, it } from 'vitest';
import { useCanvas } from '../../../resources/js/composables/useCanvas';

function fakeStorage() {
    const store = new Map<string, string>();
    return {
        getItem: (key: string) => store.get(key) ?? null,
        setItem: (key: string, value: string) => {
            store.set(key, value);
        },
        raw: store,
    };
}

describe('D43 — the report zoom key is view-namespaced', () => {
    it('the key the report writes to contains "report" and differs from the chart/list key', () => {
        const storage = fakeStorage();

        const chartOrList = useCanvas({
            assessmentUuid: 'a1',
            view: 'chart',
            userId: 7,
            storage,
        });
        chartOrList.setZoom(0.7);

        const report = useCanvas({
            assessmentUuid: 'a1',
            view: 'report',
            userId: 7,
            storage,
        });
        report.setZoom(1.4);

        const keys = [...storage.raw.keys()];
        expect(keys).toHaveLength(2);

        const reportKey = keys.find((k) => storage.raw.get(k) === '1.4')!;
        const chartListKey = keys.find((k) => storage.raw.get(k) === '0.7')!;

        expect(reportKey).toContain('report');
        expect(reportKey).not.toBe(chartListKey);
    });

    it("setting the report's zoom does not change the chart/list value", () => {
        const storage = fakeStorage();

        const chartOrList = useCanvas({
            assessmentUuid: 'a1',
            view: 'chart',
            userId: 7,
            storage,
        });
        chartOrList.setZoom(0.7);

        const report = useCanvas({
            assessmentUuid: 'a1',
            view: 'report',
            userId: 7,
            storage,
        });
        report.zoomIn();
        report.zoomIn();
        report.zoomReset();

        // Re-read the chart/list value the way a fresh mount of that view
        // would, off the same storage — it must be exactly what was set,
        // untouched by anything the report composable instance above did.
        const chartOrListReread = useCanvas({
            assessmentUuid: 'a1',
            view: 'chart',
            userId: 7,
            storage,
        });
        expect(chartOrListReread.zoom.value).toBe(0.7);
    });

    it('two users on the same assessment still get independent report keys', () => {
        const storage = fakeStorage();

        const asAlice = useCanvas({
            assessmentUuid: 'a1',
            view: 'report',
            userId: 'alice',
            storage,
        });
        asAlice.setZoom(0.6);

        const asBob = useCanvas({
            assessmentUuid: 'a1',
            view: 'report',
            userId: 'bob',
            storage,
        });

        expect(asBob.zoom.value).not.toBe(0.6);
    });
});
