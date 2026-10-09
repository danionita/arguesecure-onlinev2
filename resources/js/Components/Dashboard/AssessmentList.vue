<script setup lang="ts">
/**
 * One list of assessments, rendered the same way on "My assessments" and on
 * "Assessments shared with me" (2.5.1).
 *
 * The two pages used to be built differently: `/assessments` had a filter and
 * per-row actions, `/assessments/shared` had neither and rendered the
 * dashboard's rows instead. Two lists of the same object that disagree about
 * what you can do with a row is the drift this component removes — both pages
 * now pass their rows here and differ only in which rows they pass.
 *
 * ## Filtering (W42 item 13)
 *
 * Client-side, on title, description and label, case-insensitively, on a
 * plain substring: the whole list is already on the page, so a request would
 * be slower and no more correct, and a fuzzy match that surfaces the wrong
 * assessment is worse than no match.
 *
 * ## Actions, and what gates them (W42 item 20, W86 item 7)
 *
 * Settings · Share · Export · Duplicate · Delete, as icons with their names
 * in the tooltip and the accessible name. Each renders from a permission the
 * server computed (`can_manage`, `can_delete`), never from `is_owner` — a
 * manager who is not the owner can share, an admin who is neither can delete.
 * Hiding a button is a courtesy; the route is the guard.
 */
import ExportMenu from '@/Components/Report/ExportMenu.vue';
import type { ReportOptions } from '@/Components/Report/types';
import TagChip from '@/Components/Tags/TagChip.vue';
import Chip from '@/Components/UI/Chip.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import IconButton from '@/Components/UI/IconButton.vue';
import type { IconName } from '@/Components/UI/icons.generated';
import Tooltip from '@/Components/UI/Tooltip.vue';
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

export interface AssessmentSummary {
    uuid: string;
    title: string;
    description: string | null;
    is_public: boolean;
    is_template: boolean;
    is_owner: boolean;
    can_manage: boolean;
    can_delete: boolean;
    owner_name: string | null;
    updated_at: string | null;
    /** Labels on the root node — the assessment's own (W43 item 18). */
    tags: string[];
}

const props = defineProps<{
    assessments: AssessmentSummary[];
    emptyIcon: IconName;
    emptyTitle: string;
    emptyDescription: string;
}>();

const filter = ref('');
const pendingDelete = ref<AssessmentSummary | null>(null);
const duplicating = ref<string | null>(null);

const visible = computed(() => {
    const term = filter.value.trim().toLowerCase();
    if (term === '') return props.assessments;

    return props.assessments.filter(
        (assessment) =>
            assessment.title.toLowerCase().includes(term) ||
            (assessment.description ?? '').toLowerCase().includes(term) ||
            assessment.tags.some((tag) => tag.toLowerCase().includes(term)),
    );
});

function confirmDelete() {
    const target = pendingDelete.value;
    if (!target) return;

    router.delete(route('assessments.destroy', target.uuid), {
        onFinish: () => {
            pendingDelete.value = null;
        },
    });
}

/**
 * Cloning copies every node, both edge tables and the scales, so it is not
 * instant on a large assessment; the button disables itself meanwhile rather
 * than letting an impatient second click produce a second copy.
 */
function duplicate(assessment: AssessmentSummary) {
    duplicating.value = assessment.uuid;
    router.post(
        route('assessments.duplicate', assessment.uuid),
        {},
        { onFinish: () => (duplicating.value = null) },
    );
}

/**
 * The same defaults the editor's action bar exports with when no report
 * options panel is on screen: the current baseline, no shelved defences,
 * methodology included.
 */
const exportOptions: ReportOptions = {
    baseline: 'current',
    includeShelved: false,
    includeComments: false,
    includeMethodology: true,
    showAssignees: false,
    download: false,
};
</script>

<template>
    <div>
        <div v-if="assessments.length > 0" class="relative mb-4">
            <Icon
                name="search"
                :size="14"
                class="text-text-muted pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2"
            />
            <input
                v-model="filter"
                type="search"
                aria-label="Filter assessments"
                placeholder="Filter by title, description or label…"
                class="border-border-default bg-surface-1 text-text-primary placeholder:text-text-muted focus:border-focus-ring focus:ring-focus-ring block w-full rounded-md pl-8 text-sm"
            />
        </div>

        <EmptyState
            v-if="assessments.length === 0"
            :icon="emptyIcon"
            :title="emptyTitle"
            :description="emptyDescription"
        />

        <!-- A filter that matches nothing says so, rather than showing the
             empty-list invitation — the rows exist, they are just hidden. -->
        <EmptyState
            v-else-if="visible.length === 0"
            icon="search"
            title="No assessments match that"
            :description="`Nothing here contains “${filter.trim()}”. Clear the filter to see all ${assessments.length}.`"
        />

        <ul v-else class="flex flex-col gap-2">
            <li
                v-for="assessment in visible"
                :key="assessment.uuid"
                :data-assessment="assessment.uuid"
                class="border-border bg-surface flex items-start justify-between gap-4 rounded-lg border p-4"
            >
                <div class="min-w-0">
                    <Link
                        :href="route('assessments.show', assessment.uuid)"
                        class="text-text-primary font-medium hover:underline"
                    >
                        {{ assessment.title }}
                    </Link>
                    <p
                        v-if="assessment.description"
                        class="text-text-muted mt-0.5 line-clamp-2 text-sm"
                    >
                        {{ assessment.description }}
                    </p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <Chip v-if="assessment.is_public" tone="todo">
                            Public
                        </Chip>
                        <Chip v-if="assessment.is_template" tone="neutral">
                            Template
                        </Chip>
                        <TagChip
                            v-for="name in assessment.tags"
                            :key="name"
                            :name="name"
                        />
                        <span
                            v-if="!assessment.is_owner && assessment.owner_name"
                            class="text-text-muted text-xs"
                        >
                            Shared by {{ assessment.owner_name }}
                        </span>
                    </div>
                </div>

                <div
                    class="flex shrink-0 items-center gap-1"
                    data-testid="assessment-actions"
                >
                    <Tooltip v-if="assessment.can_manage" text="Settings">
                        <Link
                            :href="
                                route(
                                    'assessments.settings.edit',
                                    assessment.uuid,
                                )
                            "
                            aria-label="Settings"
                            class="text-text-muted hover:bg-surface-2 hover:text-text-primary focus-visible:outline-focus-ring inline-flex h-6 w-6 items-center justify-center rounded outline-none focus-visible:outline-2"
                        >
                            <Icon name="settings" :size="14" />
                        </Link>
                    </Tooltip>
                    <!-- Opens the editor's own sharing dialog: there is one
                         sharing UI (W9), and a second copy here would drift. -->
                    <Tooltip v-if="assessment.can_manage" text="Share">
                        <Link
                            :href="route('share.index', assessment.uuid)"
                            aria-label="Share"
                            class="text-text-muted hover:bg-surface-2 hover:text-text-primary focus-visible:outline-focus-ring inline-flex h-6 w-6 items-center justify-center rounded outline-none focus-visible:outline-2"
                        >
                            <Icon name="share-2" :size="14" />
                        </Link>
                    </Tooltip>
                    <!-- The editor's own ExportMenu (D40), as an icon. -->
                    <ExportMenu
                        :assessment-uuid="assessment.uuid"
                        :options="exportOptions"
                        icon-only
                    />
                    <IconButton
                        icon="copy"
                        label="Duplicate"
                        :disabled="duplicating === assessment.uuid"
                        @click="duplicate(assessment)"
                    />
                    <IconButton
                        v-if="assessment.can_delete"
                        icon="trash-2"
                        label="Delete"
                        variant="danger"
                        @click="pendingDelete = assessment"
                    />
                </div>
            </li>
        </ul>

        <ConfirmDialog
            :show="pendingDelete !== null"
            title="Delete this assessment?"
            :message="`“${pendingDelete?.title}” and everything in it will be removed from your list.`"
            confirm-label="Delete"
            danger
            @confirm="confirmDelete"
            @cancel="pendingDelete = null"
        />
    </div>
</template>
