<script setup lang="ts">
/**
 * Comments tab. Thin wiring around the self-contained CommentThread.vue —
 * see that component's own docblock for why it takes only identifiers and an
 * ability flag rather than the store itself, matching
 * DetailsTab.vue/ValuesTab.vue's existing `:store="store"
 * :uuid="node.uuid"` prop pattern.
 */
import CommentThread from '@/Components/Comments/CommentThread.vue';
import type { AssessmentStore } from '@/stores/assessmentStore';

const props = defineProps<{
    store: AssessmentStore;
    uuid: string;
}>();
</script>

<template>
    <CommentThread
        :assessment-uuid="props.store.assessment.uuid"
        :node-uuid="props.uuid"
        :can-comment="props.store.abilities.comment"
    />
</template>
