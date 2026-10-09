import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useDragDrop } from '../../../resources/js/composables/useDragDrop';
import {
    createAssessmentStore,
    type AssessmentStoreInit,
    type AttackDefenceLink,
    type NodeRecord,
    type RiskAttackLink,
} from '../../../resources/js/stores/assessmentStore';

function makeInit(): AssessmentStoreInit {
    const now = '2026-01-01T00:00:00.000000Z';
    const node = (over: Partial<NodeRecord>): NodeRecord => ({
        uuid: 'x',
        type: 'risk',
        title: '',
        description: null,
        position: 0,
        impact_mode: null,
        impact_amount: null,
        impact_level_id: null,
        likelihood_mode: null,
        likelihood_aro: null,
        likelihood_level_id: null,
        is_transfer: false,
        status: null,
        capex_amount: null,
        opex_annual_amount: null,
        tags: [],
        updated_at: now,
        ...over,
    });

    const nodes: NodeRecord[] = [
        node({ uuid: 'root', type: 'root', title: 'Assessment' }),
        node({ uuid: 'riskA', type: 'risk', title: 'Risk A', position: 0 }),
        node({ uuid: 'riskB', type: 'risk', title: 'Risk B', position: 1 }),
        node({ uuid: 'attackX', type: 'attack', title: 'Phishing' }),
        node({ uuid: 'attackY', type: 'attack', title: 'SQLi' }),
        node({ uuid: 'defence1', type: 'defence', title: 'MFA' }),
    ];

    const riskAttackLinks: RiskAttackLink[] = [
        { risk_uuid: 'riskA', attack_uuid: 'attackX', position: 0 },
        { risk_uuid: 'riskA', attack_uuid: 'attackY', position: 1 },
        // The shared occurrence: attackX also sits under riskB.
        { risk_uuid: 'riskB', attack_uuid: 'attackX', position: 0 },
    ];

    const attackDefenceLinks: AttackDefenceLink[] = [
        {
            attack_uuid: 'attackX',
            defence_uuid: 'defence1',
            position: 0,
            strength_mode: null,
            strength_fraction: null,
            strength_level_id: null,
            reduction_target: null,
        },
    ];

    return {
        assessment: {
            uuid: 'assessment-uuid',
            currency: 'EUR',
            display_mode: 'mixed',
            amortization_years: 3,
            is_public: false,
        },
        nodes,
        riskAttackLinks,
        attackDefenceLinks,
        results: {
            inherent: emptyResult('inherent'),
            current: emptyResult('current'),
            target: emptyResult('target'),
        },
        abilities: {
            comment: true,
            update: true,
            manage: false,
            transfer: false,
        },
    };
}

function emptyResult(baseline: 'inherent' | 'current' | 'target') {
    return {
        baseline,
        total: 0,
        risks: [],
        attacks: [],
        pairs: [],
        defences: [],
        completeness: {
            isPartial: false,
            pairsTotal: 0,
            pairsValued: 0,
            coverage: 0,
            risksMissingImpact: [],
            attacksMissingLikelihood: [],
            linksMissingStrength: [],
            risksWithoutAttacks: [],
            orphanAttacks: [],
            orphanDefences: [],
            sharedAttacks: [],
            implausibleStrength: [],
        },
        warnings: [],
    };
}

function fakeDragEvent() {
    const data = new Map<string, string>();
    return {
        preventDefault: vi.fn(),
        dataTransfer: {
            setData: (type: string, value: string) => data.set(type, value),
            getData: (type: string) => data.get(type) ?? '',
            effectAllowed: '',
            dropEffect: '',
        },
    } as unknown as DragEvent;
}

beforeEach(() => {
    (globalThis as unknown as { route: (...args: unknown[]) => string }).route =
        vi.fn((name: string) => `/${name}`);
    (window as unknown as { axios: unknown }).axios = {
        post: vi.fn().mockResolvedValue({ data: {} }),
    };
});

describe('useDragDrop: same-parent reorder', () => {
    it('posts the full new sibling order and patches positions locally', async () => {
        const store = createAssessmentStore(makeInit());
        const { dragProps } = useDragDrop(store);

        const source = dragProps({
            uuid: 'attackY',
            path: 'root/riskA/attackY',
            scope: 'risk_attack',
            parentUuid: 'riskA',
        });
        const target = dragProps({
            uuid: 'attackX',
            path: 'root/riskA/attackX',
            scope: 'risk_attack',
            parentUuid: 'riskA',
        });

        const dragEvent = fakeDragEvent();
        (source.onDragstart as (e: DragEvent) => void)(dragEvent);
        (target.onDrop as (e: DragEvent) => Promise<void>)(fakeDragEvent());
        await Promise.resolve();
        await Promise.resolve();

        expect(window.axios.post).toHaveBeenCalledWith(
            '/assessments.reorder',
            expect.objectContaining({
                scope: 'risk_attack',
                parent_uuid: 'riskA',
                order: ['attackY', 'attackX'],
            }),
        );
    });
});

/**
 * W38 / PLAN-2.2.md D11. A cross-parent drop is an ambiguous gesture — the
 * user may mean "it belongs here instead" or "it belongs here too" — and the
 * two are different mutations, one of which destroys an edge. The composable
 * now stops and asks rather than guessing, so every test in this block first
 * asserts that **nothing was posted**, then resolves the choice.
 */
describe('useDragDrop: cross-parent re-parent asks before acting', () => {
    /** Drags attackY (only under riskA) onto riskB's occurrence of attackX. */
    function dropAcrossParents(
        store: ReturnType<typeof createAssessmentStore>,
    ) {
        const drag = useDragDrop(store);
        const source = drag.dragProps({
            uuid: 'attackY',
            path: 'root/riskA/attackY',
            scope: 'risk_attack',
            parentUuid: 'riskA',
        });
        const target = drag.dragProps({
            uuid: 'attackX',
            path: 'root/riskB/attackX',
            scope: 'risk_attack',
            parentUuid: 'riskB',
        });

        (source.onDragstart as (e: DragEvent) => void)(fakeDragEvent());
        (target.onDrop as (e: DragEvent) => Promise<void>)(fakeDragEvent());

        return drag;
    }

    it('posts nothing on the drop itself, and describes what it is asking about', () => {
        const store = createAssessmentStore(makeInit());
        const drag = dropAcrossParents(store);

        expect(window.axios.post).not.toHaveBeenCalled();
        expect(drag.pending.value).toMatchObject({
            scope: 'risk_attack',
            childUuid: 'attackY',
            childTitle: 'SQLi',
            fromParentUuid: 'riskA',
            toParentUuid: 'riskB',
            toParentTitle: 'Risk B',
            canLink: true,
        });
    });

    it('moves the edge when the user picks Move', async () => {
        const store = createAssessmentStore(makeInit());
        const drag = dropAcrossParents(store);

        await drag.resolve('move');

        expect(window.axios.post).toHaveBeenCalledWith(
            '/assessments.move',
            expect.objectContaining({
                edge: 'risk_attack',
                from_parent_uuid: 'riskA',
                to_parent_uuid: 'riskB',
                child_uuid: 'attackY',
            }),
        );
        expect(drag.pending.value).toBeNull();
    });

    it('keeps both parents when the user picks Link — the DAG case', async () => {
        const store = createAssessmentStore(makeInit());
        const drag = dropAcrossParents(store);

        await drag.resolve('link');

        expect(window.axios.post).toHaveBeenCalledWith(
            '/assessments.risk-attack-links.store',
            expect.objectContaining({
                risk_uuid: 'riskB',
                attack_uuid: 'attackY',
            }),
        );
        // The source edge is untouched: that is the whole difference.
        expect(window.axios.post).not.toHaveBeenCalledWith(
            '/assessments.move',
            expect.anything(),
        );
        expect(
            store.riskAttackLinks.filter((l) => l.attack_uuid === 'attackY'),
        ).toHaveLength(2);
    });

    it('does nothing at all when the user cancels', async () => {
        const store = createAssessmentStore(makeInit());
        const drag = dropAcrossParents(store);

        drag.cancel();

        expect(drag.pending.value).toBeNull();
        expect(window.axios.post).not.toHaveBeenCalled();
    });

    /**
     * attackX already sits under riskB. Offering Link there would write a
     * duplicate edge, which the unique index rejects — the user would press a
     * button and get a 500. Refuse to offer it, and say why in the dialog.
     */
    it('withholds Link when the node already belongs to the target', () => {
        const store = createAssessmentStore(makeInit());
        const drag = useDragDrop(store);

        const source = drag.dragProps({
            uuid: 'attackX',
            path: 'root/riskA/attackX',
            scope: 'risk_attack',
            parentUuid: 'riskA',
        });
        const target = drag.dragProps({
            uuid: 'riskB',
            path: 'root/riskB',
            scope: 'nodes',
            parentUuid: null,
            accepts: 'risk_attack',
        });

        (source.onDragstart as (e: DragEvent) => void)(fakeDragEvent());
        (target.onDrop as (e: DragEvent) => Promise<void>)(fakeDragEvent());

        expect(drag.pending.value?.canLink).toBe(false);
    });

    it('ignores a Link the user should never have been offered', async () => {
        const store = createAssessmentStore(makeInit());
        const drag = useDragDrop(store);
        const source = drag.dragProps({
            uuid: 'attackX',
            path: 'root/riskA/attackX',
            scope: 'risk_attack',
            parentUuid: 'riskA',
        });
        const target = drag.dragProps({
            uuid: 'riskB',
            path: 'root/riskB',
            scope: 'nodes',
            parentUuid: null,
            accepts: 'risk_attack',
        });
        (source.onDragstart as (e: DragEvent) => void)(fakeDragEvent());
        (target.onDrop as (e: DragEvent) => Promise<void>)(fakeDragEvent());

        await drag.resolve('link');

        // Belt and braces: the dialog hides the option, and the composable
        // refuses it anyway. A duplicate edge is a 500, not a bad UX.
        expect(window.axios.post).not.toHaveBeenCalled();
    });
});

/**
 * The chart's gesture: drop straight onto the parent card. There is no sibling
 * to aim at across tiers, so without this the primary interaction of the whole
 * package would compare an attack's `risk_attack` scope against a risk's
 * `nodes` scope, find them different, and silently refuse.
 */
describe('useDragDrop: dropping onto a parent card', () => {
    function dropOnParent(store: ReturnType<typeof createAssessmentStore>) {
        const drag = useDragDrop(store);
        const source = drag.dragProps({
            uuid: 'attackY',
            path: 'root/riskA/attackY',
            scope: 'risk_attack',
            parentUuid: 'riskA',
        });
        const target = drag.dragProps({
            uuid: 'riskB',
            path: 'root/riskB',
            scope: 'nodes',
            parentUuid: null,
            accepts: 'risk_attack',
        });

        (source.onDragstart as (e: DragEvent) => void)(fakeDragEvent());
        (target.onDrop as (e: DragEvent) => Promise<void>)(fakeDragEvent());

        return drag;
    }

    it('reads the target card itself as the new parent', () => {
        const store = createAssessmentStore(makeInit());
        const drag = dropOnParent(store);

        expect(drag.pending.value).toMatchObject({
            childUuid: 'attackY',
            fromParentUuid: 'riskA',
            toParentUuid: 'riskB',
        });
    });

    it('ignores a drop onto the parent it already sits under', () => {
        const store = createAssessmentStore(makeInit());
        const drag = useDragDrop(store);
        const source = drag.dragProps({
            uuid: 'attackY',
            path: 'root/riskA/attackY',
            scope: 'risk_attack',
            parentUuid: 'riskA',
        });
        const target = drag.dragProps({
            uuid: 'riskA',
            path: 'root/riskA',
            scope: 'nodes',
            parentUuid: null,
            accepts: 'risk_attack',
        });

        (source.onDragstart as (e: DragEvent) => void)(fakeDragEvent());
        (target.onDrop as (e: DragEvent) => Promise<void>)(fakeDragEvent());

        expect(drag.pending.value).toBeNull();
    });

    /**
     * The cursor has to say no *before* the click lands. Previously a
     * cross-type drop was accepted by dragover and then did nothing on drop:
     * the user saw a "drop here" affordance and got no result and no reason.
     */
    it('refuses a cross-type drag at dragover, not silently at drop', () => {
        const store = createAssessmentStore(makeInit());
        const drag = useDragDrop(store);
        const source = drag.dragProps({
            uuid: 'attackY',
            path: 'root/riskA/attackY',
            scope: 'risk_attack',
            parentUuid: 'riskA',
        });
        // A defence accepts nothing, and is not on the attack's tier.
        const target = drag.dragProps({
            uuid: 'defence1',
            path: 'root/riskA/attackX/defence1',
            scope: 'attack_defence',
            parentUuid: 'attackX',
            accepts: null,
        });

        (source.onDragstart as (e: DragEvent) => void)(fakeDragEvent());
        const event = fakeDragEvent();
        (target.onDragover as (e: DragEvent) => void)(event);

        expect(event.dataTransfer!.dropEffect).toBe('none');
        expect(event.preventDefault).not.toHaveBeenCalled();
    });

    it('never touches a shared node other occurrence: dragging attackX under riskA leaves riskB untouched', async () => {
        const store = createAssessmentStore(makeInit());
        const { dragProps } = useDragDrop(store);

        // attackX occurs under both riskA and riskB. Reordering its riskA
        // occurrence against attackY must not post anything referencing riskB.
        const source = dragProps({
            uuid: 'attackX',
            path: 'root/riskA/attackX',
            scope: 'risk_attack',
            parentUuid: 'riskA',
        });
        const target = dragProps({
            uuid: 'attackY',
            path: 'root/riskA/attackY',
            scope: 'risk_attack',
            parentUuid: 'riskA',
        });

        (source.onDragstart as (e: DragEvent) => void)(fakeDragEvent());
        (target.onDrop as (e: DragEvent) => Promise<void>)(fakeDragEvent());
        await Promise.resolve();
        await Promise.resolve();

        expect(window.axios.post).toHaveBeenCalledWith(
            '/assessments.reorder',
            expect.objectContaining({ parent_uuid: 'riskA' }),
        );
        expect(window.axios.post).not.toHaveBeenCalledWith(
            expect.anything(),
            expect.objectContaining({ parent_uuid: 'riskB' }),
        );
        // riskB's own link to attackX is untouched.
        expect(
            store.riskAttackLinks.find(
                (l) => l.risk_uuid === 'riskB' && l.attack_uuid === 'attackX',
            )?.position,
        ).toBe(0);
    });
});

describe('useDragDrop: authorization', () => {
    it('is not draggable when the viewer lacks update ability', () => {
        const init = makeInit();
        init.abilities.update = false;
        const store = createAssessmentStore(init);
        const { dragProps } = useDragDrop(store);

        const props = dragProps({
            uuid: 'attackX',
            path: 'root/riskA/attackX',
            scope: 'risk_attack',
            parentUuid: 'riskA',
        });

        expect(props.draggable).toBe(false);
    });
});

/**
 * 2.5.1: where the drop would land is visible while the pointer is still
 * down. The indicator is state on the composable — both cards render from
 * `dropTarget` — so this is where its meaning is pinned.
 */
describe('useDragDrop: the drop indicator', () => {
    function payloads() {
        return {
            attackY: {
                uuid: 'attackY',
                path: 'root/riskA/attackY',
                scope: 'risk_attack' as const,
                parentUuid: 'riskA',
            },
            attackX: {
                uuid: 'attackX',
                path: 'root/riskA/attackX',
                scope: 'risk_attack' as const,
                parentUuid: 'riskA',
            },
            riskB: {
                uuid: 'riskB',
                path: 'root/riskB',
                scope: 'nodes' as const,
                parentUuid: null,
                accepts: 'risk_attack' as const,
            },
            defence: {
                uuid: 'defence1',
                path: 'root/riskA/attackX/defence1',
                scope: 'attack_defence' as const,
                parentUuid: 'attackX',
            },
        };
    }

    it('marks a sibling as "before" — the dragged card takes its place', () => {
        const drag = useDragDrop(createAssessmentStore(makeInit()));
        const p = payloads();

        (drag.dragProps(p.attackY).onDragstart as (e: DragEvent) => void)(
            fakeDragEvent(),
        );
        expect(drag.draggingPath.value).toBe('root/riskA/attackY');

        (drag.dragProps(p.attackX).onDragover as (e: DragEvent) => void)(
            fakeDragEvent(),
        );

        expect(drag.dropTarget.value).toEqual({
            path: 'root/riskA/attackX',
            kind: 'before',
        });
    });

    it('marks a parent card as "into"', () => {
        const drag = useDragDrop(createAssessmentStore(makeInit()));
        const p = payloads();

        (drag.dragProps(p.attackY).onDragstart as (e: DragEvent) => void)(
            fakeDragEvent(),
        );
        (drag.dragProps(p.riskB).onDragover as (e: DragEvent) => void)(
            fakeDragEvent(),
        );

        expect(drag.dropTarget.value).toEqual({
            path: 'root/riskB',
            kind: 'into',
        });
    });

    it('shows nothing on a card that would refuse the drop', () => {
        const drag = useDragDrop(createAssessmentStore(makeInit()));
        const p = payloads();

        (drag.dragProps(p.attackY).onDragstart as (e: DragEvent) => void)(
            fakeDragEvent(),
        );
        (drag.dragProps(p.defence).onDragover as (e: DragEvent) => void)(
            fakeDragEvent(),
        );

        expect(drag.dropTarget.value).toBeNull();
    });

    it('clears when the drag ends, dropped or abandoned', () => {
        const drag = useDragDrop(createAssessmentStore(makeInit()));
        const p = payloads();
        const source = drag.dragProps(p.attackY);

        (source.onDragstart as (e: DragEvent) => void)(fakeDragEvent());
        (drag.dragProps(p.attackX).onDragover as (e: DragEvent) => void)(
            fakeDragEvent(),
        );
        (source.onDragend as () => void)();

        expect(drag.dropTarget.value).toBeNull();
        expect(drag.draggingPath.value).toBeNull();
    });
});
