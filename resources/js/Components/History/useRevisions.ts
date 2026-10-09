/**
 * Per-node history (PLAN.md Phase 8 item 2, schema.md §3.9). Talks to the
 * W18 revision routes directly — see routes/web.php's "W18 — comments +
 * history" block for why those are closures rather than a dedicated
 * controller (this package's file-ownership list grants no
 * app/Http/Controllers/HistoryController.php path, and the exact same
 * gap-fill pattern W14's own reorder/move routes already established).
 *
 * IMPORTANT, read before wiring this in: because nothing in this codebase
 * currently writes to `node_revisions` on an ordinary field edit (see the
 * W18 report — App\Domain\History\RevisionRecorder does not exist and
 * NodeController::update() records nothing), `load()` will typically
 * return an empty list today. The revert path itself is fully functional
 * and end-to-end tested (tests/Feature/History/NodeRevisionRevertTest.php)
 * against directly-seeded rows, and correctly writes its own new revision
 * when used — there is simply nothing to revert *to* yet for a title/value
 * edit made through the tree editor, until a revision writer is wired into
 * that path by whichever package ends up owning it.
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
     * (the assessment store, outside this package's ownership — see the
     * W18 report for the exact call HistoryTab.vue needs to make) and
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
