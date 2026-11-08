/**
 * Per-node history (schema.md §3.9). Talks to the comments and history
 * routes in routes/web.php directly, which are closures rather than a
 * dedicated controller. Revision rows are written by
 * App\Domain\History\RevisionRecorder and reverted through
 * App\Domain\History\RevisionReverter.
 */
import { reactive, ref } from 'vue';
import type { NodeRevision } from './types';

export function useRevisions(assessmentUuid: string, nodeUuid: string) {
    const revisions = reactive<NodeRevision[]>([]);
    const loading = ref(false);
    const error = ref<string | null>(null);

    async function load(): Promise<void> {
        loading.value = true;
        error.value = null;
        try {
            const response = await window.axios.get<{
                revisions: NodeRevision[];
            }>(
                route('assessments.nodes.revisions.index', [
                    assessmentUuid,
                    nodeUuid,
                ]),
            );
            revisions.splice(0, revisions.length, ...response.data.revisions);
        } catch {
            error.value = 'Could not load history.';
        } finally {
            loading.value = false;
        }
    }

    /**
     * Reverts one field from one revision. Returns the field's new value
     * so the caller can patch it into whatever holds the node's live state
     * (the assessment store; HistoryTab.vue makes that call) and
     * prepends the new revision this write created, matching the server's
     * own "recorded as a normal edit" contract (schema.md §3.10) — never
     * touching the undo stack.
     */
    async function revert(
        revisionId: number,
        field: string,
    ): Promise<{ field: string; value: unknown; updatedAt: unknown }> {
        const response = await window.axios.post<{
            node: Record<string, unknown> & { uuid: string };
            revision: NodeRevision;
        }>(
            route('assessments.nodes.revisions.revert', [
                assessmentUuid,
                nodeUuid,
                revisionId,
            ]),
            { field },
        );
        revisions.unshift(response.data.revision);

        // `updated_at` travels with the value: the revert moved the row, and
        // the author no longer receives their own NodeUpdated echo, so without
        // it the next edit to this node would 409 against the revert.
        return {
            field,
            value: response.data.node[field],
            updatedAt: response.data.node.updated_at,
        };
    }

    return { revisions, loading, error, load, revert };
}
