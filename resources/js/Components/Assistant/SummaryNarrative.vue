<script setup lang="ts">
/**
 * The report's written executive summary, and job C's draft (W54).
 *
 * ## Three states, and only one of them involves a model
 *
 * The narrative is an ordinary field. Somebody types a few paragraphs at the
 * top of their report and that is the whole feature on the great majority of
 * deployments, which have no inference runtime at all (D25). The Draft action
 * appears only where one exists; nothing else on this component changes.
 *
 * ## A draft is never the document
 *
 * When one arrives it is shown in its own panel, visibly separate, with the
 * live narrative still in place beside it. Accepting opens it **in the
 * editor** rather than writing it to the report — the person's next action is
 * to read and edit, and the save is a deliberate second click.
 *
 * That is not fussiness about model quality. The spike measured that text
 * injected into a node description reaches the output on models that resist
 * every direct instruction to misbehave, and one of three candidates carried a
 * planted string into the summary prose. Job C's output is attributed to the
 * author and read by a client. **The person reading the draft is the
 * guardrail** (D24); everything else is behind them.
 *
 * ## Text, never markup
 *
 * `{{ }}` and `white-space: pre-line`, never `v-html`. Model output is the
 * last thing that should reintroduce a user-authored-HTML renderer.
 */
import GenerationProgress from '@/Components/Assistant/GenerationProgress.vue';
import Button from '@/Components/UI/Button.vue';
import { useGenerationProgress } from '@/composables/useGenerationProgress';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { router } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    assessmentUuid: string;
    narrative: string | null;
    generated: boolean;
    /** Withheld from anyone who could not accept it — see ReportController. */
    draft: string | null;
    canEdit: boolean;
    assistantAvailable: boolean;
}>();

const narrative = ref(props.narrative);
const generated = ref(props.generated);
const draft = ref(props.draft);

const editing = ref(false);
const text = ref('');
useUnsavedChanges(
    () => editing.value && text.value !== (narrative.value ?? ''),
);
/** Whether what is in the editor started life as a model's draft. */
const fromDraft = ref(false);

const saving = ref(false);
const drafting = ref(false);
const message = ref<string | null>(null);
const error = ref<string | null>(null);

const hasNarrative = computed(
    () => narrative.value !== null && narrative.value.trim() !== '',
);

function edit(seed: string | null, cameFromDraft: boolean) {
    text.value = seed ?? '';
    fromDraft.value = cameFromDraft;
    editing.value = true;
    error.value = null;
    message.value = null;
}

function cancel() {
    editing.value = false;
    fromDraft.value = false;
}

async function save() {
    saving.value = true;
    error.value = null;

    try {
        const response = await window.axios.put<{
            narrative: string | null;
            generated: boolean;
            draft: string | null;
        }>(route('assessments.summary.update', props.assessmentUuid), {
            narrative: text.value,
            from_draft: fromDraft.value,
        });

        narrative.value = response.data.narrative;
        generated.value = response.data.generated;
        draft.value = response.data.draft;
        editing.value = false;
        fromDraft.value = false;
    } catch (e: unknown) {
        error.value = messageFrom(e) ?? 'The summary could not be saved.';
    } finally {
        saving.value = false;
    }
}

async function discardDraft() {
    try {
        await window.axios.delete(
            route('assessments.summary.draft.destroy', props.assessmentUuid),
        );
        draft.value = null;
    } catch (e: unknown) {
        error.value = messageFrom(e) ?? 'The draft could not be discarded.';
    }
}

/**
 * W58. Job C runs on a queue for minutes — 249 seconds for 73 nodes, measured
 * — so between pressing Draft and the notification arriving there was nothing
 * on screen at all. This polls the assessment's generation, which is the right
 * scope: the draft belongs to the assessment, so a collaborator who did not
 * press the button can still see that one is running and still stop it.
 */
const progress = useGenerationProgress({
    progressUrl: route(
        'assessments.assistant.summary.progress',
        props.assessmentUuid,
    ),
    cancelUrl: route(
        'assessments.assistant.summary.cancel',
        props.assessmentUuid,
    ),
});

/** True from the moment it is queued until the poll says it has stopped. */
const inFlight = computed(
    () => progress.running.value || progress.queued.value,
);

/**
 * A draft that lands while the page is open. Inertia re-renders with the new
 * prop after the reload below; without this watch the component would keep
 * showing the value it copied at setup, which is the one it had before the
 * generation ran.
 */
watch(
    () => props.draft,
    (value) => {
        draft.value = value;
    },
);

// A draft can already be running when this page loads — somebody refreshed
// mid-generation, or a collaborator opened the report while it works. Probed
// once rather than polled unconditionally, so an idle report page makes no
// repeating request.
onMounted(async () => {
    if (props.assistantAvailable && props.canEdit && (await progress.probe())) {
        progress.start();
    }
});

// When the generation stops, ask the server for the page's props again — the
// draft is written by a worker in another process, so nothing on this page
// knows about it otherwise. Reloading only on the *transition* matters: a
// reload every poll would recompute the whole report every two seconds.
watch(inFlight, (running, was) => {
    if (was && !running) {
        progress.stop();
        router.reload({ only: ['assessment'] });
    }
});

async function requestDraft() {
    drafting.value = true;
    error.value = null;
    message.value = null;

    try {
        const response = await window.axios.post<{ message: string }>(
            route('assessments.assistant.summary', props.assessmentUuid),
        );

        progress.start();
        // Nothing to show yet: this returns in milliseconds and the work takes
        // minutes. The notification is what says it is ready, so the only
        // honest thing to put on screen now is that it was accepted.
        message.value = response.data.message;
    } catch (e: unknown) {
        error.value = messageFrom(e) ?? 'The assistant could not be reached.';
    } finally {
        drafting.value = false;
    }
}

function messageFrom(e: unknown): string | null {
    return (
        (e as { response?: { data?: { message?: string } } } | undefined)
            ?.response?.data?.message ?? null
    );
}
</script>

<template>
    <section
        v-if="hasNarrative || canEdit"
        class="report-section break-inside-avoid"
        data-testid="summary-narrative"
    >
        <div class="flex items-baseline justify-between gap-4">
            <!--
                "Written summary", not "Executive summary" (W67). The report
                already has a section by that name — ExecutiveSummarySection,
                the three baseline figures, which PLAN.md Phase 7 §2 names — and
                two sibling sections carrying the same accessible name give a
                reader navigating by heading the same label twice with no way
                to tell which is which. Found by the end-to-end run, as a strict
                mode violation on a selector that had matched one element when
                it was written.
            -->
            <h3 class="text-text-primary text-sm font-semibold">
                Written summary
            </h3>

            <div v-if="canEdit && !editing" class="no-print flex gap-2">
                <Button
                    v-if="assistantAvailable && draft === null"
                    variant="secondary"
                    :loading="drafting"
                    data-testid="request-draft"
                    @click="requestDraft"
                >
                    Draft with the assistant
                </Button>
                <Button
                    variant="secondary"
                    data-testid="edit-narrative"
                    @click="edit(narrative, false)"
                >
                    {{ hasNarrative ? 'Edit' : 'Write one' }}
                </Button>
            </div>
        </div>

        <p
            v-if="hasNarrative"
            class="text-text-primary mt-3 whitespace-pre-line"
        >
            {{ narrative }}
        </p>

        <p
            v-else-if="!editing"
            class="text-text-muted no-print mt-3 text-sm"
            data-testid="narrative-empty"
        >
            No written summary yet. The figures below stand on their own, but a
            few paragraphs in your own words are what most readers start with.
        </p>

        <!--
            The disclosure at the point of use. It prints, unlike every control
            on this component: a reader holding a PDF must be able to see it,
            and the methodology appendix says the same thing at length.
        -->
        <p
            v-if="hasNarrative && generated"
            class="text-text-muted mt-2 text-xs"
            data-testid="generated-mark"
        >
            Drafted by a local model and edited by the author, who is
            responsible for its contents.
        </p>

        <div
            v-if="editing"
            class="no-print border-border-default bg-surface-1 mt-3 rounded-lg border p-3"
            data-testid="narrative-editor"
        >
            <p
                v-if="fromDraft"
                class="text-text-muted mb-2 text-xs"
                data-testid="editing-draft-note"
            >
                This is the model's draft. Read it, change what is wrong, and
                save it when it says what you mean. Saving marks the summary as
                model-drafted in the report's methodology appendix.
            </p>

            <textarea
                v-model="text"
                rows="10"
                maxlength="8000"
                class="border-border-default bg-surface-0 text-text-primary focus-visible:outline-focus-ring w-full rounded-md border px-3 py-2 text-sm outline-none focus-visible:outline-2"
            ></textarea>

            <div class="mt-2 flex gap-2">
                <Button
                    variant="primary"
                    :loading="saving"
                    data-testid="save-narrative"
                    @click="save"
                >
                    Save
                </Button>
                <Button variant="secondary" @click="cancel">Cancel</Button>
            </div>
        </div>

        <div
            v-if="draft !== null && canEdit && !editing"
            class="no-print border-border-default bg-surface-1 mt-4 rounded-lg border p-3"
            data-testid="summary-draft"
        >
            <p
                class="text-text-muted mb-2 text-xs font-medium tracking-wide uppercase"
            >
                Drafted by a local model — not in the report yet
            </p>

            <!-- Text, never v-html. See this component's docblock. -->
            <p class="text-text-primary text-sm whitespace-pre-line">
                {{ draft }}
            </p>

            <div class="mt-3 flex gap-2">
                <Button
                    variant="primary"
                    data-testid="use-draft"
                    @click="edit(draft, true)"
                >
                    Edit and use
                </Button>
                <Button
                    variant="secondary"
                    data-testid="discard-draft"
                    @click="discardDraft"
                >
                    Discard
                </Button>
            </div>
        </div>

        <!-- W58. Between "Drafting" and the notification there used to be
             nothing on screen for four minutes. `no-print`: a running
             generation is not part of the document. -->
        <div v-if="inFlight" class="no-print mt-3">
            <GenerationProgress
                label="Drafting the summary"
                :waiting="progress.queued.value && !progress.running.value"
                :elapsed-label="progress.elapsedLabel.value"
                :percent="progress.percent.value"
                :tokens="progress.tokens.value"
                :expected="progress.expected.value"
                :cancelling="progress.cancelling.value"
                @cancel="progress.cancel()"
            />
        </div>

        <p
            v-else-if="message"
            class="text-text-muted no-print mt-3 text-sm"
            data-testid="draft-queued"
        >
            {{ message }}
        </p>

        <p
            v-if="error"
            class="text-node-attack-text no-print mt-3 text-sm"
            data-testid="narrative-error"
            role="alert"
        >
            {{ error }}
        </p>
    </section>
</template>
