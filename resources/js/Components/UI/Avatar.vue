<script setup lang="ts">
/**
 * Initials-based avatar (no external avatar image service — CLAUDE.md
 * "nothing loads from a CDN"). `ring` renders the coloured presence ring
 * W16 uses to show who has a field focused (docs/specs/ui.md §6).
 */
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        name: string;
        size?: 'xs' | 'sm' | 'md';
        /** CSS colour for the presence ring; omit for no ring. */
        ringColor?: string;
    }>(),
    {
        size: 'sm',
        ringColor: undefined,
    },
);

const initials = computed(() =>
    props.name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join(''),
);

const sizeClasses = computed(
    () =>
        ({
            xs: 'h-5 w-5 text-[9px]',
            sm: 'h-7 w-7 text-xs',
            md: 'h-9 w-9 text-sm',
        })[props.size],
);

// Deterministic tone per name so the same person always gets the same colour.
const hueTokens: Array<'root' | 'risk' | 'attack' | 'defence' | 'transfer'> = [
    'root',
    'risk',
    'attack',
    'defence',
    'transfer',
];
const tone = computed(() => {
    const hash = props.name
        .split('')
        .reduce((acc, char) => acc + char.charCodeAt(0), 0);
    return hueTokens[hash % hueTokens.length];
});

const toneClasses: Record<string, string> = {
    root: 'bg-node-root-bg text-node-root-text',
    risk: 'bg-node-risk-bg text-node-risk-text',
    attack: 'bg-node-attack-bg text-node-attack-text',
    defence: 'bg-node-defence-bg text-node-defence-text',
    transfer: 'bg-node-transfer-bg text-node-transfer-text',
};
</script>

<template>
    <span
        :title="name"
        class="ring-surface-1 inline-flex shrink-0 items-center justify-center rounded-full font-semibold ring-2"
        :class="[sizeClasses, toneClasses[tone]]"
        :style="ringColor ? { boxShadow: `0 0 0 2px ${ringColor}` } : undefined"
    >
        {{ initials }}
    </span>
</template>
