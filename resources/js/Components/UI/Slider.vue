<script setup lang="ts">
/** Range input — the canvas zoom control is the first consumer (docs/specs/ui.md §5, range 50%-200%). */
import { useId } from 'vue';

withDefaults(
    defineProps<{
        modelValue: number;
        min?: number;
        max?: number;
        step?: number;
        label?: string;
        /** Formats the value shown next to the slider, e.g. `(v) => \`${v}%\`` */
        format?: (value: number) => string;
    }>(),
    {
        min: 0,
        max: 100,
        step: 1,
        label: undefined,
        format: (value: number) => String(value),
    },
);

defineEmits<{ 'update:modelValue': [value: number] }>();

const id = useId();
</script>

<template>
    <div>
        <div
            v-if="label"
            class="mb-1 flex items-center justify-between text-sm"
        >
            <label :for="id" class="text-text-primary font-medium">{{
                label
            }}</label>
            <span class="text-text-muted">{{ format(modelValue) }}</span>
        </div>
        <input
            :id="id"
            type="range"
            :min="min"
            :max="max"
            :step="step"
            :value="modelValue"
            class="bg-surface-2 h-1.5 w-full cursor-pointer appearance-none rounded-full accent-(--color-node-root-text)"
            @input="
                $emit(
                    'update:modelValue',
                    Number(($event.target as HTMLInputElement).value),
                )
            "
        />
    </div>
</template>
