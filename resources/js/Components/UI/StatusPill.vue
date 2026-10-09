<script setup lang="ts">
/**
 * Read-only defence/transfer status display — the "status pill" the node
 * card's Values chips need (docs/specs/ui.md §3.2). For editing, see
 * SegmentedControl.vue, which the Values tab uses for the three-way
 * control (§7).
 */
import { computed } from 'vue';
import Chip from './Chip.vue';
import type { DefenceStatus } from './types';

const props = defineProps<{ status: DefenceStatus }>();

const labels: Record<DefenceStatus, string> = {
    implemented: 'Implemented',
    todo: 'Todo',
    shelved: 'Shelved',
};

const label = computed(() => labels[props.status]);
</script>

<template>
    <Chip :tone="status">{{ label }}</Chip>
</template>
