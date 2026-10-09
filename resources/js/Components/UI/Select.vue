<script setup lang="ts">
import { useId } from 'vue';

export interface SelectOption {
    value: string;
    label: string;
}

withDefaults(
    defineProps<{
        modelValue: string;
        options: SelectOption[];
        label?: string;
        helpText?: string;
        disabled?: boolean;
        /** Renders a "blank means default" leading option, e.g. the
         *  reduction-target selector (docs/specs/ui.md §7). */
        placeholder?: string;
    }>(),
    {
        label: undefined,
        helpText: undefined,
        disabled: false,
        placeholder: undefined,
    },
);

defineEmits<{ 'update:modelValue': [value: string] }>();

const id = useId();
</script>

<template>
    <div>
        <label
            v-if="label"
            :for="id"
            class="text-text-primary mb-1 block text-sm font-medium"
            >{{ label }}</label
        >
        <p v-if="helpText" class="text-text-muted mb-1 text-xs">
            {{ helpText }}
        </p>
        <select
            :id="id"
            :value="modelValue"
            :disabled="disabled"
            class="border-border-default bg-surface-1 text-text-primary focus:border-focus-ring focus:ring-focus-ring block w-full rounded-md text-sm shadow-sm disabled:cursor-not-allowed disabled:opacity-50"
            @change="
                $emit(
                    'update:modelValue',
                    ($event.target as HTMLSelectElement).value,
                )
            "
        >
            <option v-if="placeholder" value="">{{ placeholder }}</option>
            <option
                v-for="option in options"
                :key="option.value"
                :value="option.value"
            >
                {{ option.label }}
            </option>
        </select>
    </div>
</template>
