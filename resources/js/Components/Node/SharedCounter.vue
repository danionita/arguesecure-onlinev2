<script setup lang="ts">
/**
 * The "appears in N places" counter, shared by the chart card and the list
 * card (2.5.1).
 *
 * Both cards used to carry their own copy, and they had drifted: the chart's
 * tooltip named the parents (W69 item 11), the list's said "one node, shown
 * everywhere it belongs", which names nothing. ui.md §3.2 says the two views
 * must not explain one figure differently, so there is now one component.
 *
 * On an attack the counter is D31's interactive popover — the only place that
 * can detach an attack from one of several risks — and on anything else it
 * is a read-only tooltip listing the parents. AppearsUnderPopover renders
 * nothing for other types, which is why the split is here and not inside it.
 */
import AppearsUnderPopover from '@/Components/NodeModal/tabs/AppearsUnderPopover.vue';
import Icon from '@/Components/UI/Icon.vue';
import Tooltip from '@/Components/UI/Tooltip.vue';
import type { AssessmentStore } from '@/stores/assessmentStore';
import { computed } from 'vue';

const props = defineProps<{
    store: AssessmentStore;
    uuid: string;
}>();

/**
 * How many parent names the tooltip lists before it stops counting them out
 * (W69). Past eight, "and N more" is more useful than the ninth name: someone
 * whose attack sits under fifteen risks is learning that the number is large.
 */
const PARENT_NAME_CAP = 8;

const node = computed(() => props.store.nodes[props.uuid]);

const occurrenceCount = computed(() =>
    node.value ? props.store.occurrenceCount(props.uuid, node.value.type) : 1,
);

const parentTitles = computed<string[]>(() => {
    const n = node.value;
    if (!n) return [];

    const uuids =
        n.type === 'attack'
            ? props.store.risksForAttack(props.uuid)
            : n.type === 'defence'
              ? props.store.attacksForDefence(props.uuid)
              : [];

    const parentLabel = n.type === 'attack' ? 'risk' : 'attack';

    return uuids.map(
        (uuid) => props.store.nodes[uuid]?.title || `Untitled ${parentLabel}`,
    );
});

const parentList = computed(() => {
    const shown = parentTitles.value.slice(0, PARENT_NAME_CAP);

    return { shown, remaining: parentTitles.value.length - shown.length };
});

/**
 * The same information as one sentence, for the trigger's accessible name:
 * the bubble is `pointer-events-none` decoration a screen reader never
 * reaches.
 */
const parentSummary = computed(() => {
    const { shown, remaining } = parentList.value;
    const tail = remaining > 0 ? ` and ${remaining} more` : '';

    return shown.length
        ? `Appears in ${occurrenceCount.value} places: ${shown.join(', ')}${tail}`
        : `Appears in ${occurrenceCount.value} places`;
});
</script>

<template>
    <AppearsUnderPopover
        v-if="node?.type === 'attack'"
        :store="store"
        :uuid="uuid"
    />
    <Tooltip v-else-if="node">
        <template #content>
            <span class="block font-medium">
                Appears in {{ occurrenceCount }} places
            </span>
            <span
                v-for="(title, index) in parentList.shown"
                :key="`${index}:${title}`"
                class="block"
                data-parent-name
                >{{ title }}</span
            >
            <span
                v-if="parentList.remaining > 0"
                class="text-text-muted block italic"
                >and {{ parentList.remaining }} more</span
            >
        </template>
        <span
            class="inline-flex items-center gap-0.5 text-[10px]"
            role="img"
            :aria-label="parentSummary"
        >
            <Icon name="shuffle" :size="10" />{{ occurrenceCount }}
        </span>
    </Tooltip>
</template>
