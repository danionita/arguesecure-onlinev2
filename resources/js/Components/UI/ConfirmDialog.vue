<script setup lang="ts">
/**
 * Used for the tree's "Delete (with confirm if it has children)" action
 * and the undo prompt's "cancel" path (docs/specs/ui.md §3.6, §4). Kept
 * generic — the undo prompt itself needs Keep mine/Take theirs, which is
 * a conflict-specific composition of Button, not this component.
 */
import Button from './Button.vue';
import Modal from './Modal.vue';

withDefaults(
    defineProps<{
        show: boolean;
        title: string;
        message?: string;
        confirmLabel?: string;
        cancelLabel?: string;
        danger?: boolean;
        loading?: boolean;
        /** Initial focus on Cancel, for prompts where Enter must not discard. */
        focusCancel?: boolean;
    }>(),
    {
        message: undefined,
        confirmLabel: 'Confirm',
        cancelLabel: 'Cancel',
        danger: false,
        loading: false,
        focusCancel: false,
    },
);

const emit = defineEmits<{ confirm: []; cancel: [] }>();
</script>

<template>
    <Modal :show="show" :title="title" size="sm" @close="emit('cancel')">
        <div class="text-text-secondary px-4 py-4 text-sm">
            <p v-if="message">{{ message }}</p>
            <slot v-else />
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <Button
                    variant="ghost"
                    :autofocus="focusCancel"
                    @click="emit('cancel')"
                    >{{ cancelLabel }}</Button
                >
                <Button
                    :variant="danger ? 'danger' : 'primary'"
                    :loading="loading"
                    :autofocus="!focusCancel"
                    @click="emit('confirm')"
                >
                    {{ confirmLabel }}
                </Button>
            </div>
        </template>
    </Modal>
</template>
