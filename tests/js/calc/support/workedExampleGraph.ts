import type {
    AssessmentGraph,
    ScaleLevel,
} from '../../../../resources/js/calc';

/**
 * Builds the calculation-engine.md §14 worked example as an AssessmentGraph.
 * Mirrors tests/Unit/Calculation/Support/WorkedExampleGraph.php exactly —
 * same uuids, same scale-level ids, same "Resolves to" representatives — so
 * both suites exercise the identical graph independent of the shared JSON
 * fixture (which is a second, independently-derived encoding of the same
 * example).
 */

export const IMPACT_MODERATE_LEVEL_ID = 3;
export const LIKELIHOOD_UNLIKELY_LEVEL_ID = 12;
export const STRENGTH_STRONG_LEVEL_ID = 24;

export function buildWorkedExampleGraph(): AssessmentGraph {
    return {
        assessmentUuid: 'assessment-14',
        currency: 'EUR',
        amortizationYears: 3,
        rootTitle: 'Worked example',
        rootDescription: null,
        risks: {
            r1: {
                uuid: 'r1',
                title: 'Customer data breach',
                impactMode: 'quantitative',
                impactAmount: 200000.0,
                impactLevelId: null,
            },
            r2: {
                uuid: 'r2',
                title: 'Service outage',
                impactMode: 'qualitative',
                impactAmount: null,
                impactLevelId: IMPACT_MODERATE_LEVEL_ID,
            },
        },
        attacks: {
            a1: {
                uuid: 'a1',
                title: 'Phishing of admin credentials',
                likelihoodMode: 'quantitative',
                likelihoodAro: 0.5,
                likelihoodLevelId: null,
            },
            a2: {
                uuid: 'a2',
                title: 'SQL injection on portal',
                likelihoodMode: 'qualitative',
                likelihoodAro: null,
                likelihoodLevelId: LIKELIHOOD_UNLIKELY_LEVEL_ID,
            },
            a3: {
                uuid: 'a3',
                title: 'Volumetric DDoS',
                likelihoodMode: 'quantitative',
                likelihoodAro: 2.0,
                likelihoodLevelId: null,
            },
        },
        defences: {
            d1: {
                uuid: 'd1',
                title: 'MFA on admin accounts',
                isTransfer: false,
                status: 'implemented',
                capexAmount: null,
                opexAnnualAmount: 4000.0,
            },
            d2: {
                uuid: 'd2',
                title: 'Cyber insurance',
                isTransfer: true,
                status: 'implemented',
                capexAmount: null,
                opexAnnualAmount: 12000.0,
            },
            d3: {
                uuid: 'd3',
                title: 'Web application firewall',
                isTransfer: false,
                status: 'todo',
                capexAmount: null,
                opexAnnualAmount: 10000.0,
            },
            d4: {
                uuid: 'd4',
                title: 'Legacy VPN hardening',
                isTransfer: false,
                status: 'shelved',
                capexAmount: null,
                opexAnnualAmount: 2000.0,
            },
        },
        riskAttackLinks: [
            { riskUuid: 'r1', attackUuid: 'a1', position: 0 },
            { riskUuid: 'r2', attackUuid: 'a1', position: 1 },
            { riskUuid: 'r1', attackUuid: 'a2', position: 2 },
            { riskUuid: 'r2', attackUuid: 'a3', position: 3 },
        ],
        attackDefenceLinks: [
            {
                attackUuid: 'a1',
                defenceUuid: 'd1',
                position: 0,
                strengthMode: 'quantitative',
                strengthFraction: 0.8,
                strengthLevelId: null,
                reductionTarget: 'likelihood',
            },
            {
                attackUuid: 'a1',
                defenceUuid: 'd2',
                position: 1,
                strengthMode: 'quantitative',
                strengthFraction: 0.5,
                strengthLevelId: null,
                reductionTarget: 'impact',
            },
            {
                attackUuid: 'a2',
                defenceUuid: 'd3',
                position: 2,
                strengthMode: 'qualitative',
                strengthFraction: null,
                strengthLevelId: STRENGTH_STRONG_LEVEL_ID,
                reductionTarget: 'likelihood',
            },
            {
                attackUuid: 'a3',
                defenceUuid: 'd2',
                position: 3,
                strengthMode: 'quantitative',
                strengthFraction: 0.3,
                strengthLevelId: null,
                reductionTarget: 'impact',
            },
            {
                attackUuid: 'a3',
                defenceUuid: 'd3',
                position: 4,
                strengthMode: 'quantitative',
                strengthFraction: 0.5,
                strengthLevelId: null,
                reductionTarget: 'likelihood',
            },
            {
                attackUuid: 'a1',
                defenceUuid: 'd4',
                position: 5,
                strengthMode: 'quantitative',
                strengthFraction: 0.4,
                strengthLevelId: null,
                reductionTarget: null,
            },
        ],
        scaleLevels: workedExampleScaleLevels(),
        scales: {},
    };
}

/**
 * The §14 partial-data variant: A2's likelihood is blanked, so (R1,A2)
 * drops out of the total (§15 item 6, §14 "Partial-data variant").
 */
export function buildWorkedExampleGraphWithoutA2Likelihood(): AssessmentGraph {
    const g = buildWorkedExampleGraph();

    return {
        ...g,
        attacks: {
            ...g.attacks,
            a2: {
                uuid: 'a2',
                title: 'SQL injection on portal',
                likelihoodMode: null,
                likelihoodAro: null,
                likelihoodLevelId: null,
            },
        },
    };
}

export function workedExampleScaleLevels(): Record<number, ScaleLevel> {
    const impact: [number, string, number, number | null, number][] = [
        [1, 'negligible', 0, 1000, 250],
        [2, 'minor', 1000, 10000, 3162],
        [3, 'moderate', 10000, 100000, 30000], // §14's "Resolves to" value
        [4, 'major', 100000, 1000000, 316228],
        [5, 'severe', 1000000, null, 3000000],
    ];

    const likelihood: [number, string, number, number | null, number][] = [
        [11, 'rare', 0, 0.02, 0.005],
        [12, 'unlikely', 0.02, 0.2, 0.06],
        [13, 'possible', 0.2, 1, 0.45],
        [14, 'likely', 1, 10, 3.16],
        [15, 'frequent', 10, null, 30],
    ];

    // §14 labels the 0.5-0.8 band "strong" with representative 0.65 (rather
    // than the system default's "moderate" for that band) — both are valid
    // per-band labels; representative just has to sit within [lower, upper).
    const strength: [number, string, number, number | null, number][] = [
        [21, 'weak', 0, 0.2, 0.1],
        [22, 'limited', 0.2, 0.5, 0.35],
        [24, 'strong', 0.5, 0.8, 0.65],
        [23, 'very_strong', 0.8, 0.95, 0.875],
        [25, 'near_certain', 0.95, 1, 0.975],
    ];

    const groups: [
        string,
        string,
        number,
        [number, string, number, number | null, number][],
    ][] = [
        ['impact', 'currency', 1, impact],
        ['likelihood', 'rate_per_year', 2, likelihood],
        ['strength', 'fraction', 3, strength],
    ];

    const levels: Record<number, ScaleLevel> = {};

    for (const [kind, unit, scaleId, bands] of groups) {
        bands.forEach(([id, code, lower, upper, representative], ordinal) => {
            levels[id] = {
                id,
                scaleId,
                kind: kind as ScaleLevel['kind'],
                unit,
                code,
                label:
                    code.charAt(0).toUpperCase() +
                    code.slice(1).replace(/_/g, ' '),
                ordinal: ordinal + 1,
                lowerBound: lower,
                upperBound: upper,
                representative,
            };
        });
    }

    return levels;
}
