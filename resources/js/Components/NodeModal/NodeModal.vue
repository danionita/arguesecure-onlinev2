<script setup lang="ts">
/**
 * The tabbed node modal (ui.md §7, PLAN.md W13). "Opened by Space, the edit
 * action, or clicking an indicator icon... non-blocking over the tree...
 * never becomes a route; deep-linking to a node uses a query parameter."
 *
 * Injects the assessment store the same way every Tree/** component does
 * (ASSESSMENT_STORE_KEY) rather than taking it as a prop, so mounting this
 * once near the top of Assessment/Show.vue — inside the same provide()
 * scope TreeNode/NodeCard already render under — is enough to wire it up.
 * That single `<NodeModal />` mount, plus wiring NodeCard's pencil
 * IconButton and the tree's Space/Enter key handling to call
 * `useNodeModal().open(uuid)`, are the two integration points this package
 * could not make itself: NodeCard.vue lives in Components/Tree/** and
 * Show.vue in Pages/Assessment/**, both outside W13's file ownership. See
 * the W13 report for the exact lines needed in each.
 */
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import Icon from '@/Components/UI/Icon.vue';
import IconButton from '@/Components/UI/IconButton.vue';
import Modal from '@/Components/UI/Modal.vue';
import Tabs from '@/Components/UI/Tabs.vue';
import Tooltip from '@/Components/UI/Tooltip.vue';
import type { TabItem } from '@/Components/UI/types';
import { LOCKS_KEY } from '@/composables/useLocks';
import { NODE_TYPES, type CardType } from '@/constants/nodeTypes';
import { ASSESSMENT_STORE_KEY } from '@/stores/assessmentStore';
import { computed, inject, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import NewNodeForm from './NewNodeForm.vue';
import AssigneesTab from './tabs/AssigneesTab.vue';
import CommentsTab from './tabs/CommentsTab.vue';
import DetailsTab from './tabs/DetailsTab.vue';
import HistoryTab from './tabs/HistoryTab.vue';
import LinksTab from './tabs/LinksTab.vue';
import ReferencesTab from './tabs/ReferencesTab.vue';
import ScalesTab from './tabs/ScalesTab.vue';
import StatisticsTab from './tabs/StatisticsTab.vue';
import ValuesTab from './tabs/ValuesTab.vue';
import { useNodeModal } from './useNodeModal';

const store = inject(ASSESSMENT_STORE_KEY)!;
const {
    openUuid,
    activeTab,
    draft,
    lockedBy,
    open,
    close,
    setTab,
    restoreFromQuery,
} = useNodeModal();

onMounted(restoreFromQuery);

// --- one editor per node (2.5.1) ---------------------------------------------
//
// While this modal is open on a node it holds a whole-node lock through
// LockController (`node` field, 45s TTL, refreshed every 15s by useLocks).
// Someone opening the same node meanwhile gets the modal read-only, with the
// holder's name in the header. When the holder closes — a `released` whisper,
// or the TTL running out — the reader's modal tries again and becomes
// editable, so nobody has to close and reopen to find out.
//
// Optional, like every other LOCKS_KEY injection: component tests mount the
// modal without a realtime connection, and with no lock handle the modal
// behaves as it did before — editable to anyone with `update`.
const locks = inject(LOCKS_KEY, null);
let heldUuid: string | null = null;
let retryTimer: ReturnType<typeof setInterval> | null = null;

function stopRetrying() {
    if (retryTimer !== null) clearInterval(retryTimer);
    retryTimer = null;
}

function releaseHeld() {
    if (heldUuid !== null) void locks?.release(heldUuid, 'node');
    heldUuid = null;
}

async function tryAcquire(uuid: string) {
    if (!locks || openUuid.value !== uuid) return;
    const acquired = await locks.acquire(uuid, 'node');
    // The modal may have moved on while the request was in flight.
    if (openUuid.value !== uuid) {
        if (acquired) void locks.release(uuid, 'node');
        return;
    }
    if (acquired) {
        heldUuid = uuid;
        lockedBy.value = null;
        stopRetrying();
    } else {
        lockedBy.value = locks.lockedBy(uuid, 'node');
    }
}

watch(
    openUuid,
    (uuid) => {
        stopRetrying();
        releaseHeld();
        lockedBy.value = null;
        if (!uuid || !locks || !store.abilities.update || store.frozen) return;

        void tryAcquire(uuid).then(() => {
            if (lockedBy.value && openUuid.value === uuid) {
                retryTimer = setInterval(() => void tryAcquire(uuid), 15000);
            }
        });
    },
    { immediate: true },
);

// A `released` whisper from the holder clears useLocks' holder map at once;
// try straight away rather than waiting for the next retry.
watch(
    () =>
        openUuid.value && locks ? locks.lockedBy(openUuid.value, 'node') : null,
    (holder) => {
        if (!holder && lockedBy.value && openUuid.value) {
            void tryAcquire(openUuid.value);
        }
    },
);

onBeforeUnmount(() => {
    stopRetrying();
    releaseHeld();
    lockedBy.value = null;
});

const node = computed(() =>
    openUuid.value ? store.nodes[openUuid.value] : null,
);

const cardType = computed<CardType>(() =>
    draft.value && !node.value
        ? draft.value.childType
        : node.value?.type === 'defence' && node.value.is_transfer
          ? 'transfer'
          : (node.value?.type ?? 'risk'),
);

/**
 * Written out per type rather than built as `text-node-${color}-text`: the
 * Tailwind scanner reads source text, and a template literal is invisible to
 * it, so the generated class would simply not exist in the stylesheet. Same
 * reasoning, and same shape, as the tone map in Tree/NodeCard.vue.
 */
const TYPE_ICON_TINT: Record<CardType, string> = {
    root: 'text-node-root-text',
    risk: 'text-node-risk-text',
    attack: 'text-node-attack-text',
    defence: 'text-node-defence-text',
    transfer: 'text-node-transfer-text',
};

/**
 * The modal is open on a uuid, but the store has no row for it. Two different
 * things, and the modal must say which (W71 item 1) — rendering nothing is
 * indistinguishable from "the Details tab did not load", which is exactly how
 * this was reported:
 *
 * - `loading` — the store holds no nodes at all yet, so this is a `?node=`
 *   deep link that arrived before hydration.
 * - `gone` — the store is populated and this uuid is not in it: the node was
 *   deleted, by someone else over the wire or in another tab, while the link
 *   was still being followed.
 */
const missingState = computed<'loading' | 'gone' | null>(() => {
    if (openUuid.value === null || node.value) return null;

    return Object.keys(store.nodes).length === 0 ? 'loading' : 'gone';
});

const modalTitle = computed(() => {
    // Also the dialog's aria-label, so the two missing states need one of
    // their own — an unlabelled dialog announces as just "dialog".
    if (draft.value)
        return `New ${NODE_TYPES[cardType.value].label.toLowerCase()}`;
    if (!node.value) {
        if (missingState.value === 'loading') return 'Loading node…';

        return missingState.value === 'gone' ? 'Node unavailable' : '';
    }
    const typeLabel = NODE_TYPES[cardType.value].label;
    return node.value.title ? `${typeLabel}: ${node.value.title}` : typeLabel;
});

/**
 * Only defences still have a link tab, so this only counts their edges. Risks
 * and attacks lost theirs in W39: structure is built by dragging (W38) and by
 * the add-child affordance, and a tab that duplicated both was a third way to
 * do the same thing with none of the spatial feedback of the other two.
 */
const strengthLinkCount = computed(() =>
    node.value?.type === 'defence'
        ? store.attacksForDefence(node.value.uuid).length
        : 0,
);

/**
 * Strength lives on the edge, so a defence can be missing it on one attack
 * and have it on another (schema.md §3.6). The badge is a rollup — "there is
 * something unanswered in here" — which is exactly what a tab badge should
 * say and all it can say without opening the tab.
 */
const linksMissingStrength = computed(() => {
    if (node.value?.type !== 'defence') return 0;
    const uuid = node.value.uuid;

    return store.attackDefenceLinks.filter(
        (link) =>
            link.defence_uuid === uuid &&
            link.strength_mode === null &&
            link.strength_fraction === null &&
            link.strength_level_id === null,
    ).length;
});

const hasMissingValues = computed(() => {
    if (!node.value) return false;
    if (node.value.type === 'risk') {
        return (
            node.value.impact_mode === null ||
            (node.value.impact_mode === 'quantitative' &&
                node.value.impact_amount === null) ||
            (node.value.impact_mode === 'qualitative' &&
                node.value.impact_level_id === null)
        );
    }
    if (node.value.type === 'attack') {
        return (
            node.value.likelihood_mode === null ||
            (node.value.likelihood_mode === 'quantitative' &&
                node.value.likelihood_aro === null) ||
            (node.value.likelihood_mode === 'qualitative' &&
                node.value.likelihood_level_id === null)
        );
    }
    return false;
});

const confirmingDelete = ref(false);
const deleteMessage = computed(
    () =>
        `Delete '${node.value?.title || 'this node'}'? This also removes it from every place it appears.`,
);

async function confirmDelete() {
    const uuid = node.value?.uuid;
    confirmingDelete.value = false;
    if (!uuid) return;
    // Close first: the modal is showing a node that is about to stop
    // existing, and `node` becoming undefined under a mounted tab is how a
    // render error gets into a delete path.
    close();
    await store.deleteNode(uuid);
}

/**
 * "Values" told the reader nothing. Every type has exactly one thing it is
 * quantified by — a risk by its impact, an attack by its likelihood, a
 * defence by what it costs — and the tab now says which (W39, item 9), so a
 * workshop participant reads the answer off the tab strip instead of opening
 * it to find out. The panel behind it is unchanged; only the label is.
 */
const VALUE_TAB: Record<string, { label: string; icon: TabItem['icon'] }> = {
    risk: { label: 'Impact', icon: 'banknote' },
    attack: { label: 'Likelihood', icon: 'clock' },
    defence: { label: 'Cost', icon: 'banknote' },
};

const tabs = computed<TabItem[]>(() => {
    if (!node.value) return [];
    const type = node.value.type;

    const tabs: TabItem[] = [
        { key: 'details', label: 'Details', icon: 'file-text' },
    ];

    const value = VALUE_TAB[type];
    if (value) {
        tabs.push({
            key: 'values',
            label: value.label,
            icon: value.icon,
            badge: hasMissingValues.value ? '?' : undefined,
        });
    }

    // Renamed, not removed: this is W31's per-edge strength editor, and
    // "Strength" is what it edits. The key stays `links` — see the docblock
    // on NodeModalTab for why that is not an oversight.
    if (type === 'defence') {
        tabs.push({
            key: 'links',
            label: 'Strength',
            icon: 'shield',
            badge: linksMissingStrength.value
                ? '?'
                : strengthLinkCount.value || undefined,
        });
    }

    /*
     * W72 item 10. The root node is the assessment, so the settings that say
     * what its figures *mean* — currency, tolerance, amortisation, and the
     * three qualitative scales — belong on it rather than only on a page at
     * another URL reachable from the sidebar.
     *
     * Gated on `manage`, matching UpdateAssessmentSettingsRequest exactly:
     * hiding a tab whose endpoint would 403 is courtesy, and the FormRequest
     * is still the guard. This is a deliberate edit to W71's file — a tab
     * cannot register itself — kept to these lines and reported as such.
     */
    if (type === 'root' && store.abilities.manage) {
        // `settings`, not a ruler: the icon set is generated from Lucide and
        // regenerating it to add one glyph is outside this package. It is
        // also the icon the sidebar's "Assessment settings" item already
        // carries, which is the destination this tab absorbs.
        tabs.push({ key: 'scales', label: 'Scales', icon: 'settings' });
    }

    tabs.push(
        // The root gets one too, and it is the only tab where the three
        // assessment totals appear together now that the baseline selector
        // is gone (PLAN-2.2.md D8).
        { key: 'statistics', label: 'Statistics', icon: 'percent' },
        { key: 'references', label: 'References', icon: 'paperclip' },
        // W46, item 16. On every type including the root — someone owns the
        // assessment as a whole as surely as they own a single defence.
        { key: 'assignees', label: 'Assignees', icon: 'users' },
        { key: 'comments', label: 'Comments', icon: 'message-square' },
        { key: 'history', label: 'History', icon: 'history' },
    );

    return tabs;
});

/**
 * A deep link can name a tab this node does not have — `?tab=links` on a risk
 * still arrives from a link shared before W39 removed it — and `Tabs` renders
 * an empty panel with nothing selected when that happens, which looks like a
 * broken node rather than a stale link.
 */
const selectedTab = computed(() =>
    tabs.value.some((tab) => tab.key === activeTab.value)
        ? activeTab.value
        : 'details',
);
</script>

<template>
    <Modal
        :show="openUuid !== null || draft !== null"
        :title="modalTitle"
        size="lg"
        @close="close"
    >
        <!--
            W71 item 24. The type used to be a `<Badge tone="neutral">` in the
            #actions group, which put a piece of *identity* in the corner
            reserved for *verbs* — next to Delete, at the far end of the header
            from the title it describes. It reads as the node's type here, left
            of the title, and as an icon rather than a chip: the same icon the
            card in the tree carries, in the same colour, so the modal and the
            card are recognisably the same node.

            The label is the icon's accessible name *and* its tooltip, so
            nothing is lost by dropping the visible text — and colour is not
            the sole carrier of the meaning (ui.md §11).
        -->
        <template v-if="node || draft" #title-prefix>
            <Tooltip :text="NODE_TYPES[cardType].label">
                <Icon
                    :name="NODE_TYPES[cardType].icon"
                    :size="16"
                    :label="NODE_TYPES[cardType].label"
                    :class="TYPE_ICON_TINT[cardType]"
                />
            </Tooltip>
        </template>

        <!--
            W37 put Delete here, in the header, visible on every tab.

            It is a deliberate edit to W39's file, for the reason W36 recorded
            when it deleted TotalsPanel: an affordance cannot leave one surface
            in one commit and arrive on another in the next without a broken
            state in between. The chart card's hover panel lost Delete, and
            without this the chart would have no way to delete a node at all.

            The chart is used on a projector, where a destructive action
            reachable only by hovering a two-pixel icon is the wrong thing to
            have. Here it is deliberate: open the node, see what it is, then
            delete it — and the same confirm dialog still asks.

            W71 moved the type chip out of this group and left Delete exactly
            where W37 put it.
        -->
        <template v-if="node" #actions>
            <Tooltip
                v-if="lockedBy"
                :text="`Currently being edited by ${lockedBy.name}. You can read it; it becomes editable when they close it.`"
            >
                <span
                    class="bg-surface-2 text-text-secondary inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs"
                    data-testid="node-modal-locked"
                >
                    <Icon name="lock" :size="12" />
                    Read only — {{ lockedBy.name }} is editing
                </span>
            </Tooltip>
            <IconButton
                v-if="
                    store.abilities.update &&
                    node.type !== 'root' &&
                    lockedBy === null
                "
                icon="trash-2"
                label="Delete"
                variant="danger"
                @click="confirmingDelete = true"
            />
        </template>

        <!--
            2.5.1: a node being added has no row yet, so it has no tabs —
            only the form that creates it. See NewNodeForm.vue for why the
            node is no longer created before this is filled in.
        -->
        <NewNodeForm
            v-if="draft"
            :key="`${draft.parentPath}:${draft.childType}`"
            :store="store"
            :draft="draft"
            @created="(uuid) => open(uuid, 'details')"
            @done="close"
        />

        <!--
            Keyed by uuid so switching straight from one node to another
            without closing gets a fresh strip. Panels are lazy now (W71 item
            1) and `Tabs` remembers which ones have been opened; without the
            key, opening Comments on one node would eagerly mount Comments on
            the next node the modal is pointed at.
        -->
        <Tabs
            v-else-if="node"
            :key="node.uuid"
            :tabs="tabs"
            :model-value="selectedTab"
            @update:model-value="(key) => setTab(key as typeof activeTab)"
        >
            <template #details>
                <DetailsTab :store="store" :uuid="node.uuid" />
            </template>
            <template #values>
                <ValuesTab :store="store" :uuid="node.uuid" />
            </template>
            <template #links>
                <LinksTab :store="store" :uuid="node.uuid" />
            </template>
            <template #statistics>
                <StatisticsTab :store="store" :uuid="node.uuid" />
            </template>
            <template #references>
                <ReferencesTab
                    :store="store"
                    :uuid="node.uuid"
                    :node-type="node.type"
                />
            </template>
            <template #assignees>
                <AssigneesTab :store="store" :uuid="node.uuid" />
            </template>
            <template #comments>
                <CommentsTab :store="store" :uuid="node.uuid" />
            </template>
            <template #scales>
                <ScalesTab :store="store" :uuid="node.uuid" />
            </template>
            <template #history>
                <HistoryTab :store="store" :uuid="node.uuid" />
            </template>
        </Tabs>

        <!--
            The other half of item 1. `Tabs` used to be `v-if="node"` with no
            else, so a modal open on a uuid the store does not hold rendered an
            empty body — and an empty body is precisely what "the Details tab
            does not load" looks like from the outside. Say which of the two
            things happened instead.
        -->
        <div
            v-else-if="missingState"
            data-testid="node-missing"
            class="text-text-muted p-6 text-sm"
        >
            <p v-if="missingState === 'loading'">Loading this node…</p>
            <p v-else>
                This node is no longer in the assessment. It may have been
                deleted since the link was shared.
            </p>
        </div>

        <ConfirmDialog
            :show="confirmingDelete"
            title="Delete node"
            :message="deleteMessage"
            confirm-label="Delete"
            danger
            @confirm="confirmDelete"
            @cancel="confirmingDelete = false"
        />
    </Modal>
</template>
