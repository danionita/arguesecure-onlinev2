<script setup lang="ts">
/** Percentage formatter for reduction figures. `null` renders as the missing-value chip, never `0%`. */
import { computed } from 'vue';
import Chip from './Chip.vue';
import { formatPercent } from './format';

const props = withDefaults(
    defineProps<{
        /** A 0-1 fraction, e.g. 0.42 renders as "42%". */
        value: number | null | undefined;
        digits?: number;
        signed?: boolean;
    }>(),
    {
        digits: 0,
        signed: false,
    },
);

const formatted = computed(() =>
    formatPercent(props.value, { digits: props.digits, signed: props.signed }),
);
</script>

<template>
    <Chip v-if="formatted === null" missing />
    <span v-else class="tabular-nums">{{ formatted }}</span>
</template>
