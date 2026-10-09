/**
 * "Is this the Phishing you already have?" — suggestions for the add-a-child
 * title field (ui.md §3.3, W40, PLAN-2.2.md item 10a).
 *
 * Adding a child has always created a node. That is how an assessment ends up
 * with *Phishing* three times under three risks: three nodes, three sets of
 * defences to keep in step, and a total that counts one attack as though it
 * were three separate problems. The DAG has allowed one node under several
 * parents since W5; nothing offered it at the moment someone is actually
 * deciding, which is while they are typing the name.
 *
 * ## The fast path must not get slower
 *
 * This is the constraint the shape of the thing follows from. Typing a new
 * name and pressing Enter must still create a node in exactly the keystrokes
 * it did before — no confirmation, no extra Tab, no list to dismiss. So:
 *
 * - nothing is highlighted until the user presses ArrowDown. Enter with
 *   `activeIndex === -1` means what it has always meant.
 * - the list is advisory. It appears under the field and never takes focus,
 *   moves the caret, or rewrites what was typed.
 *
 * ## Why it is a composable and not a Combobox
 *
 * `Components/UI/Combobox.vue` owns its own input, its own value, and its own
 * blur handling. NodeCard's title field already has a draft that commits on
 * blur, an Escape-to-revert, and a W16 lock badge, and pushing all three
 * through a shared component used by the share dialog would be a rewrite of
 * two working things to add one. This supplies the list and the keyboard
 * state; the field stays the field.
 */
import type { AssessmentStore } from '@/stores/assessmentStore';
import { ref } from 'vue';

export interface NodeSuggestion {
    uuid: string;
    title: string;
    description: string | null;
    /** How many parents it already has — the reason the row is legible at all. */
    occurrences: number;
}

/** Only these two branch in the DAG; a risk has exactly one parent (schema.md §3.3). */
export type SuggestableType = 'attack' | 'defence';

/**
 * Long enough that a fast typist sends one request rather than one per letter,
 * short enough that the list is there by the time the eye reaches it.
 */
const DEBOUNCE_MS = 150;

export function useNodeSuggestions(store: AssessmentStore) {
    const suggestions = ref<NodeSuggestion[]>([]);
    /** -1 means "nothing chosen" — the state Enter must treat as create-new. */
    const activeIndex = ref(-1);

    let timer: ReturnType<typeof setTimeout> | null = null;
    // Responses can land out of order, and a stale one overwriting a fresh
    // one is how a suggestion list ends up showing results for a prefix the
    // user has already typed past. Only the newest request may write.
    let latest = 0;

    function reset(): void {
        if (timer !== null) clearTimeout(timer);
        timer = null;
        latest += 1;
        suggestions.value = [];
        activeIndex.value = -1;
    }

    async function fetchNow(
        type: SuggestableType,
        parentUuid: string | null,
        term: string,
    ): Promise<void> {
        const ticket = ++latest;

        try {
            const response = await window.axios.get<{
                nodes: NodeSuggestion[];
            }>(route('assessments.nodes.search', store.assessment.uuid), {
                params: {
                    type,
                    q: term,
                    ...(parentUuid ? { parent_uuid: parentUuid } : {}),
                },
            });

            if (ticket !== latest) return;
            suggestions.value = response.data.nodes ?? [];
            // Re-clamped rather than preserved: the row that was highlighted
            // is not the row now at that index.
            activeIndex.value = -1;
        } catch {
            // A failed lookup is not a failed edit. The field still works;
            // the user just does not get offered anything, which is exactly
            // the behaviour they had before this existed.
            if (ticket === latest) suggestions.value = [];
        }
    }

    function search(
        type: SuggestableType,
        parentUuid: string | null,
        term: string,
    ): void {
        if (timer !== null) clearTimeout(timer);
        timer = setTimeout(
            () => void fetchNow(type, parentUuid, term),
            DEBOUNCE_MS,
        );
    }

    /** Wraps at both ends, and ArrowUp from nothing lands on the last row. */
    function move(delta: number): void {
        const count = suggestions.value.length;
        if (count === 0) return;

        const next = activeIndex.value + delta;
        activeIndex.value = next < 0 ? count - 1 : next >= count ? 0 : next;
    }

    function highlighted(): NodeSuggestion | null {
        return activeIndex.value >= 0
            ? (suggestions.value[activeIndex.value] ?? null)
            : null;
    }

    return {
        suggestions,
        activeIndex,
        search,
        fetchNow,
        reset,
        move,
        highlighted,
    };
}
