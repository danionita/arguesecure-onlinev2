<script setup lang="ts">
/**
 * History tab (PLAN.md Phase 8 item 2, schema.md §3.9, W18b). Thin wiring
 * around W18's self-contained RevisionList.vue, matching CommentsTab.vue's
 * identical shape.
 *
 * RevisionList emits `reverted(field, value)` after a successful revert
 * (see its own docblock: "this component does not touch that store
 * itself"). Patching the store here — rather than relying on a page
 * reload — keeps the node card and every other open tab in sync
 * immediately, the same as every other store mutation in this package.
 */
import RevisionList from '@/Components/History/RevisionList.vue';
import type { AssessmentStore } from '@/stores/assessmentStore';
import { useNodeModal } from '../useNodeModal';

const props = defineProps<{
    store: AssessmentStore;
    uuid: string;
}>();

const { lockedBy } = useNodeModal();

function handleReverted(
    field: string,
    value: unknown,
    updatedAt?: unknown,
): void {
    const node = props.store.nodes[props.uuid];
    if (!node) return;
    Object.assign(node, { [field]: value });
    if (typeof updatedAt === 'string') node.updated_at = updatedAt;
    props.store.recomputeLocally();
}
</script>

<template>
    <RevisionList
        :assessment-uuid="props.store.assessment.uuid"
        :node-uuid="props.uuid"
        :can-revert="props.store.abilities.update && lockedBy === null"
        @reverted="handleReverted"
    />
</template>
