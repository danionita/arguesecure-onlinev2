/**
 * What the recursive chart nodes need from the view that owns them (W32):
 * where each occurrence sits, and which one the reader has selected.
 *
 * Provided rather than prop-drilled for the same reason the editor view is:
 * ChartNode renders itself recursively, so threading a layout map and a
 * selection ref through four levels of `:props` would be four chances to
 * pass a stale one.
 */
import type { TreeLayout } from '@/composables/useTreeLayout';
import type { InjectionKey, Ref } from 'vue';

export interface ChartContext {
    layout: Ref<TreeLayout>;
    selectedPathKey: Ref<string | null>;
    select: (pathKey: string) => void;
}

export const CHART_CONTEXT_KEY: InjectionKey<ChartContext> = Symbol(
    'arguesecure.chartContext',
);
