<script setup lang="ts">
/**
 * The pop-up shell behind the toolbar's "Filters" button (W137): the
 * trigger, its count of what is on, and the panel. It knows nothing about
 * what goes inside — `FilterBar.vue` fills it with the editor's dimming
 * filters, and the report's Sections button (W136) fills a second one — so
 * the two look and behave alike without sharing state (D101: the editor's
 * filters are client state, the report's options are server inputs).
 *
 * Categories are `FilterCategory.vue`s in the default slot (W132).
 */
import Dropdown from '@/Components/UI/Dropdown.vue';
import Icon from '@/Components/UI/Icon.vue';

withDefaults(
    defineProps<{
        label?: string;
        /** What is on; shown as a badge when above zero. */
        count?: number;
    }>(),
    { label: 'Filters', count: 0 },
);
</script>

<template>
    <Dropdown align="left" width="80">
        <template #trigger="{ open }">
            <button
                type="button"
                class="border-border-default bg-surface-1 text-text-primary hover:bg-surface-2 focus-visible:outline-focus-ring inline-flex h-7 shrink-0 items-center gap-1.5 rounded-md border px-2.5 text-xs font-medium outline-none focus-visible:outline-2 focus-visible:outline-offset-2"
                :aria-expanded="open"
                aria-haspopup="true"
            >
                <Icon name="filter" :size="14" />
                {{ label }}
                <span
                    v-if="count > 0"
                    class="bg-surface-2 text-text-secondary rounded-full px-1.5 text-[10px] tabular-nums"
                    aria-hidden="true"
                >
                    {{ count }}
                </span>
                <span class="sr-only" v-if="count > 0">{{ count }} active</span>
                <Icon name="chevron-down" :size="14" />
            </button>
        </template>

        <template #content>
            <div class="space-y-3 p-3">
                <slot />
            </div>
        </template>
    </Dropdown>
</template>
