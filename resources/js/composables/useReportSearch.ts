/**
 * Full-text search over the report (W83, ui.md §9 "Search, options, zoom").
 *
 * `useSearch.ts` (ui.md §5) does this job for the editor, but it is typed
 * `useSearch(store: AssessmentStore)` and walks `store.nodes` — the editor's
 * live, mutable graph. The report has no store: it is a server-computed
 * document (`ReportData`, assembled once by `ReportBuilder` and handed down
 * as an Inertia prop), and CLAUDE.md is explicit that nothing here may
 * re-derive an engine figure client-side. Constructing a full `AssessmentStore`
 * — abilities, mutation methods, calculation state — purely to get a search
 * index would be exactly that: fabricating a second, client-side notion of
 * the assessment to search over. This composable searches the *content*
 * already sitting in `report.tree` (titles, descriptions, rationale — never a
 * computed figure) and produces the same shape `SearchBox.vue` already knows
 * how to render (`Components/Toolbar/SearchBox.vue`, reused unmodified),
 * which is the "reuse the component, not fork it" half of the brief.
 *
 * `report.tree` is deliberately the search surface, not the whole document:
 * ui.md §9 calls the full-assessment section "the list view on paper" — the
 * one place every risk, attack and defence appears with its title and
 * description, indented exactly as `AssessmentTreeSection.vue` renders it.
 * Searching that mirrors what the editor's list-view search already does,
 * on the report's equivalent surface.
 *
 * Occurrences, not nodes: `AssessmentTreeSection.vue`'s own docblock says a
 * shared attack or defence "is deliberately printed once per parent edge it
 * actually has, not deduplicated" — so walking `tree.risks[].attacks[]` the
 * same way that component's template does yields exactly the occurrences
 * that render, which is what `enumerateOccurrences` does for the editor's
 * DAG. `pathKey` here is built the same way the section's own `:key`s are
 * (`risk.uuid`, `${risk.uuid}/${attack.uuid}`, `${attack.uuid}/${defence.uuid}`)
 * so a caller can compare `activeMatch.value?.pathKey` against a rendered
 * row's key exactly the way `NodeCard.vue` compares against `props.pathKey`
 * for its search-match ring.
 */
import { computed, ref, watch } from 'vue';
import type { ReportData } from '../Components/Report/types';
import type { Occurrence } from './occurrences';

export function useReportSearch(getReport: () => ReportData) {
    const query = ref('');
    const activeIndex = ref(0);

    /** Bound with `:ref="(el) => search.bindInputEl(el)"` on SearchBox.vue's `<input>`. */
    const inputEl = ref<HTMLInputElement | null>(null);

    function focus() {
        inputEl.value?.focus();
        inputEl.value?.select();
    }

    function setQuery(value: string) {
        query.value = value;
    }

    function bindInputEl(el: Element | null) {
        inputEl.value = (el as HTMLInputElement | null) ?? null;
    }

    const normalizedQuery = computed(() => query.value.trim().toLowerCase());

    interface Row {
        uuid: string;
        text: string;
    }

    /** Every rendered row of `tree.risks[].attacks[].defences[]`, title + description (+ rationale for a defence) — never a figure. */
    function rows(): Row[] {
        const out: Row[] = [];
        for (const risk of getReport().tree.risks) {
            out.push({
                uuid: risk.uuid,
                text: `${risk.title} ${risk.description ?? ''}`,
            });
            for (const attack of risk.attacks) {
                out.push({
                    uuid: attack.uuid,
                    text: `${attack.title} ${attack.description ?? ''}`,
                });
                for (const defence of attack.defences) {
                    out.push({
                        uuid: defence.uuid,
                        text: `${defence.title} ${defence.description ?? ''} ${defence.rationale ?? ''}`,
                    });
                }
            }
        }
        return out;
    }

    const matchingUuids = computed<Set<string>>(() => {
        const q = normalizedQuery.value;
        const set = new Set<string>();
        if (!q) return set;
        for (const row of rows()) {
            if (row.text.toLowerCase().includes(q)) set.add(row.uuid);
        }
        return set;
    });

    /** One Occurrence per matching rendered row — root-first depth-first, the order `next`/`previous` step through. */
    const matches = computed<Occurrence[]>(() => {
        if (matchingUuids.value.size === 0) return [];
        const out: Occurrence[] = [];
        for (const risk of getReport().tree.risks) {
            if (matchingUuids.value.has(risk.uuid)) {
                out.push({
                    uuid: risk.uuid,
                    path: [risk.uuid],
                    pathKey: risk.uuid,
                    parentPathKey: null,
                    depth: 1,
                });
            }
            for (const attack of risk.attacks) {
                const attackKey = `${risk.uuid}/${attack.uuid}`;
                if (matchingUuids.value.has(attack.uuid)) {
                    out.push({
                        uuid: attack.uuid,
                        path: [risk.uuid, attack.uuid],
                        pathKey: attackKey,
                        parentPathKey: risk.uuid,
                        depth: 2,
                    });
                }
                for (const defence of attack.defences) {
                    const defenceKey = `${attack.uuid}/${defence.uuid}`;
                    if (matchingUuids.value.has(defence.uuid)) {
                        out.push({
                            uuid: defence.uuid,
                            path: [risk.uuid, attack.uuid, defence.uuid],
                            pathKey: defenceKey,
                            parentPathKey: attackKey,
                            depth: 3,
                        });
                    }
                }
            }
        }
        return out;
    });

    // Synchronous flush: as in useSearch.ts, activeIndex must already be
    // reset by the time a caller reads it right after setting `query`.
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

export type UseReportSearchReturn = ReturnType<typeof useReportSearch>;
