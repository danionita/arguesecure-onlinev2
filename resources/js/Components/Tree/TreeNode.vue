<script setup lang="ts">
/**
 * Recursive DAG renderer (ui.md §3.1). Each recursive instance renders one
 * *occurrence* of a node — root -> risk -> attack -> defence — carrying the
 * uuid path down from the root. A node that occurs under two risks is
 * rendered by two separate TreeNode instances with different `ancestorPath`s
 * but the SAME `store.nodes[uuid]` record (see assessmentStore.ts's
 * top-of-file comment for the full rationale).
 *
 * `<script setup>` SFCs can reference themselves recursively by filename
 * without extra registration (Vue 3.2+), which is what the `<TreeNode>` tag
 * inside this file's own template resolves to.
 *
 * This is exclusively the list view's renderer — the chart's equivalent has
 * been `Components/Chart/ChartNode.vue` since W32 — but it stays in
 * `Components/Tree/` rather than moving into `Components/List/` (W84):
 * `Tree/NodeCard.vue` sits right beside it (W85's) and W88 is dispatched
 * against `Tree/useNodeSuggestions.ts`, so relocating this file would rewrite
 * import paths in files this package does not own and risk colliding with
 * W88's own in-flight work, for no functional gain — D51's spacing and
 * indent changes below only need this file edited, not moved.
 */
import { dragPayloadFor, type DragPayload } from '@/composables/useDragDrop';
import { EDITOR_VIEW_KEY } from '@/composables/useEditorView';
import type { AttackDefenceLink } from '@/stores/assessmentStore';
import { ASSESSMENT_STORE_KEY, occurrencePath } from '@/stores/assessmentStore';
import { computed, inject } from 'vue';
import NodeCard from './NodeCard.vue';

const props = defineProps<{
    uuid: string;
    ancestorPath: string[];
    /** Present only for a defence occurrence — carries this specific edge's strength/reduction_target (schema.md §3.6: strength lives on the pivot). */
    edge?: AttackDefenceLink;
}>();

const store = inject(ASSESSMENT_STORE_KEY)!;
// Optional: several tests mount TreeNode with only the store provided, and
// with no editor view the tree simply renders unfiltered and unfocused.
const view = inject(EDITOR_VIEW_KEY, null);

const node = computed(() => store.nodes[props.uuid]);
const path = computed(() => [...props.ancestorPath, props.uuid]);
const pathKey = computed(() => occurrencePath(...path.value));
const expanded = computed(() => store.isExpanded(pathKey.value));

// Focus mode hides everything outside the focused subtree (ui.md §5); filters
// only dim, so a filtered-out node still renders — see NodeCard.
const visible = computed(
    () => !view?.focus.isActive.value || view.focus.isVisible(pathKey.value),
);

// Route the chevron through useCollapseState so per-user collapse state is
// persisted; calling store.toggleExpanded directly bypasses persistence.
function toggle() {
    if (view) view.collapse.toggle(pathKey.value);
    else store.toggleExpanded(pathKey.value);
}

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

/**
 * What this occurrence is, as far as dragging is concerned (W27 defect 3).
 *
 * W38 moved the switch itself into `dragPayloadFor` in useDragDrop.ts, where
 * the chart's cards read the same one. Two copies of "what does dragging this
 * mean" would be invisible until a drop landed under the wrong parent in one
 * view and the right one in the other.
 */
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
        v-if="node && visible"
        role="treeitem"
        :aria-level="path.length"
        :aria-expanded="childUuids.length ? expanded : undefined"
        class="mb-1.5"
    >
        <!--
            W84/D51: "the list breathes" — two changes, not substitutes for
            each other (see useCanvas.ts's CANVAS_BASE_SCALE docblock for the
            other half, the baseline). `mb-1.5` (6px) is this element's own
            contribution to row spacing; NodeCard.vue's `py-0.5` (W85, not
            this package's) adds to it, so the two stack rather than one
            replacing the other. Asserted as this exact class in
            tests/js/list/TreeNode.spec.ts — a class of "some margin" would
            stay green if it were reverted to nothing.
        -->
        <NodeCard
            :uuid="uuid"
            :path-key="pathKey"
            :edge="edge"
            :expanded="expanded"
            :has-children="childUuids.length > 0"
            :drag-payload="dragPayload"
            @toggle="toggle"
        />
        <!-- W84/D51: indent per level grew from `ml-2.5 pl-2.5` (10px) to
             `ml-4 pl-4` (16px) — the item 16 density complaint was partly
             that sibling levels read as one indistinct column. -->
        <ul
            v-if="expanded && childUuids.length"
            role="group"
            class="border-border-subtle ml-4 border-l pl-4"
        >
            <TreeNode
                v-for="childUuid in childUuids"
                :key="occurrencePath(...path, childUuid)"
                :uuid="childUuid"
                :ancestor-path="path"
                :edge="childEdge(childUuid)"
            />
        </ul>
    </li>
</template>
