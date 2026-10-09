<script setup lang="ts">
/**
 * One comment: author, timestamp, body, resolved state, and its actions.
 * Renders both a top-level comment and a reply — `isReply` only hides the
 * "Reply" button, since schema.md §3.8 threading is exactly two levels and
 * a reply itself can never be replied to (CommentService::create()
 * enforces this server-side too; this is only the UI half).
 *
 * The body is rendered through plain `{{ }}` text interpolation —
 * never `v-html` — so it is always HTML-escaped by Vue itself
 * (CLAUDE.md's W18 non-negotiable: "Escape comment bodies... Never use
 * v-html on a comment body"). `white-space: pre-wrap` preserves the
 * author's line breaks without needing to render any markup. See the W18
 * report for why this does not attempt full markdown rendering: no
 * sanitized-markdown-to-HTML dependency exists anywhere in this codebase
 * yet, and adding one means editing composer.json, which is outside this
 * package's file ownership.
 */
import Avatar from '@/Components/UI/Avatar.vue';
import Badge from '@/Components/UI/Badge.vue';
import IconButton from '@/Components/UI/IconButton.vue';
import { computed, ref } from 'vue';
import type { Comment } from './types';

const props = withDefaults(
    defineProps<{
        comment: Comment;
        isReply?: boolean;
        canComment: boolean;
    }>(),
    { isReply: false },
);

const emit = defineEmits<{
    reply: [body: string];
    edit: [body: string];
    delete: [];
    resolve: [];
    unresolve: [];
}>();

const editing = ref(false);
const draftBody = ref(props.comment.body);
const replying = ref(false);
const replyBody = ref('');

const authorName = computed(() => props.comment.author?.name ?? 'Deleted user');
const isResolved = computed(() => props.comment.resolved_at !== null);

function formatDate(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : '';
}

function startEdit() {
    draftBody.value = props.comment.body;
    editing.value = true;
}

function submitEdit() {
    const body = draftBody.value.trim();
    if (body === '') return;
    emit('edit', body);
    editing.value = false;
}

function submitReply() {
    const body = replyBody.value.trim();
    if (body === '') return;
    emit('reply', body);
    replyBody.value = '';
    replying.value = false;
}
</script>

<template>
    <div class="flex gap-2" :class="{ 'ml-8': isReply }">
        <Avatar :name="authorName" size="sm" />
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-text-primary text-sm font-medium">{{
                    authorName
                }}</span>
                <span class="text-text-muted text-xs">{{
                    formatDate(comment.created_at)
                }}</span>
                <Badge v-if="isResolved" tone="neutral">Resolved</Badge>
            </div>

            <p
                v-if="!editing"
                class="text-text-secondary mt-1 text-sm whitespace-pre-wrap"
            >
                {{ comment.body }}
            </p>
            <div v-else class="mt-1 flex flex-col gap-2">
                <textarea
                    v-model="draftBody"
                    rows="3"
                    class="border-border bg-surface-1 text-text-primary w-full rounded-md border px-2 py-1 text-sm"
                />
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="text-accent text-xs font-medium"
                        @click="submitEdit"
                    >
                        Save
                    </button>
                    <button
                        type="button"
                        class="text-text-muted text-xs"
                        @click="editing = false"
                    >
                        Cancel
                    </button>
                </div>
            </div>

            <div class="mt-1 flex items-center gap-1">
                <button
                    v-if="!isReply && canComment"
                    type="button"
                    class="text-text-muted hover:text-text-primary text-xs"
                    @click="replying = !replying"
                >
                    Reply
                </button>
                <button
                    v-if="!isReply && canComment && !isResolved"
                    type="button"
                    class="text-text-muted hover:text-text-primary text-xs"
                    @click="emit('resolve')"
                >
                    Resolve
                </button>
                <button
                    v-if="!isReply && canComment && isResolved"
                    type="button"
                    class="text-text-muted hover:text-text-primary text-xs"
                    @click="emit('unresolve')"
                >
                    Unresolve
                </button>
                <IconButton
                    v-if="comment.can_edit && !editing"
                    icon="pencil"
                    label="Edit comment"
                    size="sm"
                    @click="startEdit"
                />
                <IconButton
                    v-if="comment.can_delete"
                    icon="trash-2"
                    label="Delete comment"
                    size="sm"
                    variant="danger"
                    @click="emit('delete')"
                />
            </div>

            <div v-if="replying" class="mt-2 flex flex-col gap-2">
                <textarea
                    v-model="replyBody"
                    rows="2"
                    placeholder="Reply…"
                    class="border-border bg-surface-1 text-text-primary w-full rounded-md border px-2 py-1 text-sm"
                />
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="text-accent text-xs font-medium"
                        @click="submitReply"
                    >
                        Post reply
                    </button>
                    <button
                        type="button"
                        class="text-text-muted text-xs"
                        @click="replying = false"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
