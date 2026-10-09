<script setup lang="ts">
/**
 * Details tab (ui.md §7): title and description. Commits through
 * store.updateNode() on blur — the same autosave-with-409-handling path
 * NodeCard.vue's inline title editing already uses, so a conflict here
 * surfaces via the same toolbar indicator and Show.vue's existing
 * "Keep mine / Take theirs / Cancel" banner (ui.md §3.5).
 *
 * W30 removed the notes field, and with it `notesPreview.ts` — the
 * client-side markdown renderer that existed only to preview it. Nothing on
 * this screen renders user-authored HTML any more, which is why deleting
 * that module (it carried a documented sanitisation fix) is safe rather
 * than a regression: comments still render markdown, but server-side
 * through MarkdownRenderer, which the source audit test pins.
 *
 * W43 added the labels row that ui.md §7 has listed since W13 and that W13
 * could not build: `tags`/`node_tag` existed with no owning controller, and
 * that package owned no path that could add one, so the field was left out
 * rather than built against nothing.
 *
 * W72 changed what else lives here, in both directions:
 *
 * - **A defence's status and transfer flag arrived** from the Cost tab (item
 *   9). Neither is a cost, and a tab called Cost that opened on a segmented
 *   control was answering a different question from the one its label asks.
 *   Both are statements about what this defence *is* and where it has got to
 *   — which is what Details holds for every other type.
 * - **"Appears under" left** (item 12, D31). It is not gone: it is the panel
 *   `AppearsUnderPopover` opens from the parent counter on the chart card and
 *   the list card. W72 kept it rendering from here in the meantime, for the
 *   reason W36 recorded — an affordance may not leave one surface in one
 *   commit and arrive on another in the next. Integration mounted it on both
 *   counters and removed it from here in the same commit, which closes that
 *   window rather than widening it.
 */
import TagEditor from '@/Components/Tags/TagEditor.vue';
import Input from '@/Components/UI/Input.vue';
import SegmentedControl from '@/Components/UI/SegmentedControl.vue';
import Toggle from '@/Components/UI/Toggle.vue';
import type { AssessmentStore, DefenceStatus } from '@/stores/assessmentStore';
import { computed, ref, watch } from 'vue';
import { useNodeModal } from '../useNodeModal';

const props = defineProps<{
    store: AssessmentStore;
    uuid: string;
}>();

const node = computed(() => props.store.nodes[props.uuid]);
// False while someone else has this node open (2.5.1, useNodeModal).
const { lockedBy } = useNodeModal();
const canEdit = computed(
    () => props.store.abilities.update && lockedBy.value === null,
);

const draftDescription = ref(node.value?.description ?? '');

watch(
    () => node.value?.uuid,
    () => {
        draftDescription.value = node.value?.description ?? '';
    },
);

function commitTitle(value: string) {
    if (!canEdit.value) return;
    if (!node.value || value === node.value.title) return;
    props.store.updateNode(props.uuid, { title: value });
}

/**
 * 2.5.1: this tab no longer offers W79's "link, don't duplicate" list or
 * W88's "AI suggestion" button. Both existed only for a *placeholder* — an
 * untitled node the add-child gesture had already created — and there are no
 * placeholders any more: a node being added is a draft, and both offers moved
 * with it to NewNodeForm.vue. On an existing node, typing here is a rename,
 * which is exactly where W40 refused to offer either.
 */

function commitDescription() {
    if (!canEdit.value) return;
    if (!node.value) return;
    const value =
        draftDescription.value.trim() === '' ? null : draftDescription.value;
    if (value === node.value.description) return;
    props.store.updateNode(props.uuid, { description: value });
}

/**
 * Defence status and the transfer flag (W72 item 9, moved off the Cost tab).
 */
const statusOptions: { value: DefenceStatus; label: string }[] = [
    { value: 'implemented', label: 'Implemented' },
    { value: 'todo', label: 'Todo' },
    { value: 'shelved', label: 'Shelved' },
];

function setStatus(value: string) {
    if (!canEdit.value) return;
    props.store.updateNode(props.uuid, { status: value as DefenceStatus });
}

function setTransfer(value: boolean) {
    if (!canEdit.value) return;
    // A transfer changes reduction_target's default for *new* links only
    // (schema.md §3.6); existing links are untouched here — LinkService
    // owns that default, applied at link-creation time, not retroactively.
    props.store.updateNode(props.uuid, { is_transfer: value });
}

/**
 * Labels (W43). Read straight off the node rather than kept in a local
 * draft: unlike the title and description below, there is no half-typed
 * state to protect — a label is committed the moment it is added, and the
 * store is the only copy.
 */
const tagNames = computed(() => node.value?.tags.map((tag) => tag.name) ?? []);

async function addLabel(name: string) {
    await props.store.attachTag(props.uuid, name);
}

async function removeLabel(name: string) {
    const tag = node.value?.tags.find((candidate) => candidate.name === name);
    if (tag) await props.store.detachTag(props.uuid, tag.id);
}
</script>

<template>
    <div v-if="node" class="flex flex-col gap-4 p-4">
        <Input
            label="Title"
            :model-value="node.title"
            :disabled="!canEdit"
            @update:model-value="commitTitle"
        />

        <div>
            <label class="text-text-primary mb-1 block text-sm font-medium"
                >Description</label
            >
            <textarea
                v-model="draftDescription"
                rows="4"
                maxlength="500"
                :disabled="!canEdit"
                placeholder="What this is, in a sentence or two — shown under the node and in reports"
                class="border-border-default bg-surface-1 text-text-primary focus:border-focus-ring focus:ring-focus-ring block w-full rounded-md text-sm shadow-sm disabled:cursor-not-allowed disabled:opacity-50"
                @blur="commitDescription"
            />
            <p class="text-text-muted mt-1 text-xs">Up to 500 characters.</p>
        </div>

        <div v-if="node.type === 'defence'" data-testid="defence-status">
            <label class="text-text-primary mb-1 block text-sm font-medium"
                >Status</label
            >
            <SegmentedControl
                :model-value="node.status ?? 'todo'"
                :options="statusOptions"
                @update:model-value="setStatus"
            />
        </div>

        <Toggle
            v-if="node.type === 'defence'"
            :model-value="node.is_transfer"
            label="This is a transfer (shifts the risk/loss, e.g. insurance or a contract clause)"
            :disabled="!canEdit"
            @update:model-value="setTransfer"
        />

        <TagEditor
            :names="tagNames"
            :available="store.availableTagNames.value"
            :can-edit="canEdit"
            @add="addLabel"
            @remove="removeLabel"
        />

        <!--
            W72 item 12 / D31: "Appears under" is gone from this tab. It is not
            deleted — it is the popover behind the parent counter on the chart
            card and the list card, which is where a reader asks the question.
            W72 mounted it here temporarily because both cards were outside its
            ownership; integration moved it to them, which is the whole point
            of the item. Detaching an attack from one of several risks is still
            reachable, and still the only place in the application that can do
            it, so nothing was removed by taking it off this tab.
        -->
    </div>
</template>
