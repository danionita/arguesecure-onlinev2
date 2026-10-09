<script setup lang="ts">
/**
 * The notification feed (W47, item 15).
 *
 * Three sources, one list: you were mentioned in a comment, a node was
 * assigned to you, and (W54) an executive summary you asked for has finished
 * drafting. Every row is a link to the thing it is about — the first two to
 * the node, through the modal's existing `?node=` deep link, the third to the
 * report, which is where the draft is read and accepted. That is the whole
 * reason a notification is worth having rather than a badge that means
 * "something happened somewhere".
 *
 * The summary row is the only one that can carry bad news. Job C runs for
 * minutes on a queue, so a failure that notified nothing would leave someone
 * waiting on a draft that died twenty minutes ago.
 *
 * ## Marked read on open, not per row
 *
 * The badge counts unread, and opening the list is the act of having seen it.
 * The rows stay visible after — only the badge clears — so a per-row click
 * would be a chore that buys nothing. Unread rows are still marked while they
 * are on screen, because the panel is often opened just to check.
 *
 * ## Switched on `kind`, never on the notification class
 *
 * The payload carries `kind: 'mention' | 'assignment' | 'summary'`. The PHP class name is
 * an implementation detail and putting `App\Domain\Notifications\…` in a
 * template would break this file the day that class moves.
 */
import Icon from '@/Components/UI/Icon.vue';
import type { IconName } from '@/Components/UI/icons.generated';
import Modal from '@/Components/UI/Modal.vue';
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

interface NotificationRow {
    id: string;
    kind: 'mention' | 'assignment' | 'summary';
    read: boolean;
    created_at: string | null;
    assessment_uuid: string | null;
    assessment_title: string;
    node_uuid: string | null;
    node_title: string;
    /** Mentions only. */
    author?: string | null;
    excerpt?: string;
    /** Assignments only. */
    node_type?: string;
    assigned_by?: string | null;
    /** Summary drafts only (W54). Failure is notified as well as success. */
    succeeded?: boolean;
    reason?: string | null;
}

const props = defineProps<{ show: boolean }>();

const emit = defineEmits<{ close: []; read: [] }>();

const notifications = ref<NotificationRow[]>([]);
const loading = ref(false);
const failed = ref(false);

async function load() {
    loading.value = true;
    failed.value = false;
    try {
        const response = await window.axios.get(route('notifications.index'));
        notifications.value = response.data.notifications;

        if (response.data.unread > 0) {
            await window.axios.patch(route('notifications.update'));
            // Tells the layout to drop the badge without a page reload. The
            // count is a shared Inertia prop, so the alternative is a full
            // visit just to change one number.
            emit('read');
        }
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

/**
 * The node this notification is about. Both kinds carry the same two ids, so
 * one function serves both — and a row whose node has since been deleted
 * carries a null uuid and is rendered without a link rather than with a
 * broken one.
 */
function href(row: NotificationRow): string | null {
    if (row.assessment_uuid === null) return null;

    // W54. A summary draft is about the whole assessment and carries no node,
    // so it links to the report — the one page where the draft is shown and
    // can be accepted. The other two kinds still deep-link to their node.
    if (row.kind === 'summary') {
        return route('assessments.report.show', row.assessment_uuid);
    }

    if (row.node_uuid === null) return null;

    return `${route('assessments.show', row.assessment_uuid)}?node=${row.node_uuid}`;
}

function iconFor(row: NotificationRow): IconName {
    if (row.kind === 'mention') return 'message-square';

    return row.kind === 'summary' ? 'file-text' : 'user';
}

function open(row: NotificationRow) {
    const target = href(row);
    if (target === null) return;

    emit('close');
    router.visit(target);
}

function when(iso: string | null): string {
    if (iso === null) return '';

    return new Date(iso).toLocaleString();
}

// Loaded on open, never on mount: this component lives in the layout, so
// fetching on mount would pull the feed on every page in the application for a
// list most visits never open. The badge already comes from a shared prop, so
// nothing on screen is waiting for this.
watch(
    () => props.show,
    (show) => {
        if (show) load();
    },
);
</script>

<template>
    <Modal :show="show" title="Notifications" size="md" @close="emit('close')">
        <div class="p-4">
            <p v-if="loading" class="text-text-muted text-sm">Loading…</p>

            <p v-else-if="failed" class="text-text-muted text-sm">
                Notifications could not be loaded.
            </p>

            <p
                v-else-if="notifications.length === 0"
                class="text-text-muted text-sm"
            >
                Nothing yet. When someone mentions you in a comment or assigns
                you a node, it will appear here.
            </p>

            <ul v-else class="space-y-2">
                <li
                    v-for="row in notifications"
                    :key="row.id"
                    class="border-border-subtle bg-surface-1 rounded-md border"
                    :class="!row.read && 'border-l-node-attack-text border-l-2'"
                >
                    <component
                        :is="href(row) === null ? 'div' : 'button'"
                        :type="href(row) === null ? undefined : 'button'"
                        class="focus-visible:outline-focus-ring block w-full px-3 py-2 text-left outline-none focus-visible:outline-2"
                        @click="open(row)"
                    >
                        <p
                            class="text-text-primary flex items-center gap-2 text-sm"
                        >
                            <Icon
                                :name="iconFor(row)"
                                :size="14"
                                class="shrink-0"
                            />
                            <span v-if="row.kind === 'mention'">
                                {{ row.author ?? 'Someone' }} mentioned you on
                                {{ row.node_title }}
                            </span>
                            <span
                                v-else-if="row.kind === 'summary'"
                                data-testid="summary-notification"
                            >
                                <template v-if="row.succeeded">
                                    Your executive summary draft is ready to
                                    read
                                </template>
                                <template v-else>
                                    The executive summary draft failed
                                </template>
                            </span>
                            <span v-else>
                                {{ row.assigned_by ?? 'Someone' }} assigned you
                                {{ row.node_title }}
                            </span>
                        </p>
                        <p
                            v-if="row.kind === 'mention' && row.excerpt"
                            class="text-text-secondary mt-0.5 text-xs"
                        >
                            {{ row.excerpt }}
                        </p>
                        <!--
                            A failed draft says what went wrong, because the
                            next action differs: "try again" and "ask an
                            administrator to check the assistant" are not the
                            same advice.
                        -->
                        <p
                            v-else-if="row.kind === 'summary' && row.reason"
                            class="text-text-secondary mt-0.5 text-xs"
                        >
                            {{ row.reason }}
                        </p>
                        <p class="text-text-muted mt-0.5 text-xs">
                            {{ row.assessment_title }} ·
                            {{ when(row.created_at) }}
                        </p>
                    </component>
                </li>
            </ul>
        </div>
    </Modal>
</template>
