/**
 * Labels in the store, and the filter gap they close (W43).
 *
 * `useFilters.ts` has carried a fully implemented, fully tested tag filter
 * since W15 with nothing to filter: `NodeRecord` had no tag data and the
 * lookup was injected with a default of "no node has any tags". These assert
 * the two halves of the fix — the store carries the labels, and the editor
 * view hands the real accessor to the filter — because either one alone
 * leaves the filter exactly as inert as it was.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createEditorView } from '../../../resources/js/composables/useEditorView';
import {
    createAssessmentStore,
    occurrencePath,
    type AssessmentStoreInit,
    type NodeRecord,
    type TagRecord,
} from '../../../resources/js/stores/assessmentStore';

const NOW = '2026-01-01T00:00:00.000000Z';

const post = vi.fn();
const del = vi.fn();

vi.stubGlobal(
    'route',
    (name: string, params: unknown) => `/${name}/${JSON.stringify(params)}`,
);

function node(over: Partial<NodeRecord> & { uuid: string }): NodeRecord {
    return {
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
        updated_at: NOW,
        ...over,
    };
}

const PCI: TagRecord = { id: 1, name: 'PCI', slug: 'pci', color: null };
const VENDOR: TagRecord = {
    id: 2,
    name: 'Vendor',
    slug: 'vendor',
    color: null,
};

function makeInit(): AssessmentStoreInit {
    return {
        assessment: {
            uuid: 'a-uuid',
            currency: 'EUR',
            display_mode: 'mixed',
            amortization_years: 3,
            is_public: false,
        },
        nodes: [
            node({ uuid: 'root', type: 'root', title: 'Web shop' }),
            node({ uuid: 'risk1', title: 'Card data', tags: [PCI] }),
            node({ uuid: 'risk2', title: 'Outage', tags: [PCI, VENDOR] }),
            node({ uuid: 'risk3', title: 'Fraud' }),
        ],
        riskAttackLinks: [],
        attackDefenceLinks: [],
        tags: [VENDOR, PCI],
        results: {
            inherent: empty('inherent'),
            current: empty('current'),
            target: empty('target'),
        },
        abilities: {
            comment: true,
            update: true,
            manage: false,
            transfer: false,
        },
    };
}

function empty(baseline: 'inherent' | 'current' | 'target') {
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
    post.mockReset();
    del.mockReset();
    vi.stubGlobal('window', {
        ...globalThis.window,
        axios: { post, delete: del },
    });
});

describe('the vocabulary', () => {
    it('is offered alphabetically, whatever order the server sent', () => {
        const store = createAssessmentStore(makeInit());

        expect(store.availableTagNames.value).toEqual(['PCI', 'Vendor']);
    });

    it('reports the labels one node carries, by name', () => {
        const store = createAssessmentStore(makeInit());

        expect(store.tagNamesFor('risk2')).toEqual(['PCI', 'Vendor']);
        expect(store.tagNamesFor('risk3')).toEqual([]);
        // A uuid the store has never heard of is not a crash: the filter asks
        // about every occurrence it enumerates, including during a delete.
        expect(store.tagNamesFor('gone')).toEqual([]);
    });
});

describe('attaching', () => {
    it('renders the name the server resolved, not the one that was typed', async () => {
        const store = createAssessmentStore(makeInit());
        post.mockResolvedValue({
            data: {
                tag: { id: 9, name: 'PCI DSS', slug: 'pci-dss', color: null },
            },
        });

        await store.attachTag('risk3', 'pci  dss');

        // The server resolves a variant spelling to the existing label. Showing
        // the typed spelling first would put a chip on screen that renames
        // itself a moment later, or worse, does not.
        expect(store.tagNamesFor('risk3')).toEqual(['PCI DSS']);
        expect(store.availableTagNames.value).toContain('PCI DSS');
    });

    it('does not duplicate a label the node already carries', async () => {
        const store = createAssessmentStore(makeInit());
        post.mockResolvedValue({ data: { tag: PCI } });

        await store.attachTag('risk1', 'PCI');

        expect(store.tagNamesFor('risk1')).toEqual(['PCI']);
    });

    it('sends nothing for an empty name', async () => {
        const store = createAssessmentStore(makeInit());

        await store.attachTag('risk3', '   ');

        expect(post).not.toHaveBeenCalled();
    });
});

describe('detaching', () => {
    it('takes the label off the node but leaves it in the vocabulary', async () => {
        const store = createAssessmentStore(makeInit());
        del.mockResolvedValue({ data: { detached: true } });

        await store.detachTag('risk2', VENDOR.id);

        expect(store.tagNamesFor('risk2')).toEqual(['PCI']);
        // Still offered: removing a label by mistake should cost one
        // keystroke to undo, not a retype into a near-duplicate.
        expect(store.availableTagNames.value).toContain('Vendor');
    });
});

describe('the filter, which has had nothing to filter since W15', () => {
    it('dims the occurrences that do not carry the chosen label', () => {
        const store = createAssessmentStore(makeInit());
        const view = createEditorView(store, {
            assessmentUuid: 'a-uuid',
            userId: 1,
        });

        view.filters.toggleTag('Vendor');

        expect(view.filters.isDimmed(occurrencePath('root', 'risk2'))).toBe(
            false,
        );
        expect(view.filters.isDimmed(occurrencePath('root', 'risk1'))).toBe(
            true,
        );
        expect(view.filters.isDimmed(occurrencePath('root', 'risk3'))).toBe(
            true,
        );
    });

    it('finds both nodes under a label two of them share', () => {
        const store = createAssessmentStore(makeInit());
        const view = createEditorView(store, {
            assessmentUuid: 'a-uuid',
            userId: 1,
        });

        view.filters.toggleTag('PCI');

        expect(view.filters.isDimmed(occurrencePath('root', 'risk1'))).toBe(
            false,
        );
        expect(view.filters.isDimmed(occurrencePath('root', 'risk2'))).toBe(
            false,
        );
        expect(view.filters.isDimmed(occurrencePath('root', 'risk3'))).toBe(
            true,
        );
    });
});
