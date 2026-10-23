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
import Dropdown from '@/Components/UI/Dropdown.vue';
import { persistEditorViewMode } from '@/composables/useEditorViewMode';
import { withPrintToken } from '@/composables/usePrintOnArrival';
import { router } from '@inertiajs/vue3';
import { computed, nextTick } from 'vue';

export interface AssessmentActionsAssessment {
    uuid: string;
    /** W141: a template has no owner to share it from, so it has no Share. */
    is_template?: boolean;
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
        /**
         * The editor's own client-side switch. Present only where Chart and
         * List are one click apart without navigation; absent on the report,
         * which has to visit the editor to print them.
         */
        setViewMode?: ((mode: 'chart' | 'list') => void) | null;
        userId?: string | number | null;
    }>(),
    {
        viewMode: 'chart',
        exportOptions: undefined,
        setViewMode: null,
        userId: null,
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
            includeMethodology: false,
            includeWarnings: false,
            showAssignees: false,
            omittedSections: [],
            download: false,
        },
);

/**
 * Current view first: the common case stays Print, then Enter (D109).
 */
const printChoices = computed(() => {
    const all: { value: AssessmentActionsView; label: string }[] = [
        { value: 'chart', label: 'Chart' },
        { value: 'list', label: 'List' },
        { value: 'report', label: 'Report' },
    ];

    return [
        ...all.filter((c) => c.value === props.viewMode),
        ...all.filter((c) => c.value !== props.viewMode),
    ];
});

function printNow() {
    if (typeof window !== 'undefined') window.print();
}

/**
 * Print is a CSS pipeline per view (D42), so the chosen view has to be on
 * screen before the dialog opens (D110). The current view prints in place; the
 * other editor view is switched to client-side and printed after it has
 * rendered; anything else is visited with a one-shot `?print=` token that the
 * target prints on arrival (`usePrintOnArrival`).
 *
 * `print` is emitted for the report so its one-time "Save as PDF" hint can
 * show before the dialog steals focus.
 */
async function printView(target: AssessmentActionsView) {
    if (target === 'report') emit('print');

    if (target === props.viewMode) {
        printNow();
        return;
    }

    if (target !== 'report' && props.setViewMode) {
        props.setViewMode(target);
        await nextTick();
        window.requestAnimationFrame(() => window.setTimeout(printNow, 0));
        return;
    }

    if (target === 'report') {
        router.visit(
            withPrintToken(
                route('assessments.report.show', props.assessment.uuid),
            ),
        );
        return;
    }

    persistEditorViewMode(target, {
        assessmentUuid: props.assessment.uuid,
        userId: props.userId,
    });
    router.visit(
        withPrintToken(route('assessments.show', props.assessment.uuid)),
    );
}

const itemClass =
    'text-text-primary hover:bg-surface-2 focus-visible:outline-focus-ring flex w-full items-center justify-between gap-3 px-3 py-1.5 text-left text-xs outline-none focus-visible:outline-2 focus-visible:-outline-offset-2';
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
            v-if="abilities.manage && !assessment.is_template"
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

        <Dropdown align="right" width="48">
            <template #trigger>
                <Button
                    size="sm"
                    icon="file-text"
                    aria-haspopup="menu"
                    data-testid="print-trigger"
                >
                    Print
                </Button>
            </template>

            <template #content>
                <div role="menu" aria-label="Print which view">
                    <button
                        v-for="(choice, index) in printChoices"
                        :key="choice.value"
                        type="button"
                        role="menuitem"
                        :aria-label="`Print ${choice.label.toLowerCase()}`"
                        :data-testid="`print-${choice.value}`"
                        :class="itemClass"
                        @click="printView(choice.value)"
                    >
                        <span class="font-medium">{{ choice.label }}</span>
                        <span
                            v-if="index === 0"
                            class="text-text-muted text-[11px]"
                            >current view</span
                        >
                    </button>
                </div>
            </template>
        </Dropdown>
    </div>
</template>
