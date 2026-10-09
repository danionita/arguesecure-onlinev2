<script setup lang="ts">
/**
 * Currency formatter. All computed results render as currency, never a
 * severity band (CLAUDE.md "Computed results are never band-mapped").
 * `null`/`undefined` render as the missing-value chip, never `0` — the
 * calculation engine's own rule (calculation-engine.md) applies to the
 * display layer too.
 *
 * The formatting itself lives in format.ts so the node card's tooltips can
 * quote the identical string (W29 — see that file's docblock).
 */
import { computed } from 'vue';
import Chip from './Chip.vue';
import { formatMoney } from './format';

const props = withDefaults(
    defineProps<{
        value: number | null | undefined;
        currency?: string;
        /** Prefix a partial/undercounted total with "≥" (docs/specs/ui.md §9). */
        atLeast?: boolean;
        locale?: string;
    }>(),
    {
        currency: 'EUR',
        atLeast: false,
        locale: 'en-GB',
    },
);

const formatted = computed(() =>
    formatMoney(props.value, {
        currency: props.currency,
        atLeast: props.atLeast,
        locale: props.locale,
    }),
);
</script>

<template>
    <Chip v-if="formatted === null" missing />
    <span v-else class="tabular-nums">{{ formatted }}</span>
</template>
