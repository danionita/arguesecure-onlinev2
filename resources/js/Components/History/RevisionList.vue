<script setup lang="ts">
/**
 * History tab content (PLAN.md Phase 8 item 2). Self-contained, matching
 * Comments/CommentThread.vue's own "no store dependency" pattern: takes
 * only the identifiers and ability flag it needs, so wiring this in from
 * HistoryTab.vue (W13) is a single
 * `<RevisionList :assessment-uuid="..." :node-uuid="..." :can-revert="..." @reverted="..." />`
 * — see the W18 report for what the `reverted` handler needs to do (patch
 * the reverted field into the assessment store's local node state; this
 * component does not touch that store itself).
 *
 * Revisions are grouped by `operation_id` (ui.md §7: "who changed which
 * field, from what to what, grouped by operation") — a null group (every
 * ordinary field edit, since only structural changes get an
 * operation_id — schema.md §3.10) renders as its own ungrouped section
 * rather than one large "no operation" bucket, so the natural chronological
 * read (server already orders desc by id) is preserved.
 */
import EmptyState from '@/Components/UI/EmptyState.vue';
import Skeleton from '@/Components/UI/Skeleton.vue';
import { useToast } from '@/Components/UI/useToast';
import { computed, onMounted, watch } from 'vue';
import RevisionEntry from './RevisionEntry.vue';
import type { NodeRevision } from './types';
import { useRevisions } from './useRevisions';

const props = defineProps<{
    assessmentUuid: string;
    nodeUuid: string;
    canRevert: boolean;
}>();

const emit = defineEmits<{
    reverted: [field: string, value: unknown, updatedAt?: unknown];
}>();

const { revisions, loading, error, load, revert } = useRevisions(
    props.assessmentUuid,
    props.nodeUuid,
);
const toast = useToast();

onMounted(load);
watch(() => props.nodeUuid, load);

/**
 * Groups consecutive-in-list revisions by operation_id, keeping every
 * `operation_id === null` row as its own single-entry group rather than
 * merging them — a structural create/delete/move/link groups several
 * field revisions under one operation_id (schema.md §3.10's "groups
 * revisions written by one user action"); an ordinary field edit or a
 * revert never has one at all.
 */
const groups = computed<
    Array<{ key: string; operationId: number | null; items: NodeRevision[] }>
>(() => {
    const result: Array<{
        key: string;
        operationId: number | null;
        items: NodeRevision[];
    }> = [];
    for (const revision of revisions) {
        const last = result[result.length - 1];
        if (
            revision.operation_id !== null &&
            last?.operationId === revision.operation_id
        ) {
            last.items.push(revision);
        } else {
            result.push({
                key: `${revision.operation_id ?? 'none'}-${revision.id}`,
                operationId: revision.operation_id,
                items: [revision],
            });
        }
    }
    return result;
});

async function handleRevert(revisionId: number, field: string) {
    try {
        const {
            field: revertedField,
            value,
            updatedAt,
        } = await revert(revisionId, field);
        emit('reverted', revertedField, value, updatedAt);
        toast.push(`Reverted ${field}.`, 'success');
    } catch {
        toast.push(`Could not revert ${field}.`, 'danger');
    }
}
</script>

<template>
    <div class="flex flex-col gap-3 p-4">
        <div v-if="loading" class="flex flex-col gap-2">
            <Skeleton height="2.5rem" />
            <Skeleton height="2.5rem" />
        </div>

        <p v-else-if="error" class="text-node-attack-text text-sm">
            {{ error }}
        </p>

        <EmptyState
            v-else-if="revisions.length === 0"
            icon="history"
            title="No history yet"
            description="Changes to this node's fields will appear here."
        />

        <div v-else class="flex flex-col gap-3">
            <div
                v-for="group in groups"
                :key="group.key"
                class="flex flex-col gap-1"
            >
                <div
                    v-if="group.operationId !== null && group.items.length > 1"
                    class="text-text-muted text-xs font-medium uppercase"
                >
                    One change
                </div>
                <RevisionEntry
                    v-for="revision in group.items"
                    :key="revision.id"
                    :revision="revision"
                    :can-revert="canRevert"
                    @revert="(field) => handleRevert(revision.id, field)"
                />
            </div>
        </div>
    </div>
</template>
