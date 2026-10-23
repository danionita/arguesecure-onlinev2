<script setup lang="ts">
/**
 * Chart, List, or Report (W32; third position added in W35).
 *
 * ## Why the report is a position here and not a link
 *
 * Chart / List / Report is the actual working order — sketch the shape, fill
 * in the values, read the answer — and the switcher is already where a user
 * goes to change what they are looking at. W33 put a **Report** link in the
 * assessment page's own header instead, on the reasoning that the toolbar
 * holds view state and a link out of the page is not that. True of a link;
 * this is not one. Making it the third position says the three surfaces are
 * three views of one assessment, which is the whole organising idea of 2.2.
 *
 * ## Why "report" is not part of the persisted mode
 *
 * `useEditorViewMode` still persists only `chart | list`. The report is a
 * route, and folding it into the persisted preference would mean someone who
 * last read a report opens the *report* the next time they open the
 * assessment — a reader's detour silently becoming their default editor. The
 * third position is derived from where you are, not remembered.
 *
 * ## Two callers, two shapes
 *
 * The editor passes its `EditorViewModeState` and switches client-side. The
 * report page has no store, no editor view, and may be being read by an
 * anonymous share-link guest, so it passes `current="report"` and lets the
 * component navigate. Chart and List from the report page write the persisted
 * mode before leaving (`persistEditorViewMode`), because landing on the chart
 * after clicking List would be a lie.
 */
import SegmentedControl from '@/Components/UI/SegmentedControl.vue';
import {
    persistEditorViewMode,
    type EditorViewMode,
    type EditorViewModeState,
} from '@/composables/useEditorViewMode';
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';

/** The three surfaces of an assessment. Only two of them are editor modes. */
export type EditorSurface = EditorViewMode | 'report';

const props = withDefaults(
    defineProps<{
        /** Present in the editor; absent on the report page. */
        viewMode?: EditorViewModeState | null;
        /** Which position is selected. Defaults to the editor's own mode. */
        current?: EditorSurface | null;
        /** Where Report goes. Omitted only where no report route exists. */
        reportHref?: string | null;
        /** Where Chart and List go from outside the editor. */
        editorHref?: string | null;
        /** Needed only when this component has to persist the mode itself. */
        assessmentUuid?: string | null;
        userId?: string | number | null;
    }>(),
    {
        viewMode: null,
        current: null,
        reportHref: null,
        editorHref: null,
        assessmentUuid: null,
        userId: null,
    },
);

const options = computed(() => {
    const base: {
        value: string;
        label: string;
        hint: string;
        badge?: string;
    }[] = [
        {
            value: 'chart',
            label: 'Chart',
            hint: 'The whole assessment as an org chart — best for building it with other people in the room.',
        },
        {
            value: 'list',
            label: 'List',
            hint: 'The indented outline, with the values you have entered on each row. Best for filling them in.',
        },
    ];

    if (props.reportHref) {
        base.push({
            value: 'report',
            label: 'Report',
            hint: 'Beta — everything the engine computed, in full, ready to read or print. Its layout and contents may still change.',
            // 2.5.1: the report is still in beta, and says so wherever it can
            // be chosen — here, in all three views.
            badge: 'Beta',
        });
    }

    return base;
});

const selected = computed<EditorSurface>(
    () => props.current ?? props.viewMode?.mode.value ?? 'chart',
);

function set(value: string) {
    const next = value as EditorSurface;
    if (next === selected.value) return;

    if (next === 'report') {
        if (props.reportHref) router.visit(props.reportHref);
        return;
    }

    // In the editor: a client-side switch, no navigation, no state lost.
    if (props.viewMode) {
        props.viewMode.set(next);
        return;
    }

    // Outside it: remember the choice, then go where it applies.
    if (props.assessmentUuid) {
        persistEditorViewMode(next, {
            assessmentUuid: props.assessmentUuid,
            userId: props.userId,
        });
    }
    if (props.editorHref) router.visit(props.editorHref);
}
</script>

<template>
    <!-- No visible caption: the options say what they are. The group keeps its
         accessible name through aria-label. -->
    <SegmentedControl
        :model-value="selected"
        :options="options"
        aria-label="View"
        @update:model-value="set"
    />
</template>
