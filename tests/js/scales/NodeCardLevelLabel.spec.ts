/**
 * The tree card renders a qualitative value as the band's **label**, never as
 * its code (W87, D44).
 *
 * ## Why this lives in `tests/js/scales/` rather than beside the card
 *
 * `tests/js/tree/NodeCard.spec.ts` fixes `impactLevelCode` and `aroLevelCode`
 * to `null` throughout, so the qualitative branch of the card has never been
 * rendered by a test at all. W87 changes exactly that branch, and a change
 * with no guard is the shape this whole package is about. The file is kept
 * here, in the directory this package owns, so that W85's restructuring of
 * `NodeCard.vue` — which moved its assistant handling out into
 * `Components/Node/**` — merges without a conflict in a file both packages
 * would otherwise have edited.
 *
 * ## What the card is resolving, and from where
 *
 * `RiskResult.impactLevelCode` and `AttackResult.aroLevelCode` are part of
 * calculation-engine.md §8.3's normative payload and are unchanged: the engine
 * still reports a **code**. The card looks that code up against the
 * calibration on the page props and renders the matching band's label.
 *
 * That indirection is the point. A code is derived from the label once and
 * then frozen, so that a catalogue fixture naming `moderate` keeps resolving
 * after somebody renames the band — which means the code and the label
 * diverge the moment anyone renames one, and a card printing the code would
 * be showing an internal key that contradicts the band table above it.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import NodeCard from '../../../resources/js/Components/Tree/NodeCard.vue';
import {
    createEditorView,
    EDITOR_VIEW_KEY,
} from '../../../resources/js/composables/useEditorView';
import {
    ASSESSMENT_STORE_KEY,
    createAssessmentStore,
    occurrencePath,
    type AssessmentStoreInit,
    type NodeRecord,
} from '../../../resources/js/stores/assessmentStore';

/**
 * The label and the code are deliberately different strings here. Seeded data
 * has `moderate`/`Moderate`, where a card printing either one looks correct —
 * which is precisely how this defect survived until a rename made them differ.
 */
const SCALE_LEVELS = [
    {
        id: 12,
        scale_id: 1,
        kind: 'impact' as const,
        unit: 'currency',
        code: 'moderate',
        label: 'Middling',
        ordinal: 3,
        lower_bound: 10000,
        upper_bound: 100000,
        representative: 31623,
    },
    {
        id: 21,
        scale_id: 2,
        kind: 'likelihood' as const,
        unit: 'rate_per_year',
        code: 'possible',
        label: 'Now and then',
        ordinal: 3,
        lower_bound: 0.2,
        upper_bound: 1,
        representative: 0.45,
    },
];

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            scaleLevels: SCALE_LEVELS,
            scales: [
                { id: 1, kind: 'impact', unit: 'currency', is_system: false },
                {
                    id: 2,
                    kind: 'likelihood',
                    unit: 'rate_per_year',
                    is_system: false,
                },
            ],
        },
    }),
}));

const NOW = '2026-01-01T00:00:00.000000Z';

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
        updated_at: NOW,
        ...over,
    };
}

/** A risk and an attack, both valued qualitatively — the untested branch. */
function makeInit(): AssessmentStoreInit {
    const results = {
        baseline: 'current' as const,
        total: 14230,
        risks: [
            {
                uuid: 'risk1',
                ale: 14230,
                impactValue: null,
                impactLevelCode: 'moderate',
                annualProbability: 0.36,
                pairsValued: 1,
                pairsTotal: 1,
            },
        ],
        attacks: [
            {
                uuid: 'attack1',
                ale: 14230,
                aroBase: null,
                aroResidual: 0.45,
                aroLevelCode: 'possible',
                factor: 1,
                riskCount: 1,
            },
        ],
        pairs: [],
        defences: [],
        completeness: {
            isPartial: false,
            pairsTotal: 1,
            pairsValued: 1,
            coverage: 1,
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

    return {
        assessment: {
            uuid: 'assessment-uuid',
            currency: 'EUR',
            display_mode: 'mixed',
            amortization_years: 3,
            is_public: false,
        },
        nodes: [
            node({ uuid: 'root', type: 'root', title: 'Web shop' }),
            node({
                uuid: 'risk1',
                type: 'risk',
                title: 'Customer data breach',
                impact_mode: 'qualitative',
                impact_amount: null,
                impact_level_id: 12,
            }),
            node({
                uuid: 'attack1',
                type: 'attack',
                title: 'Phishing',
                likelihood_mode: 'qualitative',
                likelihood_aro: null,
                likelihood_level_id: 21,
            }),
        ],
        riskAttackLinks: [
            { risk_uuid: 'risk1', attack_uuid: 'attack1', position: 0 },
        ],
        attackDefenceLinks: [],
        results: {
            inherent: { ...results, baseline: 'inherent' as const },
            current: results,
            target: { ...results, baseline: 'target' as const },
        },
        abilities: {
            comment: true,
            update: true,
            manage: false,
            transfer: false,
        },
    };
}

function mountCard(uuid: string, ancestors: string[] = []) {
    const store = createAssessmentStore(makeInit());
    const view = createEditorView(store, {
        assessmentUuid: 'assessment-uuid',
        userId: 7,
    });

    return mount(NodeCard, {
        props: {
            uuid,
            pathKey: occurrencePath(...ancestors, uuid),
            expanded: false,
            hasChildren: false,
        },
        global: {
            provide: {
                [ASSESSMENT_STORE_KEY as symbol]: store,
                [EDITOR_VIEW_KEY as symbol]: view,
            },
        },
    });
}

beforeEach(() => {
    localStorage.clear();
    // NodeCard resolves two assistant URLs at setup through Ziggy's global
    // helper. Not this test's subject, but the card will not mount without it.
    (globalThis as unknown as { route: (...args: unknown[]) => string }).route =
        vi.fn((name: string) => `/${name}`);
    (window as unknown as { axios: unknown }).axios = {
        get: vi.fn().mockResolvedValue({ data: {} }),
        patch: vi.fn(),
        post: vi.fn(),
        delete: vi.fn(),
    };
});

describe('a qualitative value on the tree card', () => {
    it("renders the impact band's label, not its code", () => {
        const text = mountCard('risk1').text();

        expect(text).toContain('Middling');
        // `moderate` is what the engine reports and what the database stores.
        // Neither is a thing to show a person.
        expect(text).not.toContain('moderate');
        expect(text).not.toContain('Moderate');
    });

    it("renders the likelihood band's label, not its code", () => {
        const text = mountCard('attack1', ['risk1']).text();

        expect(text).toContain('Now and then');
        expect(text).not.toContain('possible');
        expect(text).not.toContain('Possible');
    });

    it('quotes the label in the tooltip that explains the value', () => {
        // `riskImpactLevelHint` interpolates whatever it is given. Passing it
        // the code put an internal key inside a sentence written for a reader.
        const html = mountCard('risk1').html();

        expect(html).toContain('Middling');
        expect(html).not.toContain('moderate');
    });
});
