/**
 * Comments (PLAN.md Phase 8 item 1, schema.md §3.8). Talks to
 * CommentController directly, the same self-contained pattern
 * NodeModal/useLinkMutations.ts already established for this package's own
 * W13 sibling: no dependency on resources/js/stores/assessmentStore.ts
 * (outside this package's ownership, and comments are not part of its
 * state — a node's comment *count* is a separate concern, see
 * useUnresolvedCommentCounts() below), just `window.axios` + Ziggy's
 * `route()` and a small local reactive list.
 */
import { reactive, ref } from 'vue';
import type { Comment } from './types';

export function useComments(assessmentUuid: string, nodeUuid: string) {
    const comments = reactive<Comment[]>([]);
    const loading = ref(false);
    const error = ref<string | null>(null);

    async function load(): Promise<void> {
        loading.value = true;
        error.value = null;
        try {
            const response = await window.axios.get<{ comments: Comment[] }>(
                route('assessments.comments.index', [assessmentUuid, nodeUuid]),
            );
            comments.splice(0, comments.length, ...response.data.comments);
        } catch {
            error.value = 'Could not load comments.';
        } finally {
            loading.value = false;
        }
    }

    async function post(
        body: string,
        parentCommentId: number | null = null,
    ): Promise<void> {
        const response = await window.axios.post<{ comment: Comment }>(
            route('assessments.comments.store', [assessmentUuid, nodeUuid]),
            { body, parent_comment_id: parentCommentId },
        );

        if (parentCommentId === null) {
            comments.push(response.data.comment);
            return;
        }

        const parent = comments.find((c) => c.id === parentCommentId);
        parent?.replies.push(response.data.comment);
    }

    async function update(commentId: number, body: string): Promise<void> {
        const response = await window.axios.patch<{ comment: Comment }>(
            route('assessments.comments.update', [assessmentUuid, commentId]),
            { body },
        );
        applyToLocalTree(commentId, (c) =>
            Object.assign(c, response.data.comment),
        );
    }

    async function destroy(commentId: number): Promise<void> {
        await window.axios.delete(
            route('assessments.comments.destroy', [assessmentUuid, commentId]),
        );

        const topIndex = comments.findIndex((c) => c.id === commentId);
        if (topIndex !== -1) {
            comments.splice(topIndex, 1);
            return;
        }
        for (const top of comments) {
            const replyIndex = top.replies.findIndex((r) => r.id === commentId);
            if (replyIndex !== -1) {
                top.replies.splice(replyIndex, 1);
                return;
            }
        }
    }

    async function resolve(commentId: number): Promise<void> {
        const response = await window.axios.post<{ comment: Comment }>(
            route('assessments.comments.resolve', [assessmentUuid, commentId]),
        );
        applyToLocalTree(commentId, (c) =>
            Object.assign(c, response.data.comment),
        );
    }

    async function unresolve(commentId: number): Promise<void> {
        const response = await window.axios.delete<{ comment: Comment }>(
            route('assessments.comments.resolve', [assessmentUuid, commentId]),
        );
        applyToLocalTree(commentId, (c) =>
            Object.assign(c, response.data.comment),
        );
    }

    function applyToLocalTree(
        commentId: number,
        mutate: (c: Comment) => void,
    ): void {
        const top = comments.find((c) => c.id === commentId);
        if (top) {
            mutate(top);
            return;
        }
        for (const t of comments) {
            const reply = t.replies.find((r) => r.id === commentId);
            if (reply) {
                mutate(reply);
                return;
            }
        }
    }

    return {
        comments,
        loading,
        error,
        load,
        post,
        update,
        destroy,
        resolve,
        unresolve,
    };
}

/**
 * The node card's "has comments (with unresolved count)" indicator (ui.md
 * §3.2) needs a count per node across the whole assessment, not one node
 * at a time — see CommentController::unresolvedCounts(). Exported
 * separately from useComments() because the node card lives in
 * resources/js/Components/Tree/** (W12, outside this package's
 * ownership); this is the piece that package's NodeCard.vue would call.
 * See the W18 report for the exact call this composable expects to be
 * wired into.
 */
export async function fetchUnresolvedCommentCounts(
    assessmentUuid: string,
): Promise<Record<string, number>> {
    const response = await window.axios.get<{ counts: Record<string, number> }>(
        route('assessments.comments.unresolved-counts', assessmentUuid),
    );
    return response.data.counts;
}
