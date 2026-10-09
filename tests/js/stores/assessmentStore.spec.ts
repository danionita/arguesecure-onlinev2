import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    createAssessmentStore,
    occurrencePath,
    type AssessmentStoreInit,
    type AttackDefenceLink,
    type NodeRecord,
    type RiskAttackLink,
} from '../../../resources/js/stores/assessmentStore';

/**
 * The DAG-shaped fixture every test in this file shares: one root, two
 * risks (riskA, riskB) that both link to the SAME attack (sharedAttack —
 * the "an attack may belong to several risks" case from schema.md), and
 * one defence under that attack. This is exactly the shape ui.md §3.1
 * says the store must hold once and render twice.
 */
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
        node({
            uuid: 'sharedAttack',
            type: 'attack',
            title: 'Phishing',
            likelihood_mode: 'quantitative',
            likelihood_aro: 1,
        }),
        node({
            uuid: 'defence1',
            type: 'defence',
            title: 'MFA',
            status: 'implemented',
        }),
    ];

    const riskAttackLinks: RiskAttackLink[] = [
        { risk_uuid: 'riskA', attack_uuid: 'sharedAttack', position: 0 },
        { risk_uuid: 'riskB', attack_uuid: 'sharedAttack', position: 0 },
    ];

    const attackDefenceLinks: AttackDefenceLink[] = [
        {
            attack_uuid: 'sharedAttack',
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

beforeEach(() => {
    (globalThis as unknown as { route: (...args: unknown[]) => string }).route =
        vi.fn(
            (name: string, params: unknown) =>
                `/${name}/${JSON.stringify(params)}`,
        );
    (window as unknown as { axios: unknown }).axios = {
        patch: vi.fn(),
        post: vi.fn(),
        delete: vi.fn(),
    };
});

describe('DAG shape: one node record, projected into every occurrence', () => {
    it('holds the shared attack once regardless of how many risks link to it', () => {
        const store = createAssessmentStore(makeInit());

        expect(Object.keys(store.nodes)).toHaveLength(5);
        expect(store.attacksUnderRisk('riskA')).toEqual(['sharedAttack']);
        expect(store.attacksUnderRisk('riskB')).toEqual(['sharedAttack']);
    });

    it('reports every risk a shared attack occurs under', () => {
        const store = createAssessmentStore(makeInit());

        expect(store.risksForAttack('sharedAttack').sort()).toEqual([
            'riskA',
            'riskB',
        ]);
        expect(store.occurrenceCount('sharedAttack', 'attack')).toBe(2);
    });

    it('orders risks under root and attacks/defences under their edge, independently per parent', () => {
        const store = createAssessmentStore(makeInit());

        expect(store.risksOrder()).toEqual(['riskA', 'riskB']);
        expect(store.defencesUnderAttack('sharedAttack')).toEqual(['defence1']);
    });

    it('editing the shared node once is visible from both occurrences, because there is only one record', () => {
        const store = createAssessmentStore(makeInit());

        store.nodes['sharedAttack'].title = 'Renamed';

        // Both "positions" (riskA's and riskB's) resolve the same uuid back
        // to the same, now-renamed, record — there is nothing to fan the
        // edit out to.
        for (const riskUuid of ['riskA', 'riskB']) {
            const attackUuid = store.attacksUnderRisk(riskUuid)[0];
            expect(store.nodes[attackUuid].title).toBe('Renamed');
        }
    });
});

describe('occurrence-keyed expand/collapse state', () => {
    it('collapsing one occurrence of a shared node does not collapse the other', () => {
        const store = createAssessmentStore(makeInit());

        const pathUnderA = occurrencePath('root', 'riskA', 'sharedAttack');
        const pathUnderB = occurrencePath('root', 'riskB', 'sharedAttack');

        expect(store.isExpanded(pathUnderA)).toBe(true);
        expect(store.isExpanded(pathUnderB)).toBe(true);

        store.toggleExpanded(pathUnderA);

        expect(store.isExpanded(pathUnderA)).toBe(false);
        expect(store.isExpanded(pathUnderB)).toBe(true);
    });
});

describe('updateNode autosave and the 409 conflict path', () => {
    it('applies the edit optimistically, then reconciles with the server response', async () => {
        const store = createAssessmentStore(makeInit());
        const serverNode = {
            ...store.nodes['riskA'],
            title: 'Risk A renamed',
            updated_at: '2026-01-01T00:00:01.000000Z',
        };
        (window.axios.patch as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { node: serverNode },
        });

        await store.updateNode('riskA', { title: 'Risk A renamed' });

        expect(store.nodes['riskA'].title).toBe('Risk A renamed');
        expect(store.nodes['riskA'].updated_at).toBe(
            '2026-01-01T00:00:01.000000Z',
        );
        expect(store.saveState.value).toBe('saved');
        expect(store.conflicts['riskA']).toBeUndefined();
    });

    it('on a 409, records the conflict with both sides and does not silently overwrite either', async () => {
        const store = createAssessmentStore(makeInit());
        const theirs = {
            ...store.nodes['riskA'],
            title: "Dana's title",
            updated_at: '2026-01-01T00:00:05.000000Z',
        };
        (window.axios.patch as ReturnType<typeof vi.fn>).mockRejectedValue({
            response: { status: 409, data: { node: theirs } },
        });

        await store.updateNode('riskA', { title: 'My title' });

        expect(store.saveState.value).toBe('conflict');
        expect(store.conflicts['riskA'].theirs.title).toBe("Dana's title");
        expect(store.conflicts['riskA'].mine.title).toBe('My title');
        // The optimistic local edit is still visible until the user
        // resolves the conflict — never silently discarded.
        expect(store.nodes['riskA'].title).toBe('My title');
    });

    it('"take theirs" discards the local edit and adopts the server row', async () => {
        const store = createAssessmentStore(makeInit());
        const theirs = {
            ...store.nodes['riskA'],
            title: "Dana's title",
            updated_at: '2026-01-01T00:00:05.000000Z',
        };
        (window.axios.patch as ReturnType<typeof vi.fn>).mockRejectedValue({
            response: { status: 409, data: { node: theirs } },
        });
        await store.updateNode('riskA', { title: 'My title' });

        store.resolveConflictTakeTheirs('riskA');

        expect(store.nodes['riskA'].title).toBe("Dana's title");
        expect(store.conflicts['riskA']).toBeUndefined();
    });

    it('"keep mine" resends the local edit based on the server\'s latest updated_at', async () => {
        const store = createAssessmentStore(makeInit());
        const theirs = {
            ...store.nodes['riskA'],
            title: "Dana's title",
            updated_at: '2026-01-01T00:00:05.000000Z',
        };
        (window.axios.patch as ReturnType<typeof vi.fn>)
            .mockRejectedValueOnce({
                response: { status: 409, data: { node: theirs } },
            })
            .mockResolvedValueOnce({
                data: {
                    node: {
                        ...theirs,
                        title: 'My title',
                        updated_at: '2026-01-01T00:00:06.000000Z',
                    },
                },
            });
        await store.updateNode('riskA', { title: 'My title' });

        await store.resolveConflictKeepMine('riskA');

        expect(store.nodes['riskA'].title).toBe('My title');
        const secondCallBody = (window.axios.patch as ReturnType<typeof vi.fn>)
            .mock.calls[1][1] as { updated_at: string };
        expect(secondCallBody.updated_at).toBe('2026-01-01T00:00:05.000000Z');
    });
});

describe('addChild — creates a confirmed draft', () => {
    it('creates a real node via the server and links it under its parent', async () => {
        const store = createAssessmentStore(makeInit());
        const created: NodeRecord = {
            uuid: 'newAttack',
            type: 'attack',
            title: 'Phishing',
            description: null,
            position: 0,
            impact_mode: null,
            impact_amount: null,
            impact_level_id: null,
            likelihood_mode: 'quantitative',
            likelihood_aro: null,
            likelihood_level_id: null,
            is_transfer: false,
            status: null,
            capex_amount: null,
            opex_annual_amount: null,
            tags: [],
            updated_at: '2026-01-01T00:00:00.000000Z',
        };
        (window.axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { node: created },
        });

        const uuid = await store.addChild('risk', 'riskA', {
            title: 'Phishing',
        });

        expect(uuid).toBe('newAttack');
        expect(window.axios.post).toHaveBeenCalledWith(
            expect.stringContaining('assessments.nodes.store'),
            { type: 'attack', title: 'Phishing', risk_uuid: 'riskA' },
        );
        expect(store.nodes['newAttack'].title).toBe('Phishing');
        expect(store.attacksUnderRisk('riskA')).toContain('newAttack');
    });
});

describe('deleteNode', () => {
    it('removes the node and every edge that referenced it', async () => {
        const store = createAssessmentStore(makeInit());
        (window.axios.delete as ReturnType<typeof vi.fn>).mockResolvedValue({});

        await store.deleteNode('sharedAttack');

        expect(store.nodes['sharedAttack']).toBeUndefined();
        expect(
            store.riskAttackLinks.filter(
                (l) => l.attack_uuid === 'sharedAttack',
            ),
        ).toHaveLength(0);
        expect(
            store.attackDefenceLinks.filter(
                (l) => l.attack_uuid === 'sharedAttack',
            ),
        ).toHaveLength(0);
    });
});

describe('a frozen store (W63): a snapshot being read, not an assessment being edited', () => {
    /**
     * The structural half of the snapshot viewer's read-only guarantee.
     *
     * All-false abilities are the half everyone thinks of, and they are not
     * enough on their own: they gate what the UI *offers*, at `v-if` sites,
     * leaving every call site wired behind them. Two things then slip past —
     * `loadUnresolvedCommentCounts()` fires from `onMounted` with no ability
     * check at all, and the label ids on a frozen graph are minted for the
     * render rather than being real rows, so one reaching a write would attach
     * an unrelated live label to a node.
     *
     * So these assert the guard at the method: not "the button is hidden", but
     * "calling it directly does nothing".
     */
    function frozenStore() {
        return createAssessmentStore({ ...makeInit(), frozen: true });
    }

    it('exposes that it is frozen, so a component can hide what leads nowhere', () => {
        expect(frozenStore().frozen).toBe(true);
        expect(createAssessmentStore(makeInit()).frozen).toBe(false);
    });

    it('never patches a node', async () => {
        const store = frozenStore();

        await store.updateNode('riskA', { title: 'Edited' });

        expect(window.axios.patch).not.toHaveBeenCalled();
        // And the local record is untouched too. A store that skipped the
        // request but applied the change optimistically would show an edit
        // that silently vanishes on reload.
        expect(store.nodes['riskA'].title).toBe('Risk A');
    });

    it('never deletes a node', async () => {
        const store = frozenStore();

        await store.deleteNode('riskA');

        expect(window.axios.delete).not.toHaveBeenCalled();
        expect(store.nodes['riskA']).toBeDefined();
    });

    it('never attaches or detaches a label', async () => {
        const store = frozenStore();

        await store.attachTag('riskA', 'PCI scope');
        await store.detachTag('riskA', 1);

        // The one where the ids matter: a frozen graph's label ids are minted
        // for the render, so a detach reaching the network would name a live
        // row that has nothing to do with this snapshot.
        expect(window.axios.post).not.toHaveBeenCalled();
        expect(window.axios.delete).not.toHaveBeenCalled();
    });

    it('never fetches comment counts', async () => {
        // The shared mock has no `get`, so an unguarded call would reject and
        // be swallowed by the method's own catch — leaving the counts empty and
        // this test green for the wrong reason. A resolving `get` is what makes
        // the assertion mean "it did not ask" instead of "asking happened to
        // fail".
        (window as unknown as { axios: Record<string, unknown> }).axios.get = vi
            .fn()
            .mockResolvedValue({ data: { riskA: 3 } });

        const store = frozenStore();

        await store.loadUnresolvedCommentCounts();

        expect(window.axios.get).not.toHaveBeenCalled();
        // The one abilities could never have stopped — ChartView and NodeCard
        // both call it from onMounted with no check. Unguarded, a frozen page
        // would decorate an old version with today's unresolved conversations.
        expect(store.unresolvedCommentCounts).toEqual({});
    });

    it('still expands and collapses, because reading needs it', () => {
        const store = frozenStore();
        const path = occurrencePath('root', 'riskA');

        store.toggleExpanded(path);

        // Frozen means "writes nothing", not "does nothing". Collapse state is
        // local UI, never leaves the browser, and a version you cannot fold up
        // is a version you cannot read.
        expect(store.isExpanded(path)).toBe(false);
    });
});
