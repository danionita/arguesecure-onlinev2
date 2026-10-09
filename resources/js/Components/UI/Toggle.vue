<script setup lang="ts">
/** On/off switch — e.g. the defence "transfer" flag (docs/specs/ui.md §7) and public/private toggles. */
import { useId } from 'vue';

withDefaults(
    defineProps<{
        modelValue: boolean;
        label?: string;
        disabled?: boolean;
    }>(),
    {
        label: undefined,
        disabled: false,
    },
);

defineEmits<{ 'update:modelValue': [value: boolean] }>();

const id = useId();
</script>

<template>
    <label
        :for="id"
        class="inline-flex cursor-pointer items-center gap-2"
        :class="disabled && 'cursor-not-allowed opacity-50'"
    >
        <button
            :id="id"
            type="button"
            role="switch"
            :aria-checked="modelValue"
            :disabled="disabled"
            class="border-border-default focus-visible:outline-focus-ring relative inline-flex h-5 w-9 shrink-0 items-center rounded-full border transition-colors outline-none focus-visible:outline-2 focus-visible:outline-offset-2"
            :class="modelValue ? 'bg-node-root-text' : 'bg-surface-2'"
            @click="!disabled && $emit('update:modelValue', !modelValue)"
        >
            <span
                class="bg-surface-1 inline-block h-3.5 w-3.5 transform rounded-full transition-transform"
                :class="modelValue ? 'translate-x-4' : 'translate-x-0.5'"
            />
        </button>
        <span v-if="label" class="text-text-primary text-sm">{{ label }}</span>
    </label>
</template>
