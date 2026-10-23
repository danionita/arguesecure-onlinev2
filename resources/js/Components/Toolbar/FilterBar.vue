<script setup lang="ts">
/**
 * Filters over `useFilters.ts` (ui.md §5): "by tag, defence status, node
 * type, and 'has missing values'... A filter chip bar shows what is active
 * with a one-click clear."
 *
 * W34 moved the chips into a dropdown and left the *active* ones on the bar.
 * Rendering all sixteen controls inline was the single largest consumer of
 * toolbar width — at 1280px it wrapped the bar onto a second row by itself,
 * and the second row is vertical space the chart view needs for its
 * description lines. The spec's requirement survives the move intact: what is
 * active is still visible on the bar without opening anything, and clearing
 * is still one click. What is *available* is what moved behind the trigger,
 * and availability is not something a user needs on screen while working.
 *
 * Tags are rendered only if the caller supplies any — see useFilters.ts's
 * top comment on the store carrying no tag data yet (W12's `NodeRecord`).
 * W43 closes that gap.
 */
import Chip from '@/Components/UI/Chip.vue';
import IconButton from '@/Components/UI/IconButton.vue';
import Toggle from '@/Components/UI/Toggle.vue';
import type {
    FilterNodeType,
    UseFiltersReturn,
} from '@/composables/useFilters';
import type { DefenceStatus } from '@/stores/assessmentStore';
import { computed } from 'vue';
import FilterCategory from './FilterCategory.vue';
import FilterPopover from './FilterPopover.vue';

const props = withDefaults(
    defineProps<{
        filters: UseFiltersReturn;
        /** All tag names available in this assessment, if the caller has them (see the composable's tag gap note). */
        availableTags?: string[];
    }>(),
    { availableTags: () => [] },
);

const TYPE_LABELS: Record<FilterNodeType, string> = {
    root: 'Target of Assessment',
    risk: 'Risk',
    attack: 'Attack',
    defence: 'Defence',
    transfer: 'Transfer',
};

const STATUS_LABELS: Record<DefenceStatus, string> = {
    implemented: 'Implemented',
    todo: 'Todo',
    shelved: 'Shelved',
};

const typeOptions = computed(
    () => Object.entries(TYPE_LABELS) as [FilterNodeType, string][],
);
const statusOptions = computed(
    () => Object.entries(STATUS_LABELS) as [DefenceStatus, string][],
);

/**
 * How many filters are on. "Missing values" counts as one, because to a user
 * it is a filter like any other — the fact that it is a boolean and the rest
 * are sets is an implementation detail they should not have to hold.
 */
const activeCount = computed(
    () =>
        props.filters.state.types.length +
        props.filters.state.statuses.length +
        props.filters.state.tags.length +
        (props.filters.state.missingOnly ? 1 : 0),
);
</script>

<template>
    <div class="flex items-center gap-2 text-sm">
        <FilterPopover :count="activeCount">
            <FilterCategory title="Node type">
                <div class="flex flex-wrap items-center gap-1">
                    <button
                        v-for="[type, label] in typeOptions"
                        :key="type"
                        type="button"
                        :aria-pressed="filters.state.types.includes(type)"
                        @click="filters.toggleType(type)"
                    >
                        <Chip
                            :tone="
                                filters.state.types.includes(type)
                                    ? type
                                    : 'neutral'
                            "
                        >
                            {{ label }}
                        </Chip>
                    </button>
                </div>
            </FilterCategory>

            <FilterCategory title="Defence status">
                <div class="flex flex-wrap items-center gap-1">
                    <button
                        v-for="[status, label] in statusOptions"
                        :key="status"
                        type="button"
                        :aria-pressed="filters.state.statuses.includes(status)"
                        @click="filters.toggleStatus(status)"
                    >
                        <Chip
                            :tone="
                                filters.state.statuses.includes(status)
                                    ? status
                                    : 'neutral'
                            "
                        >
                            {{ label }}
                        </Chip>
                    </button>
                </div>
            </FilterCategory>

            <FilterCategory v-if="availableTags.length" title="Tags">
                <div class="flex flex-wrap items-center gap-1">
                    <button
                        v-for="tag in availableTags"
                        :key="tag"
                        type="button"
                        :aria-pressed="filters.state.tags.includes(tag)"
                        @click="filters.toggleTag(tag)"
                    >
                        <Chip
                            tone="neutral"
                            :icon="
                                filters.state.tags.includes(tag)
                                    ? 'check'
                                    : undefined
                            "
                        >
                            {{ tag }}
                        </Chip>
                    </button>
                </div>
            </FilterCategory>

            <!-- W34 / brief item 8: "only" came off the label. It was
                 never accurate — the filter dims what does not match
                 rather than hiding it — and the word made a reader
                 look for a mode they had not entered. -->
            <FilterCategory title="Values">
                <Toggle
                    :model-value="filters.state.missingOnly"
                    label="Missing values"
                    @update:model-value="filters.setMissingOnly"
                />
            </FilterCategory>
        </FilterPopover>

        <!-- Active filters stay on the bar: the spec's "shows what is active"
             must not require opening the dropdown to satisfy. -->
        <div
            v-if="filters.isActive.value"
            class="flex flex-wrap items-center gap-1"
            aria-label="Active filters"
            role="group"
        >
            <button
                v-for="type in filters.state.types"
                :key="`t-${type}`"
                type="button"
                :aria-label="`Remove filter: ${TYPE_LABELS[type]}`"
                @click="filters.toggleType(type)"
            >
                <Chip :tone="type" icon="x">{{ TYPE_LABELS[type] }}</Chip>
            </button>
            <button
                v-for="status in filters.state.statuses"
                :key="`s-${status}`"
                type="button"
                :aria-label="`Remove filter: ${STATUS_LABELS[status]}`"
                @click="filters.toggleStatus(status)"
            >
                <Chip :tone="status" icon="x">{{ STATUS_LABELS[status] }}</Chip>
            </button>
            <button
                v-for="tag in filters.state.tags"
                :key="`g-${tag}`"
                type="button"
                :aria-label="`Remove filter: ${tag}`"
                @click="filters.toggleTag(tag)"
            >
                <Chip tone="neutral" icon="x">{{ tag }}</Chip>
            </button>
            <button
                v-if="filters.state.missingOnly"
                type="button"
                aria-label="Remove filter: Missing values"
                @click="filters.setMissingOnly(false)"
            >
                <Chip tone="neutral" icon="x">Missing values</Chip>
            </button>

            <IconButton
                icon="x"
                label="Clear all filters"
                @click="filters.clear()"
            />
        </div>
    </div>
</template>
