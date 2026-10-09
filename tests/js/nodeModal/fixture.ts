/**
 * One assessment, built so the Statistics tab has something to be wrong
 * about (W39).
 *
 * The shape is chosen for the three cases the plan named and the two the
 * engine draws distinctions over:
 *
 * - **MFA** — implemented and priced. Has an `mc` in current and target, an
 *   `annualCost`, and therefore a `cbr` that can be checked against a hand
 *   figure.
 * - **Training** — todo. Has `mcAdd` against current and `mc` against target,
 *   never both in one column. This is the pair the node card used to collapse
 *   into one chip.
 * - **Air gap** — shelved. Counts in no baseline at all, so `attribution` is
 *   null rather than 0 everywhere.
 * - **Review** — implemented and unpriced. `costBucket` is `unpriced` and
 *   `cbr` is null, which is the case that would render as "0×" or "∞" if the
 *   display layer ever coerced a missing cost.
 *
 * The arithmetic is deliberately checkable by hand:
 *
 *   inherent  2.0/yr × 100,000                = 200,000
 *   current   × (1 − 0.5) × (1 − 0.1)         =  90,000
 *   target    × (1 − 0.25) more               =  67,500
 *
 * `recomputeLocally()` runs the real `RiskCalculator` over this, so the
 * assertions built on it are against the engine's own output rather than
 * against a results object a test author typed — which is the only version of
 * this test that can catch the tab reading the wrong field.
 */
import {
    createAssessmentStore,
    type AssessmentStoreInit,
    type NodeRecord,
} from '../../../resources/js/stores/assessmentStore';

const NOW = '2026-01-01T00:00:00.000000Z';

export const SCALE_LEVELS = [
    {
        id: 11,
        scale_id: 1,
        kind: 'impact' as const,
        unit: 'currency',
        code: 'sev',
        label: 'Severe',
        ordinal: 1,
        lower_bound: 0,
        upper_bound: null,
        representative: 100000,
    },
    {
        id: 21,
        scale_id: 2,
        kind: 'likelihood' as const,
        unit: 'rate_per_year',
        code: 'often',
        label: 'Often',
        ordinal: 1,
        lower_bound: 0,
        upper_bound: null,
        representative: 2,
    },
    {
        id: 31,
        scale_id: 3,
        kind: 'strength' as const,
        unit: 'fraction',
        code: 'partial',
        label: 'Partial',
        ordinal: 1,
        lower_bound: 0,
        upper_bound: null,
        representative: 0.4,
    },
];

export const SCALES = [
    { id: 1, kind: 'impact' as const, unit: 'currency', is_system: false },
    {
        id: 2,
        kind: 'likelihood' as const,
        unit: 'rate_per_year',
        is_system: false,
    },
    { id: 3, kind: 'strength' as const, unit: 'fraction', is_system: false },
];

export function node(over: Partial<NodeRecord>): NodeRecord {
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

function defenceLink(defenceUuid: string, strength: number) {
    return {
        attack_uuid: 'attack1',
        defence_uuid: defenceUuid,
        position: 0,
        strength_mode: 'quantitative' as const,
        strength_fraction: strength,
        strength_level_id: null,
        reduction_target: 'likelihood' as const,
        rationale: null,
    };
}

export function emptyResult(baseline: 'inherent' | 'current' | 'target') {
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

export function makeInit(): AssessmentStoreInit {
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
                title: 'Data breach',
                impact_mode: 'quantitative',
                impact_amount: 100000,
            }),
            node({
                uuid: 'attack1',
                type: 'attack',
                title: 'Phishing',
                likelihood_mode: 'quantitative',
                likelihood_aro: 2,
            }),
            node({
                uuid: 'mfa',
                type: 'defence',
                title: 'MFA',
                status: 'implemented',
                capex_amount: 3000,
                opex_annual_amount: 1000,
            }),
            node({
                uuid: 'training',
                type: 'defence',
                title: 'Training',
                status: 'todo',
                opex_annual_amount: 500,
            }),
            node({
                uuid: 'airgap',
                type: 'defence',
                title: 'Air gap',
                status: 'shelved',
            }),
            node({
                uuid: 'review',
                type: 'defence',
                title: 'Quarterly review',
                status: 'implemented',
            }),
        ],
        riskAttackLinks: [
            { risk_uuid: 'risk1', attack_uuid: 'attack1', position: 0 },
        ],
        attackDefenceLinks: [
            defenceLink('mfa', 0.5),
            defenceLink('training', 0.25),
            defenceLink('airgap', 0.9),
            defenceLink('review', 0.1),
        ],
        scaleLevels: SCALE_LEVELS,
        scales: SCALES,
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

/** A store whose `results` came from the real engine, not from a literal. */
export function makeComputedStore() {
    const store = createAssessmentStore(makeInit());
    store.recomputeLocally();

    return store;
}
