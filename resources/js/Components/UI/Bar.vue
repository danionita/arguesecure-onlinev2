<script setup lang="ts">
/**
 * Proportional fill bar — the risk card's contribution bar and the totals
 * panel's reduction bar both reduce to this (docs/specs/ui.md §3.2, §5).
 * `value` is a 0-1 fraction; out-of-range values are clamped rather than
 * distorting the bar.
 */
import { computed } from 'vue';
import Tooltip from './Tooltip.vue';

const props = withDefaults(
    defineProps<{
        value: number;
        tone?: 1 | 2 | 3 | 4 | 5 | 'implemented' | 'todo' | 'shelved';
        size?: 'sm' | 'md';
        /**
         * W29: a bar with no number attached is a decoration. When present,
         * the bar itself becomes the hover/focus target — wrapping it at each
         * call site instead would make the label hoverable and the bar not,
         * which is backwards.
         */
        tooltip?: string;
        /** Screen-reader name; the tooltip text is the sensible default. */
        label?: string;
    }>(),
    {
        tone: 3,
        size: 'sm',
        tooltip: undefined,
        label: undefined,
    },
);

const accessibleName = computed(() => props.label ?? props.tooltip);

const clamped = computed(() => Math.max(0, Math.min(1, props.value)));

// Full literal class names, spelled out so Tailwind's text-based scanner
// (resources/css/app.css @source globs) can find them — a template
// literal like `bg-magnitude-${n}-bg` is invisible to it.
const toneClassMap = {
    1: 'bg-magnitude-1-bg',
    2: 'bg-magnitude-2-bg',
    3: 'bg-magnitude-3-bg',
    4: 'bg-magnitude-4-bg',
    5: 'bg-magnitude-5-bg',
    implemented: 'bg-status-implemented-text',
    todo: 'bg-status-todo-text',
    shelved: 'bg-status-shelved-text',
} as const;

const fillClasses = computed(() => toneClassMap[props.tone]);

const heightClasses = computed(() => (props.size === 'sm' ? 'h-1.5' : 'h-2.5'));
</script>

<template>
    <component
        :is="tooltip ? Tooltip : 'div'"
        :text="tooltip"
        class="w-full"
        :tabindex="tooltip ? 0 : undefined"
    >
        <div
            class="bg-surface-2 w-full overflow-hidden rounded-full"
            :class="heightClasses"
            role="progressbar"
            :aria-label="accessibleName"
            :aria-valuenow="Math.round(clamped * 100)"
            aria-valuemin="0"
            aria-valuemax="100"
        >
            <div
                class="h-full rounded-full transition-[width]"
                :class="fillClasses"
                :style="{ width: `${clamped * 100}%` }"
            />
        </div>
    </component>
</template>
