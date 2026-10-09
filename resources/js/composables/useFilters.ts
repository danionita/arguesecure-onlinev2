/**
 * Filters by tag, defence status, node type, and "has missing values"
 * (ui.md §5 / PLAN.md W15). "Non-matching nodes dim rather than disappear,
 * so structure stays legible" — this composable therefore exposes a
 * *dimmed* set of occurrence path-keys, not a hidden/visible split; the
 * consumer decides how "dim" is rendered (see the report on what NodeCard
 * would need to actually apply it).
 *
 * ## The tag gap, closed in W43
 *
 * `NodeRecord` carried no tag data when this was written — the store was not
 * built with tags in scope, and nothing in the application could create one
 * — so tag lookup was injected as a plain function, `tagsForNode`,
 * defaulting to "no node has any tags", rather than duplicating store state
 * in a parallel structure. The filter was fully implemented and tested
 * against that injection from the start; it simply had nothing to filter.
 *
 * W43 supplies the real accessor (`useEditorView.ts`). The injection stays:
 * it is what let the filter be built and tested before its data existed, and
 * it is still what lets a test drive this composable without a store full of
 * labels.
 */
import { computed, reactive } from 'vue';
import type {
    AssessmentStore,
    DefenceStatus,
    NodeType,
} from '../stores/assessmentStore';
import { enumerateOccurrences, type Occurrence } from './occurrences';

/** The type union a filter chip can target — `transfer` is the same presentation split NodeCard.vue already uses (a defence with `is_transfer`). */
export type FilterNodeType = NodeType | 'transfer';

export interface FilterState {
    tags: string[];
    statuses: DefenceStatus[];
    types: FilterNodeType[];
    missingOnly: boolean;
}

export interface UseFiltersOptions {
    /** See "The tag gap" above. Defaults to no tags on any node. */
    tagsForNode?: (uuid: string) => string[];
}

function toggleInArray<T>(arr: T[], value: T) {
    const i = arr.indexOf(value);
    if (i === -1) arr.push(value);
    else arr.splice(i, 1);
}

export function useFilters(
    store: AssessmentStore,
    options: UseFiltersOptions = {},
) {
    const tagsForNode = options.tagsForNode ?? (() => [] as string[]);

    const state = reactive<FilterState>({
        tags: [],
        statuses: [],
        types: [],
        missingOnly: false,
    });

    const isActive = computed(
        () =>
            state.tags.length > 0 ||
            state.statuses.length > 0 ||
            state.types.length > 0 ||
            state.missingOnly,
    );

    /** Mirrors NodeCard.vue's `cardType` — a defence with `is_transfer` filters as `transfer`, not `defence`. */
    function cardTypeOf(uuid: string): FilterNodeType | null {
        const node = store.nodes[uuid];
        if (!node) return null;
        return node.type === 'defence' && node.is_transfer
            ? 'transfer'
            : node.type;
    }

    /**
     * A missing value is per-occurrence, not per-node, for defences: the
     * same defence has a *different* strength on each attack it is linked
     * to (schema.md §3.6 — strength lives on the pivot), so "missing" must
     * be evaluated against this occurrence's own edge, not some other
     * occurrence's.
     */
    function hasMissingValue(occurrence: Occurrence): boolean {
        const node = store.nodes[occurrence.uuid];
        if (!node) return false;
        if (node.type === 'risk') {
            return node.impact_amount === null && node.impact_level_id === null;
        }
        if (node.type === 'attack') {
            return (
                node.likelihood_aro === null &&
                node.likelihood_level_id === null
            );
        }
        if (node.type === 'defence') {
            const edge = occurrence.edge;
            return (
                edge !== undefined &&
                edge.strength_mode === null &&
                edge.strength_fraction === null &&
                edge.strength_level_id === null
            );
        }
        return false;
    }

    function matchesOccurrence(occurrence: Occurrence): boolean {
        const node = store.nodes[occurrence.uuid];
        if (!node) return false;

        if (state.types.length > 0) {
            const type = cardTypeOf(occurrence.uuid);
            if (type === null || !state.types.includes(type)) return false;
        }

        if (state.statuses.length > 0) {
            if (
                node.type !== 'defence' ||
                !node.status ||
                !state.statuses.includes(node.status)
            ) {
                return false;
            }
        }

        if (state.tags.length > 0) {
            const nodeTags = tagsForNode(node.uuid);
            if (!state.tags.some((t) => nodeTags.includes(t))) return false;
        }

        if (state.missingOnly && !hasMissingValue(occurrence)) return false;

        return true;
    }

    const dimmedPathKeys = computed<Set<string>>(() => {
        const set = new Set<string>();
        if (!isActive.value) return set;
        for (const occurrence of enumerateOccurrences(store)) {
            if (!matchesOccurrence(occurrence)) set.add(occurrence.pathKey);
        }
        return set;
    });

    function isDimmed(pathKey: string): boolean {
        return dimmedPathKeys.value.has(pathKey);
    }

    function toggleType(type: FilterNodeType) {
        toggleInArray(state.types, type);
    }

    function toggleStatus(status: DefenceStatus) {
        toggleInArray(state.statuses, status);
    }

    function toggleTag(tag: string) {
        toggleInArray(state.tags, tag);
    }

    function setMissingOnly(value: boolean) {
        state.missingOnly = value;
    }

    function clear() {
        state.tags = [];
        state.statuses = [];
        state.types = [];
        state.missingOnly = false;
    }

    return {
        state,
        isActive,
        dimmedPathKeys,
        isDimmed,
        matchesOccurrence,
        hasMissingValue,
        toggleType,
        toggleStatus,
        toggleTag,
        setMissingOnly,
        clear,
    };
}

export type UseFiltersReturn = ReturnType<typeof useFilters>;
