/**
 * The suggestion composable, on its own (W40).
 *
 * Three of these are about failure modes rather than features, because the
 * failure modes are what make a type-ahead feel broken: a stale response
 * overwriting a fresh one, a request per keystroke, and a lookup error
 * escaping into the edit the user is in the middle of making.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useNodeSuggestions } from '../../../resources/js/Components/Tree/useNodeSuggestions';
import {
    createAssessmentStore,
    type AssessmentStore,
    type AssessmentStoreInit,
} from '../../../resources/js/stores/assessmentStore';

const NOW = '2026-01-01T00:00:00.000000Z';

function makeStore(): AssessmentStore {
    const init: AssessmentStoreInit = {
        assessment: {
            uuid: 'assessment-uuid',
            currency: 'EUR',
            display_mode: 'mixed',
            amortization_years: 3,
            is_public: false,
        },
        nodes: [
            {
                uuid: 'root',
                type: 'root',
                title: 'Web shop',
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
                updated_at: NOW,
            },
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

function suggestion(uuid: string, title: string, occurrences = 0) {
    return { uuid, title, description: null, occurrences };
}

let get: ReturnType<typeof vi.fn>;

beforeEach(() => {
    vi.useFakeTimers();
    get = vi.fn().mockResolvedValue({ data: { nodes: [] } });
    (globalThis as unknown as { route: (...args: unknown[]) => string }).route =
        vi.fn((name: string) => `/${name}`);
    (window as unknown as { axios: unknown }).axios = {
        get,
        post: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
    };
});

describe('fetching', () => {
    it('sends the type, the term and the parent so the parent’s own children can be excluded', async () => {
        const s = useNodeSuggestions(makeStore());
        await s.fetchNow('attack', 'risk1', 'Phish');

        expect(get.mock.calls[0][1]).toEqual({
            params: { type: 'attack', q: 'Phish', parent_uuid: 'risk1' },
        });
    });

    it('omits the parent rather than sending null, which the endpoint would reject', async () => {
        const s = useNodeSuggestions(makeStore());
        await s.fetchNow('defence', null, '');

        expect(get.mock.calls[0][1]).toEqual({
            params: { type: 'defence', q: '' },
        });
    });

    it('sends one request for a burst of keystrokes, not one per letter', () => {
        const s = useNodeSuggestions(makeStore());
        for (const term of ['P', 'Ph', 'Phi', 'Phis']) {
            s.search('attack', 'risk1', term);
        }
        vi.runAllTimers();

        expect(get).toHaveBeenCalledTimes(1);
        expect(get.mock.calls[0][1].params.q).toBe('Phis');
    });

    it('ignores a slow response that lands after a newer one', async () => {
        const s = useNodeSuggestions(makeStore());

        let releaseFirst: (value: unknown) => void = () => {};
        get.mockReturnValueOnce(
            new Promise((resolve) => {
                releaseFirst = resolve;
            }),
        );
        get.mockResolvedValueOnce({
            data: { nodes: [suggestion('b', 'Newer')] },
        });

        const first = s.fetchNow('attack', null, 'P');
        await s.fetchNow('attack', null, 'Phis');
        releaseFirst({ data: { nodes: [suggestion('a', 'Stale')] } });
        await first;

        // The stale answer is for a prefix the user has already typed past.
        expect(s.suggestions.value.map((n) => n.title)).toEqual(['Newer']);
    });

    it('shows nothing rather than failing the edit when the lookup errors', async () => {
        const s = useNodeSuggestions(makeStore());
        get.mockRejectedValueOnce(new Error('offline'));

        await expect(s.fetchNow('attack', null, 'P')).resolves.toBeUndefined();
        expect(s.suggestions.value).toEqual([]);
    });

    it('drops in-flight results when reset, so a closed list cannot reopen itself', async () => {
        const s = useNodeSuggestions(makeStore());

        let release: (value: unknown) => void = () => {};
        get.mockReturnValueOnce(
            new Promise((resolve) => {
                release = resolve;
            }),
        );

        const pending = s.fetchNow('attack', null, 'P');
        s.reset();
        release({ data: { nodes: [suggestion('a', 'Too late')] } });
        await pending;

        expect(s.suggestions.value).toEqual([]);
    });
});

describe('keyboard selection', () => {
    it('starts with nothing highlighted, so Enter still means create-new', async () => {
        const s = useNodeSuggestions(makeStore());
        get.mockResolvedValueOnce({
            data: { nodes: [suggestion('a', 'Phishing')] },
        });
        await s.fetchNow('attack', null, 'P');

        expect(s.activeIndex.value).toBe(-1);
        expect(s.highlighted()).toBeNull();
    });

    it('wraps in both directions', async () => {
        const s = useNodeSuggestions(makeStore());
        get.mockResolvedValueOnce({
            data: { nodes: [suggestion('a', 'One'), suggestion('b', 'Two')] },
        });
        await s.fetchNow('attack', null, 'P');

        s.move(1);
        expect(s.highlighted()?.title).toBe('One');
        s.move(1);
        expect(s.highlighted()?.title).toBe('Two');
        s.move(1);
        expect(s.highlighted()?.title).toBe('One');
        // ArrowUp from the first row lands on the last, not back on nothing.
        s.move(-1);
        expect(s.highlighted()?.title).toBe('Two');
    });

    it('clears the highlight when new results arrive under it', async () => {
        const s = useNodeSuggestions(makeStore());
        get.mockResolvedValueOnce({
            data: { nodes: [suggestion('a', 'One'), suggestion('b', 'Two')] },
        });
        await s.fetchNow('attack', null, 'P');
        s.move(1);
        s.move(1);

        get.mockResolvedValueOnce({
            data: { nodes: [suggestion('c', 'Different')] },
        });
        await s.fetchNow('attack', null, 'Ph');

        // Row 1 is not the row that was row 1, and Enter must not link
        // whatever happens to be sitting at the old index.
        expect(s.activeIndex.value).toBe(-1);
    });

    it('does nothing on an empty list', () => {
        const s = useNodeSuggestions(makeStore());
        s.move(1);

        expect(s.activeIndex.value).toBe(-1);
    });
});
