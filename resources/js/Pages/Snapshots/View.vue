<script setup lang="ts">
/**
 * Browsing a frozen version in the editor's own views (W63).
 *
 * `Snapshots/Show.vue` answers "what did this version total?". This answers
 * "what did it *look* like?" — which is the question people actually have when
 * something has gone wrong and they are deciding whether to restore.
 *
 * ## The same components, not a second set of them
 *
 * `ChartView` and `TreeNode` are the live editor's, mounted here against a
 * store built from `snapshots.graph`. A read-only chart of its own would have
 * been easier and would go wrong slowly: two renderers of one graph drift, a
 * tier gains an indicator on one side only, and the version people look at when
 * they are worried is the one still drawing it the old way. The frozen graph is
 * the same shape as the live one, so the data is the only thing that differs.
 *
 * ## Read-only twice over, and the second one is the real guard
 *
 * Every ability is false, which removes every add, delete and edit affordance —
 * those are all `v-if`-gated on `abilities.update`. But abilities gate what is
 * *offered*, and two things slip past that: `loadUnresolvedCommentCounts()`
 * fires from `onMounted` with no ability check, and the label ids on this graph
 * are minted for the render (`SnapshotViewPresenter::vocabulary()`) rather than
 * being real rows. So the store is also built `frozen`, which turns every
 * method that would reach the network into a no-op — a guard at the method
 * rather than at the button.
 *
 * ## No node modal, deliberately
 *
 * The modal's tabs read live comments, history and references. Rendering
 * today's conversation against a version it did not happen on is worse than not
 * offering it, so no `NodeModal` is mounted and the cards that would open one
 * hide the control instead of leading nowhere.
 *
 * ## Its own persisted zoom and collapse state
 *
 * `createEditorView` namespaces `localStorage` by the string it is handed as
 * `assessmentUuid`. Passing the real uuid would make reading an old version
 * quietly rewrite the reader's collapse and zoom state in the live editor, so
 * the snapshot id goes into the key and the two never touch.
 */
import ChartView from '@/Components/Chart/ChartView.vue';
import RestoreSnapshotDialog from '@/Components/Snapshots/RestoreSnapshotDialog.vue';
import type { SnapshotSummary } from '@/Components/Snapshots/types';
import ZoomControls from '@/Components/Toolbar/ZoomControls.vue';
import TreeNode from '@/Components/Tree/TreeNode.vue';
import Button from '@/Components/UI/Button.vue';
import Chip from '@/Components/UI/Chip.vue';
import Icon from '@/Components/UI/Icon.vue';
import SegmentedControl from '@/Components/UI/SegmentedControl.vue';
import { createEditorView, EDITOR_VIEW_KEY } from '@/composables/useEditorView';
import {
    ASSESSMENT_STORE_KEY,
    createAssessmentStore,
    type AssessmentSummary,
    type AttackDefenceLink,
    type CalculationResults,
    type NodeRecord,
    type RiskAttackLink,
    type ScaleLevelRecord,
    type ScaleRecord,
    type TagRecord,
} from '@/stores/assessmentStore';
import { Head, Link } from '@inertiajs/vue3';
import { computed, provide, ref } from 'vue';

const props = defineProps<{
    snapshot: SnapshotSummary & { note: string | null };
    canRestore: boolean;
    assessment: AssessmentSummary;
    nodes: NodeRecord[];
    riskAttackLinks: RiskAttackLink[];
    attackDefenceLinks: AttackDefenceLink[];
    scales: ScaleRecord[];
    scaleLevels: ScaleLevelRecord[];
    tags: TagRecord[];
    results: CalculationResults;
}>();

const store = createAssessmentStore({
    assessment: props.assessment,
    nodes: props.nodes,
    riskAttackLinks: props.riskAttackLinks,
    attackDefenceLinks: props.attackDefenceLinks,
    scales: props.scales,
    scaleLevels: props.scaleLevels,
    tags: props.tags,
    results: props.results,
    // Every one of them, including `comment`: a frozen version is not a place
    // to start a conversation, and a comment left here would attach to the live
    // node anyway.
    abilities: {
        comment: false,
        update: false,
        manage: false,
        transfer: false,
    },
    assistantAvailable: false,
    frozen: true,
});

provide(ASSESSMENT_STORE_KEY, store);

const view = createEditorView(store, {
    // Namespaced to this snapshot, never the assessment — see the page note.
    assessmentUuid: `snapshot:${props.snapshot.id}`,
    userId: null,
});

provide(EDITOR_VIEW_KEY, view);

const rootUuid = computed(() => store.rootUuid());
const title = computed(
    () =>
        (rootUuid.value ? store.nodes[rootUuid.value]?.title : '') ||
        'Untitled assessment',
);

const restoring = ref(false);

const MODES = [
    { value: 'chart', label: 'Chart' },
    { value: 'list', label: 'List' },
];

// Deliberately not the editor's ViewSwitcher: that control carries a third
// Report position, and a report route serves the *live* assessment. Landing on
// today's figures from a page about an old version would be the one navigation
// this page must not make easy.
function setMode(mode: string): void {
    view.viewMode.set(mode === 'list' ? 'list' : 'chart');
}
</script>

<template>
    <Head :title="`${snapshot.name} — ${title}`" />

    <div class="bg-surface-0 flex min-h-screen flex-col">
        <!--
            The banner is the whole safety story of this page. Someone arriving
            from a link, or returning to a tab left open, has to be able to tell
            in one glance that what they are reading is not the assessment —
            because everything below it looks exactly like the assessment. It is
            sticky for the same reason: scrolled out of view, it stops being an
            answer to that question.

            The palette has no dedicated warning token; `status-todo` is the
            notice tone it already uses for the same job in the value panels.
            Colour is not the only channel — the icon, the heading and the
            Read-only chip all say it independently (ui.md §2).
        -->
        <header
            class="border-status-todo-border bg-status-todo-bg sticky top-0 z-20 border-b px-4 py-2"
            data-testid="frozen-banner"
        >
            <div
                class="mx-auto flex max-w-[1600px] flex-wrap items-center gap-3"
            >
                <span
                    class="text-status-todo-text flex items-center gap-1.5 text-sm font-semibold"
                >
                    <Icon name="history" :size="14" />
                    Viewing a saved version
                </span>
                <span class="text-text-secondary min-w-0 truncate text-sm">
                    <strong class="text-text-primary">{{
                        snapshot.name
                    }}</strong>
                    ·
                    {{
                        snapshot.createdAt
                            ? new Date(snapshot.createdAt).toLocaleString()
                            : 'an unknown date'
                    }}
                    · {{ snapshot.createdBy ?? 'Unknown' }}
                </span>
                <Chip>Read-only</Chip>

                <div class="ml-auto flex shrink-0 items-center gap-2">
                    <Link
                        :href="
                            route('assessments.snapshots.show', [
                                assessment.uuid,
                                snapshot.id,
                            ])
                        "
                        class="text-text-muted hover:text-text-primary text-xs underline"
                    >
                        Figures
                    </Link>
                    <Link
                        :href="
                            route('assessments.history.index', assessment.uuid)
                        "
                        class="text-text-muted hover:text-text-primary text-xs underline"
                    >
                        All versions
                    </Link>
                    <!--
                        The way back to the live assessment is a permanent
                        control rather than the browser's back button: this page
                        is linkable, so a reader can arrive here without ever
                        having been there.
                    -->
                    <Link
                        :href="route('assessments.show', assessment.uuid)"
                        class="text-text-muted hover:text-text-primary text-xs underline"
                    >
                        Back to the current version
                    </Link>
                    <Button
                        v-if="canRestore"
                        variant="secondary"
                        icon="history"
                        @click="restoring = true"
                    >
                        Restore this version
                    </Button>
                </div>
            </div>
        </header>

        <div class="mx-auto w-full max-w-[1600px] flex-1 px-4 py-3">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <h1
                        class="text-text-primary truncate text-lg font-semibold"
                    >
                        {{ title }}
                    </h1>
                    <p
                        v-if="snapshot.note"
                        class="text-text-secondary truncate text-xs"
                    >
                        {{ snapshot.note }}
                    </p>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <SegmentedControl
                        :model-value="view.viewMode.mode.value"
                        :options="MODES"
                        @update:model-value="setMode"
                    />
                    <ZoomControls
                        :zoom="view.canvas.zoom.value"
                        :view-mode="view.viewMode.mode.value"
                        @zoom-in="view.canvas.zoomIn"
                        @zoom-out="view.canvas.zoomOut"
                        @zoom-reset="view.canvas.zoomReset"
                    />
                </div>
            </div>

            <div
                class="overflow-auto"
                tabindex="-1"
                @wheel="view.canvas.handleWheel"
                @keydown="view.canvas.handleContainerKeydown"
                @keyup="view.canvas.handleContainerKeyup"
            >
                <ChartView v-if="view.viewMode.mode.value === 'chart'" />
                <ul
                    v-else-if="rootUuid"
                    role="tree"
                    :aria-label="title"
                    class="text-sm"
                    :style="view.canvas.listStyle.value"
                >
                    <TreeNode :uuid="rootUuid" :ancestor-path="[]" />
                </ul>
            </div>

            <!--
                Said once, at the bottom, rather than as a badge on every card.
                A reader who has scrolled this far is deciding whether to trust
                what they are looking at, and this is the honest boundary of it.
            -->
            <p class="text-text-muted mt-6 text-xs" data-testid="frozen-note">
                Comments, presence and locks are not part of a saved version and
                are not shown here. Figures are exactly as they were computed
                when this version was saved — nothing on this page is
                recalculated.
            </p>
        </div>

        <RestoreSnapshotDialog
            :assessment-uuid="assessment.uuid"
            :snapshot="restoring ? snapshot : null"
            @close="restoring = false"
        />
    </div>
</template>
