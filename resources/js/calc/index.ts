/**
 * TypeScript mirror of the PHP calculation engine (docs/specs/calculation-engine.md
 * §11.5, W11). Ships to the client for instant optimistic feedback while
 * editing; PHP remains authoritative and its broadcast result overwrites the
 * local one. Reconciled against tests/fixtures/calculation-vectors.json —
 * the same golden vectors the PHP Pest suite runs — to 1e-9.
 */

export { DefenceRanker } from './DefenceRanker';
export { GraphResolver } from './GraphResolver';
export type { AttackFactors } from './GraphResolver';
export { RiskCalculator } from './RiskCalculator';
export { ScaleMapper } from './ScaleMapper';
export type {
    AssessmentGraph,
    AttackData,
    AttackDefenceLink,
    AttackFactorData,
    AttackResult,
    Baseline,
    CalculationResult,
    CalculationResults,
    Completeness,
    CompletenessLinkRef,
    CostBucket,
    DefenceData,
    DefenceResult,
    DefenceStatus,
    PairResult,
    ReductionTarget,
    ResolvedLink,
    RiskAttackLink,
    RiskData,
    RiskResult,
    Scale,
    ScaleKind,
    ScaleLevel,
    SharedAttack,
    ValueMode,
    Warning,
} from './types';
