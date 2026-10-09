<script setup lang="ts">
/**
 * Comments tab content (PLAN.md Phase 8 item 1, ui.md §7's "Comments —
 * Threaded, resolvable (W18)" row). Self-contained: takes only the
 * identifiers and ability flag it needs, matching NodeModal/
 * useLinkMutations.ts's own "no store dependency" pattern, so wiring this
 * in from CommentsTab.vue (W13, not owned by this package) is a single
 * `<CommentThread :assessment-uuid="..." :node-uuid="..." :can-comment="..." />`
 * — see the W18 report for the exact prop values that shell needs to pass.
 */
import EmptyState from '@/Components/UI/EmptyState.vue';
import Skeleton from '@/Components/UI/Skeleton.vue';
import { useToast } from '@/Components/UI/useToast';
import { onMounted, ref, watch } from 'vue';
import CommentItem from './CommentItem.vue';
import { useComments } from './useComments';

const props = defineProps<{
    assessmentUuid: string;
    nodeUuid: string;
    canComment: boolean;
}>();

const {
    comments,
    loading,
    error,
    load,
    post,
    update,
    destroy,
    resolve,
    unresolve,
} = useComments(props.assessmentUuid, props.nodeUuid);
const toast = useToast();

const newBody = ref('');
const posting = ref(false);

onMounted(load);
watch(() => props.nodeUuid, load);

async function submitNew() {
    const body = newBody.value.trim();
    if (body === '') return;
    posting.value = true;
    try {
        await post(body);
        newBody.value = '';
    } catch {
        toast.push('Could not post the comment.', 'danger');
    } finally {
        posting.value = false;
    }
}

async function handleReply(parentId: number, body: string) {
    try {
        await post(body, parentId);
    } catch {
        toast.push('Could not post the reply.', 'danger');
    }
}

async function handleEdit(commentId: number, body: string) {
    try {
        await update(commentId, body);
    } catch {
        toast.push('Could not save the edit.', 'danger');
    }
}

async function handleDelete(commentId: number) {
    try {
        await destroy(commentId);
    } catch {
        toast.push('Could not delete the comment.', 'danger');
    }
}

async function handleResolve(commentId: number) {
    try {
        await resolve(commentId);
    } catch {
        toast.push('Could not resolve the thread.', 'danger');
    }
}

async function handleUnresolve(commentId: number) {
    try {
        await unresolve(commentId);
    } catch {
        toast.push('Could not unresolve the thread.', 'danger');
    }
}
</script>

<template>
    <div class="flex flex-col gap-4 p-4">
        <div v-if="loading" class="flex flex-col gap-2">
            <Skeleton height="2.5rem" />
            <Skeleton height="2.5rem" />
        </div>

        <p v-else-if="error" class="text-node-attack-text text-sm">
            {{ error }}
        </p>

        <EmptyState
            v-else-if="comments.length === 0"
            icon="message-square"
            title="No comments yet"
            description="Start a discussion about this node."
        />

        <div v-else class="flex flex-col gap-4">
            <div
                v-for="comment in comments"
                :key="comment.id"
                class="border-border border-b pb-4 last:border-b-0"
            >
                <CommentItem
                    :comment="comment"
                    :can-comment="canComment"
                    @reply="(body) => handleReply(comment.id, body)"
                    @edit="(body) => handleEdit(comment.id, body)"
                    @delete="() => handleDelete(comment.id)"
                    @resolve="() => handleResolve(comment.id)"
                    @unresolve="() => handleUnresolve(comment.id)"
                />
                <div
                    v-if="comment.replies.length"
                    class="mt-3 flex flex-col gap-3"
                >
                    <CommentItem
                        v-for="reply in comment.replies"
                        :key="reply.id"
                        :comment="reply"
                        :is-reply="true"
                        :can-comment="canComment"
                        @edit="(body) => handleEdit(reply.id, body)"
                        @delete="() => handleDelete(reply.id)"
                    />
                </div>
            </div>
        </div>

        <form
            v-if="canComment"
            class="border-border flex flex-col gap-2 border-t pt-4"
            @submit.prevent="submitNew"
        >
            <textarea
                v-model="newBody"
                rows="2"
                placeholder="Add a comment…"
                class="border-border bg-surface-1 text-text-primary w-full rounded-md border px-2 py-1 text-sm"
            />
            <button
                type="submit"
                :disabled="posting || newBody.trim() === ''"
                class="bg-accent self-start rounded-md px-3 py-1.5 text-xs font-medium text-white disabled:opacity-50"
            >
                Post comment
            </button>
        </form>
    </div>
</template>
