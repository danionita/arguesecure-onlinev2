<script setup lang="ts">
/**
 * A completeness-aware currency figure for one frozen baseline
 * (calculation-engine.md §6.2.3), the same "never a bare number for a
 * partial assessment" rule ReportTotal.vue applies to the live report —
 * carried into snapshots too, so the completeness block travels with the
 * snapshot. Kept as the snapshots' own copy rather than
 * importing from Components/Report.
 */
import Money from '@/Components/UI/Money.vue';
import type { CalculationResult } from '@/calc';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        result: CalculationResult;
        currency?: string;
        label?: string;
    }>(),
    {
        currency: 'EUR',
        label: undefined,
    },
);

const qualifier = computed(() => {
    if (!props.result.completeness.isPartial) return null;
    const pct = Math.round(props.result.completeness.coverage * 100);
    return `${props.result.completeness.pairsValued} of ${props.result.completeness.pairsTotal} paths valued (${pct}%)`;
});
</script>

<template>
    <div>
        <div
            v-if="label"
            class="text-text-muted text-xs font-medium tracking-wide uppercase"
        >
            {{ label }}
        </div>
        <div class="text-2xl tabular-nums">
            <Money
                :value="result.total"
                :currency="currency"
                :at-least="result.completeness.isPartial"
            />
        </div>
        <div v-if="qualifier" class="text-text-muted mt-0.5 text-xs">
            {{ qualifier }}
        </div>
    </div>
</template>
