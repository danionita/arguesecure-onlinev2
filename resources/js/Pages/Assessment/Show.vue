<script setup lang="ts">
/**
 * The tree editor (ui.md §3, PLAN.md W12) — "the screen the tool lives or
 * dies by." Loads the whole DAG once via Inertia props, builds the client
 * store (resources/js/stores/assessmentStore.ts) and renders it recursively
 * from the root occurrence down (Components/Tree/TreeNode.vue).
 */
import ChartView from '@/Components/Chart/ChartView.vue';
import ChromeTitleRow from '@/Components/Chrome/ChromeTitleRow.vue';
import NodeModal from '@/Components/NodeModal/NodeModal.vue';
import { useNodeModal } from '@/Components/NodeModal/useNodeModal';
import PresenceAvatars from '@/Components/Presence/PresenceAvatars.vue';
import ShareDialog from '@/Components/Share/ShareDialog.vue';
import ShortcutsOverlay from '@/Components/Shortcuts/ShortcutsOverlay.vue';
import AssessmentActions from '@/Components/Toolbar/AssessmentActions.vue';
import Toolbar from '@/Components/Toolbar/Toolbar.vue';
import TreeNode from '@/Components/Tree/TreeNode.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import ReparentDialog from '@/Components/UI/ReparentDialog.vue';
import { useToast } from '@/Components/UI/useToast';
import { DRAG_DROP_KEY, useDragDrop } from '@/composables/useDragDrop';
import { createEditorView, EDITOR_VIEW_KEY } from '@/composables/useEditorView';
import { useKeyboardOutline } from '@/composables/useKeyboardOutline';
import { LOCKS_KEY, useLocks } from '@/composables/useLocks';
import { PRESENCE_KEY, usePresence } from '@/composables/usePresence';
import { usePrintOnArrival } from '@/composables/usePrintOnArrival';
import {
    applyRealtimeUpdatesToStore,
    REALTIME_KEY,
    useRealtime,
    type RealtimeChangeNotice,
} from '@/composables/useRealtime';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import {
    ASSESSMENT_STORE_KEY,
    createAssessmentStore,
    type Abilities,
    type AssessmentSummary,
    type AttackDefenceLink,
    type CalculationResults,
    type NodeRecord,
    type RiskAttackLink,
    type ScaleLevelRecord,
    type ScaleRecord,
} from '@/stores/assessmentStore';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, provide, ref } from 'vue';

const props = defineProps<{
    assessment: AssessmentSummary;
    nodes: NodeRecord[];
    riskAttackLinks: RiskAttackLink[];
    attackDefenceLinks: AttackDefenceLink[];
    // W27 defect 2: AssessmentController has always sent these; this page
    // never declared them, so the store recomputed against an empty
    // calibration and threw on every edit. See recomputeLocally().
    scaleLevels: ScaleLevelRecord[];
    scales: ScaleRecord[];
    results: CalculationResults;
    abilities: Abilities;
    /** W52: whether to offer the assistant actions. See AssessmentController. */
    assistantAvailable: boolean;
}>();

// W138: the Print menu's other-view items arrive here with a one-shot token.
usePrintOnArrival();

const store = createAssessmentStore({
    assessment: props.assessment,
    nodes: props.nodes,
    riskAttackLinks: props.riskAttackLinks,
    attackDefenceLinks: props.attackDefenceLinks,
    scaleLevels: props.scaleLevels,
    scales: props.scales,
    results: props.results,
    abilities: props.abilities,
    assistantAvailable: props.assistantAvailable,
});
provide(ASSESSMENT_STORE_KEY, store);

// One shared instance of the W15 view state for the whole screen: the toolbar
// sets a filter, the tree dims to match. Two instances would silently
// disagree — see composables/useEditorView.ts.
const page = usePage();
const view = createEditorView(store, {
    assessmentUuid: props.assessment.uuid,
    userId: page.props.auth?.user?.id ?? null,
});
provide(EDITOR_VIEW_KEY, view);

// W27 defect 3: W14 wrote and unit-tested useDragDrop and then nothing ever
// imported it, so the product shipped with no way to reorder or re-parent
// anything. One instance for the page — the drag handoff between a source
// card's `dragstart` and a target card's `drop` lives inside it.
const dragDrop = useDragDrop(store);
provide(DRAG_DROP_KEY, dragDrop);

// W16 — one Reverb connection per page visit, shared by presence, locks and
// the store-patching listeners the same way the store and editor view above
// are: constructing a second connection would just be a second, disagreeing
// socket. security.md §8: a guest with no `view` on this assessment never
// gets this far in the first place (channel auth in routes/channels.php),
// so it is safe to always attempt the connection here.
//
// W27 defect 1: the credentials come from the `reverb` shared Inertia prop,
// not from `import.meta.env`. Vite substitutes VITE_* at image build time,
// but the entrypoint generates the Reverb credentials at container start —
// so the built bundle never had a key and this connection was never opened
// in any deployment. See useRealtime.ts.
const realtime = useRealtime(props.assessment.uuid, page.props.reverb);
provide(REALTIME_KEY, realtime);

const currentUser = computed(() => {
    const user = page.props.auth?.user;
    return user ? { id: user.id, name: user.name } : null;
});
const presence = usePresence(realtime, currentUser.value);
provide(PRESENCE_KEY, presence);

// W16 stage 3 (PLAN.md Phase 6 item 3, ui.md §6): field-level Redis locks.
// See useLocks.ts for why the 15s heartbeat is real authenticated HTTP
// through LockController, not a Reverb whisper.
const locks = useLocks(realtime, props.assessment.uuid);
provide(LOCKS_KEY, locks);

// W16 stage 5 (PLAN.md Phase 6 item 5, ui.md §6): "toasts are throttled and
// grouped: 'Dana made 6 changes', not six toasts. Suppress toasts for one's
// own actions." applyRealtimeUpdatesToStore() (useRealtime.ts) already
// suppresses the actor's own changes before this callback ever fires — what
// remains here is purely the per-actor grouping window: each collaborator
// gets their own pending counter and a short quiet-period timer, so a burst
// of edits collapses into the single toast below rather than one per field.
const toast = useToast();
const pendingByActor = new Map<
    string,
    {
        name: string;
        count: number;
        summary: string;
        timer: ReturnType<typeof window.setTimeout>;
    }
>();
const GROUPING_WINDOW_MS = 1200;

function onRemoteChange(notice: RealtimeChangeNotice) {
    if (notice.actorId === null) return;
    const key = String(notice.actorId);
    const existing = pendingByActor.get(key);

    if (existing) {
        window.clearTimeout(existing.timer);
        existing.count += 1;
        existing.summary = notice.summary;
    }

    const pending = existing ?? {
        name: notice.actorName ?? 'Someone',
        count: 1,
        summary: notice.summary,
        timer: 0 as unknown as ReturnType<typeof window.setTimeout>,
    };

    pending.timer = window.setTimeout(() => {
        pendingByActor.delete(key);
        toast.push(
            pending.count === 1
                ? `${pending.name} ${pending.summary}`
                : `${pending.name} made ${pending.count} changes`,
        );
    }, GROUPING_WINDOW_MS);

    pendingByActor.set(key, pending);
}

// W16 stage 2 (PLAN.md Phase 6 item 4, ui.md §6): applies every granular
// node/link/totals broadcast straight onto the store's own reactive
// `nodes`/`riskAttackLinks`/`attackDefenceLinks`/`results` — no re-fetch,
// matching CLAUDE.md's explicit "do not repeat" of the legacy
// id-then-refetch design.
const stopRealtimePatcher = applyRealtimeUpdatesToStore(
    realtime,
    store,
    currentUser.value?.id,
    onRemoteChange,
);
onBeforeUnmount(() => {
    stopRealtimePatcher();
    for (const pending of pendingByActor.values())
        window.clearTimeout(pending.timer);
});

const modal = useNodeModal();

// W27 defect: ShareController's routes existed with no page mounting the
// dialog that talks to them, so sharing had no reachable UI at all. Local
// to this page rather than store state — nothing else needs to know the
// dialog is open.
const shareDialogOpen = ref(false);

// Scopes the arrow/Enter/Tab bindings to the tree, so they don't fire while
// the node modal or a toolbar input has focus (useKeyboardOutline.ts).
const treeContainer = ref<HTMLElement | null>(null);

// Panning scrolls the container itself, so useCanvas needs the element as
// well as the event — the template can't supply a second argument on its own.
function onPointerDown(event: PointerEvent) {
    if (treeContainer.value) {
        view.canvas.handlePointerDown(event, treeContainer.value);
    }
}

function onPointerMove(event: PointerEvent) {
    if (treeContainer.value) {
        view.canvas.handlePointerMove(event, treeContainer.value);
    }
}

// W25: undo/redo are plain JSON endpoints, so they cannot be called with
// `router.post`.
//
// UndoController returns `response()->json([...])` for success and for both
// of its failure cases. Inertia's router requires an Inertia response and
// treats anything else as a fatal protocol error — it swallows the result
// and renders the body in a full-screen error iframe. So Ctrl+Z performed no
// undo at all and covered the page with an error overlay, even though the
// undo stack, the command appliers and the endpoint itself were all working.
//
// Going through window.axios matches how every other mutation on this screen
// talks to the server (assessmentStore.ts), and `router.reload()` then pulls
// the fresh graph and totals back through Inertia — the restored node's
// edges are recreated server-side, so patching the store by hand here would
// have to duplicate the applier's logic.
async function undo() {
    await window.axios.post(
        route('assessments.undo.store', props.assessment.uuid),
    );
    router.reload();
}

async function redo() {
    await window.axios.post(
        route('assessments.redo.store', props.assessment.uuid),
    );
    router.reload();
}

useKeyboardOutline({
    store,
    container: treeContainer,
    onOpenModal: (uuid) => modal.open(uuid),
    onAddChild: (parentType, parentUuid, parentPath) => {
        if (store.abilities.update) {
            modal.openDraft(parentType, parentUuid, parentPath);
        }
    },
    onUndo: undo,
    onRedo: redo,
    onFocusSearch: () => view.search.focus(),
    onZoom: (direction) => {
        if (direction === 'in') view.canvas.zoomIn();
        else if (direction === 'out') view.canvas.zoomOut();
        else view.canvas.zoomReset();
    },
});

const rootUuid = computed(() => store.rootUuid());

const title = computed(
    () =>
        (rootUuid.value && store.nodes[rootUuid.value]?.title) ||
        'Untitled assessment',
);

const saveLabel = computed(() => {
    switch (store.saveState.value) {
        case 'saving':
            return 'Saving…';
        case 'saved':
            return 'Saved';
        case 'conflict':
            return 'Conflict';
        default:
            return null;
    }
});

const conflictEntries = computed(() => Object.entries(store.conflicts));
</script>

<template>
    <Head :title="title" />

    <AuthenticatedLayout>
        <template #header>
            <!-- W81: the title row is `Components/Chrome/ChromeTitleRow.vue`,
                 which the report renders too — its height is the height of
                 everything above the view switcher, and ui.md §9 requires the
                 switcher to land on the same line in all three views. -->
            <ChromeTitleRow :title="title">
                <!-- ui.md §6: presence avatars. Show.vue's own header, not
                     AuthenticatedLayout's top bar — that layout is outside
                     this package's file ownership. -->
                <PresenceAvatars
                    :users="presence.others.value"
                    class="ml-auto"
                />
                <!-- W82, D40: Settings · History · Share · Export · Print,
                     one component, replacing what through W81 was a bare
                     Share button here and nothing else — Settings and
                     History lived in the sidebar, Export and Print existed
                     only on the report page. `AssessmentActions` renders
                     nothing `store.abilities` does not allow; Share stays a
                     page-level dialog (below), which is why the component
                     emits rather than opening one itself. -->
                <AssessmentActions
                    :assessment="assessment"
                    :abilities="store.abilities"
                    :view-mode="view.viewMode.mode.value"
                    :set-view-mode="view.viewMode.set"
                    @share="shareDialogOpen = true"
                />
                <div
                    v-if="saveLabel"
                    class="text-text-muted flex items-center gap-1.5 text-sm"
                    role="status"
                    aria-live="polite"
                >
                    <Icon
                        v-if="store.saveState.value === 'saving'"
                        name="loader-circle"
                        :size="14"
                        class="animate-spin"
                    />
                    <Icon
                        v-else-if="store.saveState.value === 'saved'"
                        name="check"
                        :size="14"
                    />
                    <Icon
                        v-else-if="store.saveState.value === 'conflict'"
                        name="alert-circle"
                        :size="14"
                        class="text-node-attack-text"
                    />
                    {{ saveLabel }}
                </div>
            </ChromeTitleRow>
        </template>

        <!-- W141: a template opens in this editor. Said once, above everything,
             because the one thing that differs is what editing it means. -->
        <div
            v-if="assessment.is_template"
            data-testid="template-banner"
            class="border-border-default bg-surface-2 text-text-secondary mx-3 mt-3 flex items-center justify-between gap-4 rounded-md border px-4 py-2 text-sm"
        >
            <span>
                You are editing a template. Changes apply to assessments started
                from it from now on; assessments already created from it are
                copies and do not change.
            </span>
            <Link
                :href="route('templates.index')"
                class="text-accent shrink-0 underline"
            >
                Back to templates
            </Link>
        </div>

        <!-- ui.md §3.5: never silently clobber, never silently discard the
             user's typing. Above the toolbar, where it was through W80: a
             conflict is about the document, not about the view of it. -->
        <div v-if="conflictEntries.length" class="px-3 pt-3">
            <div
                v-for="[uuid, conflict] in conflictEntries"
                :key="uuid"
                class="border-status-todo-border bg-status-todo-bg text-status-todo-text mb-3 flex items-center justify-between gap-4 rounded-md border px-4 py-2 text-sm"
            >
                <span>
                    Someone changed
                    <strong>{{ conflict.theirs.title || 'this node' }}</strong>
                    while you were editing.
                </span>
                <div class="flex shrink-0 gap-2">
                    <Button
                        size="sm"
                        @click="store.resolveConflictKeepMine(uuid)"
                    >
                        Keep mine
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        @click="store.resolveConflictTakeTheirs(uuid)"
                    >
                        Take theirs
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        @click="store.resolveConflictCancel(uuid)"
                    >
                        Cancel
                    </Button>
                </div>
            </div>
        </div>

        <Toolbar
            :report-href="route('assessments.report.show', assessment.uuid)"
            :available-tags="store.availableTagNames.value"
        />

        <!-- W34 / brief item 1: the editor is a canvas, not a document, so it
             takes the whole window. `max-w-7xl` capped it at 1280px and
             letterboxed the chart on exactly the wide screens the chart view
             exists to exploit. The centred container stays on the pages that
             read as documents (the report, the lists, profile). The header
             above is still constrained by AuthenticatedLayout, which W41
             owns and replaces with the sidebar shell.

             W81: the toolbar is no longer inside this padding. Its band was
             `px-3 py-3` here and the row inside it `px-3 py-1.5` in
             Toolbar.vue — two paddings in two files that the report had to
             match by inspection, and did not. Both belong to
             Components/Chrome/ChromeToolbarBand.vue now, so this wrapper pads
             only the content below the bar. -->
        <div class="px-3 py-3">
            <!-- ui.md §5: both scroll axes, panning by middle-drag or
                 space-drag. Zoom is applied by whichever view is mounted —
                 `listStyle` here, `canvasStyle` inside ChartView — because
                 after W34 the same zoom number means two different things
                 (PLAN-2.2.md D9). -->
            <div
                ref="treeContainer"
                class="overflow-auto"
                tabindex="-1"
                @wheel="view.canvas.handleWheel"
                @keydown="view.canvas.handleContainerKeydown"
                @keyup="view.canvas.handleContainerKeyup"
                @pointerdown="onPointerDown"
                @pointermove="onPointerMove"
                @pointerup="view.canvas.handlePointerUp"
            >
                <ChartView v-if="view.viewMode.mode.value === 'chart'" />
                <!--
                    W70, item 8. The chart wants the whole window; the list
                    does not. Left full-bleed on a wide monitor a risk row put
                    its title at the far left and its chips a metre away at
                    the far right, with nothing in between — the eye has to
                    travel the width of the screen to pair a value with the
                    thing it belongs to, which is the one job this view has.
                    `max-w-4xl` caps it at a measure where a long title and
                    its chips are in one glance. It is a cap, not a width:
                    narrow windows are unaffected. Whether the capped column
                    sits centred or flush left depends on the viewport — see
                    the W84 paragraph below, which is the current statement
                    of that; this paragraph is only about why there is a cap
                    at all.

                    Applied here rather than on the scroll container so the
                    container keeps scrolling the full viewport, and so that
                    CSS `zoom` on this element scales the cap with the text —
                    zoom in and the measure grows with the type, which is what
                    a reading zoom is for.

                    Print is untouched. `print.css` resets `zoom` to 1 and
                    fixes the type at 9pt (W33), and 4xl is 64rem against an
                    A4 text column of roughly 718px, so the cap never binds on
                    paper. Only the editor's list is capped: the frozen
                    snapshot reader (Pages/Snapshots/View.vue) renders its own
                    tree and is not W70's to change.

                    W84/item 11: `mx-auto` centres this column in the wider
                    scroll container above a bare left-aligned cap put the
                    whole list hard against the window's left edge on any
                    screen wider than the 4xl measure, with no reason for that
                    over centring it. The chart earns a *conditional* centring
                    (ChartView.vue) because a wide chart's root would go
                    off-screen if it centred too; the list, capped at a fixed
                    measure, has no such case — it is narrower than its
                    container whenever the cap binds at all, which is every
                    screen wide enough to show the cap doing anything.
                -->
                <ul
                    v-else-if="rootUuid"
                    role="tree"
                    :aria-label="title"
                    class="mx-auto max-w-4xl text-sm"
                    :style="view.canvas.listStyle.value"
                >
                    <TreeNode :uuid="rootUuid" :ancestor-path="[]" />
                </ul>
            </div>
        </div>

        <NodeModal />

        <!-- W38: one mount for both views. The drag handle is a page
             singleton (the dragstart/drop handoff lives inside it), so the
             dialog that resolves a drop has to be one too. -->
        <ReparentDialog
            :show="dragDrop.pending.value !== null"
            :child-title="dragDrop.pending.value?.childTitle ?? ''"
            :to-parent-title="dragDrop.pending.value?.toParentTitle ?? ''"
            :can-link="dragDrop.pending.value?.canLink ?? false"
            @move="dragDrop.resolve('move')"
            @link="dragDrop.resolve('link')"
            @cancel="dragDrop.cancel()"
        />
        <ShortcutsOverlay />
        <ShareDialog
            :show="shareDialogOpen"
            :assessment-uuid="assessment.uuid"
            :can-transfer="store.abilities.transfer"
            :can-publish-template="store.abilities.publishTemplate ?? false"
            @close="shareDialogOpen = false"
        />
    </AuthenticatedLayout>
</template>
