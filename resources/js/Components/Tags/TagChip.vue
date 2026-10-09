<script setup lang="ts">
/**
 * One label (W43, item 18).
 *
 * A plain neutral chip, deliberately. `tags.color` exists in the schema and
 * is not read here: the node card's colours already mean something specific
 * — node type, defence status, missing value (ui.md §2) — and a
 * user-chosen colour sitting beside them would compete with a vocabulary the
 * reader has already learned. Colour is never the only channel in this
 * application, and a label whose only distinguishing feature is its colour
 * would be exactly that.
 *
 * `removable` renders the remove control inside the chip rather than beside
 * it, so the whole thing reads as one object and the target is where the eye
 * already is.
 */
import Chip from '@/Components/UI/Chip.vue';
import Icon from '@/Components/UI/Icon.vue';

defineProps<{
    name: string;
    removable?: boolean;
}>();

const emit = defineEmits<{ remove: [] }>();
</script>

<template>
    <Chip tone="neutral" icon="tag">
        {{ name }}
        <button
            v-if="removable"
            type="button"
            class="hover:text-text-primary focus-visible:outline-focus-ring -mr-0.5 ml-0.5 rounded outline-none focus-visible:outline-1"
            :aria-label="`Remove label ${name}`"
            @click="emit('remove')"
        >
            <Icon name="x" :size="11" />
        </button>
    </Chip>
</template>
