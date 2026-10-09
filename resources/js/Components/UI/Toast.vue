<script setup lang="ts">
import { computed } from 'vue';
import Icon from './Icon.vue';
import IconButton from './IconButton.vue';
import type { ToastMessage } from './useToast';

const props = defineProps<{ toast: ToastMessage }>();
defineEmits<{ dismiss: [id: number] }>();

const toneClasses = computed(
    () =>
        ({
            info: 'border-border-default bg-surface-1',
            success:
                'border-status-implemented-border bg-status-implemented-bg',
            danger: 'border-node-attack-border bg-node-attack-bg',
        })[props.toast.tone],
);

const icon = computed(
    () =>
        ({
            info: 'info' as const,
            success: 'circle-check' as const,
            danger: 'alert-circle' as const,
        })[props.toast.tone],
);
</script>

<template>
    <div
        role="status"
        class="text-text-primary flex items-start gap-2 rounded-md border px-3 py-2 text-sm shadow-md"
        :class="toneClasses"
    >
        <Icon :name="icon" :size="16" class="mt-0.5 shrink-0" />
        <p class="flex-1">{{ toast.text }}</p>
        <IconButton
            icon="x"
            label="Dismiss"
            size="sm"
            @click="$emit('dismiss', toast.id)"
        />
    </div>
</template>
