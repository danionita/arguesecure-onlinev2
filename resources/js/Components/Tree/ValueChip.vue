<script setup lang="ts">
/**
 * One labelled figure on a node card: `Impact: €50,000` (W29, ui.md §3.2).
 *
 * 2.0 put the numbers on the row bare. A risk carried three of them and a
 * defence five, in a fixed order the reader had to learn, with no way to tell
 * which the engine computed and which someone typed. The label is not
 * decoration — it is the difference between a number and a fact.
 *
 * The whole thing is one hover/focus target, label included, and it is
 * focusable so the explanation is reachable from the keyboard (ui.md §11).
 */
import Tooltip from '@/Components/UI/Tooltip.vue';

withDefaults(
    defineProps<{
        label: string;
        tooltip: string;
        /** Dimmed: on screen, deliberately not counting in this baseline. */
        muted?: boolean;
    }>(),
    { muted: false },
);
</script>

<template>
    <Tooltip :text="tooltip">
        <span
            class="focus-visible:outline-focus-ring inline-flex items-center gap-1 rounded text-xs whitespace-nowrap outline-none focus-visible:outline-1"
            :class="muted && 'opacity-50'"
            tabindex="0"
        >
            <span class="text-text-muted">{{ label }}:</span>
            <span class="text-text-secondary"><slot /></span>
        </span>
    </Tooltip>
</template>
