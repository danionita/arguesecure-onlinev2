/**
 * Focus mode — isolate one subtree (ui.md §5 / PLAN.md W15).
 *
 * Focus is keyed on the *occurrence* path-key, not the node uuid, for the
 * same reason expand/collapse state is (ui.md §3.1, assessmentStore.ts's
 * top-of-file comment): focusing one occurrence of a shared node must
 * isolate only *that* branch of the DAG, not every place the node happens
 * to also appear. A defence linked to two attacks, focused via one of
 * them, should not pull its sibling attack's unrelated tree into view.
 */
import { computed, ref } from 'vue';
import type { AssessmentStore } from '../stores/assessmentStore';
import { enumerateOccurrences, type Occurrence } from './occurrences';

export interface FocusBreadcrumbEntry {
    uuid: string;
    title: string;
    pathKey: string;
}

export function useFocusMode(store: AssessmentStore) {
    const focusedPathKey = ref<string | null>(null);

    const isActive = computed(() => focusedPathKey.value !== null);

    const allOccurrences = computed<Occurrence[]>(() =>
        enumerateOccurrences(store),
    );

    const focusedOccurrence = computed<Occurrence | null>(() => {
        if (!focusedPathKey.value) return null;
        return (
            allOccurrences.value.find(
                (o) => o.pathKey === focusedPathKey.value,
            ) ?? null
        );
    });

    /**
     * Every occurrence path-key that should stay visible: the focused
     * occurrence itself plus everything below it. `null` means "no focus
     * active, everything is visible" — kept as a distinct value from an
     * empty set so callers can't accidentally hide the whole tree by
     * mistaking "not focused" for "focused on nothing".
     */
    const visiblePathKeys = computed<Set<string> | null>(() => {
        const focused = focusedOccurrence.value;
        if (!focused) return null;
        const prefix = `${focused.pathKey}/`;
        const set = new Set<string>();
        for (const occurrence of allOccurrences.value) {
            if (
                occurrence.pathKey === focused.pathKey ||
                occurrence.pathKey.startsWith(prefix)
            ) {
                set.add(occurrence.pathKey);
            }
        }
        return set;
    });

    function isVisible(pathKey: string): boolean {
        const set = visiblePathKeys.value;
        return set === null || set.has(pathKey);
    }

    /** Root-first ancestors of the focused occurrence, for the "breadcrumb shows the path back" requirement. */
    const breadcrumb = computed<FocusBreadcrumbEntry[]>(() => {
        const focused = focusedOccurrence.value;
        if (!focused) return [];
        return focused.path.map((uuid, index) => ({
            uuid,
            title: store.nodes[uuid]?.title ?? '',
            pathKey: focused.path.slice(0, index + 1).join('/'),
        }));
    });

    function enter(pathKey: string) {
        focusedPathKey.value = pathKey;
    }

    /** `Escape` clears focus mode per ui.md §4 — W14 owns the key binding and calls this. */
    function exit() {
        focusedPathKey.value = null;
    }

    return {
        focusedPathKey,
        isActive,
        focusedOccurrence,
        visiblePathKeys,
        isVisible,
        breadcrumb,
        enter,
        exit,
    };
}

export type UseFocusModeReturn = ReturnType<typeof useFocusMode>;
