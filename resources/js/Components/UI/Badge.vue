<script setup lang="ts">
/**
 * Small count/label badge — e.g. the unresolved-comment count on the node
 * card's indicator icons (docs/specs/ui.md §3.2). Distinct from Chip: a
 * Badge decorates another element (usually overlapping its corner), a Chip
 * stands alone in the value-chip row.
 */
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        tone?: 'neutral' | 'accent' | 'danger';
    }>(),
    {
        tone: 'accent',
    },
);

const toneClasses = computed(
    () =>
        ({
            neutral: 'bg-surface-2 text-text-secondary',
            accent: 'bg-node-root-text text-text-inverted',
            danger: 'bg-node-attack-text text-text-inverted',
        })[props.tone],
);
</script>

<template>
    <span
        class="inline-flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] leading-none font-semibold"
        :class="toneClasses"
    >
        <slot />
    </span>
</template>
