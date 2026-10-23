<script setup lang="ts">
/**
 * The question behind `useUnsavedChanges`. Mounted once, in the layout.
 */
import {
    answerLeaveRequest,
    pendingLeaveRequest,
} from '@/composables/useUnsavedChanges';
import { computed, onBeforeUnmount } from 'vue';
import ConfirmDialog from './ConfirmDialog.vue';

const request = pendingLeaveRequest();
const show = computed(() => request.value !== null);

// A question left open when the layout goes away would cancel every later
// guarded visit silently.
onBeforeUnmount(() => answerLeaveRequest(false));
</script>

<template>
    <ConfirmDialog
        :show="show"
        title="You have unsaved changes"
        message="If you leave now, they are lost."
        confirm-label="Leave"
        cancel-label="Stay"
        danger
        focus-cancel
        @confirm="answerLeaveRequest(true)"
        @cancel="answerLeaveRequest(false)"
    />
</template>
