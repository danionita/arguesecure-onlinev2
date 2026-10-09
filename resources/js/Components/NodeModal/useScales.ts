/**
 * Reads the assessment's three configured scales straight off the Inertia
 * page props (`usePage().props`) rather than through the assessment store.
 *
 * Gap worth recording (see the W13 report): AssessmentController already
 * sends `scaleLevels`/`scales` on Assessment/Show (app/Http/Controllers/
 * AssessmentController.php lines ~61-62 — the exact shape AssessmentGraph
 * carries, schema.md §3.2), but Pages/Assessment/Show.vue's own
 * `defineProps` doesn't declare them and createAssessmentStore()'s
 * `AssessmentStoreInit` doesn't accept them, so the qualitative Values-tab
 * level pickers have no store-exposed path to this data. Both those files
 * are outside this package's ownership (Pages/**, stores/**), so rather
 * than editing them this composable reaches the same data the page
 * component already received via Inertia's page-props object directly —
 * `usePage().props` carries everything `Inertia::render()` sent regardless
 * of what any one page component's `defineProps` declares, so this needs
 * no change to Show.vue or the store to work correctly today. The cleaner
 * long-term fix is for W12 to add `scaleLevels`/`scales` to
 * `AssessmentStoreInit` so every consumer reads them from one place.
 */
import type { PageProps } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

export type ScaleKind = 'impact' | 'likelihood' | 'strength';

export interface ScaleLevelRecord {
    id: number;
    scale_id: number;
    kind: ScaleKind;
    unit: string;
    code: string;
    label: string;
    ordinal: number;
    lower_bound: number;
    upper_bound: number | null;
    representative: number;
}

export interface ScaleRecord {
    id: number;
    kind: ScaleKind;
    unit: string;
    is_system: boolean;
}

interface ScalePageProps extends Record<string, unknown> {
    scaleLevels?: ScaleLevelRecord[];
    scales?: ScaleRecord[];
}

export function useScales() {
    const page = usePage<PageProps<ScalePageProps>>();

    const scaleLevels = computed(() => page.props.scaleLevels ?? []);
    const scales = computed(() => page.props.scales ?? []);

    function levelsForKind(kind: ScaleKind): ScaleLevelRecord[] {
        return scaleLevels.value
            .filter((level) => level.kind === kind)
            .slice()
            .sort((a, b) => a.ordinal - b.ordinal);
    }

    function levelById(id: number | null): ScaleLevelRecord | null {
        if (id === null) return null;
        return scaleLevels.value.find((level) => level.id === id) ?? null;
    }

    /** ui.md §8.4's "uncalibrated defaults" advisory, surfaced per scale. */
    function isUncalibrated(kind: ScaleKind): boolean {
        return scales.value.find((s) => s.kind === kind)?.is_system ?? false;
    }

    return { scaleLevels, scales, levelsForKind, levelById, isUncalibrated };
}
