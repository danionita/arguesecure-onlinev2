<script setup lang="ts">
/**
 * "Appears in N places" chip (ui.md §5 / §3.2 / §11): "An explicit
 * 'appears in N places' chip does the same on click, and is the
 * accessible equivalent of the hover behaviour" — clicking pins the
 * highlight via `useDuplicateHighlight.ts`'s `pin()`, which (unlike
 * `hover()`) keeps working once the pointer or keyboard focus moves away,
 * for users who can't rely on `:hover`.
 *
 * ui.md §11 also requires an `aria-description` naming the other
 * positions on the node card itself — that attribute already exists on
 * NodeCard.vue's "N places" indicator icon (W12). This component is the
 * larger, standalone version of the same affordance for a toolbar/detail
 * context; it is not a replacement for that inline indicator.
 */
import Chip from '@/Components/UI/Chip.vue';
import Icon from '@/Components/UI/Icon.vue';
import { computed } from 'vue';

const props = defineProps<{
    uuid: string;
    count: number;
    pinned: boolean;
}>();

const emit = defineEmits<{ toggle: [uuid: string] }>();

const label = computed(() => `Appears in ${props.count} places`);
</script>

<template>
    <button
        v-if="count > 1"
        type="button"
        :aria-pressed="pinned"
        :aria-description="label"
        :title="label"
        @click="emit('toggle', uuid)"
    >
        <Chip :tone="pinned ? 'root' : 'neutral'">
            <Icon name="shuffle" :size="11" />
            {{ count }}
        </Chip>
    </button>
</template>
