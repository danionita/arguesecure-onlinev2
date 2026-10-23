<script setup lang="ts">
/**
 * The assessment history page (W48, item 21; PLAN-2.2.md D15; reshaped in
 * 2.5.1).
 *
 * ## Versions are the page, changes are inside them
 *
 * Through 2.5 this was one flat timeline of changes, ending in a long note
 * that explained that whole versions ("snapshots") could be restored from
 * another page — reachable only from that note — and that usually there were
 * none, because nobody had saved one. That was a product spec left on screen.
 *
 * Now:
 *
 * - **Current version** sits on top and lists every change not yet in a
 *   saved version. Saving it by hand is one button, here.
 * - Every **saved version** follows, newest first, numbered, saying whether
 *   someone saved it or it was saved automatically (AutoVersioner: after a
 *   pause in editing, or after a run of changes). Each holds the changes that
 *   went into it, and can be viewed, compared with the one before, or
 *   restored — undoably, through the same dialog as before.
 *
 * The word is "version" throughout. The storage is still called a snapshot,
 * and URLs still say so; nobody reading the page needs to know that.
 *
 * `TimelineReader` still merges both history logs server-side, so this page
 * has no idea there are two tables; `VersionTimeline` does the grouping.
 */
import TimelineEntry, {
    type TimelineEntryRow,
} from '@/Components/History/TimelineEntry.vue';
import RestoreSnapshotDialog from '@/Components/Snapshots/RestoreSnapshotDialog.vue';
import Button from '@/Components/UI/Button.vue';
import Chip from '@/Components/UI/Chip.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import Select from '@/Components/UI/Select.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface VersionSnapshot {
    id: number;
    name: string;
    note: string | null;
    kind: 'manual' | 'auto' | 'safety';
    schemaVersion: number;
    createdBy: string | null;
    createdAt: string;
}

interface VersionGroup {
    key: string;
    number: number | null;
    snapshot: VersionSnapshot | null;
    entries: TimelineEntryRow[];
    total: number;
}

const props = defineProps<{
    assessment: { uuid: string; title: string };
    versions: VersionGroup[];
    expanded: string | null;
    filters: {
        user_id: number | null;
        action: string | null;
        node_uuid: string | null;
    };
    actors: { id: number; name: string }[];
    actions: string[];
    autoSave: { idleMinutes: number; maxChanges: number };
    abilities: { canSave: boolean; canRestore: boolean };
}>();

const ACTION_LABELS: Record<string, string> = {
    'node.create': 'Node added',
    'node.delete': 'Node deleted',
    'node.move': 'Node moved',
    'link.create': 'Link added',
    'link.delete': 'Link removed',
    'subtree.clone': 'Branch copied',
    'catalog.insert': 'Inserted from the catalogue',
    'field.update': 'Field edited',
};

const actorOptions = computed(() =>
    props.actors.map((actor) => ({
        value: String(actor.id),
        label: actor.name,
    })),
);

const actionOptions = computed(() =>
    props.actions.map((action) => ({
        value: action,
        label: ACTION_LABELS[action] ?? action,
    })),
);

const hasFilters = computed(
    () =>
        props.filters.user_id !== null ||
        props.filters.action !== null ||
        props.filters.node_uuid !== null,
);

function query(patch: Record<string, string | null>) {
    const next: Record<string, string | null> = {
        user_id: props.filters.user_id ? String(props.filters.user_id) : null,
        action: props.filters.action,
        node_uuid: props.filters.node_uuid,
        expand: props.expanded,
        ...patch,
    };

    return Object.fromEntries(
        Object.entries(next).filter(
            ([, value]) => value !== null && value !== '',
        ),
    );
}

function visit(patch: Record<string, string | null>) {
    router.get(
        route('assessments.history.index', props.assessment.uuid),
        query(patch),
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const current = computed(() =>
    props.versions.find((group) => group.key === 'current'),
);
const saved = computed(() =>
    props.versions.filter((group) => group.snapshot !== null),
);

/** The saved version before this one, for "compare with previous". */
function previousOf(group: VersionGroup): VersionGroup | null {
    const index = saved.value.indexOf(group);

    return index >= 0 ? (saved.value[index + 1] ?? null) : null;
}

// Older versions start folded, so the page opens on what is happening now.
const open = ref<Set<string>>(
    new Set(['current', ...(props.expanded ? [props.expanded] : [])]),
);

function toggle(key: string) {
    const next = new Set(open.value);
    if (next.has(key)) next.delete(key);
    else next.add(key);
    open.value = next;
}

function when(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : '—';
}

function changesLabel(count: number): string {
    return count === 1 ? '1 change' : `${count} changes`;
}

const KIND_LABELS: Record<VersionSnapshot['kind'], string> = {
    manual: 'Saved by',
    auto: 'Saved automatically',
    safety: 'Saved before a restore',
};

function savedByLine(snapshot: VersionSnapshot): string {
    if (snapshot.kind === 'manual') {
        return `Saved by ${snapshot.createdBy ?? 'someone'}`;
    }

    return KIND_LABELS[snapshot.kind];
}

// --- saving the current version --------------------------------------------

const naming = ref(false);
const form = useForm({ name: '' });
useUnsavedChanges(() => form.isDirty);

function saveVersion() {
    form.post(
        route('assessments.history.versions.store', props.assessment.uuid),
        {
            preserveScroll: true,
            onSuccess: () => {
                naming.value = false;
                form.reset();
            },
        },
    );
}

// --- restoring ---------------------------------------------------------------

const pendingRestore = ref<VersionSnapshot | null>(null);
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="`History — ${assessment.title}`" />

        <div class="mx-auto max-w-4xl px-4 py-8">
            <div class="mb-6">
                <h1 class="text-text-primary text-xl font-semibold">History</h1>
                <p class="text-text-muted text-sm">
                    Every version of {{ assessment.title }} and the changes that
                    went into it, newest first.
                    <Link
                        :href="route('assessments.show', assessment.uuid)"
                        class="hover:text-text-primary underline"
                        >Back to the assessment</Link
                    >
                </p>
            </div>

            <div class="mb-4 flex flex-wrap items-end gap-3">
                <Select
                    :model-value="
                        filters.user_id ? String(filters.user_id) : ''
                    "
                    label="Who"
                    :options="actorOptions"
                    placeholder="Anyone"
                    @update:model-value="(v) => visit({ user_id: v || null })"
                />
                <Select
                    :model-value="filters.action ?? ''"
                    label="What"
                    :options="actionOptions"
                    placeholder="Anything"
                    @update:model-value="(v) => visit({ action: v || null })"
                />
                <Button
                    v-if="hasFilters"
                    variant="ghost"
                    @click="
                        visit({ user_id: null, action: null, node_uuid: null })
                    "
                >
                    Clear filters
                </Button>
            </div>

            <!--
                The node filter is set by arriving from a node, not by a
                picker: a dropdown of every node in a large assessment is
                unusable, and the useful path is "I am looking at this node,
                show me its history".
            -->
            <p
                v-if="filters.node_uuid"
                class="text-text-muted mb-3 text-xs"
                data-testid="node-filter-note"
            >
                Showing field edits for one node only. Structural changes —
                additions, deletions, links — are assessment-wide and are not
                listed while this filter is on.
            </p>

            <!-- Current version: what is not in a saved version yet. -->
            <section
                v-if="current"
                class="border-focus-ring bg-surface-1 mb-4 rounded-lg border-2 p-4"
                data-testid="version-current"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-text-primary text-sm font-semibold">
                            Current version
                        </h2>
                        <p class="text-text-muted text-xs">
                            <template v-if="current.total > 0">
                                {{ changesLabel(current.total) }} not yet in a
                                saved version.
                            </template>
                            <template v-else-if="saved.length > 0">
                                No changes since the last saved version.
                            </template>
                            <template v-else>No changes recorded yet.</template>
                            Saved automatically after
                            {{ autoSave.idleMinutes }} minutes without edits, or
                            every {{ autoSave.maxChanges }} changes.
                        </p>
                    </div>
                    <Button
                        v-if="abilities.canSave && !naming"
                        variant="primary"
                        size="sm"
                        icon="save"
                        :disabled="current.total === 0 && !hasFilters"
                        data-testid="save-version"
                        @click="naming = true"
                    >
                        Save as version
                    </Button>
                </div>

                <form
                    v-if="naming"
                    class="mt-3 flex flex-wrap items-end gap-2"
                    data-testid="save-version-form"
                    @submit.prevent="saveVersion"
                >
                    <label class="min-w-0 flex-1">
                        <span
                            class="text-text-primary mb-1 block text-xs font-medium"
                            >Name (optional)</span
                        >
                        <input
                            v-model="form.name"
                            type="text"
                            maxlength="120"
                            placeholder="e.g. Sent to client — Q3 review"
                            class="border-border-default bg-surface-1 text-text-primary block w-full rounded-md text-sm"
                            autofocus
                        />
                    </label>
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="
                            naming = false;
                            form.reset();
                        "
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="primary"
                        size="sm"
                        :loading="form.processing"
                        data-testid="save-version-submit"
                    >
                        Save version
                    </Button>
                </form>

                <ul v-if="current.entries.length" class="mt-3 space-y-2">
                    <TimelineEntry
                        v-for="entry in current.entries"
                        :key="entry.id"
                        :entry="entry"
                        :assessment-uuid="assessment.uuid"
                    />
                </ul>
                <button
                    v-if="current.total > current.entries.length"
                    type="button"
                    class="text-text-link mt-2 text-xs underline"
                    @click="visit({ expand: 'current' })"
                >
                    Show all {{ current.total }} changes
                </button>
            </section>

            <!-- Saved versions, newest first. -->
            <ol v-if="saved.length" class="space-y-3">
                <li
                    v-for="group in saved"
                    :key="group.key"
                    class="border-border-subtle bg-surface-1 rounded-lg border"
                    :data-testid="`version-${group.number}`"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3 p-4"
                    >
                        <button
                            type="button"
                            class="flex min-w-0 flex-1 items-start gap-2 text-left"
                            :aria-expanded="open.has(group.key)"
                            @click="toggle(group.key)"
                        >
                            <Icon
                                :name="
                                    open.has(group.key)
                                        ? 'chevron-down'
                                        : 'chevron-right'
                                "
                                :size="16"
                                class="text-text-muted mt-0.5 shrink-0"
                            />
                            <span class="min-w-0">
                                <span
                                    class="text-text-primary block text-sm font-semibold"
                                >
                                    Version {{ group.number }}
                                    <span
                                        class="text-text-secondary font-normal"
                                        >— {{ group.snapshot!.name }}</span
                                    >
                                </span>
                                <span class="text-text-muted block text-xs">
                                    {{ savedByLine(group.snapshot!) }} ·
                                    {{ when(group.snapshot!.createdAt) }} ·
                                    {{ changesLabel(group.total) }}
                                </span>
                            </span>
                            <Chip
                                v-if="group.snapshot!.kind === 'auto'"
                                tone="neutral"
                                class="shrink-0"
                                >Auto</Chip
                            >
                        </button>

                        <div class="flex shrink-0 items-center gap-3 text-xs">
                            <Link
                                :href="
                                    route('assessments.snapshots.view', {
                                        assessment: assessment.uuid,
                                        snapshot: group.snapshot!.id,
                                    })
                                "
                                class="text-text-muted hover:text-text-primary underline"
                            >
                                View
                            </Link>
                            <!-- The frozen totals: "what did it total?" as
                                 against View's "what did it look like?". -->
                            <Link
                                :href="
                                    route('assessments.snapshots.show', {
                                        assessment: assessment.uuid,
                                        snapshot: group.snapshot!.id,
                                    })
                                "
                                class="text-text-muted hover:text-text-primary underline"
                            >
                                Figures
                            </Link>
                            <Link
                                v-if="previousOf(group)"
                                :href="
                                    route('assessments.snapshots.diff', {
                                        assessment: assessment.uuid,
                                        from: previousOf(group)!.snapshot!.id,
                                        to: group.snapshot!.id,
                                    })
                                "
                                class="text-text-muted hover:text-text-primary underline"
                            >
                                Compare with version
                                {{ previousOf(group)!.number }}
                            </Link>
                            <!-- Labelled, not an icon: the one action that
                                 can throw away everybody's work does not sit
                                 behind a guess (W61). -->
                            <button
                                v-if="abilities.canRestore"
                                type="button"
                                class="text-text-muted hover:text-text-primary underline"
                                @click="pendingRestore = group.snapshot"
                            >
                                Restore this version
                            </button>
                        </div>
                    </div>

                    <div
                        v-if="open.has(group.key)"
                        class="border-border-subtle border-t px-4 py-3"
                    >
                        <p
                            v-if="group.snapshot!.note"
                            class="text-text-secondary mb-2 text-xs"
                        >
                            {{ group.snapshot!.note }}
                        </p>
                        <ul v-if="group.entries.length" class="space-y-2">
                            <TimelineEntry
                                v-for="entry in group.entries"
                                :key="entry.id"
                                :entry="entry"
                                :assessment-uuid="assessment.uuid"
                            />
                        </ul>
                        <p v-else class="text-text-muted text-xs">
                            <template v-if="hasFilters">
                                No changes in this version match the filters.
                            </template>
                            <template v-else>
                                No recorded changes went into this version.
                            </template>
                        </p>
                        <button
                            v-if="group.total > group.entries.length"
                            type="button"
                            class="text-text-link mt-2 text-xs underline"
                            @click="visit({ expand: group.key })"
                        >
                            Show all {{ group.total }} changes
                        </button>
                    </div>
                </li>
            </ol>

            <EmptyState
                v-else-if="!current || current.total === 0"
                icon="history"
                title="Nothing recorded yet"
                description="Edits, additions and deletions appear here as they happen, and are saved into versions as you go."
            />
        </div>

        <RestoreSnapshotDialog
            :assessment-uuid="assessment.uuid"
            :snapshot="pendingRestore"
            @close="pendingRestore = null"
        />
    </AuthenticatedLayout>
</template>
