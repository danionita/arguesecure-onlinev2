/**
 * Shared fixture for the W15 composable tests. Builds a real
 * `createAssessmentStore` (W12) rather than a hand-rolled fake, so every
 * test exercises the composables against the actual public store surface
 * they are constrained to use.
 *
 * The DAG shape deliberately goes one step further than
 * assessmentStore.spec.ts's own fixture: `sharedAttack` sits under both
 * `riskA` and `riskB` (the ordinary "an attack belongs to several risks"
 * case), and `defence2` is linked to *both* `sharedAttack` and `attack2`.
 * That makes `defence2` occur three times in the rendered tree (twice via
 * `sharedAttack`'s two positions, once via `attack2`) even though it has
 * only two distinct parent links — the exact transitive-duplication case
 * occurrences.ts's top comment calls out, and the case a tree (as opposed
 * to a DAG) could never produce.
 */
import {
    createAssessmentStore,
    type AssessmentStoreInit,
    type AttackDefenceLink,
    type CalculationResults,
    type NodeRecord,
    type RiskAttackLink,
} from '../../../../resources/js/stores/assessmentStore';

const NOW = '2026-01-01T00:00:00.000000Z';

function node(
    over: Partial<NodeRecord> & { uuid: string; type: NodeRecord['type'] },
): NodeRecord {
    return {
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

function emptyResult(
    baseline: 'inherent' | 'current' | 'target',
): CalculationResults['inherent'] {
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

export function makeFixtureInit(): AssessmentStoreInit {
    const nodes: NodeRecord[] = [
        node({ uuid: 'root', type: 'root', title: 'Assessment' }),
        node({ uuid: 'riskA', type: 'risk', title: 'Risk A', position: 0 }),
        node({ uuid: 'riskB', type: 'risk', title: 'Risk B', position: 1 }),
        node({
            uuid: 'sharedAttack',
            type: 'attack',
            title: 'Phishing',
            description: 'Credential theft via email',
            likelihood_mode: 'quantitative',
            likelihood_aro: 0.5,
        }),
        node({
            uuid: 'attack2',
            type: 'attack',
            title: 'SQL injection',
            // Deliberately missing both likelihood fields — the "has
            // missing values" filter test case.
        }),
        node({
            uuid: 'defence1',
            type: 'defence',
            title: 'MFA',
            status: 'implemented',
        }),
        node({
            uuid: 'defence2',
            type: 'defence',
            title: 'WAF',
            status: 'todo',
        }),
    ];

    const riskAttackLinks: RiskAttackLink[] = [
        { risk_uuid: 'riskA', attack_uuid: 'sharedAttack', position: 0 },
        { risk_uuid: 'riskB', attack_uuid: 'sharedAttack', position: 0 },
        { risk_uuid: 'riskA', attack_uuid: 'attack2', position: 1 },
    ];

    const attackDefenceLinks: AttackDefenceLink[] = [
        {
            attack_uuid: 'sharedAttack',
            defence_uuid: 'defence1',
            position: 0,
            strength_mode: 'quantitative',
            strength_fraction: 0.8,
            strength_level_id: null,
            reduction_target: 'likelihood',
        },
        {
            // Deliberately missing strength on this edge only.
            attack_uuid: 'sharedAttack',
            defence_uuid: 'defence2',
            position: 1,
            strength_mode: null,
            strength_fraction: null,
            strength_level_id: null,
            reduction_target: null,
        },
        {
            attack_uuid: 'attack2',
            defence_uuid: 'defence2',
            position: 0,
            strength_mode: 'quantitative',
            strength_fraction: 0.5,
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

export function makeFixtureStore() {
    return createAssessmentStore(makeFixtureInit());
}

/** In-memory `Storage` stand-in for tests, so persistence tests don't touch jsdom's real localStorage or leak between tests. */
export function makeMemoryStorage(): Pick<Storage, 'getItem' | 'setItem'> & {
    data: Map<string, string>;
} {
    const data = new Map<string, string>();
    return {
        data,
        getItem: (key: string) => data.get(key) ?? null,
        setItem: (key: string, value: string) => {
            data.set(key, value);
        },
    };
}
