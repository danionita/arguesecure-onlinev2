<script setup lang="ts">
/**
 * The chrome above the report document: search, the options row, zoom and
 * the view switcher (W83, ui.md §9). Extracted out of `Pages/Report/Show.vue`
 * so its two D39 branches (sidebar for an authenticated viewer, bare for a
 * share-link guest) mount this **once each** rather than each carrying its
 * own copy — the exact drift W74's hand-copied toolbar geometry produced
 * once already (`Components/Chrome/*`'s own docblocks) is what mounting the
 * same component twice avoids, where hand-copying the markup twice would
 * not.
 *
 * "Every option on one line, always visible, immediately beside the
 * search" (brief item 5) replaces the open/close panel W35–W80 had: there
 * is no open/close state here at all any more. Changing an option still
 * re-requests the page with a new query string via the `update-options`
 * emit — this component holds no report figure and computes none; it only
 * assembles the query patch `Pages/Report/Show.vue`'s `updateOptions()`
 * already sends to the server.
 */
import SearchBox from '@/Components/Toolbar/SearchBox.vue';
import ViewSwitcher from '@/Components/Toolbar/ViewSwitcher.vue';
import SegmentedControl from '@/Components/UI/SegmentedControl.vue';
import Toggle from '@/Components/UI/Toggle.vue';
import type { UseReportSearchReturn } from '@/composables/useReportSearch';
import ChromeToolbarBand from '../Chrome/ChromeToolbarBand.vue';
import ReportZoomControls from './ReportZoomControls.vue';
import type { ReportOptions } from './types';

defineProps<{
    options: ReportOptions;
    search: UseReportSearchReturn;
    zoom: number;
    reportHref: string;
    editorHref: string;
    assessmentUuid: string;
    userId: string | number | null;
}>();

const emit = defineEmits<{
    'update-options': [patch: Partial<ReportOptions>];
    'zoom-in': [];
    'zoom-out': [];
    'zoom-reset': [];
}>();

const baselineOptions = [
    { value: 'current', label: 'Current' },
    { value: 'target', label: 'Target' },
    { value: 'both', label: 'Both' },
];

/**
 * The second radio group — the third until W80 removed the figures control
 * between them (see `ReportOptions`' PHP docblock: it never bound anything,
 * and computed results are rendered as currency and never band-mapped, so
 * it could not have).
 *
 * "Include shelved" reads as a choice between two accounts of the same
 * programme ("Implemented & planned" / "Include shelved") rather than an
 * inclusion like the toggles beside it — shelved defences are a different
 * account of the programme, not an addition to a complete one. It is still
 * one boolean on the wire; `ReportOptions::$includeShelved` and the query
 * parameter are unchanged.
 */
const shelvedOptions = [
    { value: 'without', label: 'Implemented & planned' },
    { value: 'with', label: 'Include shelved' },
];
</script>

<template>
    <ChromeToolbarBand>
        <SearchBox :search="search" data-print-hide />

        <div
            data-testid="report-option-choices"
            class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm"
            data-print-hide
        >
            <SegmentedControl
                :model-value="options.baseline"
                :options="baselineOptions"
                aria-label="Baseline"
                @update:model-value="
                    (v) => emit('update-options', { baseline: v as any })
                "
            />
            <SegmentedControl
                :model-value="options.includeShelved ? 'with' : 'without'"
                :options="shelvedOptions"
                aria-label="Shelved defences"
                @update:model-value="
                    (v) =>
                        emit('update-options', {
                            includeShelved: v === 'with',
                        })
                "
            />
        </div>

        <div
            data-testid="report-option-inclusions"
            class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm"
            data-print-hide
        >
            <Toggle
                :model-value="options.includeComments"
                label="Include comments"
                @update:model-value="
                    (v) => emit('update-options', { includeComments: v })
                "
            />
            <Toggle
                :model-value="options.includeMethodology"
                label="Include methodology"
                @update:model-value="
                    (v) => emit('update-options', { includeMethodology: v })
                "
            />
            <!--
                W46, item 16. Default off, and no export turns it on for you:
                a report is a document that leaves the room, and who is
                responsible for which line is internal information the
                exporter has to choose to include.
            -->
            <Toggle
                :model-value="options.showAssignees"
                label="Show assignees"
                @update:model-value="
                    (v) => emit('update-options', { showAssignees: v })
                "
            />
        </div>

        <!-- Zoom in `trailing`, the switcher in `anchor` — the editor's own
             Toolbar.vue puts its zoom controls there for the same reason
             ChromeToolbarBand.vue gives: the anchor slot is last, so the
             switcher's distance from the window's right edge is the band's
             padding and nothing else. -->
        <template #trailing>
            <ReportZoomControls
                :zoom="zoom"
                @zoom-in="emit('zoom-in')"
                @zoom-out="emit('zoom-out')"
                @zoom-reset="emit('zoom-reset')"
            />
        </template>
        <template #anchor>
            <ViewSwitcher
                current="report"
                :report-href="reportHref"
                :editor-href="editorHref"
                :assessment-uuid="assessmentUuid"
                :user-id="userId"
            />
        </template>
    </ChromeToolbarBand>
</template>
