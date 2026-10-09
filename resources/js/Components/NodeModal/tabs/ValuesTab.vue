<script setup lang="ts">
/**
 * Type-dispatch shell for the value tab (ui.md §7).
 *
 * The tab's *label* is per type since W39 — Impact, Likelihood or Cost — but
 * the dispatch was already per type, so the rename cost nothing here. The
 * root branch below is unreachable from the modal now (the root has no value
 * tab: it is quantified by its children, not by itself) and stays only as a
 * guard for a direct mount.
 */
import type { AssessmentStore } from '@/stores/assessmentStore';
import { computed } from 'vue';
import AttackValuesPanel from './AttackValuesPanel.vue';
import DefenceValuesPanel from './DefenceValuesPanel.vue';
import RiskValuesPanel from './RiskValuesPanel.vue';

const props = defineProps<{
    store: AssessmentStore;
    uuid: string;
}>();

const node = computed(() => props.store.nodes[props.uuid]);
</script>

<template>
    <div class="p-4">
        <RiskValuesPanel
            v-if="node?.type === 'risk'"
            :store="store"
            :uuid="uuid"
        />
        <AttackValuesPanel
            v-else-if="node?.type === 'attack'"
            :store="store"
            :uuid="uuid"
        />
        <DefenceValuesPanel
            v-else-if="node?.type === 'defence'"
            :store="store"
            :uuid="uuid"
        />
        <p v-else class="text-text-muted text-sm">
            The root node has no values of its own. Its totals are on the
            Statistics tab.
        </p>
    </div>
</template>
