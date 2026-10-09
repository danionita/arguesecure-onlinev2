<script setup lang="ts">
/**
 * "Appears under" — the risks one attack belongs to, with detach and
 * link-under-another (W72 item 12, PLAN-2.4.md D31; ui.md §7).
 *
 * D31 is explicit that this is a *move*, not a deletion: this is the only
 * place in the whole application that can detach an attack from one of the
 * several risks it belongs to. Dragging (W38) moves an edge and the add-child
 * affordance creates a node; neither detaches one parent of many, and no card
 * carries an unlink action. Delete it and the capability goes with it.
 *
 * Where it moves to is a *popover behind the parent counter* — the `shuffle`
 * badge that already says "appears in 3 places" on the tree card and the chart
 * card. That badge is where a reader asks "under which three?", and until now
 * the answer was a tooltip that could only tell them the number they had just
 * read. So the section leaves the Details tab, where it was a permanently
 * expanded block of structure sitting under two text fields, and becomes the
 * thing the counter opens.
 *
 * ## Why this is a component rather than markup in a card
 *
 * The counter exists twice — `Components/Tree/NodeCard.vue` and
 * `Components/Chart/ChartCard.vue` — and both are outside W72's file
 * ownership. So the popover is built here, self-contained and mounted from a
 * single `<AppearsUnderPopover :store :uuid />`, and W72's report names the
 * two lines each card needs. Until those land it renders from the Details
 * tab, because an affordance may not leave one surface in one commit and
 * arrive on another in the next: the W36/W37 rule, and the reason the Delete
 * button in NodeModal.vue's header was a deliberate cross-package edit too.
 *
 * ## The popup is anchored, not nested
 *
 * Same constraint the combobox inside it has (ui.md §7, D29): every surface
 * this can be mounted on — the modal body, the chart's canvas, the tree's
 * scroller — is a clipping ancestor, so an absolutely-positioned child is cut
 * off rather than overlaid. The panel is therefore teleported to `body` and
 * positioned from the trigger's measured rect, and "click outside to close"
 * has to name every part of the popup explicitly, including the combobox's
 * own listbox, which is a third top-level element again.
 */
import Combobox from '@/Components/UI/Combobox.vue';
import Icon from '@/Components/UI/Icon.vue';
import IconButton from '@/Components/UI/IconButton.vue';
import type { AssessmentStore } from '@/stores/assessmentStore';
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { useLinkMutations } from '../useLinkMutations';

const props = defineProps<{
    store: AssessmentStore;
    uuid: string;
}>();

const node = computed(() => props.store.nodes[props.uuid]);
const canEdit = computed(() => props.store.abilities.update);
const mutations = useLinkMutations(props.store);

const open = ref(false);
const trigger = ref<HTMLElement | null>(null);
const panelId = `${useId()}-appears-under`;

const parentRisks = computed(() =>
    node.value?.type === 'attack'
        ? props.store.risksForAttack(props.uuid).map((uuid) => ({
              uuid,
              title: props.store.nodes[uuid]?.title || 'Untitled risk',
          }))
        : [],
);

/**
 * Detaching the only parent does not delete the attack, it hides it: an
 * orphan is a legitimate state the engine reports on, but it is not visible
 * anywhere in the tree, so a user who meant "delete" gets a node they cannot
 * find. Blocked with the reason in the panel rather than silently absent.
 *
 * The condition is `> 1`, not `>= 1` and not "there are parents" — a presence
 * check here would pass with one parent and leave the hole this guard exists
 * to close.
 */
const canDetach = computed(() => canEdit.value && parentRisks.value.length > 1);

async function detachFrom(riskUuid: string) {
    await mutations.unlinkRiskFromAttack(riskUuid, props.uuid);
}

/**
 * The other half of the same question. Dragging a card onto a second risk and
 * choosing "Link it here as well" (W38) is the fast way to do this and the
 * only way that shows you where the node lands; this is the way that works
 * when the other risk is scrolled off screen, collapsed, or filtered out —
 * which, in an assessment large enough to have shared attacks, it usually is.
 */
const linkableRisks = computed(() =>
    node.value?.type === 'attack'
        ? Object.values(props.store.nodes)
              .filter(
                  (candidate) =>
                      candidate.type === 'risk' &&
                      !parentRisks.value.some((r) => r.uuid === candidate.uuid),
              )
              .map((candidate) => ({
                  value: candidate.uuid,
                  label: candidate.title || 'Untitled risk',
              }))
        : [],
);

async function attachTo(riskUuid: string) {
    await mutations.linkRiskToAttack(riskUuid, props.uuid);
}

const panelStyle = ref<Record<string, string>>({});

const GAP = 4;
const PANEL_WIDTH = 288; // w-72, in px — the panel is measured, not fluid.

function updatePosition(): void {
    const rect = trigger.value?.getBoundingClientRect();
    if (!rect) return;

    // Clamped to the viewport's right edge rather than flipped: the counter
    // sits at the right of a card, so an unclamped panel is off-screen far
    // more often than it is too low.
    const left = Math.max(
        GAP,
        Math.min(rect.left, window.innerWidth - PANEL_WIDTH - GAP),
    );

    panelStyle.value = {
        position: 'fixed',
        left: `${left}px`,
        top: `${rect.bottom + GAP}px`,
        width: `${PANEL_WIDTH}px`,
    };
}

/**
 * "Outside" is spelled out because none of the three pieces contains the
 * others any more: the trigger is in the page, the panel is in `body`, and
 * the combobox's listbox is a third element in `body` again. Closing on a
 * click in the listbox would eat the very selection the user is making.
 */
function onDocumentMousedown(event: MouseEvent): void {
    const target = event.target as HTMLElement | null;
    if (!target) return;
    if (trigger.value?.contains(target)) return;
    if (target.closest(`#${CSS.escape(panelId)}`)) return;
    if (target.closest('[data-combobox-placement]')) return;

    open.value = false;
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') open.value = false;
}

watch(open, (isOpen) => {
    if (isOpen) {
        updatePosition();
        window.addEventListener('scroll', updatePosition, true);
        window.addEventListener('resize', updatePosition);
        document.addEventListener('mousedown', onDocumentMousedown, true);
        document.addEventListener('keydown', onKeydown);
    } else {
        window.removeEventListener('scroll', updatePosition, true);
        window.removeEventListener('resize', updatePosition);
        document.removeEventListener('mousedown', onDocumentMousedown, true);
        document.removeEventListener('keydown', onKeydown);
    }
});

onBeforeUnmount(() => {
    open.value = false;
});
</script>

<template>
    <div v-if="node?.type === 'attack'" class="inline-block">
        <!-- W85 item 1 / D31: the trigger now matches the defence counter
             (icon + number chip) at the same size and with the same gap. -->
        <button
            ref="trigger"
            type="button"
            class="border-border-subtle text-text-secondary hover:bg-surface-2 focus-visible:outline-focus-ring inline-flex items-center gap-0.5 rounded-md border px-1.5 py-0.5 text-[10px] outline-none focus-visible:outline-2 focus-visible:outline-offset-2"
            data-testid="appears-under-trigger"
            :aria-expanded="open"
            :aria-controls="open ? panelId : undefined"
            :aria-label="`Appears under ${parentRisks.length} risk${parentRisks.length === 1 ? '' : 's'}`"
            @click="open = !open"
        >
            <Icon name="shuffle" :size="10" />
            <span class="font-medium">{{ parentRisks.length }}</span>
        </button>

        <Teleport to="body">
            <div
                v-if="open"
                :id="panelId"
                data-testid="appears-under"
                class="border-border-default bg-surface-1 z-50 rounded-md border p-3 shadow-lg"
                :style="panelStyle"
            >
                <h3 class="text-text-primary mb-1 text-sm font-medium">
                    Appears under
                </h3>
                <ul class="space-y-1">
                    <li
                        v-for="risk in parentRisks"
                        :key="risk.uuid"
                        class="flex items-center justify-between gap-2 text-sm"
                        :data-parent="risk.uuid"
                    >
                        <span class="text-text-primary">{{ risk.title }}</span>
                        <IconButton
                            v-if="canDetach"
                            icon="circle-x"
                            :label="`Remove from ${risk.title}`"
                            @click="detachFrom(risk.uuid)"
                        />
                    </li>
                </ul>

                <p
                    v-if="canEdit && parentRisks.length === 1"
                    class="text-text-muted mt-1 text-xs"
                >
                    This is the only risk it appears under. Removing it would
                    leave the attack with nowhere to show — delete it instead,
                    or link it to another risk first.
                </p>
                <p v-else-if="canEdit" class="text-text-muted mt-1 text-xs">
                    The same attack, shown under each of these. Editing it in
                    one place changes it everywhere.
                </p>

                <Combobox
                    v-if="canEdit"
                    class="mt-2"
                    :model-value="null"
                    :options="linkableRisks"
                    placeholder="Link under an existing risk…"
                    @update:model-value="attachTo"
                />
            </div>
        </Teleport>
    </div>
</template>
