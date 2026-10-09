<script setup lang="ts">
/**
 * One occurrence in the chart, positioned from the layout (W32).
 *
 * The nesting is deliberate and load-bearing. It would be simpler to flatten
 * the chart into four rows of absolutely-positioned divs — the coordinates
 * are already computed — but that throws away the `tree`/`treeitem`/`group`
 * structure, and with it the screen-reader reading of the assessment, the
 * `aria-level` that says which tier a card is on, and the keyboard outline
 * (useKeyboardOutline.ts) which walks the same DOM the list view walks. So
 * the `<ul>`/`<li>` tree survives exactly as in TreeNode.vue and only the
 * card inside each `<li>` is absolutely positioned, against the sized
 * wrapper ChartView renders.
 */
import { useNodeModal } from '@/Components/NodeModal/useNodeModal';
import { dragPayloadFor, type DragPayload } from '@/composables/useDragDrop';
import { EDITOR_VIEW_KEY } from '@/composables/useEditorView';
import type { AttackDefenceLink } from '@/stores/assessmentStore';
import { ASSESSMENT_STORE_KEY, occurrencePath } from '@/stores/assessmentStore';
import { computed, inject } from 'vue';
import ChartCard from './ChartCard.vue';
import { CHART_CONTEXT_KEY } from './chartContext';

const props = defineProps<{
    uuid: string;
    ancestorPath: string[];
    edge?: AttackDefenceLink;
}>();

const store = inject(ASSESSMENT_STORE_KEY)!;
const view = inject(EDITOR_VIEW_KEY, null);
const chart = inject(CHART_CONTEXT_KEY)!;
const modal = useNodeModal();

const node = computed(() => store.nodes[props.uuid]);
const path = computed(() => [...props.ancestorPath, props.uuid]);
const pathKey = computed(() => occurrencePath(...path.value));
const expanded = computed(() => store.isExpanded(pathKey.value));

const visible = computed(
    () => !view?.focus.isActive.value || view.focus.isVisible(pathKey.value),
);

const box = computed(() => chart.layout.value.boxes.get(pathKey.value));

const childUuids = computed<string[]>(() => {
    if (!node.value) return [];
    switch (node.value.type) {
        case 'root':
            return store.risksOrder();
        case 'risk':
            return store.attacksUnderRisk(props.uuid);
        case 'attack':
            return store.defencesUnderAttack(props.uuid);
        default:
            return [];
    }
});

function toggle() {
    if (view) view.collapse.toggle(pathKey.value);
    else store.toggleExpanded(pathKey.value);
}

/**
 * Opens the modal on a draft — nothing is created until the person names it
 * and presses Add (NewNodeForm.vue). Until 2.5.1 this POSTed an empty node
 * first, which put an untitled card on the board and told every collaborator
 * about it before anyone had typed a word.
 */
function addChild() {
    if (!node.value || store.frozen) return;
    modal.openDraft(
        node.value.type as 'root' | 'risk' | 'attack',
        props.uuid,
        pathKey.value,
    );
}

/**
 * Opening the modal, unless there is no modal to open (W63).
 *
 * A frozen snapshot renders these cards with no `NodeModal` mounted, because
 * the modal's tabs read live comments, history and references — today's
 * conversation attached to a version it did not happen on. Without this guard
 * a double-click would rewrite the address bar with `?node=…` and put nothing
 * on screen, which reads as the page being broken rather than as the feature
 * being absent.
 */
function openDetails(tab?: Parameters<typeof modal.open>[1]): void {
    if (store.frozen) return;

    modal.open(props.uuid, tab);
}

const dragPayload = computed<DragPayload | null>(() =>
    dragPayloadFor(
        node.value?.type,
        props.uuid,
        pathKey.value,
        props.ancestorPath,
    ),
);

function childEdge(childUuid: string): AttackDefenceLink | undefined {
    if (node.value?.type !== 'attack') return undefined;
    return store.attackDefenceLinks.find(
        (l) => l.attack_uuid === props.uuid && l.defence_uuid === childUuid,
    );
}
</script>

<template>
    <li
        v-if="node && visible && box"
        role="treeitem"
        :aria-level="path.length"
        :aria-expanded="childUuids.length ? expanded : undefined"
        :aria-selected="chart.selectedPathKey.value === pathKey"
    >
        <div
            class="absolute"
            :style="{
                left: `${box.x}px`,
                top: `${box.y}px`,
                width: `${box.width}px`,
                height: `${box.height}px`,
            }"
        >
            <ChartCard
                :uuid="uuid"
                :path-key="pathKey"
                :edge="edge"
                :has-children="childUuids.length > 0"
                :collapsed="!expanded"
                :selected="chart.selectedPathKey.value === pathKey"
                :drag-payload="dragPayload"
                @select="chart.select(pathKey)"
                @open="openDetails()"
                @open-values="(tab) => openDetails(tab)"
                @toggle="toggle"
                @add-child="addChild"
            />
        </div>

        <ul v-if="expanded && childUuids.length" role="group">
            <ChartNode
                v-for="childUuid in childUuids"
                :key="occurrencePath(...path, childUuid)"
                :uuid="childUuid"
                :ancestor-path="path"
                :edge="childEdge(childUuid)"
            />
        </ul>
    </li>
</template>
