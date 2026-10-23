<script setup lang="ts">
/**
 * One card in the chart view (W32, ui.md §3.7): type, title, description, and
 * one indicator saying whether this node's values are set.
 *
 * ## W37 — the simplest of the three views
 *
 * The chart is where a room agrees **what the boxes are**. It is not where
 * anyone reads a number, so the card carries none: the completeness bar, the
 * contribution bar, the likelihood ramp and the strength ramp are all gone,
 * along with `CueRamp` itself. W32 kept them on the argument that a cue is not
 * a figure. In practice they were figures with the digits filed off — a reader
 * still had to hover each one to learn anything, and four different visual
 * languages on four tiers made the board harder to read across, which is the
 * one thing this view exists to make easy.
 *
 * What replaces them is a single, **always visible** indicator: are this
 * node's values set, or not. Clicking it opens the node modal on the tab that
 * fixes it. That makes the card a navigation surface rather than a readout,
 * which is what a brainstorming view should be — and because the indicator is
 * permanent rather than conditional, the space it occupies is reserved, so a
 * card never reflows when someone fills a value in.
 *
 * The root has no indicator. It owns no values of its own and has no values
 * tab to open; a control that leads nowhere is worse than a gap. Assessment
 * completeness is a whole-graph fact and lives on the list's ToS card and in
 * the report.
 *
 * Defences also show their status as an **icon only** — implemented, todo or
 * shelved — with the word in the tooltip and in the accessible name. It is one
 * glyph, it is structural rather than computed, and a room needs to see at a
 * glance which controls are real and which are aspirations.
 *
 * ## W69 — the indicator moved, and became the figure
 *
 * Two changes, both to the same control (PLAN-2.4.md D26).
 *
 * It **moved to the bottom row**, which had `mt-auto` and nothing in it but
 * the status glyph and the counters. In the title row it competed for width
 * with a two-line `line-clamp`, so the one card element that must always be
 * legible was the one being squeezed by the title.
 *
 * And it now renders the **figure** rather than a checkmark. A checkmark
 * answers "is this filled in", which is a question about the data-entry task;
 * the figure answers "what did we say", which is the question a room actually
 * asks of a board. This is not a return to W37's cues — those were four
 * different visual languages standing in for numbers nobody could read
 * without hovering. This is one short string, in one place, on every tier.
 *
 * Three constraints hold it together:
 *
 * - **A defence's figure is per occurrence.** Strength lives on the
 *   `attack_defence` pivot (CLAUDE.md), so the same defence under two attacks
 *   shows two different percentages. That is correct, and it is why the edge
 *   is a prop rather than something looked up from the uuid.
 * - **A qualitative value is not a number.** Such a node carries a
 *   `*_level_id` and no amount; the level's **label** is what gets rendered.
 *   `ScaleMapper` is the only thing in this codebase that turns a level into a
 *   figure and it does that for the engine, against the assessment's own
 *   calibration — not for a card.
 * - **The space stays reserved in both states**, which is the whole reason
 *   W37 made the indicator permanent. Filling a value in must not reflow the
 *   card.
 *
 * The ToS is the exception, and it is the one place a *computed* figure
 * belongs: it is the whole assessment's answer rather than one node's input.
 * It renders as a two-segment bar against a full-width inherent ground —
 * current and target — with the three totals in the tooltip. A bar precisely
 * so it cannot be mistaken for one of the typed-in figures the other tiers
 * show, and because the root still owns no values of its own, so there is
 * still no tab for it to open and still no `?` state to reserve room for.
 */
import type { DefenceStatus } from '@/calc/types';
import AssistantPanel from '@/Components/Node/AssistantPanel.vue';
import SharedCounter from '@/Components/Node/SharedCounter.vue';
import TemplatePicker from '@/Components/Node/TemplatePicker.vue';
import { useAssistantChildren } from '@/Components/Node/useAssistantChildren';
import { useTemplateChildren } from '@/Components/Node/useTemplateChildren';
import { useScales } from '@/Components/NodeModal/useScales';
import {
    formatMoney,
    formatMoneyShort,
    formatPercent,
    formatRate,
    formatRateShort,
} from '@/Components/UI/format';
import HiddenCountBadge from '@/Components/UI/HiddenCountBadge.vue';
import Icon from '@/Components/UI/Icon.vue';
import IconButton from '@/Components/UI/IconButton.vue';
import Tooltip from '@/Components/UI/Tooltip.vue';
import { DRAG_DROP_KEY, type DragPayload } from '@/composables/useDragDrop';
import { EDITOR_VIEW_KEY } from '@/composables/useEditorView';
import { LOCKS_KEY } from '@/composables/useLocks';
import { NODE_TYPES, hasChildType, type CardType } from '@/constants/nodeTypes';
import { MISSING_HINTS } from '@/constants/valueHints';
import {
    ASSESSMENT_STORE_KEY,
    type AttackDefenceLink,
} from '@/stores/assessmentStore';
import { computed, inject, ref, watch } from 'vue';

const props = defineProps<{
    uuid: string;
    pathKey: string;
    edge?: AttackDefenceLink;
    hasChildren: boolean;
    /** Direct children; the badge shows it while collapsed (W127). */
    childCount?: number;
    collapsed: boolean;
    selected: boolean;
    /** What dragging this occurrence means; null for the root. See ChartNode.vue. */
    dragPayload?: DragPayload | null;
}>();

const emit = defineEmits<{
    select: [];
    open: [];
    /** Carries the tab, because the card is what knows where this type's value lives. */
    openValues: [tab: 'values' | 'links'];
    toggle: [];
    addChild: [];
}>();

const store = inject(ASSESSMENT_STORE_KEY)!;
const view = inject(EDITOR_VIEW_KEY, null);
// Optional, like every other injection here: the chart specs mount cards with
// only the store, and without a drag handle the card is simply not draggable.
const dragDrop = inject(DRAG_DROP_KEY, null);
const locks = inject(LOCKS_KEY, null);

/** Someone else has this node open in the modal (2.5.1). */
const nodeLockedBy = computed(
    () => locks?.lockedBy(props.uuid, 'node') ?? null,
);

/** A frozen snapshot mounts no modal, so nothing on it opens one. */
const canOpen = computed(() => !store.frozen);

/**
 * W38: the chart gets the same drag affordance the list has had since W27,
 * and on the same element for the same reason — the card root is exactly one
 * occurrence, whereas the enclosing `<li role="treeitem">` wraps the whole
 * subtree, so a drop anywhere below a node would register on every ancestor.
 */
// Not a drag source while the template picker is open (2.5.1): the picker is
// inside this root, so a press-and-move on its text would otherwise pick up
// the whole card. It stays a drop target.
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

const cardType = computed<CardType>(() =>
    node.value?.type === 'defence' && node.value.is_transfer
        ? 'transfer'
        : (node.value?.type ?? 'risk'),
);
const meta = computed(() => NODE_TYPES[cardType.value]);

const TYPE_CLASSES: Record<CardType, string> = {
    root: 'border-node-root-border bg-node-root-bg text-node-root-text',
    risk: 'border-node-risk-border bg-node-risk-bg text-node-risk-text',
    attack: 'border-node-attack-border bg-node-attack-bg text-node-attack-text',
    defence:
        'border-node-defence-border bg-node-defence-bg text-node-defence-text',
    transfer:
        'border-node-transfer-border bg-node-transfer-bg text-node-transfer-text',
};
const typeClasses = computed(() => TYPE_CLASSES[cardType.value]);

const dimmed = computed(() => view?.filters.isDimmed(props.pathKey) ?? false);
const highlighted = computed(
    () =>
        view?.duplicates.highlightedPathKeys.value.has(props.pathKey) ?? false,
);
const isSearchMatch = computed(
    () => view?.search.activeMatch.value?.pathKey === props.pathKey,
);

const occurrenceCount = computed(() =>
    node.value ? store.occurrenceCount(props.uuid, node.value.type) : 1,
);
const unresolvedCommentCount = computed(
    () => store.unresolvedCommentCounts[props.uuid] ?? 0,
);

const canEdit = computed(() => store.abilities.update);
const showAddChild = computed(
    () => node.value !== undefined && hasChildType(node.value.type),
);

/** D95: the count stands in for the chevron while collapsed. */
const showBadge = computed(
    () => props.collapsed && (props.childCount ?? 0) > 0,
);
const showBar = computed(
    () =>
        showBadge.value ||
        props.hasChildren ||
        (showAddChild.value && canEdit.value),
);

// W85/D50: the same trigger and the same guardrail (D24) as the tree card
// (NodeCard.vue), sharing useAssistantChildren.ts rather than a second copy
// of it — see that file's docblock for why.
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
    computed(() => node.value?.type),
);

// 2.5.1: add from a template — the tree card's trigger and panel, via the
// same composable.
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
    computed(() => node.value?.type),
);

/**
 * W105: both panels float below the card inside the chart's scroll container,
 * which clips them. Below the bottom tier of a chart taller than the window
 * the panel would open out of sight, so it is scrolled into view when it
 * appears and again when its content changes: the picker grows when its
 * templates load and when one is chosen, the suggestions when the generation
 * finishes. `nearest` moves nothing when the panel already fits.
 */
const assistantFloat = ref<HTMLElement | null>(null);
const templateFloat = ref<HTMLElement | null>(null);

function reveal(panel: HTMLElement | null) {
    panel?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
}

watch(
    [assistantFloat, assistantAsking, assistantError, assistantItems],
    () => reveal(assistantFloat.value),
    { flush: 'post' },
);
watch(
    [templateFloat, templateLoading, templateUuid, templateItems],
    () => reveal(templateFloat.value),
    { flush: 'post' },
);

const STATUS_ICONS: Record<
    DefenceStatus,
    'circle-check' | 'circle-dot' | 'circle-x'
> = {
    implemented: 'circle-check',
    todo: 'circle-dot',
    shelved: 'circle-x',
};

const STATUS_LABELS: Record<DefenceStatus, string> = {
    implemented: 'Implemented',
    todo: 'Todo',
    shelved: 'Shelved',
};

const defenceStatus = computed(() =>
    node.value?.type === 'defence'
        ? ((node.value.status ?? 'todo') as DefenceStatus)
        : null,
);

const currency = computed(() => store.assessment.currency);

// Reads the assessment's calibration off the Inertia page props. Both pages
// that mount this card (Assessment/Show and Snapshots/View) receive
// `scaleLevels`; see useScales.ts for why it does not go through the store.
const { levelById } = useScales();

const shortMoney = (value: number | null) =>
    formatMoneyShort(value, { currency: currency.value });

/**
 * The figure a set value renders as: an abbreviated amount, a rate, a
 * percentage, or — for a qualitative value — the chosen level's **label**.
 *
 * The label, not the code (W87, D44). `scale_levels.code` is internal: it is
 * derived from the label when a band is created and then frozen, so that a
 * catalogue fixture naming "moderate" keeps resolving after someone renames
 * the band. Drawing it here meant that renaming "Moderate" to "Medium" left
 * every card still saying "moderate" — an internal key, shown to a user,
 * disagreeing with the band table they had just edited.
 *
 * Returns `'—'` rather than `null` for the one state that should not occur:
 * a `*_level_id` naming a level the page did not send. That is a scale the
 * card cannot resolve, not a value nobody entered, and rendering `?` there
 * would contradict `data-values-set`, which stays structural.
 */
function figureFor(levelId: number | null, quantitative: string | null) {
    if (levelId !== null) return levelById(levelId)?.label ?? '—';

    return quantitative ?? '—';
}

/**
 * Whether this node's own values are set, and what to say about it.
 *
 * "Its own" is the operative word, and it differs by type because the schema
 * puts the value in a different place each time: a risk owns its impact, an
 * attack owns its likelihood, and a defence's strength lives on the
 * `attack_defence` edge (CLAUDE.md), so a defence appearing under two attacks
 * can legitimately be complete in one place and not the other. The indicator
 * is per occurrence for exactly that reason.
 *
 * `null` for the root — see the file header.
 */
const values = computed(() => {
    const n = node.value;
    if (!n) return null;

    if (n.type === 'risk') {
        const set = n.impact_amount !== null || n.impact_level_id !== null;
        return {
            set,
            tab: 'values' as const,
            // Impact is loss per occurrence, never annual loss
            // (calculation-engine.md) — so the tooltip says so, because a
            // currency figure on a risk card invites exactly that misreading.
            label: set
                ? `Impact ${figureFor(
                      n.impact_level_id,
                      formatMoney(n.impact_amount, {
                          currency: currency.value,
                      }),
                  )} per occurrence — open to change it`
                : MISSING_HINTS.impact,
            figure: figureFor(n.impact_level_id, shortMoney(n.impact_amount)),
        };
    }

    if (n.type === 'attack') {
        const set = n.likelihood_aro !== null || n.likelihood_level_id !== null;
        return {
            set,
            tab: 'values' as const,
            // A rate per year, which may exceed 1 (calculation-engine.md), so
            // it is never phrased as a chance of happening.
            label: set
                ? `Likelihood ${figureFor(
                      n.likelihood_level_id,
                      formatRate(n.likelihood_aro),
                  )} — open to change it`
                : MISSING_HINTS.likelihood,
            figure: figureFor(
                n.likelihood_level_id,
                formatRateShort(n.likelihood_aro),
            ),
        };
    }

    if (n.type === 'defence') {
        // No edge means this defence is being rendered outside a link, which
        // the chart does not currently do; treat it as nothing to assert
        // rather than as missing, since there is no edge to be missing on.
        if (!props.edge) return null;
        const set =
            props.edge.strength_fraction !== null ||
            props.edge.strength_level_id !== null;
        const figure = figureFor(
            props.edge.strength_level_id,
            formatPercent(props.edge.strength_fraction),
        );
        return {
            set,
            tab: 'links' as const,
            // "for this attack" is the whole point: the figure is the edge's,
            // so the same defence under another attack shows another number.
            label: set
                ? `Strength ${figure} against this attack — open to change it`
                : MISSING_HINTS.strength,
            figure,
        };
    }

    return null;
});

/**
 * The ToS bar: current and target annual loss as fractions of inherent.
 *
 * Null whenever there is no inherent loss to be a fraction of — an assessment
 * with nothing valued yet, which is the root's "unset" state. There is
 * deliberately no `?` here: unlike the other three tiers the root owns no
 * value anyone could go and fill in, so a placeholder would be a prompt to do
 * something impossible.
 *
 * Both fractions are clamped. `target` includes todo defences and `current`
 * does not, so target is normally the smaller of the two, but nothing in the
 * engine guarantees it — `reduction_target` moves which sub-product a defence
 * divides into, and a partially-valued graph can order them either way. The
 * bar must not overflow its ground on that account.
 */
const tosBar = computed(() => {
    if (node.value?.type !== 'root') return null;

    const results = store.results.value;
    const inherent = results.inherent.total;
    if (!(inherent > 0)) return null;

    const clamp = (value: number) => Math.min(1, Math.max(0, value / inherent));
    const money = (value: number) =>
        formatMoney(value, { currency: currency.value }) ?? '—';

    return {
        current: clamp(results.current.total),
        target: clamp(results.target.total),
        label: `Annual loss — inherent ${money(inherent)}, current ${money(results.current.total)}, target ${money(results.target.total)}`,
    };
});
</script>

<template>
    <div
        v-if="node"
        class="group/chartcard bg-surface-1 relative flex h-full w-full flex-col gap-1 rounded-md border-t border-r border-b border-l-4 p-2 text-left shadow-sm transition-opacity"
        :class="[
            typeClasses,
            (dimmed || beingDragged) && 'opacity-40',
            (dropKind === 'into' || dropKind === 'parent') &&
                'outline-focus-ring outline-2 outline-offset-2 outline-dashed',
            selected && 'ring-focus-ring ring-2',
            highlighted && !selected && 'ring-focus-ring ring-1',
            isSearchMatch && 'ring-focus-ring ring-2',
        ]"
        v-bind="dragAttrs"
        @mouseenter="view?.duplicates.hover(uuid)"
        @mouseleave="view?.duplicates.unhover(uuid)"
        @click="emit('select')"
    >
        <!-- 2.5.1: the drop indicator. A bar at the card's leading edge when
             the dragged card would take this one's place; an outline (above)
             when it would go under this card or under its parent. -->
        <span
            v-if="dropKind === 'before'"
            class="bg-focus-ring pointer-events-none absolute inset-y-0 -left-2 w-1 rounded-full"
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
        <div class="flex items-start gap-1">
            <Tooltip :text="meta.label">
                <span class="mt-0.5 shrink-0">
                    <Icon :name="meta.icon" :size="12" />
                </span>
            </Tooltip>

            <!-- 2.5.1: a click on the title or the description opens the
                 modal on Details — the same gesture as in the list. A click
                 anywhere else on the card still selects it. -->
            <button
                type="button"
                class="text-text-primary hover:text-text-link min-w-0 flex-1 text-left text-xs leading-tight font-medium focus:outline-none"
                :class="[
                    !node.title && 'text-text-muted italic',
                    canOpen ? 'cursor-pointer' : 'cursor-default',
                ]"
                :aria-label="`${meta.label}: ${node.title || 'Untitled'}`"
                data-testid="node-title"
                @click.stop="
                    emit('select');
                    emit('open');
                "
                @keydown.enter.prevent="emit('open')"
            >
                <span class="line-clamp-2">{{
                    node.title || `Untitled ${meta.label.toLowerCase()}`
                }}</span>
            </button>
        </div>

        <p
            v-if="node.description"
            class="text-text-muted line-clamp-3 text-[11px] leading-snug"
            :class="canOpen && 'cursor-pointer'"
            data-testid="node-description"
            @click.stop="
                emit('select');
                emit('open');
            "
        >
            {{ node.description }}
        </p>
        <p
            v-else
            class="text-text-muted/60 text-[11px] italic"
            :class="canOpen && 'cursor-pointer'"
            data-testid="node-description"
            @click.stop="
                emit('select');
                emit('open');
            "
        >
            No description
        </p>

        <div class="text-text-muted mt-auto flex items-center gap-1.5">
            <Tooltip
                v-if="defenceStatus"
                :text="`Status: ${STATUS_LABELS[defenceStatus]}`"
            >
                <span
                    class="inline-flex items-center"
                    role="img"
                    :aria-label="`Status: ${STATUS_LABELS[defenceStatus]}`"
                >
                    <Icon :name="STATUS_ICONS[defenceStatus]" :size="12" />
                </span>
            </Tooltip>

            <!-- W69: the figure, bottom-left, after the status glyph for a
                 defence. Present in both states — a `?` when unset — so the
                 space is reserved and the card never reflows when a value is
                 filled in. `min-w-0` + `truncate` rather than a wider box:
                 the neighbouring counters must keep their place even when a
                 qualitative level's code is long. -->
            <Tooltip v-if="values" :text="values.label">
                <button
                    type="button"
                    class="inline-flex h-4 min-w-4 shrink-0 cursor-pointer items-center justify-center rounded px-0.5 text-[10px] leading-none tabular-nums"
                    :class="
                        values.set
                            ? 'text-text-secondary hover:text-text-primary font-medium'
                            : 'border-missing-border text-missing-text bg-missing-bg border border-dashed'
                    "
                    :aria-label="values.label"
                    :data-values-set="values.set ? 'true' : 'false'"
                    @click.stop="emit('openValues', values.tab)"
                >
                    <span class="max-w-16 truncate">{{
                        values.set ? values.figure : '?'
                    }}</span>
                </button>
            </Tooltip>

            <!-- The one computed figure on the board, and the one node it can
                 belong to. Two segments over a full-width inherent ground; the
                 amounts are in the tooltip, deliberately not on the card. -->
            <Tooltip v-if="tosBar" :text="tosBar.label">
                <span
                    class="bg-surface-2 relative block h-1.5 w-16 overflow-hidden rounded-full"
                    role="img"
                    data-tos-bar
                    :aria-label="tosBar.label"
                >
                    <span
                        class="bg-magnitude-3-bg absolute inset-y-0 left-0 rounded-full"
                        data-tos-segment="current"
                        :style="{ width: `${tosBar.current * 100}%` }"
                    />
                    <span
                        class="bg-magnitude-1-bg absolute inset-y-0 left-0 rounded-full"
                        data-tos-segment="target"
                        :style="{ width: `${tosBar.target * 100}%` }"
                    />
                </span>
            </Tooltip>

            <span class="ml-auto flex items-center gap-1">
                <!-- W69/D31, shared with the list card since 2.5.1. -->
                <SharedCounter
                    v-if="occurrenceCount > 1"
                    :store="store"
                    :uuid="uuid"
                />
                <Tooltip
                    v-if="nodeLockedBy"
                    :text="`Currently being edited by ${nodeLockedBy.name}`"
                >
                    <span
                        class="inline-flex items-center"
                        role="img"
                        :aria-label="`Currently being edited by ${nodeLockedBy.name}`"
                        data-testid="node-locked"
                    >
                        <Icon name="lock" :size="10" />
                    </span>
                </Tooltip>
                <Tooltip
                    v-if="unresolvedCommentCount > 0"
                    :text="`${unresolvedCommentCount} unresolved comment${unresolvedCommentCount === 1 ? '' : 's'}`"
                >
                    <Icon name="message-square" :size="10" />
                </Tooltip>
            </span>
        </div>

        <!--
            W37: the hover panel moved from the top-right to the bottom edge,
            and lost two of its four actions.

            It used to sit over exactly where the values indicator now lives
            permanently, hiding the one thing the card is meant to always
            show. Open and Delete left it rather than being relocated: opening
            is a double-click anywhere on the card, and deleting is in the
            modal's header, on every tab. Both were affordances that opened a
            dialog from a two-pixel target on hover — the chart is used on a
            projector, and a destructive action reachable only by hovering a
            small icon is the wrong thing to have there.
        -->
        <!--
            W127 follow-up (D104): one bar on the bottom edge. A collapsed card
            shows its hidden-children count there at rest, in the bar's own
            button ground (the owner: it must read as the expand button, always
            showing, with the count on it); on hover the count
            slides left as the add-child actions open out beside it, so the
            count is the "expand" button. It is absolute, so it adds no height.

            Revealed by hover or by *keyboard* focus (`focus-visible`), not by
            `focus-within`: a mouse click leaves focus on the clicked button,
            and `focus-within` then kept the bar open after the pointer left,
            and while other cards were used, until the button was used again.
        -->
        <div
            v-if="showBar"
            class="absolute -bottom-2 left-1/2 flex -translate-x-1/2 items-center rounded border px-0.5"
            :class="
                showBadge
                    ? 'bg-surface-1 border-border-subtle shadow-sm'
                    : 'group-hover/chartcard:bg-surface-1 group-hover/chartcard:border-border-subtle group-has-focus-visible/chartcard:bg-surface-1 group-has-focus-visible/chartcard:border-border-subtle border-transparent group-hover/chartcard:shadow-sm group-has-focus-visible/chartcard:shadow-sm'
            "
            data-testid="card-bar"
        >
            <HiddenCountBadge
                v-if="showBadge"
                :count="childCount ?? 0"
                @toggle="emit('toggle')"
            />
            <div
                class="invisible flex max-w-0 items-center gap-0.5 overflow-hidden opacity-0 transition-[max-width,opacity,visibility] duration-150 group-hover/chartcard:visible group-hover/chartcard:max-w-48 group-hover/chartcard:opacity-100 group-has-focus-visible/chartcard:visible group-has-focus-visible/chartcard:max-w-48 group-has-focus-visible/chartcard:opacity-100"
                data-testid="card-actions"
            >
                <IconButton
                    v-if="hasChildren && !showBadge"
                    :icon="collapsed ? 'chevron-right' : 'chevron-down'"
                    :label="collapsed ? 'Expand' : 'Collapse'"
                    @click="emit('toggle')"
                />
                <IconButton
                    v-if="showAddChild && canEdit"
                    icon="plus"
                    label="Add child"
                    @click="emit('addChild')"
                />
                <IconButton
                    v-if="showAddChild && canEdit"
                    icon="copy"
                    :label="`Add ${templateChildType}s from a template`"
                    data-testid="add-from-template"
                    @click="openTemplates"
                />
                <!-- W85 item 19 / D50: the assistant trigger, gated on the same
                     three conditions as on the tree card (NodeCard.vue), and
                     the same label — "the same trigger" is the whole of D50. -->
                <IconButton
                    v-if="showAddChild && canEdit && store.assistantAvailable"
                    icon="square-plus"
                    :label="`Suggest ${assistantChildType}s`"
                    :disabled="assistantAsking"
                    data-testid="ask-assistant"
                    @click="askAssistant"
                />
            </div>
        </div>

        <!-- W52/W58, shared with the tree card via AssistantPanel.vue: floats
             below the card rather than pushing chart layout around, and stays
             visible while a generation runs even after the pointer leaves the
             card — the hover panel above may already be hidden by then. -->
        <div
            v-if="assistantAsking || assistantError || assistantItems"
            ref="assistantFloat"
            class="border-border-subtle bg-surface-1 absolute top-full left-0 z-20 mt-1 w-64 rounded border p-2 shadow-md"
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

        <!-- 2.5.1: add from a template. Floats below the card like the
             assistant panel, and is wider because a control's title is. -->
        <div
            v-if="templateOpen"
            ref="templateFloat"
            class="absolute top-full left-0 z-20 mt-1 w-96 shadow-md"
            @click.stop
            @dblclick.stop
        >
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
    </div>
</template>
