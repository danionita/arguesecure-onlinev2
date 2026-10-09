import type {
    AssessmentGraph,
    Scale,
    ScaleLevel,
} from '../../../../resources/js/calc';

/**
 * Translates one golden-vector `input` block (tests/fixtures/calculation-vectors.json,
 * see tests/fixtures/README.md for the format) into an AssessmentGraph — the
 * only shape RiskCalculator accepts (calculation-engine.md §11.1). Mirrors
 * tests/Unit/Calculation/Support/GoldenVectorGraphAdapter.php field-for-field
 * so the two adapters can't quietly diverge on how a vector is interpreted.
 *
 * Same two deliberate adapter-only decisions as the PHP version (see that
 * class's docblock for the full rationale):
 *
 * 1. Scale levels are addressed by `code` in the fixture but by numeric
 *    `*LevelId` in AssessmentGraph — this assigns synthetic sequential ids
 *    per vector and keeps a code=>id map only long enough to resolve the
 *    risks/attacks/links that reference a level by code.
 * 2. A vector that declares `"levels": []` (only exercises quantitative
 *    values) gets a single schema-valid catch-all band `[0, ∞)` with
 *    representative 0 synthesized in its place, so RiskCalculator's
 *    unconditional band-mapping step (§11.2 step 8) has somewhere to land.
 */

type CodeMaps = {
    impact: Record<string, number>;
    likelihood: Record<string, number>;
    strength: Record<string, number>;
};

const SCALE_IDS: Record<'impact' | 'likelihood' | 'strength', number> = {
    impact: 1,
    likelihood: 2,
    strength: 3,
};
const DEFAULT_UNITS: Record<'impact' | 'likelihood' | 'strength', string> = {
    impact: 'currency',
    likelihood: 'rate_per_year',
    strength: 'fraction',
};

export function buildGraph(input: Record<string, any>): AssessmentGraph {
    const [scaleLevels, codeMaps] = buildScaleLevels(input.scales ?? {});

    const risks: AssessmentGraph['risks'] = {};
    for (const r of input.risks ?? []) {
        risks[r.uuid] = {
            uuid: r.uuid,
            title: r.name ?? r.uuid,
            impactMode: r.impact_mode ?? null,
            impactAmount: floatOrNull(r.impact_amount ?? null),
            impactLevelId: levelId(
                codeMaps.impact,
                r.impact_level_code ?? null,
            ),
        };
    }

    const attacks: AssessmentGraph['attacks'] = {};
    for (const a of input.attacks ?? []) {
        attacks[a.uuid] = {
            uuid: a.uuid,
            title: a.name ?? a.uuid,
            likelihoodMode: a.likelihood_mode ?? null,
            likelihoodAro: floatOrNull(a.likelihood_amount ?? null),
            likelihoodLevelId: levelId(
                codeMaps.likelihood,
                a.likelihood_level_code ?? null,
            ),
        };
    }

    const defences: AssessmentGraph['defences'] = {};
    for (const d of input.defences ?? []) {
        defences[d.uuid] = {
            uuid: d.uuid,
            title: d.name ?? d.uuid,
            isTransfer: Boolean(d.is_transfer ?? false),
            status: d.status ?? null,
            capexAmount: floatOrNull(d.capex_amount ?? null),
            opexAnnualAmount: floatOrNull(d.opex_annual_amount ?? null),
        };
    }

    const riskAttackLinks: AssessmentGraph['riskAttackLinks'] = (
        input.risk_attack ?? []
    ).map((ra: any, i: number) => ({
        riskUuid: ra.risk_uuid,
        attackUuid: ra.attack_uuid,
        position: ra.position ?? i,
    }));

    const attackDefenceLinks: AssessmentGraph['attackDefenceLinks'] = (
        input.attack_defence ?? []
    ).map((l: any, i: number) => ({
        attackUuid: l.attack_uuid,
        defenceUuid: l.defence_uuid,
        position: l.position ?? i,
        strengthMode: l.strength_mode ?? null,
        strengthFraction: floatOrNull(l.strength_fraction ?? null),
        strengthLevelId: levelId(
            codeMaps.strength,
            l.strength_level_code ?? null,
        ),
        reductionTarget: l.reduction_target ?? null,
    }));

    const assessment = input.assessment ?? {};

    return {
        assessmentUuid: 'golden-vector',
        currency: assessment.currency ?? 'EUR',
        amortizationYears: Number(assessment.amortization_years ?? 1),
        rootTitle: null,
        rootDescription: null,
        risks,
        attacks,
        defences,
        riskAttackLinks,
        attackDefenceLinks,
        scaleLevels,
        scales: buildScales(input.scales ?? {}),
    };
}

function floatOrNull(v: unknown): number | null {
    return v === null || v === undefined ? null : Number(v);
}

function levelId(
    codeMap: Record<string, number>,
    code: string | null,
): number | null {
    if (code === null || code === undefined) {
        return null;
    }

    return codeMap[code] ?? null;
}

/**
 * Builds the flat `scaleLevels` map AssessmentGraph expects, plus a
 * per-kind `code => id` map used only by this adapter to resolve the
 * fixture's `*_level_code` fields.
 */
export function buildScaleLevels(
    scalesInput: Record<string, any>,
): [Record<number, ScaleLevel>, CodeMaps] {
    const levels: Record<number, ScaleLevel> = {};
    const codeMaps: CodeMaps = { impact: {}, likelihood: {}, strength: {} };
    let nextId = 1;

    for (const kind of ['impact', 'likelihood', 'strength'] as const) {
        const scale = scalesInput[kind] ?? null;
        const unit = scale?.unit ?? DEFAULT_UNITS[kind];
        const rawLevels = scale?.levels ?? [];

        if (rawLevels.length === 0) {
            // See module docblock point 2: a schema-valid catch-all band
            // standing in for a vector that declared an empty scale.
            const id = nextId++;
            levels[id] = {
                id,
                scaleId: SCALE_IDS[kind],
                kind,
                unit,
                code: '_adapter_fallback',
                label: 'Fallback (unused by this vector)',
                ordinal: 1,
                lowerBound: 0.0,
                upperBound: null,
                representative: 0.0,
            };

            continue;
        }

        rawLevels.forEach((lvl: any, ord: number) => {
            const id = nextId++;
            codeMaps[kind][lvl.code] = id;
            levels[id] = {
                id,
                scaleId: SCALE_IDS[kind],
                kind,
                unit,
                code: lvl.code,
                label: lvl.label ?? lvl.code,
                ordinal: lvl.ordinal ?? ord + 1,
                lowerBound: Number(lvl.lower_bound),
                upperBound:
                    lvl.upper_bound === null || lvl.upper_bound === undefined
                        ? null
                        : Number(lvl.upper_bound),
                representative: Number(lvl.representative),
            };
        });
    }

    return [levels, codeMaps];
}

/**
 * The parent scale rows behind `scaleLevels`, carrying the `isSystem` flag
 * `uncalibrated_scales` is decided from (calculation-engine.md §12).
 *
 * A kind the vector omits entirely gets no entry: the band synthesized for
 * it in buildScaleLevels() stands in for a scale the vector never exercises,
 * and claiming it is an unmodified system default would fire the warning on
 * every vector that says nothing about calibration. Absent `is_system` on a
 * scale the vector *does* declare means false — the vector defined its own
 * bands, which is what calibration is.
 */
export function buildScales(
    scalesInput: Record<string, any>,
): Record<number, Scale> {
    const scales: Record<number, Scale> = {};

    for (const kind of ['impact', 'likelihood', 'strength'] as const) {
        const scale = scalesInput[kind] ?? null;

        if (scale === null || typeof scale !== 'object') {
            continue;
        }

        const id = SCALE_IDS[kind];
        scales[id] = {
            id,
            kind,
            unit: scale.unit ?? DEFAULT_UNITS[kind],
            isSystem: Boolean(scale.is_system ?? false),
        };
    }

    return scales;
}
