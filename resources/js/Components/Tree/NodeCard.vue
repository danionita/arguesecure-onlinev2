<script setup lang="ts">
/**
 * The tree editor's node card (ui.md §3.2). Compact by default: one line
 * always visible — expand chevron, type icon, title, value chips, indicator
 * icons, actions — with a second line for the description appearing only
 * when the occurrence is expanded and a description exists ("three at most
 * when expanded").
 *
 * Actions are always present in the DOM, low-contrast until row hover/focus
 * (`group-hover`/`focus-within` below), full contrast on touch — "No
 * wrench, no reveal animation" (ui.md §3.2): nothing is hidden behind a
 * click to reveal a click.
 *
 * ## W29 — labelled, explained
 *
 * The card used to render current and target figures side by side, unlabelled:
 * a risk row carried three bare numbers and a defence five, with nothing on
 * screen saying which of them a person typed and which the engine computed.
 * Every figure now carries a label, and every label, chip and bar carries a
 * hover/focus explanation from constants/valueHints.ts. The chart view reads
 * the same copy module, so the two views cannot explain the same number
 * differently.
 *
 * ## W36 — the list shows what a human entered
 *
 * The three views have three jobs (PLAN-2.2.md): the chart is for agreeing
 * what the boxes are, the list is for filling in their values, the report is
 * for reading what those values imply. So this card shows **inputs** — impact,
 * likelihood, status, strength — and no engine output.
 *
 * The one exception is the root, which carries all three totals at once
 * (Base / Current / Target ALE). That is the figure a facilitator glances at
 * while working, and showing the three together is the comparison the removed
 * baseline selector kept asking people to make one at a time. It also absorbs
 * the docked `TotalsPanel`, deleted in the same package: two places rendering
 * the same three numbers, one of them costing a permanent strip of vertical
 * space above the tree.
 *
 * What left — annual loss on risks and attacks, the contribution bar, a
 * defence's cost and saving, and the "not applied in this baseline" dimming —
 * is engine output, and all of it reappears in the modal's Statistics tab
 * (W39) and the report, shown against all three baselines rather than against
 * whichever one a control happened to be set to.
 *
 * ## W70 — one shape for every entered value
 *
 * Two changes, both about a row that reads at a glance (PLAN-2.4.md items 22
 * and 23).
 *
 * The **completeness bar is gone from the root row**. A bar is a comparison
 * against a whole, and the whole it compared against — how many risk/attack
 * pairs exist — is not on this row and cannot be inferred from it, so the
 * filled fraction was a shape without a scale. The figure itself did not go
 * anywhere: it is a sentence in the modal's Statistics tab and in the report,
 * where the counts that give it meaning are on screen beside it. `Bar.vue`
 * stays; the report renders it and so does the ToS card's chart-view twin.
 *
 * Every node-specific **entered** value is now a `ValueChip` — the same
 * label-colon-value shape, the same hover/focus explanation. The defence was
 * the last holdout, rendering a bare sentence chip; its strength is now the
 * chip's value and its reduction target is the chip's label, which keeps
 * W29's finding (the percentage and the words are one fact, not two) while
 * putting the row in one shape. Computed figures are unaffected because there
 * are none left here but the root's three totals.
 */
// Two imports from Components/NodeModal/**, which this package does not own:
// `useNodeModal` predates W40, and `useLinkMutations` is the edge-writing
// surface W13 built and documented as the store's missing addLink/removeLink.
// Reimplementing it here to stay inside a directory boundary would be two
// implementations of one POST, which is how they drift.
import AssistantPanel from '@/Components/Node/AssistantPanel.vue';
import SharedCounter from '@/Components/Node/SharedCounter.vue';
import TemplatePicker from '@/Components/Node/TemplatePicker.vue';
import { useAssistantChildren } from '@/Components/Node/useAssistantChildren';
import { useTemplateChildren } from '@/Components/Node/useTemplateChildren';
import {
    useNodeModal,
    type NodeModalTab,
} from '@/Components/NodeModal/useNodeModal';
import { useScales } from '@/Components/NodeModal/useScales';
import Chip from '@/Components/UI/Chip.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import { formatMoney, formatPercent, formatRate } from '@/Components/UI/format';
import Icon from '@/Components/UI/Icon.vue';
import IconButton from '@/Components/UI/IconButton.vue';
import Money from '@/Components/UI/Money.vue';
import StatusPill from '@/Components/UI/StatusPill.vue';
import Tooltip from '@/Components/UI/Tooltip.vue';
import { DRAG_DROP_KEY, type DragPayload } from '@/composables/useDragDrop';
import { EDITOR_VIEW_KEY } from '@/composables/useEditorView';
import { LOCKS_KEY } from '@/composables/useLocks';
import { NODE_TYPES, hasChildType, type CardType } from '@/constants/nodeTypes';
import {
    MISSING_HINTS,
    RISK_IMPACT_HINT,
    ROOT_TOTALS_HINT,
    ROOT_TOTAL_LABELS,
    VALUE_LABELS,
    attackLikelihoodEnteredHint,
    attackLikelihoodLevelHint,
    reductionChipHint,
    riskImpactLevelHint,
} from '@/constants/valueHints';
import {
    ASSESSMENT_STORE_KEY,
    type AttackDefenceLink,
} from '@/stores/assessmentStore';
import { computed, inject, nextTick, onMounted, ref } from 'vue';
import ValueChip from './ValueChip.vue';

const props = defineProps<{
    uuid: string;
    pathKey: string;
    edge?: AttackDefenceLink;
    expanded: boolean;
    hasChildren: boolean;
    /** What dragging this occurrence means; null for the root. See TreeNode.vue. */
    dragPayload?: DragPayload | null;
}>();

const emit = defineEmits<{ toggle: [] }>();

const store = inject(ASSESSMENT_STORE_KEY)!;
// Optional — see TreeNode.vue. Without an editor view the card renders
// undimmed and unhighlighted, which is the correct degraded behaviour. Since
// W36 it needs nothing else from the view: the figures it shows are inputs
// and the root's three totals, none of which depend on view state.
const view = inject(EDITOR_VIEW_KEY, null);
// Optional for the same reason: several existing tests mount NodeCard with
// only the store provided (ui.md §6, W16). With no lock handle present the
// title field simply behaves as it did before W16 — always editable,
// never shown as locked.
const locks = inject(LOCKS_KEY, null);
// Optional for the same reason again: the existing card tests mount this
// with only the store. Without a drag handle the card renders exactly as it
// did before W27 — no `draggable`, no drop target.
const dragDrop = inject(DRAG_DROP_KEY, null);
const modal = useNodeModal();
// Page props, not the store: the store does not carry the calibration (see
// useScales.ts's own note). Used only to turn the engine's level *code* into
// the band's label for display (W87, D44).
const { levelsForKind, levelById } = useScales();

/**
 * Spread onto the card root, which is deliberately both the drag source and
 * the drop target: it is the one element that is exactly one occurrence
 * (the enclosing `<li role="treeitem">` contains the whole subtree). Empty
 * when there is nothing draggable here, so `v-bind` adds no attributes at
 * all and the root stays a plain div.
 *
 * Not a drag source while the template picker is open (2.5.1): the picker is
 * inside this root, so a press-and-move on its text would otherwise pick up
 * the whole card. It stays a drop target.
 */
const dragAttrs = computed<Record<string, unknown>>(() => {
    if (!dragDrop || !props.dragPayload) return {};
    const attrs = dragDrop.dragProps(props.dragPayload);

    return templateOpen.value ? { ...attrs, draggable: false } : attrs;
});

/**
 * Where a drag in progress would land (2.5.1): a bar above this card when the
 * dragged node would take its place, an outline when it would go under it or
 * under its parent. Shown while the pointer is still down, so the drop can be
 * aimed rather than discovered.
 */
const dropKind = computed(() =>
    dragDrop?.dropTarget?.value?.path === props.pathKey
        ? dragDrop.dropTarget.value.kind
        : null,
);
const beingDragged = computed(
    () => dragDrop?.draggingPath?.value === props.pathKey,
);

const node = computed(() => store.nodes[props.uuid]);

// A filtered-out occurrence dims rather than disappears (ui.md §5) so the
// shape of the tree is never silently misrepresented.
const dimmed = computed(() => view?.filters.isDimmed(props.pathKey) ?? false);

// Every occurrence of the hovered node lights up, including this one.
const highlighted = computed(
    () =>
        view?.duplicates.highlightedPathKeys.value.has(props.pathKey) ?? false,
);

// Search moves through occurrences; the active one is marked so the ring is
// visible without stealing focus from whatever the user is typing into.
const isSearchMatch = computed(
    () => view?.search.activeMatch.value?.pathKey === props.pathKey,
);

const cardType = computed<CardType>(() =>
    node.value?.type === 'defence' && node.value.is_transfer
        ? 'transfer'
        : (node.value?.type ?? 'risk'),
);
const meta = computed(() => NODE_TYPES[cardType.value]);

// Full literal class names, spelled out per type so Tailwind's text-based
// scanner can find them — the same reasoning Bar.vue documents for its own
// tone map; a template literal like `bg-node-${color}-bg` is invisible to it.
const TYPE_ICON_CLASSES: Record<CardType, string> = {
    root: 'bg-node-root-bg text-node-root-text',
    risk: 'bg-node-risk-bg text-node-risk-text',
    attack: 'bg-node-attack-bg text-node-attack-text',
    defence: 'bg-node-defence-bg text-node-defence-text',
    transfer: 'bg-node-transfer-bg text-node-transfer-text',
};
const typeIconClasses = computed(() => TYPE_ICON_CLASSES[cardType.value]);
const showAddChild = computed(
    () => node.value !== undefined && hasChildType(node.value.type),
);

const occurrenceCount = computed(() =>
    node.value ? store.occurrenceCount(props.uuid, node.value.type) : 1,
);

// ui.md §3.2: "indicators for... comments". Fetched assessment-wide once
// per store instance (see loadUnresolvedCommentCounts()'s own docblock);
// every card's onMounted asks for it, but only the first actually issues
// a request.
onMounted(() => {
    void store.loadUnresolvedCommentCounts();
});
const unresolvedCommentCount = computed(
    () => store.unresolvedCommentCounts[props.uuid] ?? 0,
);

// --- opening the editor (2.5.1) --------------------------------------------
//
// The list and the chart now edit a node the same way: a click on the title
// or the description opens the modal on Details, and a click on an entered
// value opens it on the tab that holds that value. The list's inline title
// field is gone — two editors for one field is how they diverge, and the
// inline one could not show the lock, the conflict banner or the other tabs.
//
// The one inline edit that stays is the description, on double-click, which
// is the list's job (filling things in) and has no equivalent on the chart.
// A frozen snapshot mounts no modal, so nothing here opens one there.

const canOpen = computed(() => !store.frozen);

function openTab(tab: NodeModalTab = 'details') {
    if (!canOpen.value) return;
    modal.open(props.uuid, tab);
}

/**
 * The description answers two gestures, and a double-click is two clicks
 * first. So a single click waits out the double-click window before opening
 * the modal, and a double-click cancels it — otherwise every attempt to edit
 * inline would open the modal on top of the field.
 */
const DOUBLE_CLICK_MS = 250;
let pendingDescriptionClick: ReturnType<typeof setTimeout> | null = null;

function onDescriptionClick() {
    if (editingDescription.value) return;
    if (pendingDescriptionClick !== null) clearTimeout(pendingDescriptionClick);
    pendingDescriptionClick = setTimeout(() => {
        pendingDescriptionClick = null;
        openTab('details');
    }, DOUBLE_CLICK_MS);
}

const editingDescription = ref(false);
const draftDescription = ref('');
const descriptionInput = ref<HTMLTextAreaElement | null>(null);

/**
 * Whether someone else has this node open (2.5.1). The modal holds a
 * whole-node lock while it is open; the inline description respects it too,
 * so the list cannot become a way around it.
 */
const nodeLockedBy = computed(
    () => locks?.lockedBy(props.uuid, 'node') ?? null,
);

async function onDescriptionDblclick() {
    if (pendingDescriptionClick !== null) {
        clearTimeout(pendingDescriptionClick);
        pendingDescriptionClick = null;
    }
    if (!node.value || !store.abilities.update || store.frozen) return;
    if (locks && !(await locks.acquire(props.uuid, 'node'))) return;

    draftDescription.value = node.value.description ?? '';
    editingDescription.value = true;
    void nextTick(() => descriptionInput.value?.focus());
}

async function commitDescription() {
    if (!editingDescription.value || !node.value) return;
    editingDescription.value = false;
    void locks?.release(props.uuid, 'node');

    const value =
        draftDescription.value.trim() === '' ? null : draftDescription.value;
    if (value !== node.value.description) {
        await store.updateNode(props.uuid, { description: value });
    }
}

function cancelDescription() {
    editingDescription.value = false;
    void locks?.release(props.uuid, 'node');
}

// --- delete ---------------------------------------------------------------
const confirmingDelete = ref(false);

function requestDelete() {
    confirmingDelete.value = true;
}

async function confirmDelete() {
    confirmingDelete.value = false;
    await store.deleteNode(props.uuid);
}

// --- add child --------------------------------------------------------------
// Opens a draft; nothing is created until it is named and confirmed
// (NewNodeForm.vue explains why the old insert-immediately flow went).
function addChild() {
    if (!node.value || store.frozen) return;
    modal.openDraft(
        node.value.type as 'root' | 'risk' | 'attack',
        props.uuid,
        props.pathKey,
    );
}

// --- ask the assistant for children (W52, job B) -----------------------------
//
// Named `assistant*` throughout, and deliberately not `suggest*`: this file
// already has a `suggesting` and a `SuggestionList` from W40, which mean
// something entirely different — finding an *existing* node to link. Two
// unrelated notions of "suggestion" one scroll apart is how the wrong one gets
// edited.
//
// Extracted into useAssistantChildren.ts in W85/D50 so ChartCard.vue can carry
// the same trigger and the same guardrail (D24) instead of a second,
// divergent copy of this block.
const assistantNodeType = computed(() => node.value?.type);
const {
    assistantItems,
    assistantChildType,
    assistantAsking,
    assistantError,
    assistantAccepted,
    assistantProgress,
    askAssistant,
    acceptAssistantSuggestion,
    dismissAssistant,
} = useAssistantChildren(
    store,
    computed(() => props.uuid),
    assistantNodeType,
);

// 2.5.1: the same trigger-and-panel shape, fed from a template instead of a
// model — see useTemplateChildren.ts. Shared with ChartCard.vue.
const {
    templateOpen,
    templates,
    templateUuid,
    templateItems,
    templateLoading,
    templateError,
    templateBusy,
    templateChildType,
    templateItemState,
    openTemplates,
    chooseTemplate,
    closeTemplates,
    takeTemplateItem,
} = useTemplateChildren(
    store,
    computed(() => props.uuid),
    assistantNodeType,
);

// --- value chips, by type (ui.md §3.2 table) -------------------------------
const currency = computed(() => store.assessment.currency);

/**
 * Impact and likelihood are **inputs**. The engine echoes them back
 * identically in all three baselines, so which one this reads from is
 * arbitrary — `current` is named outright rather than tracked through a
 * `baseline` ref, because after W36 there is no baseline selection in the
 * editor to track. Anything genuinely baseline-dependent has left this card.
 */
const inputs = computed(() => store.results.value.current);

/**
 * The ToS card is the one place in the editor that still shows a computed
 * figure, and it shows **all three at once** rather than one chosen by a
 * control (PLAN-2.2.md D8). A facilitator glances at these while working;
 * they are also the comparison the removed selector kept asking people to
 * make one baseline at a time, which is the harder way to make it.
 */
const rootTotals = computed(() => {
    const results = store.results.value;
    // Only `isPartial` survives W70's removal of the completeness bar, and it
    // is not a leftover: it decides whether the three totals render with a
    // "≥" (ui.md §9), which is a statement about the figures themselves
    // rather than a second reading of coverage.
    const { completeness } = results.current;
    const inherent = results.inherent.total;

    return {
        entries: (['inherent', 'current', 'target'] as const).map(
            (baseline) => ({
                baseline,
                label: ROOT_TOTAL_LABELS[baseline],
                total: results[baseline].total,
                /**
                 * CLAUDE.md: computed results "render as currency with a
                 * reduction percentage" — never band-mapped to low/medium/
                 * high. W36 moved these three figures off the docked panel
                 * and onto this card and dropped the percentage on the way,
                 * which is what scenario 6 caught the first time the e2e
                 * suite ran after it.
                 *
                 * Against inherent, because that is what "reduction" means
                 * here: how much of the untreated loss the defences remove.
                 * Null on inherent itself (it is the baseline, not a
                 * reduction of anything) and null when inherent is zero,
                 * where the ratio is undefined rather than 0%.
                 */
                reduction:
                    baseline === 'inherent' || inherent <= 0
                        ? null
                        : (inherent - results[baseline].total) / inherent,
            }),
        ),
        isPartial: completeness.isPartial,
    };
});

/**
 * Where the current total sits against the tolerance someone set (W44).
 *
 * Null when no tolerance is set — which is not the same as a tolerance of
 * zero, and a badge reading "0 over" on every unconfigured assessment would
 * be worse than no badge at all.
 *
 * Compared against **current**, not target: current is what the assessment
 * costs today, and the question a tolerance answers is whether today is
 * acceptable. Target is a plan, and a plan that would come in under the line
 * says nothing about whether to act now.
 *
 * The result is a sentence, never a band. `calculation-engine.md` forbids
 * mapping a computed figure into low/medium/high, and colouring this by
 * whether it is over or under would be that rule broken with the user's own
 * threshold as cover.
 */
const tolerance = computed(() => {
    const limit = store.assessment.risk_tolerance;
    if (limit === null || limit === undefined) return null;

    const current = store.results.value.current.total;
    const over = current - limit;
    const limitText = formatMoney(limit, { currency: currency.value }) ?? '—';

    return {
        label:
            over > 0
                ? `${formatMoney(over, { currency: currency.value })} over tolerance`
                : 'Within tolerance',
        hint:
            over > 0
                ? `Current annual loss is above the ${limitText} tolerance set for this assessment. This is a comparison, not a verdict — the tolerance is a figure someone chose and it does not affect any calculation.`
                : `Current annual loss is at or below the ${limitText} tolerance set for this assessment. This is a comparison, not a verdict — the tolerance is a figure someone chose and it does not affect any calculation.`,
    };
});

const riskResult = computed(() =>
    inputs.value.risks.find((r) => r.uuid === props.uuid),
);
/**
 * A band's **label**, looked up from the code the engine reports (W87, D44).
 *
 * `RiskResult.impactLevelCode` and `AttackResult.aroLevelCode` are part of
 * calculation-engine.md §8.3's normative payload and do not change. What
 * changed is that the card no longer *draws* one: `scale_levels.code` is
 * internal — derived from the label on create, then frozen so a catalogue
 * fixture naming "moderate" keeps resolving — so a card showing it went stale
 * the moment anyone renamed the band, and showed an internal key either way.
 *
 * Null when the code names no band on the loaded calibration, which the
 * callers render as "not set" rather than inventing a name for it.
 */
function levelLabel(
    kind: 'impact' | 'likelihood',
    code: string | null,
): string | null {
    if (code === null) return null;

    return (
        levelsForKind(kind).find((level) => level.code === code)?.label ?? null
    );
}

const riskChips = computed(() => {
    const r = riskResult.value;
    const node_ = node.value;

    return {
        impactValue: r?.impactValue ?? node_?.impact_amount ?? null,
        impactLevelLabel: levelLabel('impact', r?.impactLevelCode ?? null),
    };
});

const riskHints = computed(() => ({
    impact:
        riskChips.value.impactValue === null &&
        riskChips.value.impactLevelLabel === null
            ? MISSING_HINTS.impact
            : riskChips.value.impactLevelLabel !== null &&
                riskChips.value.impactValue === null
              ? riskImpactLevelHint(riskChips.value.impactLevelLabel)
              : RISK_IMPACT_HINT,
}));

const attackResult = computed(() =>
    inputs.value.attacks.find((a) => a.uuid === props.uuid),
);
const attackChips = computed(() => {
    const a = attackResult.value;
    const aro = a?.aroBase ?? node.value?.likelihood_aro ?? null;

    return {
        aro,
        approxProbability: aro !== null ? 1 - Math.exp(-aro) : null,
        aroLevelLabel: levelLabel('likelihood', a?.aroLevelCode ?? null),
    };
});

/**
 * The residual likelihood is not a chip and is no longer in this hint either:
 * residual is baseline-dependent, and stating one baseline's residual with no
 * control to change it would be an arbitrary choice presented as a fact. It
 * lives in the modal's Statistics tab (W39), where all three appear together.
 * What stays is the entered rate and its probability, both of which are what
 * the user typed.
 */
const attackHints = computed(() => ({
    likelihood:
        attackChips.value.aro !== null
            ? attackLikelihoodEnteredHint(
                  formatRate(attackChips.value.aro) ?? '—',
                  formatPercent(attackChips.value.approxProbability) ?? '—',
              )
            : attackChips.value.aroLevelLabel !== null
              ? attackLikelihoodLevelHint(attackChips.value.aroLevelLabel)
              : MISSING_HINTS.likelihood,
}));

const defenceChips = computed(() => ({
    strength: props.edge?.strength_fraction ?? null,
    strengthLevelId: props.edge?.strength_level_id ?? null,
    reductionTarget: props.edge?.reduction_target ?? null,
}));

/**
 * Read from the **edge**, above, never from the defence. Strength lives on the
 * `attack_defence` pivot because one control may be 80% effective against one
 * attack and 20% against another, so the same defence renders two different
 * percentages under two attacks and that is correct (CLAUDE.md, schema.md).
 */
const strengthText = computed(() => {
    const { strength, strengthLevelId } = defenceChips.value;
    if (strength !== null) return formatPercent(strength);

    // A qualitative strength has a band and no fraction. Read only the
    // fraction and every such edge rendered `?` — "missing" — while the chart
    // card beside it showed the band, and the engine counted it (2.5.1). Same
    // rule as ChartCard's figureFor(): the band's label, or `—` for a band the
    // page did not send, which is unresolvable rather than unset.
    if (strengthLevelId !== null)
        return levelById(strengthLevelId)?.label ?? '—';

    return null;
});

/**
 * The "not applied in this baseline" dimming is gone with the selector.
 * It answered a question — *is this control counting in what I am looking
 * at?* — that the card no longer poses, because the card no longer shows a
 * figure a control could be counted into. The status pill states
 * implemented / todo / shelved outright, which is the whole of what this row
 * knows; the consequence of that status is in the Statistics tab and the
 * report, against all three baselines at once.
 */
const defenceHints = computed(() => ({
    // Deliberately fed the same formatted string the chip renders: a hint that
    // rounds differently from the figure it explains reads as a second, wrong
    // number (Components/UI/format.ts).
    reduction: reductionChipHint(
        defenceChips.value.reductionTarget,
        // "removes Limited of the likelihood" is not a sentence; a band is
        // a share, not an amount.
        defenceChips.value.strength === null && strengthText.value !== null
            ? `a “${strengthText.value}” share`
            : strengthText.value,
    ),
}));

/**
 * The chip's label names the reduction target; its value is the strength
 * (W70, item 23).
 *
 * `null` is **not** a missing reduction target — it is the default, "overall
 * loss", which is what `LinkStrengthEditor`'s placeholder says and what the
 * engine applies. Rendering a `?` here would invent a gap where the user made
 * no choice because none was needed, and would collide with the `?` on the
 * strength beside it, which does mean missing.
 *
 * The nouns are spelled out locally because `valueHints.ts` keeps its own map
 * private; `Report/AssessmentTreeSection.vue`, `Report/TransfersSection.vue`
 * and `NodeModal/LinkStrengthEditor.vue` each already carry the same three
 * words for the same reason. Exporting one map is the right fix and belongs
 * to whoever owns that module — W70 does not.
 */
const REDUCTION_TARGET_NOUNS: Record<string, string> = {
    likelihood: 'likelihood',
    impact: 'impact',
};

const reductionLabel = computed(
    () =>
        `Reduces ${
            REDUCTION_TARGET_NOUNS[defenceChips.value.reductionTarget ?? ''] ??
            'overall loss'
        }`,
);
</script>

<template>
    <div
        v-if="node"
        class="group/card relative flex flex-col gap-0.5 rounded py-0.5 transition-opacity"
        :class="[
            (dimmed || beingDragged) && 'opacity-40',
            (dropKind === 'into' || dropKind === 'parent') &&
                'bg-node-root-bg outline-focus-ring outline-2 outline-dashed',
            highlighted && 'bg-node-root-bg ring-focus-ring ring-1',
            isSearchMatch && 'ring-focus-ring ring-2',
        ]"
        v-bind="dragAttrs"
        @mouseenter="view?.duplicates.hover(uuid)"
        @mouseleave="view?.duplicates.unhover(uuid)"
    >
        <span
            v-if="dropKind === 'before'"
            class="bg-focus-ring pointer-events-none absolute inset-x-0 -top-1 h-0.5 rounded-full"
            data-testid="drop-indicator"
            data-drop="before"
            aria-hidden="true"
        />
        <span
            v-else-if="dropKind"
            class="sr-only"
            data-testid="drop-indicator"
            :data-drop="dropKind"
        />
        <div class="flex min-w-0 items-center gap-1.5">
            <button
                v-if="hasChildren"
                type="button"
                class="text-text-muted hover:text-text-primary inline-flex h-5 w-5 shrink-0 items-center justify-center"
                :aria-label="expanded ? 'Collapse' : 'Expand'"
                @click="emit('toggle')"
            >
                <Icon
                    :name="expanded ? 'chevron-down' : 'chevron-right'"
                    :size="14"
                />
            </button>
            <span v-else class="w-5 shrink-0" />

            <Tooltip :text="meta.label">
                <span
                    class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded"
                    :class="typeIconClasses"
                >
                    <Icon :name="meta.icon" :size="12" />
                </span>
            </Tooltip>

            <!-- 2.5.1: a click opens the modal on Details, exactly as on the
                 chart card. The inline title field is gone; see the script. -->
            <button
                type="button"
                class="text-text-primary hover:text-text-link min-w-0 flex-1 truncate text-left text-sm"
                :class="[
                    !node.title && 'text-text-muted italic',
                    canOpen ? 'cursor-pointer' : 'cursor-default',
                ]"
                :title="canOpen ? 'Open to edit' : undefined"
                data-testid="node-title"
                @click="openTab('details')"
            >
                {{ node.title || `Untitled ${meta.label.toLowerCase()}` }}
            </button>

            <!-- ui.md §6: someone else has this node open. Read-only for
                 everyone else until they close it (2.5.1). -->
            <Tooltip
                v-if="nodeLockedBy"
                :text="`Currently being edited by ${nodeLockedBy.name}`"
            >
                <span
                    class="text-text-muted bg-surface-2 inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 text-xs"
                    data-testid="node-locked"
                >
                    <Icon name="lock" :size="10" />
                    {{ nodeLockedBy.name }}
                </span>
            </Tooltip>

            <!--
                W36: what a *human entered*, per node — plus the three totals
                at the root, which are the only computed figures left in the
                editor. Everything removed here (annual loss, contribution,
                saves, cost) is engine output and lives in the modal's
                Statistics tab and the report, against all three baselines at
                once rather than against whichever one a control happened to
                be set to.
            -->
            <div class="flex shrink-0 items-center gap-2">
                <template v-if="node.type === 'root'">
                    <!-- The live region the docked totals panel used to be
                         (deleted in W36). Collaborative edits change these
                         figures without the reader touching anything, which
                         is exactly when an assertive-free announcement is
                         worth having. -->
                    <div
                        class="flex items-center gap-2"
                        data-testid="assessment-totals"
                        role="status"
                        aria-live="polite"
                    >
                        <ValueChip
                            v-for="entry in rootTotals.entries"
                            :key="entry.baseline"
                            :label="entry.label"
                            :tooltip="ROOT_TOTALS_HINT"
                        >
                            <Money
                                :value="entry.total"
                                :currency="currency"
                                :at-least="rootTotals.isPartial"
                            />
                            <span
                                v-if="entry.reduction !== null"
                                class="text-text-muted ml-1"
                                >({{
                                    formatPercent(entry.reduction)
                                }}
                                lower)</span
                            >
                        </ValueChip>
                    </div>

                    <!--
                        W44. A statement of where the current total sits
                        relative to a threshold someone chose, and nothing
                        more. Deliberately not a red/amber/green pill:
                        calculation-engine.md forbids band-mapping a computed
                        figure, and two tones would be exactly that with the
                        thresholds supplied by the user instead of by us. It
                        reports the comparison and leaves the judgement where
                        it belongs.
                    -->
                    <Tooltip v-if="tolerance !== null" :text="tolerance.hint">
                        <span
                            class="text-text-muted text-xs whitespace-nowrap"
                            data-testid="risk-tolerance"
                        >
                            {{ tolerance.label }}
                        </span>
                    </Tooltip>

                    <!-- W70, item 22: the completeness bar was here. A bar is
                         a fraction of a whole, and the whole — how many
                         risk/attack pairs the assessment has — was never on
                         this row, so the fill had no scale to be read
                         against. The figure is unchanged and still reachable:
                         the Statistics tab and the report state it as a count
                         of pairs, with the counts beside it. -->
                </template>

                <template v-else-if="node.type === 'risk'">
                    <ValueChip
                        :class="canOpen && 'cursor-pointer'"
                        data-testid="node-value"
                        @click="openTab('values')"
                        :label="VALUE_LABELS.riskImpact"
                        :tooltip="riskHints.impact"
                    >
                        <Chip
                            v-if="
                                riskChips.impactValue === null &&
                                riskChips.impactLevelLabel === null
                            "
                            missing
                        />
                        <Money
                            v-else-if="riskChips.impactValue !== null"
                            :value="riskChips.impactValue"
                            :currency="currency"
                        />
                        <Chip v-else tone="neutral">{{
                            riskChips.impactLevelLabel
                        }}</Chip>
                    </ValueChip>
                </template>

                <template v-else-if="node.type === 'attack'">
                    <ValueChip
                        :class="canOpen && 'cursor-pointer'"
                        data-testid="node-value"
                        @click="openTab('values')"
                        :label="VALUE_LABELS.attackLikelihood"
                        :tooltip="attackHints.likelihood"
                    >
                        <span
                            v-if="attackChips.aro !== null"
                            class="tabular-nums"
                        >
                            {{ attackChips.aro.toFixed(2) }}/yr
                        </span>
                        <Chip
                            v-else-if="attackChips.aroLevelLabel"
                            tone="neutral"
                            >{{ attackChips.aroLevelLabel }}</Chip
                        >
                        <Chip v-else missing />
                    </ValueChip>
                </template>

                <template v-else-if="node.type === 'defence'">
                    <StatusPill v-if="node.status" :status="node.status" />

                    <!-- W29 merged strength and reduction target into one
                         phrase, because as two bare chips ("40%" beside
                         "Reduces likelihood") the reader had to infer that
                         the number belonged to the words. W70 keeps them one
                         fact and gives them the shape every other entered
                         value on this row has: the target is the label, the
                         strength is the value. The label does the joining
                         that the sentence used to do, so nothing has to be
                         inferred and the row reads in one pass. -->
                    <ValueChip
                        v-if="edge"
                        :class="canOpen && 'cursor-pointer'"
                        data-testid="node-value"
                        @click="openTab('links')"
                        :label="reductionLabel"
                        :tooltip="defenceHints.reduction"
                    >
                        <!-- A missing strength is a `?`, never a blank and
                             never a 0%: it is treated as no reduction at all,
                             which overstates residual risk, and an absent
                             chip would be indistinguishable from a strength
                             of zero (calculation-engine.md). -->
                        <Chip v-if="strengthText === null" missing />
                        <span v-else class="tabular-nums">{{
                            strengthText
                        }}</span>
                    </ValueChip>
                </template>
            </div>

            <!-- indicator icons -->
            <div class="text-text-muted flex shrink-0 items-center gap-1">
                <!-- One component for both cards (2.5.1): an attack's counter
                     is D31's popover, a shared defence's names its parents in
                     the tooltip. The list's used to say only "one node, shown
                     everywhere it belongs", which named nothing. -->
                <SharedCounter
                    v-if="occurrenceCount > 1"
                    :store="store"
                    :uuid="uuid"
                />
                <Tooltip
                    v-if="unresolvedCommentCount > 0"
                    :text="`${unresolvedCommentCount} unresolved comment${unresolvedCommentCount === 1 ? '' : 's'}`"
                >
                    <span class="inline-flex items-center gap-0.5 text-xs">
                        <Icon name="message-square" :size="12" />
                        {{ unresolvedCommentCount }}
                    </span>
                </Tooltip>
            </div>

            <!-- Actions: always in the DOM, low-contrast until hover/focus.
                 W29 removed the pencil (the title is editable by clicking it)
                 and the link icon (the settings icon opens the same modal, one
                 tab away) — five actions per row, two of them synonyms, made
                 the row harder to read than the numbers on it. -->
            <div
                class="flex shrink-0 items-center gap-0.5 opacity-40 transition-opacity group-focus-within/card:opacity-100 group-hover/card:opacity-100 has-[:focus-visible]:opacity-100"
            >
                <IconButton
                    v-if="showAddChild && store.abilities.update"
                    icon="plus"
                    label="Add child"
                    @click="addChild"
                />
                <IconButton
                    v-if="showAddChild && store.abilities.update"
                    icon="copy"
                    :label="`Add ${templateChildType}s from a template`"
                    data-testid="add-from-template"
                    @click="openTemplates"
                />
                <!-- W52: one action, next to the one it assists. Rendered only
                     where the assistant is actually available — a control that
                     cannot work is worse than no control (W8b). -->
                <IconButton
                    v-if="
                        showAddChild &&
                        store.abilities.update &&
                        store.assistantAvailable
                    "
                    icon="square-plus"
                    :label="`Suggest ${assistantChildType}s`"
                    :disabled="assistantAsking"
                    data-testid="ask-assistant"
                    @click="askAssistant"
                />
                <IconButton
                    v-if="store.abilities.update"
                    icon="trash-2"
                    label="Delete"
                    variant="danger"
                    @click="requestDelete"
                />
            </div>
        </div>

        <!-- Click opens the modal on Details, like the title; double-click
             edits the description in place (2.5.1). -->
        <textarea
            v-if="editingDescription"
            ref="descriptionInput"
            v-model="draftDescription"
            rows="2"
            maxlength="500"
            class="border-focus-ring bg-surface-1 text-text-primary ml-[3.75rem] rounded border px-1 py-0.5 text-xs"
            data-testid="node-description-input"
            placeholder="Description"
            @blur="commitDescription"
            @keydown.enter.exact.prevent="commitDescription"
            @keydown.esc.prevent="cancelDescription"
        />
        <p
            v-else-if="expanded && node.description"
            class="text-text-muted ml-[3.75rem] line-clamp-2 text-xs"
            :class="canOpen && 'cursor-pointer'"
            :title="
                canOpen && store.abilities.update
                    ? 'Click to open, double-click to edit'
                    : undefined
            "
            data-testid="node-description"
            @click="onDescriptionClick"
            @dblclick="onDescriptionDblclick"
        >
            {{ node.description }}
        </p>

        <!-- W52/W58, extracted into AssistantPanel.vue in W85/D50: progress
             while it runs, the error if it failed, and the suggestions list
             with its accept step. Nothing reaches the graph until each item
             is accepted by hand (D24). -->
        <div
            v-if="assistantAsking || assistantError || assistantItems"
            class="mt-2 ml-[3.75rem]"
        >
            <AssistantPanel
                :asking="assistantAsking"
                :error="assistantError"
                :items="assistantItems"
                :child-type="assistantChildType"
                :accepted="assistantAccepted"
                :elapsed-label="assistantProgress.elapsedLabel.value"
                :percent="assistantProgress.percent.value"
                :tokens="assistantProgress.tokens.value"
                :expected="assistantProgress.expected.value"
                :cancelling="assistantProgress.cancelling.value"
                @accept="acceptAssistantSuggestion"
                @dismiss="dismissAssistant"
                @cancel="assistantProgress.cancel()"
            />
        </div>

        <!-- 2.5.1: add from a template. Stays open until Done, so several
             can be taken in a row. -->
        <div v-if="templateOpen" class="mt-2 ml-[3.75rem]">
            <TemplatePicker
                mode="multi"
                :child-type="templateChildType"
                :templates="templates"
                :template-uuid="templateUuid"
                :items="templateItems"
                :loading="templateLoading"
                :error="templateError"
                :busy="templateBusy"
                :state-of="templateItemState"
                @choose="chooseTemplate"
                @take="takeTemplateItem"
                @dismiss="closeTemplates"
            />
        </div>

        <ConfirmDialog
            :show="confirmingDelete"
            title="Delete node"
            :message="`Delete '${node.title || 'this node'}'? This also removes it from every place it appears.`"
            confirm-label="Delete"
            danger
            @confirm="confirmDelete"
            @cancel="confirmingDelete = false"
        />
    </div>
</template>
