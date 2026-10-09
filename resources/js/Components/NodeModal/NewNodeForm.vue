<script setup lang="ts">
/**
 * The node modal's body for a node that does not exist yet (2.5.1).
 *
 * ## Why this exists
 *
 * "Add child" used to create an empty node on the server and then open the
 * modal on it. Three things followed from that, and all three were reported:
 *
 * 1. An untitled node appeared on the board behind the modal the moment the
 *    button was pressed, and stayed there if the modal was abandoned in any
 *    way the empty-title sweep did not catch.
 * 2. Every collaborator was told "someone added a node" before it had a name.
 * 3. About a second later the author's own `node.created` broadcast arrived
 *    and *replaced* the store record the open Details tab was bound to, with
 *    a payload that carried no `tags` — `node.tags.map` then threw inside the
 *    tab's render and its content vanished, while the chart re-rendered the
 *    untitled card.
 *
 * W88 fixed the store's own write overlap and pinned (3) as item 22b; the
 * pin's two candidate fixes both addressed the echo and neither addressed
 * (1) or (2), which are the actual complaint: **no node should exist until a
 * person has said what it is.** So nothing here writes until Add is pressed.
 * The echo is fixed as well, separately (NodeCreated/NodeUpdated now skip the
 * originating socket), because an existing node's Details tab could still be
 * overwritten by it.
 *
 * ## What survived from the placeholder flow
 *
 * - **"Link, don't duplicate" (W40/W79).** Typing a title for an attack or a
 *   defence offers existing nodes of that type not already under this parent;
 *   choosing one links it and closes the modal. There is no placeholder to
 *   delete any more, which removes the ordering hazard W79 documented.
 * - **Ask the assistant (W88 item 23).** Offered while the title is empty.
 *   Since 2.5.1 it asks for one suggestion and puts it straight into the
 *   fields — it is not written anywhere — so the person still reads it and
 *   presses Add (D24: the accept step is the guardrail). Output renders as
 *   text only: it lands in an input and a textarea, and AssistantPanel shows
 *   only progress and errors here.
 *
 * ## From a template (2.5.1)
 *
 * The same shape as the assistant's button, fed from a vetted template
 * instead of a model: TemplatePicker in `single` mode, and Use fills the
 * fields. Both buttons go away once the person types a title of their own
 * (ui.md §7, "Three offers on an empty title").
 */
import AssistantPanel from '@/Components/Node/AssistantPanel.vue';
import TemplatePicker from '@/Components/Node/TemplatePicker.vue';
import { useAssistantChildren } from '@/Components/Node/useAssistantChildren';
import {
    useTemplateChildren,
    type TemplateNodeItem,
} from '@/Components/Node/useTemplateChildren';
import SuggestionList from '@/Components/Tree/SuggestionList.vue';
import {
    useNodeSuggestions,
    type NodeSuggestion,
} from '@/Components/Tree/useNodeSuggestions';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import SegmentedControl from '@/Components/UI/SegmentedControl.vue';
import Toggle from '@/Components/UI/Toggle.vue';
import { NODE_TYPES } from '@/constants/nodeTypes';
import type {
    AssessmentStore,
    AssistantSuggestion,
    DefenceStatus,
} from '@/stores/assessmentStore';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { useLinkMutations } from './useLinkMutations';
import type { NodeDraft } from './useNodeModal';

const props = defineProps<{
    store: AssessmentStore;
    draft: NodeDraft;
}>();

const emit = defineEmits<{
    /** The node now exists; the modal switches to it. */
    created: [uuid: string];
    /** Linked an existing node instead, or abandoned — nothing to show. */
    done: [];
}>();

const title = ref('');
const description = ref('');
const status = ref<DefenceStatus>('todo');
const isTransfer = ref(false);
const saving = ref(false);
const error = ref<string | null>(null);

const typeLabel = computed(() => NODE_TYPES[props.draft.childType].label);
const canSubmit = computed(() => title.value.trim() !== '' && !saving.value);

const root = ref<HTMLElement | null>(null);
onMounted(() => {
    void nextTick(() => root.value?.querySelector('input')?.focus());
});

async function submit() {
    if (!canSubmit.value) return;

    saving.value = true;
    error.value = null;

    try {
        const uuid = await props.store.addChild(
            props.draft.parentType,
            props.draft.parentUuid,
            {
                title: title.value.trim(),
                description:
                    description.value.trim() === '' ? null : description.value,
                ...(props.draft.childType === 'defence'
                    ? { status: status.value, is_transfer: isTransfer.value }
                    : {}),
            },
        );
        emit('created', uuid);
    } catch (e: unknown) {
        error.value =
            (e as { response?: { data?: { message?: string } } } | undefined)
                ?.response?.data?.message ??
            `Could not add this ${typeLabel.value.toLowerCase()}.`;
    } finally {
        saving.value = false;
    }
}

// --- link, don't duplicate (W40/W79) ------------------------------------------

const linkMutations = useLinkMutations(props.store);
const nodeSuggestions = useNodeSuggestions(props.store);
const suggestionItems = nodeSuggestions.suggestions;
const suggestionIndex = nodeSuggestions.activeIndex;

const suggestableType = computed(() =>
    props.draft.childType === 'attack' || props.draft.childType === 'defence'
        ? props.draft.childType
        : null,
);

function onTitleInput(value: string) {
    title.value = value;
    if (!suggestableType.value) return;
    nodeSuggestions.search(
        suggestableType.value,
        props.draft.parentUuid,
        value,
    );
}

async function chooseSuggestion(suggestion: NodeSuggestion) {
    nodeSuggestions.reset();
    error.value = null;

    try {
        if (suggestableType.value === 'attack') {
            await linkMutations.linkRiskToAttack(
                props.draft.parentUuid,
                suggestion.uuid,
            );
        } else if (suggestableType.value === 'defence') {
            await linkMutations.linkAttackToDefence(
                props.draft.parentUuid,
                suggestion.uuid,
            );
        }
        emit('done');
    } catch {
        error.value = `Could not link '${suggestion.title}' here.`;
    }
}

function onTitleKeydown(event: KeyboardEvent) {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        if (suggestionItems.value.length === 0) return;
        event.preventDefault();
        nodeSuggestions.move(event.key === 'ArrowDown' ? 1 : -1);

        return;
    }

    if (event.key === 'Enter') {
        event.preventDefault();
        const chosen = nodeSuggestions.highlighted();
        if (chosen) void chooseSuggestion(chosen);
        else void submit();

        return;
    }

    // Escape closes the list first; with no list open it reaches the modal,
    // which abandons the draft.
    if (event.key === 'Escape' && suggestionItems.value.length > 0) {
        event.preventDefault();
        event.stopPropagation();
        nodeSuggestions.reset();
    }
}

// --- where the words came from ---------------------------------------------------

/**
 * Set when the form was filled for the person rather than by them, so the form
 * can say so (the D24 rule that the reader must never have to remember which
 * words on screen a model wrote applies to the assistant; the template note is
 * the same courtesy). Cleared the moment they empty the title and start over.
 */
const filledFrom = ref<
    { kind: 'assistant' } | { kind: 'template'; name: string } | null
>(null);

/**
 * Fills the form and nothing else — never saves. Runs the "link, don't
 * duplicate" search on the new title too, so a template control that already
 * exists here is offered as a link rather than silently copied twice.
 */
function fill(newTitle: string, newDescription: string | null) {
    title.value = newTitle;
    description.value = newDescription ?? '';
    if (suggestableType.value) {
        nodeSuggestions.search(
            suggestableType.value,
            props.draft.parentUuid,
            newTitle,
        );
    }
}

// Offered only while the title is empty: once the person is writing their own,
// a button that would replace it is in the way (2.5.1).
const titleEmpty = computed(() => title.value.trim() === '');

watch(titleEmpty, (empty) => {
    if (empty) filledFrom.value = null;
});

// --- ask the assistant for one (W88 item 23, D24; 2.5.1) --------------------------

const assistantParentType = computed<'root' | 'risk' | 'attack' | undefined>(
    () =>
        props.store.abilities.update && props.store.assistantAvailable
            ? props.draft.parentType
            : undefined,
);

// One, not five: this form makes one node, and the count is most of the wait.
const {
    assistantItems,
    assistantChildType,
    assistantAsking,
    assistantError,
    assistantProgress,
    askAssistant,
    dismissAssistant,
} = useAssistantChildren(
    props.store,
    computed(() => props.draft.parentUuid),
    assistantParentType,
    1,
);

const assistantEmpty = ref(false);

/**
 * The suggestion goes straight into the form — there is only one, so a list
 * to accept it from would be a click with nothing to decide. The accept step
 * D24 requires is still there: it is the Add button, pressed by a person who
 * can see the text and a note saying a model wrote it. Only `title` and
 * `description` are read (D23). If the person started typing while it ran,
 * their words win and the suggestion is dropped.
 */
watch(assistantItems, (items: AssistantSuggestion[] | null) => {
    if (items === null) return;

    const first = items[0];
    assistantEmpty.value = first === undefined;
    if (first !== undefined && titleEmpty.value) {
        fill(first.title, first.description ?? null);
        filledFrom.value = { kind: 'assistant' };
    }
    dismissAssistant();
});

function askOne() {
    assistantEmpty.value = false;
    closeTemplates();

    return askAssistant();
}

// --- from a template (2.5.1) ------------------------------------------------------

const {
    templateOpen,
    templates,
    templateUuid,
    templateItems,
    templateLoading,
    templateError,
    templateChildType,
    openTemplates,
    chooseTemplate,
    closeTemplates,
} = useTemplateChildren(
    props.store,
    computed(() => props.draft.parentUuid),
    computed(() => props.draft.parentType),
);

function takeFromTemplate(item: TemplateNodeItem) {
    const name =
        templates.value?.find((t) => t.uuid === templateUuid.value)?.title ??
        'a template';

    fill(item.title, item.description);
    if (props.draft.childType === 'defence')
        isTransfer.value = item.is_transfer;
    filledFrom.value = { kind: 'template', name };
    closeTemplates();
}

const offerHelpers = computed(
    () =>
        props.store.abilities.update &&
        titleEmpty.value &&
        !assistantAsking.value &&
        !templateOpen.value,
);

watch(
    () => props.draft,
    () => {
        dismissAssistant();
        closeTemplates();
        nodeSuggestions.reset();
        filledFrom.value = null;
    },
);

// A debounced search still pending when the form goes away would land its
// results nowhere useful — and in a test, in the next test's mock.
onBeforeUnmount(() => nodeSuggestions.reset());

const statusOptions: { value: DefenceStatus; label: string }[] = [
    { value: 'implemented', label: 'Implemented' },
    { value: 'todo', label: 'Todo' },
    { value: 'shelved', label: 'Shelved' },
];
</script>

<template>
    <form
        ref="root"
        class="flex flex-col gap-4 p-4"
        data-testid="new-node-form"
        @submit.prevent="submit"
    >
        <div class="relative">
            <Input
                label="Title"
                :model-value="title"
                :placeholder="`Name this ${typeLabel.toLowerCase()}`"
                :role="suggestableType ? 'combobox' : undefined"
                :expanded="
                    suggestableType ? suggestionItems.length > 0 : undefined
                "
                data-testid="new-node-title"
                @update:model-value="onTitleInput"
                @keydown="onTitleKeydown"
            />
            <SuggestionList
                v-if="suggestableType"
                :suggestions="suggestionItems"
                :active-index="suggestionIndex"
                :child-type="suggestableType"
                @choose="chooseSuggestion"
            />
        </div>

        <!-- 2.5.1: two ways to start from something other than a blank page.
             Both fill the form and save nothing; both go away once the
             person starts writing a title of their own. -->
        <div
            v-if="offerHelpers"
            class="-mt-2 flex flex-wrap items-center gap-2"
            data-testid="new-node-helpers"
        >
            <Button
                size="sm"
                variant="secondary"
                icon="copy"
                data-testid="new-node-from-template"
                @click="openTemplates"
            >
                From template
            </Button>
            <Button
                v-if="assistantParentType !== undefined"
                size="sm"
                variant="secondary"
                icon="square-plus"
                data-testid="details-ask-assistant"
                @click="askOne"
            >
                AI suggestion
            </Button>
            <span
                class="text-text-muted text-xs"
                data-testid="details-ask-assistant-hint"
            >
                Fills in the title and description; nothing is added until you
                press Add.
            </span>
        </div>

        <TemplatePicker
            v-if="templateOpen && titleEmpty"
            mode="single"
            :child-type="templateChildType"
            :templates="templates"
            :template-uuid="templateUuid"
            :items="templateItems"
            :loading="templateLoading"
            :error="templateError"
            @choose="chooseTemplate"
            @take="takeFromTemplate"
            @dismiss="closeTemplates"
        />

        <!-- Progress and errors only: the one suggestion goes straight into
             the fields above, so there is no list to accept it from. -->
        <AssistantPanel
            v-if="assistantAsking || assistantError"
            :asking="assistantAsking"
            :error="assistantError"
            :items="null"
            :child-type="assistantChildType"
            :accepted="[]"
            :elapsed-label="assistantProgress.elapsedLabel.value"
            :percent="assistantProgress.percent.value"
            :tokens="assistantProgress.tokens.value"
            :expected="assistantProgress.expected.value"
            :cancelling="assistantProgress.cancelling.value"
            @cancel="assistantProgress.cancel()"
        />
        <p
            v-if="assistantEmpty && titleEmpty"
            class="text-text-muted text-xs"
            data-testid="assistant-empty"
        >
            The assistant did not return anything usable. Try again, or write
            one yourself.
        </p>

        <p
            v-if="filledFrom"
            class="border-border-subtle bg-surface-2 text-text-secondary -mt-2 rounded border px-2 py-1 text-xs"
            data-testid="new-node-filled-from"
        >
            <template v-if="filledFrom.kind === 'assistant'"
                >Written by the assistant, a local model. Read it — and change
                it — before you press Add.</template
            >
            <template v-else
                >Copied from the “{{ filledFrom.name }}” template. Edit it to
                fit this assessment before you press Add.</template
            >
        </p>

        <div>
            <label class="text-text-primary mb-1 block text-sm font-medium"
                >Description</label
            >
            <textarea
                v-model="description"
                rows="4"
                maxlength="500"
                placeholder="What this is, in a sentence or two — shown under the node and in reports"
                class="border-border-default bg-surface-1 text-text-primary focus:border-focus-ring focus:ring-focus-ring block w-full rounded-md text-sm shadow-sm"
                data-testid="new-node-description"
            />
            <p class="text-text-muted mt-1 text-xs">Up to 500 characters.</p>
        </div>

        <template v-if="draft.childType === 'defence'">
            <div>
                <label class="text-text-primary mb-1 block text-sm font-medium"
                    >Status</label
                >
                <SegmentedControl
                    :model-value="status"
                    :options="statusOptions"
                    @update:model-value="
                        (value: string) => (status = value as DefenceStatus)
                    "
                />
            </div>
            <Toggle
                v-model="isTransfer"
                label="This is a transfer (shifts the risk/loss, e.g. insurance or a contract clause)"
            />
        </template>

        <p
            v-if="error"
            class="text-node-attack-text text-sm"
            role="alert"
            data-testid="new-node-error"
        >
            {{ error }}
        </p>

        <div class="flex items-center justify-end gap-2">
            <Button
                variant="secondary"
                data-testid="new-node-cancel"
                @click="emit('done')"
            >
                Cancel
            </Button>
            <Button
                type="submit"
                :disabled="!canSubmit"
                :loading="saving"
                data-testid="new-node-submit"
            >
                Add {{ typeLabel.toLowerCase() }}
            </Button>
        </div>
    </form>
</template>
