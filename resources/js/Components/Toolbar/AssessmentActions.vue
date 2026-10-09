<script setup lang="ts">
/**
 * The one action bar every assessment view renders (W82, D40): **Settings ·
 * History · Share · Export · Print**, always in that order.
 *
 * Five actions used to exist in four arrangements: Settings and History
 * lived in `Sidebar.vue`, gated on the sidebar's own `currentAssessment`
 * prop; Share was a bare `Button` in the editor's `ChromeTitleRow` slot,
 * gated on `store.abilities.manage`; Export and Print were an `ExportMenu`
 * and a second `Button` in the report's own `ChromeTitleRow` slot. Four
 * places that had to agree by inspection is exactly the failure W81 records
 * for the toolbar band itself six pixels at a time — the fix is the same
 * one: render this from the chrome the three views already share
 * (`Components/Chrome/*`), not compose four different clusters per page.
 *
 * ## The prop contract
 *
 * `assessment` and `abilities` are the minimal shapes every caller already
 * has to hand — an assessment's `uuid` and the four `AssessmentPolicy`
 * booleans `stores/assessmentStore.ts` calls `Abilities`. `viewMode` is
 * real, not decorative: it only changes the Print button's accessible name
 * today (`aria-label="Print chart"` etc., the same pattern
 * `ZoomControls.vue` uses for "Decrease chart size" vs "Decrease text
 * size"), but W83 and W84 are the reason it is a prop and not inferred —
 * the report is a fourth caller with no editor view-mode state of its own,
 * and a control that changes nothing per CLAUDE.md's own account of
 * `ReportOptions::$figures` is worse than no control, so this one earns its
 * keep from day one rather than waiting for a second reason to exist.
 *
 * `exportOptions` defaults to a plain reading of the current baseline: the
 * chart and list views have no live `ReportOptions` panel to read, so they
 * export what the report would show by default. The report page passes its
 * own `report.options` instead, so an export from there is never a
 * differently-configured file than the one on screen (`ExportMenu.vue`'s
 * whole reason for binding to the panel's live state).
 *
 * ## What this component does not own
 *
 * Neither `ShareDialog` nor the print-hint paragraph the report shows once
 * per browser is mounted here. Share stays a page-level dialog — it
 * self-fetches collaborator state over axios and needs a page-level `show`
 * flag — so this emits `share` and the caller decides what opening it
 * means. Print calls `window.print()` itself, because that is the whole of
 * D42 in every caller ("print the current view" — the CSS pipeline decides
 * what that means per view), and also emits `print` first so a caller that
 * wants to layer something on — the report's one-time "Choose Save as PDF"
 * hint — can, without this component knowing that hint exists.
 *
 * ## What renders nothing a viewer cannot do
 *
 * Settings and Share both require `abilities.manage` — the same ability
 * `Sidebar.vue`'s old Settings item and `Pages/Assessment/Show.vue`'s old
 * Share button each checked on their own. History, Export and Print need
 * only `view`, which is the ability that got this component mounted at
 * all: nothing in this file additionally gates them, because there is
 * nothing weaker than "you can see this assessment" left to check.
 */
import ExportMenu from '@/Components/Report/ExportMenu.vue';
import type { ReportOptions } from '@/Components/Report/types';
import Button from '@/Components/UI/Button.vue';
import { computed } from 'vue';

export interface AssessmentActionsAssessment {
    uuid: string;
}

/**
 * The two `AssessmentPolicy` booleans this component reads. A subset of
 * `stores/assessmentStore.ts`'s `Abilities` — every caller already has that
 * whole object in hand and can pass it straight through.
 */
export interface AssessmentActionsAbilities {
    /** AssessmentPolicy::manage() — gates Settings and Share. */
    manage: boolean;
}

/** Which of the three surfaces this bar is rendered from (D40's "all three views"). */
export type AssessmentActionsView = 'chart' | 'list' | 'report';

const props = withDefaults(
    defineProps<{
        assessment: AssessmentActionsAssessment;
        abilities: AssessmentActionsAbilities;
        viewMode?: AssessmentActionsView;
        exportOptions?: ReportOptions;
    }>(),
    {
        viewMode: 'chart',
        exportOptions: undefined,
    },
);

const emit = defineEmits<{ share: []; print: [] }>();

/**
 * What Export requests when no view has live report options to read — the
 * chart and list views, which have never had an options panel. Matches
 * `ReportOptions::fromQuery()`'s own defaults: the current baseline, no
 * shelved defences, methodology included, nothing else.
 *
 * A `computed` rather than a `withDefaults` default: `<script setup>`
 * compiles `defineProps()` before the rest of the file runs, so its default
 * factory cannot reference a variable declared anywhere else in this
 * module — only literals. A resolved value read through `props` has no
 * such restriction.
 */
const exportOptions = computed<ReportOptions>(
    () =>
        props.exportOptions ?? {
            baseline: 'current',
            includeShelved: false,
            includeComments: false,
            includeMethodology: true,
            showAssignees: false,
            download: false,
        },
);

const printLabel = computed(() => {
    if (props.viewMode === 'report') return 'Print report';
    if (props.viewMode === 'list') return 'Print list';
    return 'Print chart';
});

/**
 * `emit` first, `window.print()` second: a caller's own listener (the
 * report's one-time PDF hint) runs before the print dialog steals focus,
 * which is the order a hint has to run in to be seen at all.
 */
function onPrint() {
    emit('print');
    if (typeof window !== 'undefined') window.print();
}
</script>

<template>
    <div
        class="no-print flex shrink-0 items-center gap-2"
        data-print-hide
        data-testid="assessment-actions"
    >
        <Button
            v-if="abilities.manage"
            size="sm"
            variant="ghost"
            icon="settings"
            :href="route('assessments.settings.edit', assessment.uuid)"
        >
            Settings
        </Button>

        <Button
            size="sm"
            variant="ghost"
            icon="history"
            :href="route('assessments.history.index', assessment.uuid)"
        >
            History
        </Button>

        <Button
            v-if="abilities.manage"
            size="sm"
            variant="ghost"
            icon="users"
            @click="emit('share')"
        >
            Share
        </Button>

        <ExportMenu
            :assessment-uuid="assessment.uuid"
            :options="exportOptions"
        />

        <Button
            size="sm"
            icon="file-text"
            :aria-label="printLabel"
            @click="onPrint"
        >
            Print
        </Button>
    </div>
</template>
