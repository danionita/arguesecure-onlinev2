/**
 * TypeScript mirror of App\Domain\Graph\AssessmentGraph and the
 * App\Domain\Calculation result value objects (docs/specs/calculation-engine.md
 * §11.1, §13). Kept as plain, serializable types — no classes — so the
 * engine that consumes them stays pure, matching RiskCalculator's PHP
 * counterpart (calculation-engine.md §11.1: "no Eloquent, no I/O, no clock,
 * no randomness").
 *
 * Naming: the PHP DTO uses snake_case array keys (schema.md's column names);
 * this mirror uses camelCase properties, matching how the PHP *result*
 * classes already expose their properties (e.g. RiskResult::impactValue).
 * Both are the same shape, just idiomatic to their language.
 */

export type ValueMode = 'quantitative' | 'qualitative';

export type ReductionTarget = 'likelihood' | 'impact';

export type DefenceStatus = 'implemented' | 'todo' | 'shelved';

export type Baseline = 'inherent' | 'current' | 'target';

export type ScaleKind = 'impact' | 'likelihood' | 'strength';

export type CostBucket = 'priced' | 'free' | 'unpriced';

export interface RiskData {
    uuid: string;
    title: string;
    impactMode: ValueMode | null;
    impactAmount: number | null;
    impactLevelId: number | null;
}

export interface AttackData {
    uuid: string;
    title: string;
    likelihoodMode: ValueMode | null;
    likelihoodAro: number | null;
    likelihoodLevelId: number | null;
}

export interface DefenceData {
    uuid: string;
    title: string;
    isTransfer: boolean;
    status: DefenceStatus | null;
    capexAmount: number | null;
    opexAnnualAmount: number | null;
}

export interface RiskAttackLink {
    riskUuid: string;
    attackUuid: string;
    position: number;
}

export interface AttackDefenceLink {
    attackUuid: string;
    defenceUuid: string;
    position: number;
    strengthMode: ValueMode | null;
    strengthFraction: number | null;
    strengthLevelId: number | null;
    reductionTarget: ReductionTarget | null;
}

export interface ScaleLevel {
    id: number;
    scaleId: number;
    kind: ScaleKind;
    unit: string;
    code: string;
    label: string;
    ordinal: number;
    lowerBound: number;
    upperBound: number | null;
    representative: number;
}

export interface Scale {
    id: number;
    kind: ScaleKind;
    unit: string;
    isSystem: boolean;
}

/**
 * The only input RiskCalculator.compute() accepts (calculation-engine.md
 * §11.1). `risks`/`attacks`/`defences` are keyed by uuid, matching the PHP
 * DTO; `scaleLevels`/`scales` are keyed by their numeric id, since links
 * reference a level by `*LevelId` and must look it up in one step.
 */
export interface AssessmentGraph {
    assessmentUuid: string;
    currency: string;
    amortizationYears: number;
    rootTitle: string | null;
    rootDescription: string | null;
    risks: Record<string, RiskData>;
    attacks: Record<string, AttackData>;
    defences: Record<string, DefenceData>;
    riskAttackLinks: RiskAttackLink[];
    attackDefenceLinks: AttackDefenceLink[];
    scaleLevels: Record<number, ScaleLevel>;
    /** Parent scale rows behind scaleLevels — only needed for `uncalibrated_scales` (§12). */
    scales: Record<number, Scale>;
}

/**
 * One attack_defence link after §6.1/§6.2 resolution: a missing strength has
 * already been coerced to `s = 0` and flagged via `missing` (the single
 * point where that asymmetry is applied — mirrors GraphResolver::resolveLinks).
 */
export interface ResolvedLink {
    attackUuid: string;
    defenceUuid: string;
    reductionTarget: ReductionTarget | null;
    s: number;
    missing: boolean;
}

/** §3/§7 factor(a,S) plus the §5 L/I/C sub-products, and the active link list used by DefenceRanker. */
export interface AttackFactorData {
    factor: number;
    productL: number;
    productI: number;
    productC: number;
    activeLinks: ResolvedLink[];
}

export interface PairResult {
    riskUuid: string;
    attackUuid: string;
    aroResidual: number | null;
    impactResidual: number | null;
    ale: number | null;
    valued: boolean;
}

export interface RiskResult {
    uuid: string;
    ale: number;
    impactValue: number | null;
    impactLevelCode: string | null;
    annualProbability: number;
    pairsValued: number;
    pairsTotal: number;
}

export interface AttackResult {
    uuid: string;
    ale: number;
    aroBase: number | null;
    aroResidual: number | null;
    aroLevelCode: string | null;
    factor: number;
    riskCount: number;
}

export interface DefenceResult {
    uuid: string;
    status: string;
    mc: number | null;
    mcAdd: number | null;
    attribution: number | null;
    annualCost: number | null;
    cbr: number | null;
    rosi: number | null;
    netBenefit: number | null;
    costBucket: CostBucket;
}

export interface CompletenessLinkRef {
    attackUuid: string;
    defenceUuid: string;
}

export interface SharedAttack {
    uuid: string;
    riskCount: number;
}

/** The §6.3 completeness block, one per baseline. */
export interface Completeness {
    isPartial: boolean;
    pairsTotal: number;
    pairsValued: number;
    coverage: number;
    risksMissingImpact: string[];
    attacksMissingLikelihood: string[];
    linksMissingStrength: CompletenessLinkRef[];
    risksWithoutAttacks: string[];
    orphanAttacks: string[];
    orphanDefences: string[];
    sharedAttacks: SharedAttack[];
    implausibleStrength: CompletenessLinkRef[];
}

/** One engine-emitted warning (§12). `nodeUuid` is null for assessment-level warnings. */
export interface Warning {
    code: string;
    nodeUuid: string | null;
    message: string;
}

/** One baseline's worth of computed results (§13). */
export interface CalculationResult {
    baseline: Baseline;
    total: number;
    risks: RiskResult[];
    attacks: AttackResult[];
    pairs: PairResult[];
    defences: DefenceResult[];
    completeness: Completeness;
    warnings: Warning[];
}

/** The container of all three baselines, computed together in a single pass. */
export interface CalculationResults {
    inherent: CalculationResult;
    current: CalculationResult;
    target: CalculationResult;
}
