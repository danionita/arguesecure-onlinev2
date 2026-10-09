<script setup lang="ts">
/**
 * The catalogue browser (schema.md §3.14, PLAN.md Phase 8 item 4). Opened
 * bare (`/catalog`) it is browse-only; opened with `?assessment=<uuid>`
 * from the tree editor's command palette (a caller with `update` on that
 * assessment — CatalogController::index() enforces that server-side) it
 * additionally offers "Insert", which may need to ask which risk (for an
 * attack entry) or attack (for a defence entry) the new node attaches
 * under — CatalogController::insert()'s own `parent_uuid` contract.
 */
import CatalogEntryCard from '@/Components/Catalog/CatalogEntryCard.vue';
import type {
    CatalogEntryKind,
    CatalogEntrySummary,
    CatalogInsertResult,
    CatalogParents,
} from '@/Components/Catalog/types';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Input from '@/Components/UI/Input.vue';
import Modal from '@/Components/UI/Modal.vue';
import Select from '@/Components/UI/Select.vue';
import { useToast } from '@/Components/UI/useToast';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    entries: CatalogEntrySummary[];
    filters: { kind: CatalogEntryKind | null; q: string | null };
    assessment: { uuid: string } | null;
    parents: CatalogParents;
}>();

const toast = useToast();

const q = ref(props.filters.q ?? '');
const kind = ref<CatalogEntryKind | ''>(props.filters.kind ?? '');

function search() {
    router.get(
        route('catalog.index'),
        {
            q: q.value || undefined,
            kind: kind.value || undefined,
            assessment: props.assessment?.uuid,
        },
        { preserveState: true, replace: true },
    );
}

const kindOptions = [
    { value: '', label: 'All kinds' },
    { value: 'risk', label: 'Risk' },
    { value: 'attack', label: 'Attack' },
    { value: 'defence', label: 'Defence' },
];

// --- inserting --------------------------------------------------------

const insertingEntryId = ref<number | null>(null);
const pendingEntry = ref<CatalogEntrySummary | null>(null);
const selectedParentUuid = ref('');

/** A `risk` entry never needs a parent; an `attack` needs a risk to
 *  attach under; a `defence` needs an attack — CatalogInserter's own
 *  assertValidShape() enforces this server-side, this just avoids a
 *  round trip that would always 422. */
function parentOptionsFor(entry: CatalogEntrySummary) {
    if (entry.kind === 'attack') return props.assessment ? propsRisks() : [];
    if (entry.kind === 'defence') return props.assessment ? propsAttacks() : [];
    return [];
}

function propsRisks() {
    return props.parents.risks.map((r) => ({
        value: r.uuid,
        label: r.title || '(untitled risk)',
    }));
}

function propsAttacks() {
    return props.parents.attacks.map((a) => ({
        value: a.uuid,
        label: a.title || '(untitled attack)',
    }));
}

function startInsert(entry: CatalogEntrySummary) {
    if (entry.kind === 'risk') {
        void doInsert(entry, null);
        return;
    }

    pendingEntry.value = entry;
    selectedParentUuid.value = '';
}

async function doInsert(entry: CatalogEntrySummary, parentUuid: string | null) {
    if (!props.assessment) return;

    insertingEntryId.value = entry.id;

    try {
        const response = await window.axios.post<CatalogInsertResult>(
            route('assessments.catalog-entries.insert', {
                assessment: props.assessment.uuid,
                catalogEntry: entry.id,
            }),
            parentUuid ? { parent_uuid: parentUuid } : {},
        );

        toast.push(
            response.data.nodes.length > 1
                ? `Inserted '${entry.title}' and ${response.data.nodes.length - 1} linked item(s).`
                : `Inserted '${entry.title}'.`,
            'success',
        );
        pendingEntry.value = null;
    } catch (error) {
        const message =
            (error as { response?: { data?: { message?: string } } })?.response
                ?.data?.message ?? 'Could not insert that entry.';
        toast.push(message, 'danger');
    } finally {
        insertingEntryId.value = null;
    }
}

function confirmInsert() {
    if (!pendingEntry.value || !selectedParentUuid.value) return;
    void doInsert(pendingEntry.value, selectedParentUuid.value);
}
</script>

<template>
    <Head title="Catalogue" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-text-primary text-xl leading-tight font-semibold">
                Catalogue
            </h2>
        </template>

        <div class="mx-auto max-w-3xl px-4 py-6 sm:px-6">
            <div class="mb-4 flex flex-wrap items-end gap-3">
                <div class="min-w-[14rem] flex-1">
                    <Input
                        v-model="q"
                        label="Search"
                        placeholder="Title or description"
                        @keyup.enter="search"
                    />
                </div>
                <div class="w-40">
                    <Select
                        v-model="kind"
                        label="Kind"
                        :options="kindOptions"
                    />
                </div>
                <Button variant="secondary" icon="search" @click="search"
                    >Search</Button
                >
            </div>

            <div class="border-border-subtle bg-surface-1 rounded-lg border">
                <EmptyState
                    v-if="entries.length === 0"
                    icon="search"
                    title="No matching entries"
                    description="Try a different search term or kind."
                />
                <CatalogEntryCard
                    v-for="entry in entries"
                    :key="entry.id"
                    :entry="entry"
                    :can-insert="assessment !== null"
                    :inserting="insertingEntryId === entry.id"
                    @insert="startInsert(entry)"
                />
            </div>
        </div>

        <Modal
            :show="pendingEntry !== null"
            :title="`Insert '${pendingEntry?.title ?? ''}'`"
            @close="pendingEntry = null"
        >
            <div class="space-y-4 px-4 py-4">
                <Select
                    v-model="selectedParentUuid"
                    :label="
                        pendingEntry?.kind === 'attack'
                            ? 'Attach under risk'
                            : 'Attach under attack'
                    "
                    placeholder="Choose one…"
                    :options="
                        pendingEntry ? parentOptionsFor(pendingEntry) : []
                    "
                />
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button variant="ghost" @click="pendingEntry = null"
                        >Cancel</Button
                    >
                    <Button
                        variant="primary"
                        :disabled="!selectedParentUuid"
                        :loading="insertingEntryId === pendingEntry?.id"
                        @click="confirmInsert"
                    >
                        Insert
                    </Button>
                </div>
            </template>
        </Modal>
    </AuthenticatedLayout>
</template>
