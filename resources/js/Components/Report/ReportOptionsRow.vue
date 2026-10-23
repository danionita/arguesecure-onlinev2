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
import Toggle from '@/Components/UI/Toggle.vue';
import type { UseReportSearchReturn } from '@/composables/useReportSearch';
import { computed } from 'vue';
import ChromeToolbarBand from '../Chrome/ChromeToolbarBand.vue';
import ReportOptionsPopover from './ReportOptionsPopover.vue';
import ReportZoomControls from './ReportZoomControls.vue';
import { REPORT_SECTIONS } from './reportSections';
import type { ReportOptions } from './types';

const props = defineProps<{
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

const bodySections = REPORT_SECTIONS.filter((s) => s.group === 'body');
const appendixSections = REPORT_SECTIONS.filter((s) => s.group === 'appendix');

const isShown = (id: string) => !props.options.omittedSections.includes(id);

// Sends the whole list: the server reads `omit_sections` as one value.
function toggleSection(id: string, shown: boolean) {
    const rest = props.options.omittedSections.filter((o) => o !== id);
    emit('update-options', {
        omittedSections: shown ? rest : [...rest, id],
    });
}

// Planned defences on means the target figures sit beside the current ones
// (`both`); off means current only. A hand-built `baseline=target` link still
// reads as planned-on, and the next click normalises it.
const plannedShown = computed(() => props.options.baseline !== 'current');
</script>

<template>
    <ChromeToolbarBand>
        <SearchBox :search="search" data-print-hide />

        <!--
            W136, D106. All the report's settings sit behind two buttons, each
            group under a heading that says what it decides. Defences is
            which defences the report covers (W153: it was Content, with two
            controls that both concerned defences and did not say so);
            Sections is what is added to the document, and the four optional
            appendices and details start off. There is no Filters button here: these are not node
            filters. Defaults are `ReportOptions::fromQuery()`'s.
        -->
        <div class="flex flex-wrap items-center gap-2 text-sm" data-print-hide>
            <ReportOptionsPopover
                label="Defences"
                testid="report-content-button"
            >
                <fieldset data-testid="report-option-choices" class="space-y-2">
                    <legend class="text-text-primary text-xs font-semibold">
                        Which defences the report covers
                    </legend>
                    <p class="text-text-muted text-xs">
                        Only the defences that are implemented reduce the
                        current figures.
                    </p>
                    <Toggle
                        :model-value="true"
                        disabled
                        label="Implemented defences"
                        data-testid="defences-implemented"
                    />
                    <Toggle
                        :model-value="plannedShown"
                        label="Planned defences, with the target figures"
                        data-testid="defences-planned"
                        @update:model-value="
                            (v) =>
                                emit('update-options', {
                                    baseline: v ? 'both' : 'current',
                                })
                        "
                    />
                    <Toggle
                        :model-value="options.includeShelved"
                        label="Shelved defences, listed only"
                        data-testid="defences-shelved"
                        @update:model-value="
                            (v) => emit('update-options', { includeShelved: v })
                        "
                    />
                    <p class="text-text-muted text-xs">
                        Shelved defences never reduce a total; listing them only
                        shows what was given up.
                    </p>
                </fieldset>
            </ReportOptionsPopover>

            <ReportOptionsPopover
                label="Sections"
                testid="report-sections-button"
            >
                <div
                    data-testid="report-option-inclusions"
                    class="max-h-[70vh] space-y-4 overflow-y-auto"
                >
                    <fieldset class="space-y-2">
                        <legend class="text-text-primary text-xs font-semibold">
                            Report body
                        </legend>
                        <p class="text-text-muted text-xs">
                            Switch a section off to leave it out. The figures in
                            the others do not change, and the report names what
                            was left out.
                        </p>
                        <Toggle
                            v-for="section in bodySections"
                            :key="section.id"
                            :model-value="isShown(section.id)"
                            :label="section.label"
                            :data-testid="`section-toggle-${section.id}`"
                            @update:model-value="
                                (v) => toggleSection(section.id, v)
                            "
                        />
                    </fieldset>
                    <fieldset class="space-y-2" data-testid="report-appendices">
                        <legend class="text-text-primary text-xs font-semibold">
                            Appendices
                        </legend>
                        <Toggle
                            v-for="section in appendixSections"
                            :key="section.id"
                            :model-value="isShown(section.id)"
                            :label="`Include ${section.label.toLowerCase()}`"
                            :data-testid="`section-toggle-${section.id}`"
                            @update:model-value="
                                (v) => toggleSection(section.id, v)
                            "
                        />
                        <Toggle
                            :model-value="options.includeComments"
                            label="Include comments"
                            @update:model-value="
                                (v) =>
                                    emit('update-options', {
                                        includeComments: v,
                                    })
                            "
                        />
                        <Toggle
                            :model-value="options.includeMethodology"
                            label="Include methodology"
                            @update:model-value="
                                (v) =>
                                    emit('update-options', {
                                        includeMethodology: v,
                                    })
                            "
                        />
                        <!--
                        W136, D105; default off since W153. Leaving it out
                        replaces the appendix with a count of what was left
                        out.
                    -->
                        <Toggle
                            :model-value="options.includeWarnings"
                            label="Include model-quality warnings"
                            @update:model-value="
                                (v) =>
                                    emit('update-options', {
                                        includeWarnings: v,
                                    })
                            "
                        />
                    </fieldset>
                    <fieldset class="space-y-2">
                        <legend class="text-text-primary text-xs font-semibold">
                            Detail
                        </legend>
                        <!--
                        W46, item 16. Default off, and no export turns it on
                        for you: a report is a document that leaves the room,
                        and who is responsible for which line is internal
                        information the exporter has to choose to include.
                    -->
                        <Toggle
                            :model-value="options.showAssignees"
                            label="Show assignees"
                            @update:model-value="
                                (v) =>
                                    emit('update-options', { showAssignees: v })
                            "
                        />
                    </fieldset>
                </div>
            </ReportOptionsPopover>
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
