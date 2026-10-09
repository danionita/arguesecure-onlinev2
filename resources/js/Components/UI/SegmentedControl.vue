<script setup lang="ts">
/**
 * Generic segmented control. Backs the defence status three-way selector
 * and the impact/likelihood quantitative-vs-qualitative mode toggle
 * (docs/specs/ui.md §7). `role="radiogroup"` over a button group rather
 * than a native <select> because these sets are always small (2-3) and
 * benefit from being all visible at once.
 */
defineProps<{
    modelValue: string;
    /** `hint` becomes the option's hover/focus title — W29's baseline selector needs each choice explained, not just named. */
    options: { value: string; label: string; hint?: string; badge?: string }[];
}>();

defineEmits<{ 'update:modelValue': [value: string] }>();
</script>

<template>
    <div
        role="radiogroup"
        class="border-border-default bg-surface-2 inline-flex rounded-md border p-0.5 text-sm"
    >
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            role="radio"
            :aria-checked="modelValue === option.value"
            :title="option.hint"
            class="focus-visible:outline-focus-ring rounded px-2.5 py-1 font-medium transition-colors outline-none focus-visible:outline-2 focus-visible:outline-offset-1"
            :class="
                modelValue === option.value
                    ? 'bg-surface-1 text-text-primary shadow-sm'
                    : 'text-text-muted hover:text-text-primary'
            "
            @click="$emit('update:modelValue', option.value)"
        >
            {{ option.label }}
            <!-- e.g. "Beta" on the report (2.5.1). Part of the option's
                 accessible name, which is the point: it is a fact about the
                 option, not decoration. -->
            <span
                v-if="option.badge"
                class="bg-node-transfer-bg text-node-transfer-text ml-1 rounded px-1 py-px align-middle text-[10px] font-semibold tracking-wide uppercase"
                data-testid="option-badge"
                >{{ option.badge }}</span
            >
        </button>
    </div>
</template>
