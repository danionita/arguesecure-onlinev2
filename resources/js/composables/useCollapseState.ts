/**
 * Collapse/expand per occurrence, plus expand-all/collapse-all, persisted
 * per user per assessment (ui.md §5 / PLAN.md W15).
 *
 * The per-occurrence expand/collapse *state itself* already lives in
 * assessmentStore.ts (`isExpanded`/`toggleExpanded`, keyed by occurrence
 * path — see that file's top comment), because it has to: TreeNode.vue
 * reads it on every render to decide whether to recurse into a node's
 * children at all. This composable does not duplicate that state; it only
 * adds persistence around it, via the store's existing public accessors.
 *
 * ## The integration seam this can't close itself
 *
 * The store exposes `isExpanded`/`toggleExpanded` as *functions*, not the
 * underlying reactive map, so there is nothing here to `watch()` — a
 * toggle that happens through NodeCard.vue's chevron button (which calls
 * `store.toggleExpanded(pathKey)` directly from TreeNode.vue's `@toggle`
 * handler) is invisible to this composable. `expandAll()`/`collapseAll()`
 * and `toggle()` below all persist correctly because they *are* the call
 * site, but a lone per-node click on the existing chevron does not persist
 * until TreeNode.vue's one-line `@toggle="store.toggleExpanded(pathKey)"`
 * is changed to call `useCollapseState().toggle(pathKey)` instead.
 * TreeNode.vue is W12-owned and outside this package's file list, so this
 * is reported rather than edited — see the W15 report.
 */
import type { AssessmentStore } from '../stores/assessmentStore';
import { enumerateOccurrences } from './occurrences';

export interface UseCollapseStateOptions {
    assessmentUuid: string;
    /** The current user's id, for per-user persistence — see useCanvas.ts's comment on the same parameter. */
    userId?: string | number | null;
    storage?: Pick<Storage, 'getItem' | 'setItem'>;
}

function resolveStorage(
    injected: UseCollapseStateOptions['storage'],
): Pick<Storage, 'getItem' | 'setItem'> | undefined {
    if (injected) return injected;
    return typeof localStorage !== 'undefined' ? localStorage : undefined;
}

function storageKey(assessmentUuid: string, userId: unknown): string {
    return `arguesecure:collapsed:${userId ?? 'anon'}:${assessmentUuid}`;
}

export function useCollapseState(
    store: AssessmentStore,
    options: UseCollapseStateOptions,
) {
    const storage = resolveStorage(options.storage);
    const key = storageKey(options.assessmentUuid, options.userId);

    function readPersisted(): Set<string> {
        if (!storage) return new Set();
        try {
            const raw = storage.getItem(key);
            if (!raw) return new Set();
            const parsed: unknown = JSON.parse(raw);
            return Array.isArray(parsed) ? new Set(parsed) : new Set();
        } catch {
            return new Set();
        }
    }

    function writePersisted(collapsedPathKeys: Set<string>) {
        storage?.setItem(key, JSON.stringify([...collapsedPathKeys]));
    }

    /** Snapshots every occurrence's current collapse state and persists it. */
    function persist() {
        const collapsed = new Set<string>();
        for (const occurrence of enumerateOccurrences(store)) {
            if (!store.isExpanded(occurrence.pathKey)) {
                collapsed.add(occurrence.pathKey);
            }
        }
        writePersisted(collapsed);
    }

    /** Applies a previously persisted snapshot. Call once on mount, after the store is populated. */
    function restore() {
        const collapsed = readPersisted();
        for (const pathKey of collapsed) {
            if (store.isExpanded(pathKey)) store.toggleExpanded(pathKey);
        }
    }

    /** Toggle one occurrence and persist the result — see this file's top comment for why callers must go through this rather than `store.toggleExpanded` directly for persistence to take effect. */
    function toggle(pathKey: string) {
        store.toggleExpanded(pathKey);
        persist();
    }

    function expandAll() {
        for (const occurrence of enumerateOccurrences(store)) {
            if (!store.isExpanded(occurrence.pathKey)) {
                store.toggleExpanded(occurrence.pathKey);
            }
        }
        persist();
    }

    function collapseAll() {
        for (const occurrence of enumerateOccurrences(store)) {
            if (store.isExpanded(occurrence.pathKey)) {
                store.toggleExpanded(occurrence.pathKey);
            }
        }
        persist();
    }

    return { restore, persist, toggle, expandAll, collapseAll };
}

export type UseCollapseStateReturn = ReturnType<typeof useCollapseState>;
