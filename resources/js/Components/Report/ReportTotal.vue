<script setup lang="ts">
/**
 * A completeness-aware currency figure (calculation-engine.md §6.2.3, ui.md
 * §9 "Presenting the numbers honestly"). Never a bare number for a partial
 * assessment: a partial total is prefixed "≥" and followed by the exact
 * coverage qualifier the spec requires, e.g.
 * "≥ €53,500 · 3 of 4 paths valued (75%)". A fully-valued total renders
 * plainly with no qualifier — the "≥" would be misleading noise there,
 * since the number is then exact, not a lower bound.
 */
import { computed } from 'vue';
import Money from '../UI/Money.vue';
import type { TotalBlock } from './types';

const props = withDefaults(
    defineProps<{
        block: TotalBlock;
        currency?: string;
        /** Shows the "inherent / current / target" baseline label before the figure. */
        showBaselineLabel?: boolean;
        size?: 'sm' | 'md' | 'lg';
    }>(),
    {
        currency: 'EUR',
        showBaselineLabel: false,
        size: 'md',
    },
);

const baselineLabels: Record<TotalBlock['baseline'], string> = {
    inherent: 'Inherent',
    current: 'Current',
    target: 'Target',
};

const sizeClasses: Record<string, string> = {
    sm: 'text-sm',
    md: 'text-xl',
    lg: 'text-3xl',
};

const qualifier = computed(() => {
    if (!props.block.isPartial) return null;
    const pct = Math.round(props.block.coverage * 100);
    return `${props.block.pairsValued} of ${props.block.pairsTotal} paths valued (${pct}%)`;
});
</script>

<template>
    <div>
        <div
            v-if="showBaselineLabel"
            class="text-text-muted text-xs font-medium tracking-wide uppercase"
        >
            {{ baselineLabels[block.baseline] }}
        </div>
        <div class="tabular-nums" :class="sizeClasses[size]">
            <Money
                :value="block.total"
                :currency="currency"
                :at-least="block.isPartial"
            />
        </div>
        <div v-if="qualifier" class="text-text-muted mt-0.5 text-xs">
            {{ qualifier }}
        </div>
    </div>
</template>
