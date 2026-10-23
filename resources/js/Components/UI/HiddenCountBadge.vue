<script setup lang="ts">
/**
 * W127/D95: how many children a collapsed card is hiding. A button, because
 * the number is also the quickest way to expand: it does what the chevron does,
 * and is styled like it (IconButton's `subtle` variant: muted, no ground until
 * hovered) so it sits naturally on the card's button bar.
 */
import Icon from './Icon.vue';

defineProps<{ count: number }>();
defineEmits<{ toggle: [] }>();
</script>

<template>
    <button
        type="button"
        class="text-text-muted hover:bg-surface-2 hover:text-text-primary focus-visible:text-text-primary focus-visible:outline-focus-ring inline-flex h-6 shrink-0 items-center justify-center gap-0.5 rounded px-1 text-[11px] leading-4 transition-colors outline-none focus-visible:outline-2 focus-visible:outline-offset-1"
        :aria-label="`Expand, ${count} hidden ${count === 1 ? 'child' : 'children'}`"
        data-testid="hidden-count"
        @click.stop="$emit('toggle')"
    >
        <Icon name="chevron-right" :size="10" />
        {{ count }}
    </button>
</template>
