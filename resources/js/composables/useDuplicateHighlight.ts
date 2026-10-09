/**
 * Duplicate highlighting on hover (ui.md §5 / PLAN.md W15): "hovering or
 * focusing a node that occupies more than one position highlights every
 * occurrence with a distinct outline and draws a connector between them.
 * An explicit 'appears in N places' chip does the same on click, and is
 * the accessible equivalent of the hover behaviour."
 *
 * `hover()`/`unhover()` and `pin()`/`unpin()` are separate so the click
 * (pin) affordance keeps working after the pointer leaves — exactly what
 * "accessible equivalent of the hover behaviour" requires for keyboard and
 * screen-reader users, who never fire a hover event at all.
 *
 * Counting occurrences: this walks the full rendered DAG
 * (enumerateOccurrences) rather than reusing `store.occurrenceCount()`.
 * That store function counts distinct parent *links*, which is right for
 * the node card's own "N places" chip (ui.md §3.2) but understates true
 * rendered occurrences when an ancestor is itself duplicated — see
 * occurrences.ts's top comment and this file's test for the concrete case
 * (a defence linked to one attack that itself sits under two risks renders
 * three times total, not two). "Duplicate detection finds every occurrence
 * of a shared node and reports the right count" (the W15 brief) means
 * *this* count, not the link count.
 */
import { computed, ref } from 'vue';
import type { AssessmentStore } from '../stores/assessmentStore';
import { enumerateOccurrences, type Occurrence } from './occurrences';

export function useDuplicateHighlight(store: AssessmentStore) {
    const hoveredUuid = ref<string | null>(null);
    const pinnedUuid = ref<string | null>(null);

    /** Pin wins over hover so a click stays highlighted while the mouse wanders elsewhere. */
    const activeUuid = computed(() => pinnedUuid.value ?? hoveredUuid.value);

    const allOccurrences = computed<Occurrence[]>(() =>
        enumerateOccurrences(store),
    );

    function occurrencesOf(uuid: string): Occurrence[] {
        return allOccurrences.value.filter((o) => o.uuid === uuid);
    }

    function countFor(uuid: string): number {
        return occurrencesOf(uuid).length;
    }

    const highlightedOccurrences = computed<Occurrence[]>(() =>
        activeUuid.value ? occurrencesOf(activeUuid.value) : [],
    );

    const highlightedPathKeys = computed<Set<string>>(
        () => new Set(highlightedOccurrences.value.map((o) => o.pathKey)),
    );

    const isDuplicate = computed(() => highlightedOccurrences.value.length > 1);

    function hover(uuid: string | null) {
        hoveredUuid.value = uuid;
    }

    function unhover(uuid: string) {
        if (hoveredUuid.value === uuid) hoveredUuid.value = null;
    }

    /** Toggle: clicking the same chip twice unpins. */
    function pin(uuid: string) {
        pinnedUuid.value = pinnedUuid.value === uuid ? null : uuid;
    }

    function clear() {
        hoveredUuid.value = null;
        pinnedUuid.value = null;
    }

    return {
        hoveredUuid,
        pinnedUuid,
        activeUuid,
        highlightedOccurrences,
        highlightedPathKeys,
        isDuplicate,
        hover,
        unhover,
        pin,
        clear,
        occurrencesOf,
        countFor,
    };
}

export type UseDuplicateHighlightReturn = ReturnType<
    typeof useDuplicateHighlight
>;
