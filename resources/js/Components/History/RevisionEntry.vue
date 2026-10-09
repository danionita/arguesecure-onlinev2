<script setup lang="ts">
/**
 * One revision row: who changed what, from what, to what, with a "revert
 * this field" action per field (ui.md §7: "Revision list with per-field
 * revert"). Only 'create'/'update' actions carry a field-keyed `changes`
 * shape (schema.md §3.9) — link/move/delete/restore actions render as a
 * plain summary line with no revert affordance, since there is no single
 * field to revert.
 */
import IconButton from '@/Components/UI/IconButton.vue';
import { computed } from 'vue';
import type { FieldChange, NodeRevision } from './types';

const props = defineProps<{
    revision: NodeRevision;
    canRevert: boolean;
}>();

const emit = defineEmits<{ revert: [field: string] }>();

const isFieldLevel = computed(
    () =>
        props.revision.action === 'create' ||
        props.revision.action === 'update',
);

const fieldChanges = computed<Array<[string, FieldChange]>>(() => {
    if (!isFieldLevel.value) return [];
    return Object.entries(props.revision.changes).filter(
        (entry): entry is [string, FieldChange] =>
            typeof entry[1] === 'object' &&
            entry[1] !== null &&
            'from' in (entry[1] as object) &&
            'to' in (entry[1] as object),
    );
});

const actionLabel = computed(
    () =>
        ({
            create: 'created',
            update: 'updated',
            delete: 'deleted',
            restore: 'restored',
            move: 'moved',
            link: 'linked',
            unlink: 'unlinked',
            link_update: 'changed a link on',
        })[props.revision.action],
);

function formatValue(value: unknown): string {
    if (value === null || value === undefined) return '—';
    if (typeof value === 'boolean') return value ? 'yes' : 'no';
    return String(value);
}

function formatDate(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : '';
}
</script>

<template>
    <div class="border-border rounded-md border p-2 text-sm">
        <div class="text-text-muted flex flex-wrap items-center gap-1 text-xs">
            <span class="text-text-primary font-medium">{{
                revision.user?.name ?? 'Someone'
            }}</span>
            <span>{{ actionLabel }} this node</span>
            <span>·</span>
            <span>{{ formatDate(revision.created_at) }}</span>
        </div>

        <ul v-if="isFieldLevel" class="mt-1 flex flex-col gap-1">
            <li
                v-for="[field, change] in fieldChanges"
                :key="field"
                class="flex flex-wrap items-center justify-between gap-2"
            >
                <span class="text-text-secondary">
                    <span class="font-medium">{{ field }}</span
                    >:
                    <span class="line-through">{{
                        formatValue(change.from)
                    }}</span>
                    →
                    <span>{{ formatValue(change.to) }}</span>
                </span>
                <IconButton
                    v-if="canRevert"
                    icon="history"
                    :label="`Revert ${field} to its prior value`"
                    size="sm"
                    @click="emit('revert', field)"
                />
            </li>
        </ul>
    </div>
</template>
