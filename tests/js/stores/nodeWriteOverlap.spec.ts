import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    createAssessmentStore,
    type AssessmentStoreInit,
    type NodeRecord,
} from '../../../resources/js/stores/assessmentStore';

/**
 * W88 items 21 and 22, reproduced before they were fixed (D49).
 *
 * Everything in this file drives the store through a **fake server that
 * behaves like `NodeController::update()`** rather than through a mock that
 * answers whatever the test wants: it holds the row's `updated_at`, compares
 * the incoming one, answers 409 with the current row on a mismatch, and
 * otherwise bumps `updated_at` and echoes the row back. That is the whole
 * point — the defect is an interaction between the client's send pattern and
 * that rule, so a mock that does not enforce the rule cannot reproduce it and
 * a fix written against one would be untestable.
 *
 * Responses are resolved by hand (`server.flush()`), so "two keystrokes
 * before the first response lands" is deterministic here rather than a race
 * the test hopes to lose.
 */

type PatchCall = {
    body: Record<string, unknown>;
    settle: () => void;
    /** Reject this request with a 500 — a failure that is not a conflict. */
    fail: () => void;
};

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

    return {
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
            node({ uuid: 'attack1', type: 'attack', title: '' }),
        ],
        riskAttackLinks: [
            { risk_uuid: 'riskA', attack_uuid: 'attack1', position: 0 },
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

/**
 * A stand-in for `NodeController::update()`: optimistic-locking on
 * `updated_at`, 409 on mismatch carrying the server's current row.
 * `flush()` releases responses in the order they are asked for, so a test
 * can land the *second* request's response before the first one's.
 *
 * `externalEdit()` is how another user is simulated: it moves the *server's*
 * row, which is what a second editor actually does. Tampering with an
 * outgoing request's token instead would produce a 409 too, but from a
 * corrupted client rather than from a row somebody else saved — and it shows,
 * because the `theirs` that comes back is then the row nobody edited.
 *
 * `fail()` is a non-409 failure — a 500, a 419, a dropped connection. It has
 * its own method because the store's handling of it is completely different
 * from a conflict's: nothing is recorded, the error is rethrown, and the queue
 * behind it has to survive.
 */
function fakeServer(row: Record<string, unknown>) {
    let current = { ...row };
    let version = 0;
    const calls: PatchCall[] = [];

    const patch = vi.fn((_url: string, body: Record<string, unknown>) => {
        return new Promise((resolve, reject) => {
            calls.push({
                body,
                settle: () => {
                    if (body.updated_at !== current.updated_at) {
                        reject({
                            response: {
                                status: 409,
                                data: { node: { ...current } },
                            },
                        });

                        return;
                    }

                    const { updated_at: _ignored, ...fields } = body;
                    version += 1;
                    current = {
                        ...current,
                        ...fields,
                        updated_at: `2026-01-01T00:00:0${version}.000000Z`,
                    };
                    resolve({ data: { node: { ...current } } });
                },
                fail: () => {
                    reject({
                        response: { status: 500, data: {} },
                        message: 'Request failed with status code 500',
                    });
                },
            });
        });
    });

    return {
        patch,
        calls,
        /** Settle the recorded requests, oldest first unless `order` says otherwise. */
        async flush(order?: number[]) {
            const indices = order ?? calls.map((_, i) => i);
            for (const i of indices) calls[i].settle();
            await settleMicrotasks();
        },
        /** Somebody else saved this node: the server's row moves on. */
        externalEdit(fields: Record<string, unknown>) {
            version += 1;
            current = {
                ...current,
                ...fields,
                updated_at: `2026-01-01T00:00:1${version}.000000Z`,
            };
        },
        get current() {
            return current;
        },
    };
}

/** Enough ticks for a settled response to travel through the store's own awaits. */
async function settleMicrotasks() {
    for (let i = 0; i < 5; i++) await Promise.resolve();
}

let server: ReturnType<typeof fakeServer>;

beforeEach(() => {
    (globalThis as unknown as { route: (...args: unknown[]) => string }).route =
        vi.fn(
            (name: string, params: unknown) =>
                `/${name}/${JSON.stringify(params)}`,
        );
    server = fakeServer({
        uuid: 'attack1',
        type: 'attack',
        title: '',
        description: null,
        updated_at: '2026-01-01T00:00:00.000000Z',
    });
    (window as unknown as { axios: unknown }).axios = {
        patch: server.patch,
        post: vi.fn(),
        delete: vi.fn(),
    };
});

describe('W88 item 21: a node fights its own keystrokes', () => {
    /**
     * `DetailsTab.vue` commits the title on `@input`, so this is two
     * keystrokes typed faster than one round trip. Before the fix, the second
     * PATCH was built from the node as it stood *before* the first response
     * landed, carried that stale `updated_at`, and the server answered 409 —
     * "Someone changed this while you were editing", where the someone was
     * the same user, one keystroke earlier.
     */
    it('does not raise a conflict when the same user types faster than one round trip', async () => {
        const store = createAssessmentStore(makeInit());

        const first = store.updateNode('attack1', { title: 'P' });
        const second = store.updateNode('attack1', { title: 'Ph' });

        await server.flush();
        await server.flush();
        await first;
        await second;

        expect(store.saveState.value).toBe('saved');
        expect(store.conflicts['attack1']).toBeUndefined();
        expect(store.nodes['attack1'].title).toBe('Ph');
    });

    /**
     * The same overlap seen from the wire: no request may be sent for a node
     * while another is in flight for it. This is the guard itself, asserted
     * as a *count of requests in flight*, not as "a conflict did not happen"
     * — a fix that widened the conflict window, or swallowed 409s, would
     * satisfy the test above and fail this one.
     */
    it('never has two writes in flight for one node', async () => {
        const store = createAssessmentStore(makeInit());

        void store.updateNode('attack1', { title: 'P' });
        void store.updateNode('attack1', { title: 'Ph' });
        void store.updateNode('attack1', { title: 'Phi' });
        await Promise.resolve();
        await Promise.resolve();

        expect(server.calls).toHaveLength(1);
    });

    /**
     * And the keystrokes typed during the round trip are not lost: they are
     * merged into one follow-up request rather than one request each, which
     * is the "debounce" half of the fix. Three keystrokes cost two requests
     * — the one that was already going, and one carrying everything since.
     */
    it('coalesces the keystrokes typed during a round trip into a single follow-up', async () => {
        const store = createAssessmentStore(makeInit());

        const typing = [
            store.updateNode('attack1', { title: 'P' }),
            store.updateNode('attack1', { title: 'Ph' }),
            store.updateNode('attack1', { title: 'Phi' }),
        ];

        await server.flush();
        await server.flush();
        await Promise.all(typing);

        expect(server.calls).toHaveLength(2);
        expect(server.calls[1].body.title).toBe('Phi');
        expect(store.nodes['attack1'].title).toBe('Phi');
        expect(store.saveState.value).toBe('saved');
    });
});

describe('W88 item 22: the Details tab reverting under the user', () => {
    /**
     * One half of item 22, and the reason it read as a separate defect. It
     * needs no reordering and no lost race: the responses arrive **in
     * order**, and the first one is already stale by the time it lands.
     *
     * The user types "P", then "Ph" before the round trip completes. The
     * first response echoes the row the server saved — title "P" — and
     * `Object.assign(node, response.data.node)` writes it over the record the
     * Details tab's input is bound to. About a round trip after typing, the
     * field is showing "P" again and the second character is gone.
     *
     * Serialising the writes removes the possibility rather than papering
     * over it: the follow-up request is built only after the first response
     * has landed, so no echo is ever older than what is on screen.
     */
    it('does not overwrite the field with an already-stale response', async () => {
        const store = createAssessmentStore(makeInit());

        const first = store.updateNode('attack1', { title: 'P' });
        const second = store.updateNode('attack1', { title: 'Ph' });

        await server.flush();
        await server.flush();
        await first;
        await second;

        expect(store.nodes['attack1'].title).toBe('Ph');
    });
});

describe('a genuine second editor still raises the conflict banner', () => {
    /**
     * The guard above must not be a 409 suppressor. Here nobody types twice:
     * one edit is sent, and while it is in flight *another user* saves the
     * same node, so the server's row moves on. The next write must still come
     * back 409 and must still land in `conflicts` with both sides, because
     * that is what Show.vue's "Keep mine / Take theirs" banner reads
     * (ui.md §3.5).
     *
     * Dana is simulated by moving the **server's** row, not by tampering with
     * the token on the outgoing request. Both produce a 409, but only one of
     * them is the situation being described — and the difference is visible in
     * the assertion below, which reads Dana's title out of `theirs`. A
     * corrupted client would hand back the row nobody edited.
     */
    it('reports a conflict when the server row moved under a single write', async () => {
        const store = createAssessmentStore(makeInit());

        const mine = store.updateNode('attack1', { title: 'My title' });

        // Dana saves the same node from her own session before mine lands.
        server.externalEdit({ title: "Dana's title" });

        await server.flush();
        await mine;

        expect(store.saveState.value).toBe('conflict');
        expect(store.conflicts['attack1'].mine.title).toBe('My title');
        expect(store.conflicts['attack1'].theirs.title).toBe("Dana's title");
    });

    it('"keep mine" resends on top of the server row and settles', async () => {
        const store = createAssessmentStore(makeInit());

        const mine = store.updateNode('attack1', { title: 'My title' });
        server.externalEdit({ title: "Dana's title" });
        await server.flush();
        await mine;

        const resend = store.resolveConflictKeepMine('attack1');
        await server.flush([1]);
        await resend;

        expect(store.saveState.value).toBe('saved');
        expect(store.conflicts['attack1']).toBeUndefined();
        expect(store.nodes['attack1'].title).toBe('My title');
    });
});

describe('a failed write must not take the node down with it', () => {
    /**
     * The regression the serialisation nearly introduced, and the reason the
     * queue and the drain are now decided separately.
     *
     * One 500, one 419, one dropped connection — anything that is not a 409 —
     * while the user carries on typing. The first version of the fix merged
     * the keystroke that arrived mid-flight into `pendingWrites` and returned
     * the in-flight promise; when the request then failed, the drain ended,
     * `activeWrites` emptied, and the queued patch had nothing behind it. Every
     * later keystroke merged into that same orphaned entry and returned
     * `Promise.resolve()`. **Autosave stopped for that node for the life of
     * the store, silently**, and typing again did not recover it — which is
     * worse than the behaviour before the fix, where the next keystroke fired
     * its own request.
     *
     * So the assertion is not "the error propagated". It is that the wire sees
     * a request again afterwards, carrying what was typed in the meantime.
     */
    it('sends again after a non-409 failure, carrying what was typed meanwhile', async () => {
        const store = createAssessmentStore(makeInit());

        const first = store.updateNode('attack1', { title: 'P' });
        const duringFlight = store.updateNode('attack1', { title: 'Ph' });
        // Both reject: the store rethrows anything that is not a conflict, and
        // an unhandled rejection here would fail the run for the wrong reason.
        const failures = Promise.allSettled([first, duringFlight]);

        server.calls[0].fail();
        await settleMicrotasks();
        await failures;

        expect(store.saveState.value).toBe('idle');
        expect(server.calls).toHaveLength(1);

        // The user keeps typing, which is what people do when a save fails.
        const after = store.updateNode('attack1', { title: 'Phi' });
        await settleMicrotasks();

        expect(server.calls).toHaveLength(2);
        expect(server.calls[1].body.title).toBe('Phi');

        await server.flush([1]);
        await after;

        expect(store.saveState.value).toBe('saved');
        expect(store.nodes['attack1'].title).toBe('Phi');
    });

    /**
     * The same recovery with nothing queued behind the failure — the simpler
     * half, which the pre-fix store also handled. Kept because a fix aimed
     * only at the queued case could easily break this one.
     */
    it('sends again after a failure with an empty queue behind it', async () => {
        const store = createAssessmentStore(makeInit());

        const first = store.updateNode('attack1', { title: 'P' });
        const failure = first.catch(() => {});
        server.calls[0].fail();
        await settleMicrotasks();
        await failure;

        const after = store.updateNode('attack1', { title: 'Ph' });
        await server.flush([1]);
        await after;

        expect(server.calls).toHaveLength(2);
        expect(store.saveState.value).toBe('saved');
        expect(store.nodes['attack1'].title).toBe('Ph');
    });

    /**
     * A node deleted while a second write for it is still queued. Nothing can
     * be sent — but the indicator was switched to "Saving…" when the keystroke
     * arrived, and leaving it there for the rest of the session says a write
     * is outstanding when none is.
     */
    it('does not leave the indicator saying "Saving…" for a node that was deleted', async () => {
        const store = createAssessmentStore(makeInit());

        const first = store.updateNode('attack1', { title: 'P' });
        const queued = store.updateNode('attack1', { title: 'Ph' });

        delete store.nodes['attack1'];
        await server.flush();
        await first;
        await queued;

        expect(store.saveState.value).toBe('idle');
    });
});
