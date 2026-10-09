/**
 * TypeScript mirror of the payload App\Domain\Reporting\ReportAssembler
 * builds server-side (PLAN.md Phase 7). Plain, serializable shapes only —
 * this is Inertia page-prop data, not the calculation engine, so it does
 * not need to satisfy calc/types.ts's "no classes, mirrors the PHP DTO"
 * rule for its own sake, but it follows the same camelCase convention for
 * consistency with the rest of the frontend.
 *
 * Kept inside Components/Report/ rather than the shared resources/js/types/
 * directory: this package's file ownership is Pages/Report/** and
 * Components/Report/** only (W17 task brief) — resources/js/types/** is not
 * listed, so a type module other packages might also want to touch stays
 * out of scope here.
 */

export type ReportBaseline = 'current' | 'target' | 'both';
export type CostBucketKey = 'priced' | 'free' | 'unpriced';

/**
 * W80 removed `figures`, which was a `'full' | 'qualitative'` that no
 * renderer read on either side of the wire. See `ReportOptions`' PHP
 * docblock: computed results are rendered as currency and never band-mapped
 * (calculation-engine.md §8.3), so the option could not have meant what its
 * label said, and the per-node rendering it half-described already happens.
 */
export interface ReportOptions {
    baseline: ReportBaseline;
    includeShelved: boolean;
    includeComments: boolean;
    includeMethodology: boolean;
    /** Names on nodes in the full-assessment section (W46). Default off, and `download` does not turn it on. */
    showAssignees: boolean;
    /** Requested through the Download button: everything expanded, both states, no options panel. */
    download: boolean;
}

/** What the plan costs and what it buys (W33). */
export interface ProgrammeSummary {
    planned: {
        /** opex + capex / amortization years — already amortised. */
        annualisedCost: number;
        oneOffCost: number;
        recurringCost: number;
        count: number;
        freeCount: number;
        unpricedCount: number;
    };
    totals: { inherent: number; current: number; target: number };
    riskReduced: {
        inherentToCurrent: number;
        currentToTarget: number;
        inherentToTarget: number;
    };
    /** Null, never Infinity, when the plan has no recorded cost. */
    returnOnPlanned: number | null;
}

export interface TotalBlock {
    baseline: 'inherent' | 'current' | 'target';
    total: number;
    isPartial: boolean;
    pairsValued: number;
    pairsTotal: number;
    coverage: number;
}

export interface ScaleLevelRow {
    code: string;
    label: string;
    lowerBound: number;
    upperBound: number | null;
    representative: number;
}

export interface ScaleCalibrationRow {
    kind: 'impact' | 'likelihood' | 'strength';
    unit: string;
    isSystem: boolean;
    levels: ScaleLevelRow[];
}

export interface ReportCover {
    title: string | null;
    description: string | null;
    owner: { name: string } | null;
    generatedAt: string;
    scaleCalibration: ScaleCalibrationRow[];
    completeness: TotalBlock;
}

export interface ExecutiveSummary {
    inherent: TotalBlock;
    current: TotalBlock;
    target: TotalBlock;
    reductionCurrent: number | null;
    reductionTarget: number | null;
    annualCostOfControls: {
        priced: number;
        free: number;
        unpricedCount: number;
        implementedCount: number;
    };
}

export interface RiskRegisterAttackRow {
    uuid: string;
    title: string;
    currentAle: number | null;
    currentValued: boolean;
    targetAle: number | null;
    targetValued: boolean;
}

export interface RiskRegisterRow {
    uuid: string;
    title: string;
    impactMode: 'quantitative' | 'qualitative' | null;
    impactAmount: number | null;
    impactValue: number | null;
    impactLevelCode: string | null;
    attacks: RiskRegisterAttackRow[];
    inherent: { ale: number; pairsValued: number; pairsTotal: number };
    current: { ale: number; pairsValued: number; pairsTotal: number };
    target: { ale: number; pairsValued: number; pairsTotal: number };
    reductionCurrent: number | null;
    reductionTarget: number | null;
}

export interface DefenceRankingRow {
    uuid: string;
    title: string;
    isTransfer: boolean;
    status: 'implemented' | 'todo' | 'shelved';
    benefit: number | null;
    mc: number | null;
    mcAdd: number | null;
    attribution: number | null;
    annualCost: number | null;
    cbr: number | null;
    rosi: number | null;
    netBenefit: number | null;
    costBucket: CostBucketKey;
}

export interface DefenceBuckets {
    priced: DefenceRankingRow[];
    free: DefenceRankingRow[];
    unpriced: DefenceRankingRow[];
}

export interface TransferRetainedRow {
    riskUuid: string;
    riskTitle: string;
    currentRetainedImpact: number | null;
    targetRetainedImpact: number | null;
}

export interface TransferLinkRow {
    attackUuid: string;
    attackTitle: string;
    reductionTarget: 'likelihood' | 'impact' | null;
    strength: number | null;
    retained: TransferRetainedRow[];
}

export interface TransferRow {
    uuid: string;
    title: string;
    description: string | null;
    status: 'implemented' | 'todo' | 'shelved';
    links: TransferLinkRow[];
}

export interface TreeSummary {
    ale: number;
    annualCost?: number | null;
    costBucket?: CostBucketKey;
    pairsValued?: number;
    pairsTotal?: number;
    aroResidual?: number | null;
    factor?: number;
    mc?: number | null;
    mcAdd?: number | null;
    attribution?: number | null;
}

export interface TreeDefenceRow {
    uuid: string;
    title: string;
    description: string | null;
    rationale: string | null;
    status: 'implemented' | 'todo' | 'shelved';
    isTransfer: boolean;
    reductionTarget: 'likelihood' | 'impact' | null;
    strength: number | null;
    capexAmount: number | null;
    opexAnnualAmount: number | null;
    /** Empty unless the Show assignees option is on — display names only. */
    assignees: string[];
    current: TreeSummary | null;
    target: TreeSummary | null;
}

export interface TreeAttackRow {
    uuid: string;
    title: string;
    description: string | null;
    likelihoodMode: 'quantitative' | 'qualitative' | null;
    likelihoodAro: number | null;
    /** Empty unless the Show assignees option is on — display names only. */
    assignees: string[];
    inherent: TreeSummary | null;
    current: TreeSummary | null;
    target: TreeSummary | null;
    defences: TreeDefenceRow[];
}

export interface TreeRiskRow {
    uuid: string;
    title: string;
    description: string | null;
    impactMode: 'quantitative' | 'qualitative' | null;
    impactAmount: number | null;
    /** Empty unless the Show assignees option is on — display names only. */
    assignees: string[];
    inherent: TreeSummary | null;
    current: TreeSummary | null;
    target: TreeSummary | null;
    attacks: TreeAttackRow[];
}

export interface ReportTree {
    risks: TreeRiskRow[];
    orphanAttacks: { uuid: string; title: string }[];
    orphanDefences: { uuid: string; title: string }[];
}

/**
 * PLAN-2.7 D65: a control is `implemented` when any citing defence is,
 * `planned` when none is but one is todo, `shelved` otherwise.
 */
export type ControlStatus = 'implemented' | 'planned' | 'shelved';

export interface ControlCitingDefence {
    uuid: string;
    title: string;
    status: 'implemented' | 'todo' | 'shelved';
    isTransfer: boolean;
    /** Zero means the defence counters no attack in this assessment. */
    attackCount: number;
}

export interface CoverageControl {
    /** `FRAMEWORK:CONTROL`, as stored. */
    identifier: string;
    /** The control part as the framework writes it (`Art. 21(2)(d)`). */
    label: string;
    status: ControlStatus;
    defences: ControlCitingDefence[];
}

export interface CoverageFramework {
    framework: string;
    label: string;
    url: string;
    controls: CoverageControl[];
}

export interface ControlCoverage {
    /** Only frameworks with at least one control to list. */
    frameworks: CoverageFramework[];
    /** Controls cited only by shelved defences, left out unless shelved defences are included (D66). */
    hiddenShelved: number;
}

export interface ReferenceRow {
    nodeUuid: string;
    nodeTitle: string;
    kind: string;
    identifier: string;
    url: string | null;
    title: string | null;
}

export interface CommentReplyRow {
    author: string | null;
    body: string;
    createdAt: string | null;
}

export interface CommentRow {
    nodeUuid: string;
    nodeTitle: string;
    author: string | null;
    body: string;
    createdAt: string | null;
    resolved: boolean;
    replies: CommentReplyRow[];
}

export interface WarningRow {
    code: string;
    nodeUuid: string | null;
    nodeTitle: string | null;
    message: string;
}

export interface ReportAppendices {
    references: ReferenceRow[];
    comments: CommentRow[];
    warnings: WarningRow[];
}

export interface ReportData {
    assessment: {
        uuid: string;
        currency: string;
        amortizationYears: number;
        /**
         * Display only (W44, PLAN-2.2.md D14): BaselineBarChart draws a
         * threshold line against it. No figure in this report is computed
         * from it. Null means none is set, which is not zero.
         */
        riskTolerance: number | null;
        isPublic: boolean;
    };
    options: ReportOptions;
    /**
     * The author's own words and the disclosure that goes with them (W54),
     * added to the assembled report in W74 so the HTML and Word exports have
     * one source for the prose sections rather than reading the model again.
     * The *draft* is deliberately not here: it is un-reviewed model output
     * the server withholds from anyone who could not accept it (D24), and an
     * export is exactly the path by which it would reach them.
     */
    writtenSummary: { text: string | null; generated: boolean };
    cover: ReportCover;
    executiveSummary: ExecutiveSummary;
    programme: ProgrammeSummary;
    riskRegister: RiskRegisterRow[];
    defencesByImportance: DefenceBuckets;
    defencesByOpportunity: DefenceBuckets;
    shelvedOpportunity: DefenceBuckets;
    transfers: TransferRow[];
    controlCoverage: ControlCoverage;
    tree: ReportTree;
    appendices: ReportAppendices;
}
