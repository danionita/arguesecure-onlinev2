<script setup lang="ts">
/**
 * The list under the add-a-child title field (W40).
 *
 * Advisory, not modal: it never takes focus, so the caret stays in the field
 * and typing continues to work with the list open. `mousedown.prevent` is what
 * makes clicking a row possible at all — the field commits on blur, and
 * without this the blur would delete the placeholder node out from under the
 * click.
 *
 * Each row says where the node already appears. That is the line that makes
 * the feature explicable: the whole point is linking to something that is not
 * on screen, and "Phishing — already under 2 risks" is the difference between
 * an obvious choice and an alarming one.
 */
import type { NodeSuggestion, SuggestableType } from './useNodeSuggestions';

const props = defineProps<{
    suggestions: NodeSuggestion[];
    activeIndex: number;
    /** The type being added, which fixes what its parents are called. */
    childType: SuggestableType;
}>();

const emit = defineEmits<{ choose: [suggestion: NodeSuggestion] }>();

/** Derived from the type, not from a display label — labels are product copy and move. */
function occurrenceLabel(count: number): string {
    if (count === 0) return 'Not linked anywhere yet';

    const parent = props.childType === 'attack' ? 'risk' : 'attack';

    return `Already under ${count} ${parent}${count === 1 ? '' : 's'}`;
}
</script>

<template>
    <ul
        v-if="suggestions.length"
        role="listbox"
        aria-label="Existing nodes you can link instead"
        class="border-border-default bg-surface-1 absolute z-30 mt-1 max-h-60 w-72 overflow-y-auto rounded-md border py-1 shadow-lg"
    >
        <li
            v-for="(suggestion, index) in suggestions"
            :key="suggestion.uuid"
            role="option"
            :aria-selected="index === activeIndex"
            class="cursor-pointer px-3 py-1.5"
            :class="
                index === activeIndex ? 'bg-surface-2' : 'hover:bg-surface-2'
            "
            @mousedown.prevent="emit('choose', suggestion)"
        >
            <div class="text-text-primary truncate text-sm">
                {{ suggestion.title || 'Untitled' }}
            </div>
            <div
                v-if="suggestion.description"
                class="text-text-muted truncate text-xs"
            >
                {{ suggestion.description }}
            </div>
            <div class="text-text-secondary text-xs">
                {{ occurrenceLabel(suggestion.occurrences) }}
            </div>
        </li>
    </ul>
</template>
