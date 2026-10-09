<script setup lang="ts">
/**
 * Full-text search box over `useSearch.ts` (ui.md §5). Binds the
 * composable's `inputEl` to the real `<input>` so `focus()` — and
 * therefore W14's `/` binding — actually reaches this element.
 */
import Icon from '@/Components/UI/Icon.vue';
import IconButton from '@/Components/UI/IconButton.vue';
import type { UseSearchReturn } from '@/composables/useSearch';

const props = defineProps<{
    search: UseSearchReturn;
}>();

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Enter') {
        event.preventDefault();
        if (event.shiftKey) props.search.previous();
        else props.search.next();
    } else if (event.key === 'Escape') {
        props.search.clear();
        (event.target as HTMLInputElement).blur();
    }
}
</script>

<template>
    <div class="relative flex items-center gap-1">
        <Icon
            name="search"
            :size="14"
            class="text-text-muted pointer-events-none absolute left-2"
        />
        <input
            :ref="(el) => search.bindInputEl(el as Element | null)"
            :value="search.query.value"
            type="search"
            placeholder="Search title and description…"
            aria-label="Search the assessment"
            class="border-border-default bg-surface-1 text-text-primary placeholder:text-text-muted focus:border-focus-ring focus:ring-focus-ring w-56 rounded-md py-1 pr-2 pl-7 text-sm shadow-sm"
            @input="search.setQuery(($event.target as HTMLInputElement).value)"
            @keydown="onKeydown"
        />
        <span
            v-if="search.query.value"
            class="text-text-muted shrink-0 text-xs tabular-nums"
            role="status"
            aria-live="polite"
        >
            {{
                search.matches.value.length === 0
                    ? 'No matches'
                    : `${search.activeIndex.value + 1} / ${search.matches.value.length}`
            }}
        </span>
        <template v-if="search.query.value && search.matches.value.length > 0">
            <IconButton
                icon="chevron-up"
                label="Previous match"
                @click="search.previous()"
            />
            <IconButton
                icon="chevron-down"
                label="Next match"
                @click="search.next()"
            />
        </template>
        <IconButton
            v-if="search.query.value"
            icon="x"
            label="Clear search"
            @click="search.clear()"
        />
    </div>
</template>
