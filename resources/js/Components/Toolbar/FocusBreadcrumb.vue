<script setup lang="ts">
/**
 * Ancestor breadcrumb (ui.md §5): "Sticky ancestor breadcrumb when
 * scrolled past a parent" for ordinary scrolling, and "a breadcrumb shows
 * the path back" while focus mode is active. Both are the same
 * component — `useFocusMode.ts`'s `breadcrumb` list already carries every
 * ancestor of the current occurrence root-first, which is exactly what a
 * sticky "where am I" trail needs whether or not focus mode narrowed the
 * view.
 *
 * `sticky` positioning is applied here (`sticky top-0`); the parent is
 * responsible for placing this inside the scrolling canvas container so
 * the CSS sticky behaviour has the right containing block.
 */
import Icon from '@/Components/UI/Icon.vue';
import IconButton from '@/Components/UI/IconButton.vue';
import type { FocusBreadcrumbEntry } from '@/composables/useFocusMode';

defineProps<{
    entries: FocusBreadcrumbEntry[];
    /** True while an isolated subtree is showing, so "Exit focus" only appears then. */
    focusActive?: boolean;
}>();

const emit = defineEmits<{
    select: [pathKey: string];
    'exit-focus': [];
}>();
</script>

<template>
    <nav
        v-if="entries.length"
        aria-label="Ancestor path"
        class="border-border-subtle bg-surface-1/95 sticky top-0 z-10 flex items-center gap-1 border-b px-2 py-1 text-xs backdrop-blur"
    >
        <template v-for="(entry, index) in entries" :key="entry.pathKey">
            <Icon
                v-if="index > 0"
                name="chevron-right"
                :size="10"
                class="text-text-muted"
            />
            <button
                type="button"
                class="text-text-secondary hover:text-text-primary truncate"
                @click="emit('select', entry.pathKey)"
            >
                {{ entry.title || 'Untitled' }}
            </button>
        </template>

        <IconButton
            v-if="focusActive"
            icon="x"
            label="Exit focus mode"
            class="ml-auto"
            @click="emit('exit-focus')"
        />
    </nav>
</template>
