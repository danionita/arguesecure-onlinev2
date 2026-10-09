import { describe, expect, it, vi } from 'vitest';
import {
    applyRealtimeUpdatesToStore,
    type RealtimeChangeNotice,
    type RealtimeConnection,
} from '../../../resources/js/composables/useRealtime';
import {
    createAssessmentStore,
    type AssessmentStoreInit,
    type AttackDefenceLink,
    type NodeRecord,
    type RiskAttackLink,
} from '../../../resources/js/stores/assessmentStore';

/**
 * ui.md §6 / PLAN.md Phase 6 item 4: applyRealtimeUpdatesToStore() is what
 * turns a granular broadcast into a store patch — "carry the changed data
 * so the client patches its store... do not repeat" the legacy
 * id-then-refetch design. This suite fakes the low-level connection (only
 * `.on()` matters here) so it can drive the handlers directly without a
 * real WebSocket.
 */
function node(over: Partial<NodeRecord>): NodeRecord {
    return {
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
        updated_at: '2026-01-01T00:00:00.000000Z',
        ...over,
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

function makeStore() {
    const init: AssessmentStoreInit = {
        assessment: {
            uuid: 'assessment-uuid',
            currency: 'EUR',
            display_mode: 'mixed',
            amortization_years: 3,
            is_public: false,
        },
        nodes: [
            node({ uuid: 'root', type: 'root', title: 'Assessment' }),
            node({ uuid: 'riskA', type: 'risk', title: 'Risk A' }),
            node({ uuid: 'attackA', type: 'attack', title: 'Phishing' }),
        ],
        riskAttackLinks: [
            { risk_uuid: 'riskA', attack_uuid: 'attackA', position: 0 },
        ],
        attackDefenceLinks: [],
        results: {
            inherent: emptyResult('inherent'),
            current: emptyResult('current'),
            target: emptyResult('target'),
        },
        abilities: {
            comment: true,
            update: true,
            manage: true,
            transfer: true,
        },
    };

    return createAssessmentStore(init);
}

/** A fake RealtimeConnection exposing only what applyRealtimeUpdatesToStore uses: `.on()`. */
function makeFakeRealtime() {
    const handlers = new Map<string, (data: unknown) => void>();

    const fake = {
        connected: { value: true },
        members: new Map(),
        on: vi.fn((event: string, handler: (data: unknown) => void) => {
            handlers.set(event, handler);
            return () => handlers.delete(event);
        }),
        whisper: vi.fn(),
        disconnect: vi.fn(),
        channelName: 'presence-assessment.assessment-uuid',
    };

    return {
        realtime: fake as unknown as RealtimeConnection,
        emit(event: string, data: unknown) {
            handlers.get(event)?.(data);
        },
    };
}

describe('applyRealtimeUpdatesToStore', () => {
    it('adds a new node on node.created', () => {
        const store = makeStore();
        const { realtime, emit } = makeFakeRealtime();
        applyRealtimeUpdatesToStore(realtime, store, 1);

        emit('node.created', {
            node: node({ uuid: 'defenceA', type: 'defence', title: 'MFA' }),
            actor_id: 2,
            actor_name: 'Dana',
        });

        expect(store.nodes.defenceA?.title).toBe('MFA');
    });

    it('merges the patch onto the existing node on node.updated', () => {
        const store = makeStore();
        const { realtime, emit } = makeFakeRealtime();
        applyRealtimeUpdatesToStore(realtime, store, 1);

        emit('node.updated', {
            node: { ...store.nodes.riskA, title: 'Renamed by Dana' },
            actor_id: 2,
            actor_name: 'Dana',
        });

        expect(store.nodes.riskA?.title).toBe('Renamed by Dana');
    });

    it('removes the node and every link touching it on node.deleted', () => {
        const store = makeStore();
        const { realtime, emit } = makeFakeRealtime();
        applyRealtimeUpdatesToStore(realtime, store, 1);

        emit('node.deleted', {
            node_uuid: 'attackA',
            actor_id: 2,
            actor_name: 'Dana',
        });

        expect(store.nodes.attackA).toBeUndefined();
        expect(store.riskAttackLinks).toHaveLength(0);
    });

    it('adds a risk_attack link on link.changed created', () => {
        const store = makeStore();
        const { realtime, emit } = makeFakeRealtime();
        applyRealtimeUpdatesToStore(realtime, store, 1);
        store.nodes.riskB = node({
            uuid: 'riskB',
            type: 'risk',
            title: 'Risk B',
        });

        const link: RiskAttackLink = {
            risk_uuid: 'riskB',
            attack_uuid: 'attackA',
            position: 1,
        };
        emit('link.changed', {
            edge: 'risk_attack',
            action: 'created',
            link,
            actor_id: 2,
            actor_name: 'Dana',
        });

        expect(store.riskAttackLinks).toContainEqual(link);
    });

    it('removes an attack_defence link on link.changed deleted', () => {
        const store = makeStore();
        const link: AttackDefenceLink = {
            attack_uuid: 'attackA',
            defence_uuid: 'defenceA',
            position: 0,
            strength_mode: null,
            strength_fraction: null,
            strength_level_id: null,
            reduction_target: null,
        };
        store.attackDefenceLinks.push(link);
        const { realtime, emit } = makeFakeRealtime();
        applyRealtimeUpdatesToStore(realtime, store, 1);

        emit('link.changed', {
            edge: 'attack_defence',
            action: 'deleted',
            link,
            actor_id: 2,
            actor_name: 'Dana',
        });

        expect(store.attackDefenceLinks).toHaveLength(0);
    });

    it('replaces store.results.value outright on totals.recomputed', () => {
        const store = makeStore();
        const { realtime, emit } = makeFakeRealtime();
        applyRealtimeUpdatesToStore(realtime, store, 1);

        const newResults = {
            inherent: { ...emptyResult('inherent'), total: 187000 },
            current: { ...emptyResult('current'), total: 65500 },
            target: { ...emptyResult('target'), total: 36700 },
        };
        emit('totals.recomputed', { results: newResults });

        expect(store.results.value.current.total).toBe(65500);
    });

    it("suppresses onRemoteChange for the current user's own actions", () => {
        const store = makeStore();
        const { realtime, emit } = makeFakeRealtime();
        const notices: RealtimeChangeNotice[] = [];
        applyRealtimeUpdatesToStore(realtime, store, 1, (n) => notices.push(n));

        // actor_id 1 is "me" (the third argument above) — my own change,
        // echoed back by the broadcast, must not surface a notice.
        emit('node.updated', {
            node: { ...store.nodes.riskA, title: 'My own edit' },
            actor_id: 1,
            actor_name: 'Me',
        });

        expect(notices).toHaveLength(0);
    });

    it("calls onRemoteChange for another user's action", () => {
        const store = makeStore();
        const { realtime, emit } = makeFakeRealtime();
        const notices: RealtimeChangeNotice[] = [];
        applyRealtimeUpdatesToStore(realtime, store, 1, (n) => notices.push(n));

        emit('node.updated', {
            node: { ...store.nodes.riskA, title: "Dana's edit" },
            actor_id: 2,
            actor_name: 'Dana',
        });

        expect(notices).toHaveLength(1);
        expect(notices[0]?.actorName).toBe('Dana');
    });

    it('unsubscribes every handler when the returned stop function is called', () => {
        const store = makeStore();
        const { realtime, emit } = makeFakeRealtime();
        const stop = applyRealtimeUpdatesToStore(realtime, store, 1);

        stop();
        emit('node.deleted', {
            node_uuid: 'attackA',
            actor_id: 2,
            actor_name: 'Dana',
        });

        // The handler was unsubscribed before this event fired, so the
        // node must still be present.
        expect(store.nodes.attackA).toBeDefined();
    });
});
