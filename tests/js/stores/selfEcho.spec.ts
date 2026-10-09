import { describe, expect, it, vi } from 'vitest';
import {
    applyRealtimeUpdatesToStore,
    type RealtimeConnection,
} from '../../../resources/js/composables/useRealtime';
import {
    createAssessmentStore,
    type AssessmentStoreInit,
    type NodeRecord,
} from '../../../resources/js/stores/assessmentStore';

/**
 * FIXED (2.5.1): the **second** cause of W88 item 22 ("the Details tab blanks
 * after a second"), pinned by W88 as a DEFECT because it lived in files W88
 * did not own.
 *
 * `NodeUpdated` and `NodeCreated` were dispatched to everyone, including the
 * author, and `applyRealtimeUpdatesToStore` applied node payloads without
 * consulting the actor. With the modal open on a node being typed into, the
 * author's own echo landed about a second behind the keystrokes and wrote the
 * row as saved over the record the input was bound to. On `node.created` the
 * whole record was *replaced* with a payload carrying no `tags`, so the
 * Details tab's `node.tags.map` threw and its content vanished — the literal
 * report. (The larger fix is that "Add child" no longer creates anything
 * until the draft is confirmed; see NewNodeForm.vue.)
 *
 * ## Which of the two candidate fixes was taken, and why
 *
 * The pin offered two. The client-side one — apply node payloads only when
 * `isRemote(actor_id)` — would have stopped the same person's *second tab*
 * from seeing their edits. The server-side one was taken: both events call
 * `dontBroadcastToCurrentUser()`, and useRealtime.ts sends its socket id as
 * `X-Socket-ID` on every request, so exactly the originating connection is
 * left out (tests/Feature/Realtime/SelfEchoTest.php asserts the server half).
 *
 * The client keeps one guard of its own, below: a `node.created` for a node
 * this client already holds is ignored rather than replacing the record,
 * because the only way that happens is this client having created it, and
 * whatever has been typed into it since is newer than the payload.
 *
 * `node.updated` from the same *user* is still applied — that is the second
 * tab, and it must keep working. The notice's `isRemote` check is a separate
 * question ("whose edit was this?") and stays.
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
        ],
        riskAttackLinks: [],
        attackDefenceLinks: [],
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

    return createAssessmentStore(init);
}

/** Only `.on()` is used by applyRealtimeUpdatesToStore. */
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

describe('FIXED: a user no longer has their own echo overwrite what they are typing', () => {
    it('FIXED: node.created for a node this client already holds does not replace it', () => {
        const store = makeStore();
        const { realtime, emit } = makeFakeRealtime();
        applyRealtimeUpdatesToStore(realtime, store, 1);

        store.nodes['newRisk'] = node({
            uuid: 'newRisk',
            type: 'risk',
            title: 'Phishing',
            tags: [{ id: 3, name: 'web' }],
        });
        const record = store.nodes['newRisk'];

        emit('node.created', {
            node: node({ uuid: 'newRisk', type: 'risk', title: '' }),
            actor_id: 1,
            actor_name: 'Me',
        });

        expect(store.nodes['newRisk'].title).toBe('Phishing');
        // The same object — an open modal bound to it keeps its binding —
        // and the tags a payload never carries are still there.
        expect(store.nodes['newRisk']).toBe(record);
        expect(store.nodes['newRisk'].tags).toHaveLength(1);
    });

    it('a node.created for a node this client does not hold gets a tags array', () => {
        const store = makeStore();
        const { realtime, emit } = makeFakeRealtime();
        applyRealtimeUpdatesToStore(realtime, store, 1);

        const payload = node({ uuid: 'theirs', type: 'risk', title: 'X' });
        delete (payload as Partial<NodeRecord>).tags;
        emit('node.created', {
            node: payload,
            actor_id: 2,
            actor_name: 'Dana',
        });

        expect(store.nodes['theirs'].tags).toEqual([]);
    });

    it('node.updated from the same user is still applied — that is their other tab', () => {
        const store = makeStore();
        const { realtime, emit } = makeFakeRealtime();
        applyRealtimeUpdatesToStore(realtime, store, 1);

        emit('node.updated', {
            node: node({ uuid: 'riskA', type: 'risk', title: 'From tab two' }),
            actor_id: 1,
            actor_name: 'Me',
        });

        // The server no longer sends this tab its own echo (X-Socket-ID), so
        // an update carrying this user's id came from another connection.
        expect(store.nodes['riskA'].title).toBe('From tab two');
    });

    it('a genuine second editor must keep being applied — this is what any fix must not break', () => {
        const store = makeStore();
        const { realtime, emit } = makeFakeRealtime();
        applyRealtimeUpdatesToStore(realtime, store, 1);

        emit('node.updated', {
            node: node({ uuid: 'riskA', type: 'risk', title: "Dana's title" }),
            actor_id: 2,
            actor_name: 'Dana',
        });

        expect(store.nodes['riskA'].title).toBe("Dana's title");
    });
});
