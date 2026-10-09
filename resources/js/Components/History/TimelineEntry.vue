<script setup lang="ts">
/**
 * One entry in the assessment timeline (W48, item 21).
 *
 * Two logs feed this component — `assessment_operations` for structural
 * changes and `node_revisions` for field edits — but a reader does not care
 * which table a change came from, so the row shape is identical and only the
 * icon and the expandable detail differ. `TimelineReader` decides what the
 * summary says; this decides how it looks.
 *
 * Field detail is collapsed by default. "Changed 3 fields on Phishing" is what
 * someone scanning wants; the from/to pairs are what someone investigating
 * wants, and putting both on screen at once makes the first job harder without
 * making the second easier.
 */
import Badge from '@/Components/UI/Badge.vue';
import Icon from '@/Components/UI/Icon.vue';
import type { IconName } from '@/Components/UI/icons.generated';
import { computed, ref } from 'vue';

export interface TimelineChange {
    node_uuid: string | null;
    node_title: string | null;
    field: string;
    from: unknown;
    to: unknown;
}

export interface TimelineEntryRow {
    id: string;
    source: 'operation' | 'revision';
    action: string;
    summary: string;
    user: string | null;
    created_at: string | null;
    node_uuid: string | null;
    undone: boolean;
    changes: TimelineChange[];
}

const props = defineProps<{
    entry: TimelineEntryRow;
    assessmentUuid: string;
}>();

const expanded = ref(false);

const ACTION_ICONS: Record<string, IconName> = {
    'node.create': 'square-plus',
    'node.delete': 'trash-2',
    'node.move': 'corner-down-right',
    'link.create': 'paperclip',
    'link.delete': 'paperclip',
    'subtree.clone': 'copy',
    'catalog.insert': 'file-text',
    'field.update': 'file-text',
};

const icon = computed<IconName>(
    () => ACTION_ICONS[props.entry.action] ?? 'history',
);

const when = computed(() =>
    props.entry.created_at === null
        ? ''
        : new Date(props.entry.created_at).toLocaleString(),
);

/**
 * The node this entry is about, if it names exactly one. A structural entry
 * can touch several — a clone, a catalogue insert — so it names none, and
 * offering a link to "the" node would be a guess.
 */
const href = computed(() =>
    props.entry.node_uuid === null
        ? null
        : `${route('assessments.show', props.assessmentUuid)}?node=${props.entry.node_uuid}`,
);

/** `null` prints as "not set", never as an empty string that reads as blank. */
function show(value: unknown): string {
    if (value === null || value === undefined || value === '') return 'not set';

    return String(value);
}
</script>

<template>
    <li class="border-border-subtle bg-surface-1 rounded-lg border p-3">
        <div class="flex items-start gap-3">
            <Icon
                :name="icon"
                :size="16"
                class="text-text-muted mt-0.5 shrink-0"
            />

            <div class="min-w-0 flex-1">
                <p
                    class="text-text-primary flex flex-wrap items-center gap-2 text-sm"
                >
                    <span>{{ entry.summary }}</span>
                    <!--
                        An undone operation stays in the timeline rather than
                        disappearing. History that edits itself is not history,
                        and "Dana deleted this, then undid it" is a more useful
                        record than silence.
                    -->
                    <Badge v-if="entry.undone" tone="neutral">undone</Badge>
                </p>

                <p class="text-text-muted mt-0.5 text-xs">
                    {{ entry.user ?? 'A removed account' }} · {{ when }}
                    <a
                        v-if="href"
                        :href="href"
                        class="hover:text-text-primary ml-1 underline"
                        >open the node</a
                    >
                </p>

                <button
                    v-if="entry.changes.length"
                    type="button"
                    class="text-text-muted hover:text-text-primary focus-visible:outline-focus-ring mt-1 rounded text-xs underline outline-none focus-visible:outline-2"
                    :aria-expanded="expanded"
                    @click="expanded = !expanded"
                >
                    {{ expanded ? 'Hide' : 'Show' }}
                    {{ entry.changes.length }}
                    {{ entry.changes.length === 1 ? 'change' : 'changes' }}
                </button>

                <dl v-if="expanded" class="mt-2 space-y-1 text-xs">
                    <div
                        v-for="(change, index) in entry.changes"
                        :key="`${change.field}-${index}`"
                        class="flex flex-wrap gap-x-2"
                    >
                        <dt class="text-text-secondary font-medium">
                            {{ change.field }}
                        </dt>
                        <dd class="text-text-muted">
                            {{ show(change.from) }} → {{ show(change.to) }}
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </li>
</template>
