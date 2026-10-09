import { useGenerationProgress } from '@/composables/useGenerationProgress';
import type {
    AssessmentStore,
    AssistantSuggestion,
} from '@/stores/assessmentStore';
import { ref, type ComputedRef } from 'vue';

/**
 * Ask the assistant for children of a node, and accept them one at a time
 * (W52, job B).
 *
 * Extracted in W85/D50 so the tree card and the chart card share one
 * implementation instead of the chart growing a second copy that can drift
 * from the first — verbatim duplication of this block is exactly what D50's
 * ruling forbids. `NodeCard.vue` owned this outright before the extraction;
 * `ChartCard.vue` had no assistant trigger at all (D50's own complaint: "the
 * string `assistant` appears zero times").
 *
 * Named `assistant*` throughout and never `suggest*` — a caller may also have
 * (or grow) a `suggesting`/`SuggestionList` pair from W40, which means
 * something unrelated: finding an *existing* node to link, not generating a
 * new one. Two notions of "suggestion" one scroll apart is how the wrong one
 * gets edited (see `NodeCard.vue`'s own warning about this).
 *
 * ## The guardrail lives here (PLAN-2.3-ai.md D24)
 *
 * `acceptAssistantSuggestion` is the accept step, and it is the guardrail
 * itself, not a safety net behind one: the spike measured injected text
 * reaching a model's output on 3 of 3 usable models, and `title` is a field
 * the accept path legitimately reads, so nothing downstream can tell an
 * injected suggestion from a genuine one. It goes through `addChild` — the
 * same validated write path a hand-typed node takes — once per suggestion, by
 * a person who has read it. There is deliberately no "accept all".
 */
export function useAssistantChildren(
    store: AssessmentStore,
    uuid: ComputedRef<string>,
    /** `undefined` when the node has not loaded yet, or is a type that cannot have children. */
    nodeType: ComputedRef<'root' | 'risk' | 'attack' | 'defence' | undefined>,
    /**
     * How many to ask for. The card asks for a list to pick from; the
     * new-node form asks for one (2.5.1), which the server also gives a
     * smaller generation budget — the number asked for is most of the wait.
     */
    count = 5,
) {
    const assistantItems = ref<AssistantSuggestion[] | null>(null);
    const assistantChildType = ref<'risk' | 'attack' | 'defence'>('attack');
    const assistantAsking = ref(false);
    const assistantError = ref<string | null>(null);
    const assistantAccepted = ref<number[]>([]);

    /**
     * W58. A suggestion measured 28 seconds on a workstation and will be
     * slower on a host that gives inference four cores, so the button's
     * spinner is not enough on its own: this polls how far the generation
     * has got and offers a Stop that actually stops it, rather than one that
     * merely stops watching.
     */
    const assistantProgress = useGenerationProgress({
        progressUrl: route('assistant.generation.show'),
        cancelUrl: route('assistant.generation.destroy'),
    });

    async function askAssistant() {
        if (nodeType.value === undefined || assistantAsking.value) return;

        assistantAsking.value = true;
        assistantError.value = null;
        assistantAccepted.value = [];
        assistantProgress.start();

        try {
            const result = await store.suggestChildren(uuid.value, count);

            // Stopped by the user. Close the panel rather than showing an
            // empty one that reads as "the model had nothing to say".
            if (result.cancelled) {
                assistantItems.value = null;

                return;
            }

            assistantChildType.value = result.childType as
                'risk' | 'attack' | 'defence';
            assistantItems.value = result.suggestions;
        } catch (e: unknown) {
            // The server's own sentence where there is one — "you already
            // have a suggestion running", "the assistant is switched off" —
            // because each names a different thing to do next.
            assistantError.value =
                (
                    e as
                        | { response?: { data?: { message?: string } } }
                        | undefined
                )?.response?.data?.message ??
                'The assistant could not be reached.';
        } finally {
            assistantAsking.value = false;
            assistantProgress.stop();
        }
    }

    async function acceptAssistantSuggestion(
        suggestion: AssistantSuggestion,
        index: number,
    ) {
        if (nodeType.value === undefined) return;

        await store.addChild(
            nodeType.value as 'root' | 'risk' | 'attack',
            uuid.value,
            suggestion,
        );

        assistantAccepted.value = [...assistantAccepted.value, index];
    }

    function dismissAssistant() {
        assistantItems.value = null;
    }

    return {
        assistantItems,
        assistantChildType,
        assistantAsking,
        assistantError,
        assistantAccepted,
        assistantProgress,
        askAssistant,
        acceptAssistantSuggestion,
        dismissAssistant,
    };
}
