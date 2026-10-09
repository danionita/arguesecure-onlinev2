<script setup lang="ts">
/**
 * Job B's suggestions, and the accept step that is the actual guardrail
 * (W52, PLAN-2.3-ai.md D24).
 *
 * ## Why every suggestion is read before it lands
 *
 * The 2.3 spike measured that text injected into a node description reaches
 * the model's output on **3 of 3** usable models, and that no prompt wording
 * prevents it: an injection that merely adds to the output costs the model
 * nothing to obey, so it never has to choose between the injection and the
 * task. `title` is a field the accept path legitimately reads, so no parser
 * can tell an injected title from a genuine one.
 *
 * That makes the person reading this list the control, not a courtesy behind
 * one. Hence: nothing is pre-selected, nothing is accepted in bulk, each item
 * is accepted on its own, and the panel says plainly where the text came from.
 *
 * Rendered as text throughout — `{{ }}`, never `v-html`.
 */
import Button from '@/Components/UI/Button.vue';
import { ref } from 'vue';

interface AssistantSuggestion {
    title: string;
    description: string;
}

const props = defineProps<{
    suggestions: AssistantSuggestion[];
    childType: 'risk' | 'attack' | 'defence';
    /** Which have already been added, by index, so they cannot be added twice. */
    accepted: number[];
    busy: boolean;
}>();

const emit = defineEmits<{
    accept: [suggestion: AssistantSuggestion, index: number];
    dismiss: [];
}>();

const acceptingIndex = ref<number | null>(null);

async function accept(suggestion: AssistantSuggestion, index: number) {
    acceptingIndex.value = index;
    emit('accept', suggestion, index);
    acceptingIndex.value = null;
}
</script>

<template>
    <div
        class="border-border-default bg-surface-1 mt-2 rounded-lg border p-3"
        data-testid="suggestions-panel"
    >
        <div class="flex items-start justify-between gap-2">
            <p class="text-text-muted text-xs">
                Suggested {{ childType }}s, written by a local model. Read each
                one before adding it — nothing is added until you do.
            </p>
            <button
                type="button"
                class="text-text-muted hover:text-text-primary text-xs underline"
                data-testid="dismiss-suggestions"
                @click="emit('dismiss')"
            >
                Dismiss
            </button>
        </div>

        <p
            v-if="suggestions.length === 0"
            class="text-text-muted mt-3 text-sm"
            data-testid="no-suggestions"
        >
            The assistant did not return anything usable. Try again, or add one
            yourself.
        </p>

        <ul v-else class="mt-3 space-y-2">
            <li
                v-for="(suggestion, index) in suggestions"
                :key="index"
                class="border-border-subtle bg-surface-0 rounded-md border p-2"
                data-testid="suggestion"
            >
                <p class="text-text-primary text-sm font-medium">
                    {{ suggestion.title }}
                </p>
                <p
                    v-if="suggestion.description"
                    class="text-text-secondary mt-0.5 text-xs"
                >
                    {{ suggestion.description }}
                </p>

                <div class="mt-2 flex justify-end">
                    <span
                        v-if="props.accepted.includes(index)"
                        class="text-text-muted text-xs"
                        data-testid="suggestion-added"
                    >
                        Added
                    </span>
                    <Button
                        v-else
                        variant="secondary"
                        :disabled="busy"
                        :loading="acceptingIndex === index"
                        data-testid="accept-suggestion"
                        @click="accept(suggestion, index)"
                        >Add</Button
                    >
                </div>
            </li>
        </ul>
    </div>
</template>
