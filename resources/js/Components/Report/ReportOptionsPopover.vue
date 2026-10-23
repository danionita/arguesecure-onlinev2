<script setup lang="ts">
/**
 * One of the report toolbar's two settings buttons (W136, D106): a trigger
 * over a `Dropdown` panel the caller fills with headed groups. It is the Filters
 * button's look (`Toolbar/FilterBar.vue`) without its state: the report's
 * settings are server inputs that change what the document contains, not
 * client filters that dim nodes.
 */
import Dropdown from '@/Components/UI/Dropdown.vue';
import Icon from '@/Components/UI/Icon.vue';

defineProps<{
    label: string;
    testid: string;
}>();
</script>

<template>
    <Dropdown align="left" width="80">
        <template #trigger="{ open }">
            <button
                type="button"
                :data-testid="testid"
                class="border-border-default bg-surface-1 text-text-primary hover:bg-surface-2 focus-visible:outline-focus-ring inline-flex h-7 shrink-0 items-center gap-1.5 rounded-md border px-2.5 text-xs font-medium outline-none focus-visible:outline-2 focus-visible:outline-offset-2"
                :aria-expanded="open"
                aria-haspopup="true"
            >
                {{ label }}
                <Icon name="chevron-down" :size="14" />
            </button>
        </template>

        <template #content>
            <div class="space-y-4 p-3 text-sm"><slot /></div>
        </template>
    </Dropdown>
</template>
