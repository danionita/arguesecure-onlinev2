<script setup lang="ts">
/**
 * The assistant's results for one node: progress while it runs, the error if
 * it failed, and the suggestions list with its accept step — the three
 * things `NodeCard.vue` used to render inline, extracted in W85/D50 so the
 * chart card can show the same thing rather than nothing at all.
 *
 * This component only renders what `useAssistantChildren.ts` gives it and
 * forwards `accept`/`dismiss`/`cancel` untouched; the guardrail (D24) lives in
 * the composable's `acceptAssistantSuggestion`, not here. Text throughout —
 * `{{ }}`, never `v-html` — there is no HTML renderer in this codebase for
 * generated output and none is introduced here.
 */
import AssistantSuggestions from '@/Components/Assistant/AssistantSuggestions.vue';
import GenerationProgress from '@/Components/Assistant/GenerationProgress.vue';
import type { AssistantSuggestion } from '@/stores/assessmentStore';

defineProps<{
    asking: boolean;
    error: string | null;
    items: AssistantSuggestion[] | null;
    childType: 'risk' | 'attack' | 'defence';
    accepted: number[];
    elapsedLabel: string;
    percent: number;
    tokens: number;
    expected: number;
    cancelling: boolean;
}>();

const emit = defineEmits<{
    accept: [suggestion: AssistantSuggestion, index: number];
    dismiss: [];
    cancel: [];
}>();
</script>

<template>
    <div>
        <!-- W58. While it is running: elapsed time, a bar against the token
             budget, and a Stop that stops the generation rather than only
             the waiting. -->
        <div v-if="asking">
            <GenerationProgress
                label="Suggesting"
                :elapsed-label="elapsedLabel"
                :percent="percent"
                :tokens="tokens"
                :expected="expected"
                :cancelling="cancelling"
                @cancel="emit('cancel')"
            />
        </div>

        <!-- W52. Appears because somebody asked; nothing is unsolicited, and
             nothing reaches the graph until each item is accepted by hand. -->
        <p
            v-if="error"
            class="text-node-attack-text text-xs"
            data-testid="assistant-suggest-error"
            role="alert"
        >
            {{ error }}
        </p>

        <AssistantSuggestions
            v-if="items"
            :suggestions="items"
            :child-type="childType"
            :accepted="accepted"
            :busy="asking"
            @accept="(suggestion, index) => emit('accept', suggestion, index)"
            @dismiss="emit('dismiss')"
        />
    </div>
</template>
