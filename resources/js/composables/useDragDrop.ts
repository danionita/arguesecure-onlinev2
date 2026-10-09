/**
 * Drag and drop: reorder siblings, re-parent across a different parent
 * (ui.md §3, PLAN.md W14). Ordering lives on the edge (schema.md
 * "ordering lives on the edge"), so a drag persists as either a same-parent
 * `reorder` or a cross-parent `move` — see the two routes appended to
 * routes/web.php's W14 block for why those exist at all: W12's merged
 * NodeController has no endpoint for either, and this package cannot add a
 * new controller (only UndoController.php is in its ownership), so they
 * are implemented as small closures there that delegate to
 * App\Domain\Structure\ReorderService / LinkService.
 *
 * Wiring (W27 defect 3): W14 shipped this composable with nothing importing
 * it, because binding `draggable` needs a template change in
 * TreeNode.vue/NodeCard.vue, which W14 did not own — so the product had no
 * reordering or re-parenting affordance at all. Assessment/Show.vue now
 * creates one instance and `provide()`s it under DRAG_DROP_KEY; TreeNode
 * derives each occurrence's DragPayload and NodeCard spreads
 * `dragProps(payload)` onto its own root element, which is both the drag
 * source and the drop target.
 *
 * Note the element that carries it: the card root, not the
 * `<li role="treeitem">`. A treeitem wraps its entire subtree, so a drop
 * anywhere below a node would register on every ancestor as well.
 *
 * A shared node's occurrences are distinct edge rows (ui.md §3.1): dragging
 * one occurrence only ever touches the edge belonging to *that* path — the
 * `move` route resolves endpoints from the uuids the drag itself carries,
 * never by uuid alone, so a shared attack's other risk still shows it
 * exactly where it was.
 */
import { ref, type InjectionKey, type Ref } from 'vue';
import type { AssessmentStore } from '../stores/assessmentStore';

export type DragScope = 'nodes' | 'risk_attack' | 'attack_defence';

export interface DragPayload {
    uuid: string;
    path: string;
    scope: DragScope;
    /** null only for scope 'nodes' (risks under root, defences in the library — no parent to key on). */
    parentUuid: string | null;
    /**
     * The edge scope this node can *parent*, if any — a risk accepts
     * `risk_attack`, an attack accepts `attack_defence`, a defence accepts
     * nothing (W38).
     *
     * The list view's gesture is dropping onto a **sibling**, which says both
     * where to go and in what order. The chart has no sibling to aim at across
     * parents: tiers are rows, and the obvious gesture is dropping an attack
     * straight onto a different risk's card. Without this, that drop compared
     * an attack's `risk_attack` against a risk's `nodes` scope, found them
     * different, and refused — so the primary chart gesture would have
     * silently done nothing.
     */
    accepts?: Exclude<DragScope, 'nodes'> | null;
}

/**
 * What dragging one occurrence means, derived from its type and its ancestor
 * path (schema.md: attacks hang off `risk_attack` under their risk, defences
 * off `attack_defence` under their attack; risks order on `nodes.position`).
 *
 * Shared by both views rather than written out in each. The list and the chart
 * disagreeing about what a drag means would be invisible until a drop went to
 * the wrong parent, and the parent here is the last uuid of *this occurrence's*
 * path — not "the" parent, which a DAG does not have.
 */
export function dragPayloadFor(
    type: string | undefined,
    uuid: string,
    path: string,
    ancestorPath: string[],
): DragPayload | null {
    const parentUuid = ancestorPath[ancestorPath.length - 1] ?? null;

    switch (type) {
        case 'root':
            // Not draggable, but it parents risks, so it is a drop target.
            return null;
        case 'risk':
            return {
                uuid,
                path,
                scope: 'nodes',
                parentUuid: null,
                accepts: 'risk_attack',
            };
        case 'attack':
            return parentUuid
                ? {
                      uuid,
                      path,
                      scope: 'risk_attack',
                      parentUuid,
                      accepts: 'attack_defence',
                  }
                : null;
        case 'defence':
            return parentUuid
                ? {
                      uuid,
                      path,
                      scope: 'attack_defence',
                      parentUuid,
                      accepts: null,
                  }
                : null;
        default:
            return null;
    }
}

/**
 * A cross-parent drop that has landed and is waiting on the user to say what
 * they meant (W38, PLAN-2.2.md D11).
 *
 * The gesture is ambiguous and the two readings are genuinely different
 * mutations, so the composable **stops** here rather than guessing. `Move`
 * re-parents the edge; `Link` adds a second one and leaves the first, which is
 * the DAG capability the whole schema is built around — one attack really can
 * belong to two risks, and the "appears in N places" badge already exists to
 * make that legible.
 *
 * `canLink` is false when the target edge already exists. Writing it would hit
 * the unique index and surface as a 500; offering it and then failing is worse
 * than not offering it.
 */
export interface PendingReparent {
    scope: 'risk_attack' | 'attack_defence';
    childUuid: string;
    childTitle: string;
    fromParentUuid: string;
    toParentUuid: string;
    toParentTitle: string;
    canLink: boolean;
}

/**
 * Where the dragged card would land if released now (2.5.1), so the drop can
 * be seen before the click is let go.
 *
 * - `before` — same parent: it takes the target's place and the target moves
 *   down, so a bar is drawn above the target.
 * - `into` — the target is a parent card (the chart's gesture): the dragged
 *   node goes under it, appended.
 * - `parent` — a sibling under a *different* parent: it goes under that
 *   parent, after a Move-or-Link question.
 *
 * Keyed by occurrence path, not uuid: a shared node is several drop targets.
 */
export interface DropTarget {
    path: string;
    kind: 'before' | 'into' | 'parent';
}

export interface UseDragDrop {
    dragProps: (payload: DragPayload) => Record<string, unknown>;
    /** The occurrence under the pointer that would accept the drop, or null. */
    dropTarget: Ref<DropTarget | null>;
    /** The occurrence being dragged, so its card can show that it is. */
    draggingPath: Ref<string | null>;
    /** The drop awaiting a Move-or-Link decision; null when none is. */
    pending: Ref<PendingReparent | null>;
    resolve: (mode: 'move' | 'link') => Promise<void>;
    cancel: () => void;
}

/**
 * provide()/inject() key — one instance per page, for the same reason the
 * store and the editor view are singletons: `dragging` is a single
 * cross-component handoff between the source card's `dragstart` and the
 * target card's `drop`, and two instances would never see each other's.
 */
export const DRAG_DROP_KEY: InjectionKey<UseDragDrop> = Symbol(
    'arguesecure.dragdrop',
);

const DRAG_MIME = 'application/x-arguesecure-node';

function siblingsFor(
    store: AssessmentStore,
    scope: DragScope,
    parentUuid: string | null,
): string[] {
    if (scope === 'nodes') {
        // Risks under root and defences in the library both key off
        // nodes.position (schema.md §3.4); the caller disambiguates which
        // set it means by which uuids it drags between, so this reads
        // whichever list the dragged node's own type belongs to.
        return store.risksOrder();
    }
    if (scope === 'risk_attack' && parentUuid) {
        return store.attacksUnderRisk(parentUuid);
    }
    if (scope === 'attack_defence' && parentUuid) {
        return store.defencesUnderAttack(parentUuid);
    }
    return [];
}

export function useDragDrop(store: AssessmentStore): UseDragDrop {
    let dragging: DragPayload | null = null;
    const pending = ref<PendingReparent | null>(null);
    const dropTarget = ref<DropTarget | null>(null);
    const draggingPath = ref<string | null>(null);

    function endDrag(): void {
        dragging = null;
        dropTarget.value = null;
        draggingPath.value = null;
    }

    /** What a drop of `from` onto `onto` would do, or null if it is refused. */
    function dropKind(
        from: DragPayload,
        onto: DragPayload,
    ): DropTarget['kind'] | null {
        if (from.uuid === onto.uuid) return null;
        if (onto.accepts && from.scope === onto.accepts) {
            return from.parentUuid === onto.uuid ? null : 'into';
        }
        if (from.scope !== onto.scope) return null;

        return from.parentUuid === onto.parentUuid ? 'before' : 'parent';
    }

    function isLinked(
        scope: 'risk_attack' | 'attack_defence',
        parentUuid: string,
        childUuid: string,
    ): boolean {
        return scope === 'risk_attack'
            ? store.riskAttackLinks.some(
                  (l) =>
                      l.risk_uuid === parentUuid && l.attack_uuid === childUuid,
              )
            : store.attackDefenceLinks.some(
                  (l) =>
                      l.attack_uuid === parentUuid &&
                      l.defence_uuid === childUuid,
              );
    }

    function titleOf(uuid: string): string {
        return store.nodes[uuid]?.title || 'this node';
    }

    async function reorder(
        scope: DragScope,
        parentUuid: string | null,
        order: string[],
    ): Promise<void> {
        await window.axios.post(
            route('assessments.reorder', store.assessment.uuid),
            {
                scope,
                parent_uuid: parentUuid ?? undefined,
                order,
            },
        );

        // Optimistic local patch so the reorder is visible before the next
        // full reload — mutating the store's own exposed reactive arrays,
        // its public surface, not its internals.
        order.forEach((uuid, index) => {
            if (scope === 'risk_attack') {
                const link = store.riskAttackLinks.find(
                    (l) => l.risk_uuid === parentUuid && l.attack_uuid === uuid,
                );
                if (link) link.position = index;
            } else if (scope === 'attack_defence') {
                const link = store.attackDefenceLinks.find(
                    (l) =>
                        l.attack_uuid === parentUuid && l.defence_uuid === uuid,
                );
                if (link) link.position = index;
            } else if (store.nodes[uuid]) {
                store.nodes[uuid].position = index;
            }
        });
        store.recomputeLocally();
    }

    async function move(
        scope: Exclude<DragScope, 'nodes'>,
        fromParentUuid: string,
        toParentUuid: string,
        childUuid: string,
    ): Promise<void> {
        await window.axios.post(
            route('assessments.move', store.assessment.uuid),
            {
                edge: scope,
                from_parent_uuid: fromParentUuid,
                to_parent_uuid: toParentUuid,
                child_uuid: childUuid,
            },
        );

        if (scope === 'risk_attack') {
            const link = store.riskAttackLinks.find(
                (l) =>
                    l.risk_uuid === fromParentUuid &&
                    l.attack_uuid === childUuid,
            );
            if (link) link.risk_uuid = toParentUuid;
        } else {
            const link = store.attackDefenceLinks.find(
                (l) =>
                    l.attack_uuid === fromParentUuid &&
                    l.defence_uuid === childUuid,
            );
            if (link) link.attack_uuid = toParentUuid;
        }
        store.recomputeLocally();
    }

    /**
     * The other half of D11: keep the source edge and add a second one, so the
     * node now belongs in both places. Appended at the destination — ordering
     * lives on the edge (CLAUDE.md), so a node arriving under a new parent has
     * no position there to inherit.
     */
    async function link(
        scope: 'risk_attack' | 'attack_defence',
        toParentUuid: string,
        childUuid: string,
    ): Promise<void> {
        if (scope === 'risk_attack') {
            const response = await window.axios.post(
                route('assessments.risk-attack-links.store', {
                    assessment: store.assessment.uuid,
                }),
                { risk_uuid: toParentUuid, attack_uuid: childUuid },
            );

            // The server's row is authoritative for `position`, but the local
            // patch is built field by field rather than by pushing whatever
            // came back. A response shape that drifts would otherwise put an
            // `undefined` into the store, where it surfaces three layers away
            // inside the calculator rather than here.
            store.riskAttackLinks.push({
                risk_uuid: toParentUuid,
                attack_uuid: childUuid,
                position: response?.data?.link?.position ?? 0,
            });
        } else {
            const response = await window.axios.post(
                route('assessments.attack-defence-links.store', {
                    assessment: store.assessment.uuid,
                }),
                { attack_uuid: toParentUuid, defence_uuid: childUuid },
            );

            store.attackDefenceLinks.push({
                attack_uuid: toParentUuid,
                defence_uuid: childUuid,
                position: response?.data?.link?.position ?? 0,
                // A new edge carries no strength: the defence has not been
                // rated against *this* attack yet. Null, never zero —
                // calculation-engine.md treats a missing strength as no
                // reduction, which overstates residual risk, and that is the
                // safe direction. Zero would claim someone had assessed it.
                strength_mode: null,
                strength_fraction: null,
                strength_level_id: null,
                reduction_target: null,
                rationale: null,
            });
        }
        store.recomputeLocally();
    }

    async function resolve(mode: 'move' | 'link'): Promise<void> {
        const drop = pending.value;
        pending.value = null;
        if (!drop) return;

        if (mode === 'link') {
            if (!drop.canLink) return;
            await link(drop.scope, drop.toParentUuid, drop.childUuid);
            return;
        }

        await move(
            drop.scope,
            drop.fromParentUuid,
            drop.toParentUuid,
            drop.childUuid,
        );
    }

    function cancel(): void {
        pending.value = null;
    }

    function dragProps(payload: DragPayload): Record<string, unknown> {
        return {
            draggable: store.abilities.update,
            onDragstart: (event: DragEvent) => {
                dragging = payload;
                draggingPath.value = payload.path;
                event.dataTransfer?.setData(DRAG_MIME, payload.path);
                if (event.dataTransfer)
                    event.dataTransfer.effectAllowed = 'move';
            },
            onDragover: (event: DragEvent) => {
                if (!dragging) return;
                // W38: the refusal moved here from onDrop. A cross-type drop
                // (an attack onto a defence, say) used to be accepted by the
                // cursor and then silently do nothing — the user saw a
                // "yes, drop here" affordance and got no result and no
                // reason. Refusing at dragover makes the cursor say no
                // before the click lands, which is the only moment the
                // answer is still useful.
                const sameTier = dragging.scope === payload.scope;
                const ontoParent = dragging.scope === payload.accepts;
                if (!sameTier && !ontoParent) {
                    if (event.dataTransfer)
                        event.dataTransfer.dropEffect = 'none';
                    return;
                }
                event.preventDefault();
                if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';

                const kind = dropKind(dragging, payload);
                if (
                    dropTarget.value?.path !== payload.path ||
                    dropTarget.value.kind !== kind
                ) {
                    dropTarget.value = kind
                        ? { path: payload.path, kind }
                        : null;
                }
            },
            onDragleave: (event: DragEvent) => {
                // Leaving for one of this card's own children is not leaving.
                const into = event.relatedTarget as Node | null;
                const self = event.currentTarget as HTMLElement | null;
                if (into && self?.contains(into)) return;
                if (dropTarget.value?.path === payload.path) {
                    dropTarget.value = null;
                }
            },
            // Fires on the source whether the drop landed or was abandoned
            // (Escape, released outside), which is the only reliable end.
            onDragend: () => endDrag(),
            onDrop: async (event: DragEvent) => {
                event.preventDefault();
                const from = dragging;
                endDrag();
                if (!from || from.uuid === payload.uuid) return;
                if (!store.abilities.update) return;

                // Dropped straight onto a parent card — the chart's gesture.
                // The target *is* the new parent, so there is no sibling to
                // read a position from and the edge is appended.
                if (
                    payload.accepts &&
                    from.scope === payload.accepts &&
                    from.parentUuid
                ) {
                    if (from.parentUuid === payload.uuid) return;

                    pending.value = {
                        scope: payload.accepts,
                        childUuid: from.uuid,
                        childTitle: titleOf(from.uuid),
                        fromParentUuid: from.parentUuid,
                        toParentUuid: payload.uuid,
                        toParentTitle: titleOf(payload.uuid),
                        canLink: !isLinked(
                            payload.accepts,
                            payload.uuid,
                            from.uuid,
                        ),
                    };
                    return;
                }

                if (
                    from.scope === payload.scope &&
                    from.parentUuid === payload.parentUuid
                ) {
                    // Same-parent reorder: splice the dragged uuid to the
                    // drop target's position in that parent's sibling list.
                    const siblings = siblingsFor(
                        store,
                        payload.scope,
                        payload.parentUuid,
                    );
                    const order = siblings.filter((u) => u !== from.uuid);
                    const targetIndex = order.indexOf(payload.uuid);
                    order.splice(
                        targetIndex === -1 ? order.length : targetIndex,
                        0,
                        from.uuid,
                    );
                    await reorder(payload.scope, payload.parentUuid, order);
                    return;
                }

                if (
                    from.scope === payload.scope &&
                    from.parentUuid &&
                    payload.parentUuid &&
                    (payload.scope === 'risk_attack' ||
                        payload.scope === 'attack_defence')
                ) {
                    // Cross-parent drop. W38 stops here and asks (D11): the
                    // gesture is ambiguous, and moving when the user meant
                    // link silently destroys an edge they wanted to keep.
                    pending.value = {
                        scope: payload.scope,
                        childUuid: from.uuid,
                        childTitle: titleOf(from.uuid),
                        fromParentUuid: from.parentUuid,
                        toParentUuid: payload.parentUuid,
                        toParentTitle: titleOf(payload.parentUuid),
                        canLink: !isLinked(
                            payload.scope,
                            payload.parentUuid,
                            from.uuid,
                        ),
                    };
                }
            },
        };
    }

    return { dragProps, dropTarget, draggingPath, pending, resolve, cancel };
}
