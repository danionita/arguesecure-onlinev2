/**
 * References (schema.md §3.12, ui.md §7). Talks to ReferenceController
 * directly, the same self-contained pattern
 * Components/Comments/useComments.ts and NodeModal/useLinkMutations.ts
 * already established: no dependency on resources/js/stores/
 * assessmentStore.ts, just `window.axios` + Ziggy's `route()` and a small
 * local reactive list — a node's references are not part of the store's
 * own state, matching how comments are not either.
 */
import { reactive, ref } from 'vue';

export interface NodeReference {
    id: number;
    kind: string;
    identifier: string | null;
    url: string | null;
    title: string | null;
    position: number;
}

export interface NewNodeReference {
    kind: string;
    identifier?: string;
    url?: string;
    title?: string;
}

export function useReferences(assessmentUuid: string, nodeUuid: string) {
    const references = reactive<NodeReference[]>([]);
    const loading = ref(false);
    const error = ref<string | null>(null);

    async function load(): Promise<void> {
        loading.value = true;
        error.value = null;
        try {
            const response = await window.axios.get<{
                references: NodeReference[];
            }>(
                route('assessments.node-references.index', [
                    assessmentUuid,
                    nodeUuid,
                ]),
            );
            references.splice(
                0,
                references.length,
                ...response.data.references,
            );
        } catch {
            error.value = 'Could not load references.';
        } finally {
            loading.value = false;
        }
    }

    async function add(payload: NewNodeReference): Promise<void> {
        const response = await window.axios.post<{ reference: NodeReference }>(
            route('assessments.node-references.store', [
                assessmentUuid,
                nodeUuid,
            ]),
            payload,
        );
        references.push(response.data.reference);
    }

    async function remove(id: number): Promise<void> {
        await window.axios.delete(
            route('assessments.node-references.destroy', [
                assessmentUuid,
                nodeUuid,
                id,
            ]),
        );
        const index = references.findIndex((r) => r.id === id);
        if (index !== -1) references.splice(index, 1);
    }

    async function reorder(orderedIds: number[]): Promise<void> {
        const response = await window.axios.post<{
            references: NodeReference[];
        }>(
            route('assessments.node-references.reorder', [
                assessmentUuid,
                nodeUuid,
            ]),
            { order: orderedIds },
        );
        references.splice(0, references.length, ...response.data.references);
    }

    /** Swaps two adjacent references and persists the new order. */
    async function move(id: number, direction: 'up' | 'down'): Promise<void> {
        const index = references.findIndex((r) => r.id === id);
        const target = direction === 'up' ? index - 1 : index + 1;
        if (index === -1 || target < 0 || target >= references.length) return;

        const order = references.map((r) => r.id);
        [order[index], order[target]] = [order[target], order[index]];
        await reorder(order);
    }

    return { references, loading, error, load, add, remove, reorder, move };
}
