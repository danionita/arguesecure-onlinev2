<script setup lang="ts">
/**
 * References tab (ui.md §7, schema.md §3.12, PLAN.md Phase 8 item 5). W13
 * shipped this as a deliberate read-only shell — see its docblock's own
 * hand-off note — with the intended shape (kind, identifier/URL, title)
 * rendered but disabled, because no controller/route existed yet. This is
 * that swap: a real list bound to `node.references` via useReferences.ts
 * (loaded on mount, the same self-contained-composable pattern
 * CommentsTab.vue/CommentThread.vue already established for this modal —
 * references are not part of resources/js/stores/assessmentStore.ts's own
 * state, matching why comments aren't either) and a working add form.
 *
 * CVE is surfaced prominently — it is the only kind shown by default —
 * with every other kind behind a "More kinds" disclosure (schema.md
 * §3.12's last line). No identifier pattern is validated here; the server
 * is authoritative (security.md §7) and returns a 422 with the specific
 * reason, shown inline, if the identifier does not match its kind.
 */
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import IconButton from '@/Components/UI/IconButton.vue';
import Select from '@/Components/UI/Select.vue';
import { useToast } from '@/Components/UI/useToast';
import type { AssessmentStore } from '@/stores/assessmentStore';
import { computed, onMounted, ref, watch } from 'vue';
import { useNodeModal } from '../useNodeModal';
import {
    kindLabel,
    kindPlaceholder,
    kindsFor,
    moreKinds,
    needsIdentifierField,
    needsUrlField,
    primaryKindFor,
} from './references';
import { useReferences } from './useReferences';

const props = defineProps<{
    store: AssessmentStore;
    uuid: string;
    /** Decides which kinds are offered and which one the form opens on. */
    nodeType: string;
}>();

// False while someone else has this node open (2.5.1, useNodeModal).
const { lockedBy } = useNodeModal();
const canEdit = computed(
    () => props.store.abilities.update && lockedBy.value === null,
);
const { references, loading, error, load, add, remove, move } = useReferences(
    props.store.assessment.uuid,
    props.uuid,
);
const toast = useToast();

onMounted(load);
watch(() => props.uuid, load);

// --- add form --------------------------------------------------------------

const showMore = ref(false);
const primaryKind = computed(() => primaryKindFor(props.nodeType));
const kind = ref<string>(primaryKind.value);
const identifier = ref('');
const url = ref('');
const title = ref('');
const submitting = ref(false);
const formError = ref<string | null>(null);

const kindOptions = computed(() =>
    kindsFor(props.nodeType).map((k) => ({
        value: k.value,
        label: k.label,
    })),
);

// "More kinds (CWE, CAPEC, ATT&CK, URL…)" on an attack; on a defence the
// list starts with CVE, since Control is the kind already showing.
const moreLabel = computed(
    () =>
        `More kinds (${moreKinds(props.nodeType)
            .slice(0, 4)
            .map((k) => (k.value === 'attack_technique' ? 'ATT&CK' : k.label))
            .join(', ')}…)`,
);

function toggleMore() {
    showMore.value = !showMore.value;
    if (!showMore.value) {
        kind.value = primaryKind.value;
    }
}

watch(
    () => props.nodeType,
    () => {
        showMore.value = false;
        kind.value = primaryKind.value;
    },
);

const identifierPlaceholder = computed(() => kindPlaceholder(kind.value));

async function submitAdd() {
    formError.value = null;
    const payload: {
        kind: string;
        identifier?: string;
        url?: string;
        title?: string;
    } = { kind: kind.value };

    if (needsIdentifierField(kind.value)) {
        if (identifier.value.trim() === '') {
            formError.value = `Enter a ${kindLabel(kind.value)} identifier.`;
            return;
        }
        payload.identifier = identifier.value.trim();
    }
    if (needsUrlField(kind.value)) {
        if (url.value.trim() === '') {
            formError.value = 'Enter a URL.';
            return;
        }
        payload.url = url.value.trim();
    }
    if (title.value.trim() !== '') {
        payload.title = title.value.trim();
    }

    submitting.value = true;
    try {
        await add(payload);
        identifier.value = '';
        url.value = '';
        title.value = '';
    } catch (e: unknown) {
        const response = (
            e as {
                response?: {
                    data?: {
                        message?: string;
                        errors?: Record<string, string[]>;
                    };
                };
            }
        ).response;
        formError.value =
            response?.data?.errors?.identifier?.[0] ??
            response?.data?.errors?.url?.[0] ??
            response?.data?.message ??
            `Could not add that ${kindLabel(kind.value)} reference.`;
    } finally {
        submitting.value = false;
    }
}

async function handleRemove(id: number) {
    try {
        await remove(id);
    } catch {
        toast.push('Could not remove that reference.', 'danger');
    }
}

async function handleMove(id: number, direction: 'up' | 'down') {
    try {
        await move(id, direction);
    } catch {
        toast.push('Could not reorder references.', 'danger');
    }
}
</script>

<template>
    <div class="flex flex-col gap-4 p-4">
        <div v-if="loading" class="text-text-muted text-sm">Loading…</div>

        <p v-else-if="error" class="text-node-attack-text text-sm">
            {{ error }}
        </p>

        <EmptyState
            v-else-if="references.length === 0"
            icon="link-2"
            title="No references yet"
            :description="
                nodeType === 'defence'
                    ? 'Cite the framework control this implements, or a CVE or related identifier.'
                    : 'Attach a CVE or related identifier to back this up.'
            "
        />

        <ul v-else class="flex flex-col gap-1.5">
            <li
                v-for="(reference, index) in references"
                :key="reference.id"
                class="border-border-subtle flex items-center justify-between gap-2 rounded-md border px-2.5 py-1.5 text-sm"
            >
                <div class="flex min-w-0 flex-col">
                    <div class="flex items-center gap-1.5">
                        <span
                            class="text-text-muted shrink-0 text-xs font-semibold uppercase"
                            >{{ kindLabel(reference.kind) }}</span
                        >
                        <a
                            v-if="reference.url"
                            :href="reference.url"
                            target="_blank"
                            rel="noopener nofollow"
                            class="text-accent truncate hover:underline"
                            >{{ reference.identifier ?? reference.url }}</a
                        >
                        <span v-else class="truncate">{{
                            reference.identifier
                        }}</span>
                    </div>
                    <span
                        v-if="reference.title"
                        class="text-text-muted truncate text-xs"
                        >{{ reference.title }}</span
                    >
                </div>
                <div v-if="canEdit" class="flex shrink-0 items-center gap-0.5">
                    <IconButton
                        icon="chevron-up"
                        label="Move up"
                        :disabled="index === 0"
                        @click="handleMove(reference.id, 'up')"
                    />
                    <IconButton
                        icon="chevron-down"
                        label="Move down"
                        :disabled="index === references.length - 1"
                        @click="handleMove(reference.id, 'down')"
                    />
                    <IconButton
                        icon="x"
                        label="Remove"
                        variant="danger"
                        @click="handleRemove(reference.id)"
                    />
                </div>
            </li>
        </ul>

        <form
            v-if="canEdit"
            class="border-border-subtle flex flex-col gap-2 border-t pt-4"
            @submit.prevent="submitAdd"
        >
            <legend class="text-text-primary mb-1 text-sm font-semibold">
                Add a reference
            </legend>

            <label
                v-if="needsIdentifierField(kind)"
                class="text-text-primary block text-sm font-medium"
                >{{
                    kind === 'other' ? 'What is this?' : kindLabel(kind)
                }}</label
            >
            <input
                v-if="needsIdentifierField(kind)"
                v-model="identifier"
                type="text"
                :placeholder="identifierPlaceholder"
                class="border-border-default bg-surface-1 text-text-primary placeholder:text-text-muted block w-full rounded-md text-sm shadow-sm"
            />
            <p v-if="kind === 'control'" class="text-text-muted text-xs">
                Framework, a colon, then the control as the framework numbers
                it. The report groups defences by what they cite.
            </p>
            <label
                v-if="needsUrlField(kind)"
                class="text-text-primary block text-sm font-medium"
                >URL</label
            >
            <input
                v-if="needsUrlField(kind)"
                v-model="url"
                type="text"
                placeholder="https://…"
                class="border-border-default bg-surface-1 text-text-primary placeholder:text-text-muted block w-full rounded-md text-sm shadow-sm"
            />

            <button
                type="button"
                class="text-accent flex w-fit items-center gap-1 text-xs font-medium"
                @click="toggleMore"
            >
                <Icon
                    name="chevron-down"
                    :size="12"
                    :class="{ 'rotate-180': showMore }"
                />
                {{ showMore ? `Just ${kindLabel(primaryKind)}` : moreLabel }}
            </button>

            <Select
                v-if="showMore"
                label="Kind"
                :model-value="kind"
                :options="kindOptions"
                @update:model-value="
                    (v) => {
                        kind = v;
                        identifier = '';
                        url = '';
                    }
                "
            />

            <label class="text-text-primary block text-sm font-medium"
                >Label (optional)</label
            >
            <input
                v-model="title"
                type="text"
                maxlength="255"
                placeholder="A short note about why this applies"
                class="border-border-default bg-surface-1 text-text-primary placeholder:text-text-muted block w-full rounded-md text-sm shadow-sm"
            />

            <p v-if="formError" class="text-node-attack-text text-xs">
                {{ formError }}
            </p>

            <button
                type="submit"
                :disabled="submitting"
                class="bg-accent self-start rounded-md px-3 py-1.5 text-xs font-medium text-white disabled:opacity-50"
            >
                Add reference
            </button>
        </form>
    </div>
</template>
