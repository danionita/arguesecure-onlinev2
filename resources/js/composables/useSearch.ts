/**
 * Full-text search over the tree (ui.md §5 / PLAN.md W15).
 *
 * ## Client-side vs. server round-trip
 *
 * schema.md backs search with `FULLTEXT(title, description)` for exactly
 * this feature, but W12's page load already ships the *entire* assessment
 * graph to the browser in one Inertia visit (CLAUDE.md: "Eager-load
 * everything... a whole assessment must load in four queries") — there is
 * no partial/paginated tree. Searching the copy already sitting in
 * `store.nodes` is therefore strictly faster than a round trip: no network
 * latency and no server load. (Until W30 it also matched `notes`, which had
 * no FULLTEXT index at all; that field is gone, so the client-side scan and
 * the server-side index now cover exactly the same two columns.)
 *
 * This stops being true past some graph size — MySQL FULLTEXT ranking
 * beats a linear substring scan once an assessment has, say, tens of
 * thousands of nodes, and at that size shipping the whole graph to the
 * client in one page load would itself already be the bottleneck. For the
 * shape this tool targets (a workshop-scale risk assessment: dozens to a
 * few hundred nodes, per PLAN.md's worked example), that threshold is far
 * above anything realistic, so client-side is the deliberate choice here.
 * If assessments grow large enough to challenge that, the fix is a
 * server-backed search endpoint using the FULLTEXT index, not a rewrite of
 * this composable's shape — it would still resolve to a set of matching
 * uuids feeding the same `matches`/`next`/`previous` surface.
 *
 * Search matches every *occurrence* of a matching node, not just the node
 * once: ui.md §5 says "the canvas scrolls to each match", and a shared
 * node rendered under two risks is two places to scroll to, not one.
 */
import { computed, ref, watch } from 'vue';
import type { AssessmentStore } from '../stores/assessmentStore';
import { enumerateOccurrences, type Occurrence } from './occurrences';

export function useSearch(store: AssessmentStore) {
    const query = ref('');
    const activeIndex = ref(0);
    /**
     * Bound with `ref="inputEl"` on the search `<input>` in SearchBox.vue.
     * Exposed as `focus()` below — this is exactly what W14 calls to
     * implement `/` from ui.md §4's keyboard map; W15 does not register
     * the key binding itself (that file is W14's, running concurrently).
     */
    const inputEl = ref<HTMLInputElement | null>(null);

    function focus() {
        inputEl.value?.focus();
        inputEl.value?.select();
    }

    /**
     * Mutator methods rather than exposing the raw refs for direct template
     * assignment: this composable's return value is passed to SearchBox.vue
     * as a prop (so Toolbar.vue and SearchBox.vue share one instance),
     * and Vue's own lint rule (`vue/no-mutating-props`) correctly refuses
     * `search.query.value = x` in a child's template — props are read-only
     * by convention even when, structurally, "the prop" is a controller
     * object holding refs meant to be driven by that child.
     */
    function setQuery(value: string) {
        query.value = value;
    }

    /** Bind with `:ref="(el) => search.bindInputEl(el)"` on the real `<input>`. */
    function bindInputEl(el: Element | null) {
        inputEl.value = (el as HTMLInputElement | null) ?? null;
    }

    const normalizedQuery = computed(() => query.value.trim().toLowerCase());

    /** Node uuids whose title or description contain the query. */
    const matchingUuids = computed<Set<string>>(() => {
        const q = normalizedQuery.value;
        const set = new Set<string>();
        if (!q) return set;
        for (const node of Object.values(store.nodes)) {
            const haystack =
                `${node.title} ${node.description ?? ''}`.toLowerCase();
            if (haystack.includes(q)) set.add(node.uuid);
        }
        return set;
    });

    /** Every rendered occurrence of a matching node, root-first depth-first — the order `next`/`previous` step through. */
    const matches = computed<Occurrence[]>(() => {
        if (matchingUuids.value.size === 0) return [];
        return enumerateOccurrences(store).filter((o) =>
            matchingUuids.value.has(o.uuid),
        );
    });

    // Synchronous flush: activeIndex must already be reset by the time a
    // caller reads it right after setting `query`, not on the next tick —
    // tests and W14's key handling both do exactly that.
    watch(
        matches,
        () => {
            activeIndex.value = 0;
        },
        { flush: 'sync' },
    );

    const activeMatch = computed<Occurrence | null>(
        () => matches.value[activeIndex.value] ?? null,
    );

    function next() {
        if (matches.value.length === 0) return;
        activeIndex.value = (activeIndex.value + 1) % matches.value.length;
    }

    function previous() {
        if (matches.value.length === 0) return;
        activeIndex.value =
            (activeIndex.value - 1 + matches.value.length) %
            matches.value.length;
    }

    function clear() {
        query.value = '';
        activeIndex.value = 0;
    }

    return {
        query,
        inputEl,
        focus,
        setQuery,
        bindInputEl,
        matchingUuids,
        matches,
        activeIndex,
        activeMatch,
        next,
        previous,
        clear,
    };
}

export type UseSearchReturn = ReturnType<typeof useSearch>;
