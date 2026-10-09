<script setup lang="ts">
/**
 * Job A's single action (W51, PLAN-2.3-ai.md).
 *
 * No chat bubble, no floating button, no unsolicited suggestion — the plan is
 * explicit that the assistant appears as **one action in the place where it
 * helps**, and for job A that place is the top of the help index, where
 * someone is already looking for an answer.
 *
 * Three things this deliberately does:
 *
 * - **Says the first answer is slow, before it is asked.** On a CPU the first
 *   question of a session costs ~90s because the documentation has to be read
 *   into the model's cache; every question after it costs a few seconds. A
 *   spinner that gives no hint of that reads as a hang.
 * - **Marks the answer as generated, at the point of use.** The reader must
 *   never have to remember which text on their screen came from a model.
 * - **Renders the answer as text.** `{{ }}`, never `v-html`: W30 deleted this
 *   codebase's only user-authored-HTML renderer, and model output is the last
 *   thing that should bring one back.
 */
import GenerationProgress from '@/Components/Assistant/GenerationProgress.vue';
import Button from '@/Components/UI/Button.vue';
import { useGenerationProgress } from '@/composables/useGenerationProgress';
import { computed, ref } from 'vue';

const props = defineProps<{
    /**
     * From `HelpController`'s `assistant` page prop, which asks `Availability`
     * for the **help** feature by name (W87, D48) — not from
     * `/assistant/status`, which answers for the assistant in general and
     * would offer this control on an instance that has switched help off.
     *
     * The control is not rendered without it.
     */
    available: boolean;
    unavailableReason?: string | null;
}>();

const question = ref('');
const answer = ref<string | null>(null);
const error = ref<string | null>(null);
const asking = ref(false);
/** Whether this session has already paid the cache-warming cost. */
const asked = ref(false);

const canAsk = computed(
    () => props.available && question.value.trim().length >= 3 && !asking.value,
);

/**
 * W58. The first question of a session is the worst wait in the whole product
 * — the entire corpus has to be processed before a single token comes back,
 * measured at 94 seconds — and it is the one place a person is most likely to
 * decide the feature is broken. So the wait is narrated, and it can be ended.
 */
const progress = useGenerationProgress({
    progressUrl: route('assistant.generation.show'),
    cancelUrl: route('assistant.generation.destroy'),
});

async function ask() {
    if (!canAsk.value) return;

    asking.value = true;
    error.value = null;
    answer.value = null;
    progress.start();

    try {
        // `window.axios`, as every other request in this codebase does: it is
        // configured in bootstrap.ts and carries the XSRF cookie Laravel
        // expects. A hand-rolled `fetch` would need a CSRF token from a meta
        // tag this application does not render.
        const response = await window.axios.post<{
            answer?: string;
            cancelled?: boolean;
        }>(route('assistant.help'), { question: question.value.trim() });

        // A stopped answer comes back 200 with `{cancelled: true}` (W58). The
        // person asked for that, so there is nothing to say about it: no
        // answer, no error, the box goes back to how it was.
        if (response.data.cancelled) return;

        answer.value = response.data.answer ?? null;
        asked.value = true;
    } catch (e: unknown) {
        // The server's own sentence where there is one — "you already have a
        // question being answered", "the assistant is switched off" — because
        // each names a different thing to do next.
        const message = (
            e as { response?: { data?: { message?: string } } } | undefined
        )?.response?.data?.message;

        error.value = message ?? 'The assistant could not be reached.';
    } finally {
        asking.value = false;
        progress.stop();
    }
}
</script>

<template>
    <section
        v-if="available"
        class="border-border-default bg-surface-1 mt-6 rounded-lg border p-4"
        data-testid="ask-assistant"
    >
        <label
            for="assistant-question"
            class="text-text-primary block text-sm font-medium"
        >
            Ask a question about ArgueSecure
        </label>

        <div class="mt-2 flex gap-2">
            <input
                id="assistant-question"
                v-model="question"
                type="text"
                maxlength="500"
                placeholder="What is the difference between a defence and a transfer?"
                class="border-border-default bg-surface-0 text-text-primary focus-visible:outline-focus-ring flex-1 rounded-md border px-3 py-2 text-sm outline-none focus-visible:outline-2"
                @keydown.enter.prevent="ask"
            />
            <Button
                variant="primary"
                :disabled="!canAsk"
                :loading="asking"
                @click="ask"
                >Ask</Button
            >
        </div>

        <p class="text-text-muted mt-2 text-xs">
            Answered on this server by a local model, from the help pages below.
            Nothing leaves the machine.
            <!--
                2.6 (D57): the help pages are read into the model when its
                server starts, so the slow first answer is now the one asked
                in the minute or two after a restart, not every session's
                first. Still said up front, because that wait is real and a
                spinner with no explanation reads as a hang.
            -->
            <span v-if="!asked" data-testid="first-answer-warning">
                Answers usually take a few seconds; just after the server
                restarts, the first can take a minute or two.
            </span>
        </p>

        <div v-if="asking" class="mt-3">
            <GenerationProgress
                label="Reading the help pages"
                :elapsed-label="progress.elapsedLabel.value"
                :percent="progress.percent.value"
                :tokens="progress.tokens.value"
                :expected="progress.expected.value"
                :cancelling="progress.cancelling.value"
                @cancel="progress.cancel()"
            />
        </div>

        <p
            v-if="error"
            class="text-node-attack-text mt-3 text-sm"
            data-testid="assistant-error"
            role="alert"
        >
            {{ error }}
        </p>

        <div
            v-if="answer"
            class="border-border-subtle bg-surface-0 mt-3 rounded-md border p-3"
            data-testid="assistant-answer"
        >
            <p
                class="text-text-muted mb-2 text-xs font-medium tracking-wide uppercase"
            >
                Generated answer — check it against the pages below
            </p>
            <!-- Text, never v-html. See this component's docblock. -->
            <p class="text-text-primary text-sm whitespace-pre-line">
                {{ answer }}
            </p>
        </div>
    </section>

    <p
        v-else-if="unavailableReason"
        class="text-text-muted mt-6 text-xs"
        data-testid="assistant-unavailable"
    >
        {{ unavailableReason }}
    </p>
</template>
