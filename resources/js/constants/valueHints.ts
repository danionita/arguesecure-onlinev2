/**
 * Every label and every hover explanation the node card shows (ui.md §3.2,
 * W29). One module, for three reasons:
 *
 * 1. It is product copy. It explains a calculation to a workshop participant
 *    who has never read `calculation-engine.md`, and it will be edited by
 *    people who are not editing Vue.
 * 2. It has to be assertable. "A missing impact excludes the pair from the
 *    total; a missing strength is treated as zero reduction" is the honest
 *    distinction CLAUDE.md insists on, and the only way it stays true is if a
 *    test reads the string.
 * 3. The chart view (W32) renders the same numbers in a different shape. Two
 *    views explaining the same figure differently is worse than either view
 *    explaining it badly.
 *
 * Anything that interpolates a figure takes a *formatted* string, not a raw
 * number: Components/UI/format.ts owns rounding, and a hint that rounds
 * differently from the chip it explains reads as a contradiction.
 *
 * ## Unreferenced by the cards, on purpose (W37)
 *
 * `rootTotalHint`, `rootReductionHint`, `riskAleHint`, `attackAleHint`,
 * `contributionHint`, `defenceSavingHint`, `notAppliedHint`, `BASELINE_LABELS`
 * / `BASELINE_HINTS`, and several `VALUE_LABELS` keys currently have no caller.
 * That is the direct consequence of W36 and W37 taking engine output off both
 * cards: the copy did not become wrong, it became **early**. Every one of them
 * is consumed by the node modal's Statistics tab in W39, which shows exactly
 * these figures across all three baselines, and rewriting them there from
 * scratch would be how the two surfaces start explaining one number
 * differently — the thing this module exists to prevent.
 *
 * Two exports *were* deleted rather than parked, because they can never come
 * back: `BASELINE_OPTIONS` fed the baseline selector, which PLAN-2.2.md D8
 * closes for good, and `attackLikelihoodHint` interpolated one baseline's
 * residual into a sentence, which is precisely the arbitrary choice D8 removes.
 */
import type { Baseline, DefenceStatus, ReductionTarget } from '@/calc/types';

// --- baselines ------------------------------------------------------------

export const BASELINE_LABELS: Record<Baseline, string> = {
    inherent: 'Inherent',
    current: 'Current',
    target: 'Target',
};

export const BASELINE_HINTS: Record<Baseline, string> = {
    inherent:
        'Before any defence — what the risk would be with nothing in place.',
    current:
        'Counting implemented defences only. What you are exposed to today.',
    target: 'Counting implemented and planned (todo) defences. What the plan would buy.',
};

/**
 * What the three totals are called **on the ToS card** (W36).
 *
 * "Inherent" is the engine's word and the report's word; it is also jargon,
 * and the card is the surface a facilitator glances at mid-workshop. "Base"
 * is what people say out loud. The formal vocabulary is not replaced —
 * `BASELINE_LABELS` above still drives the report and the methodology
 * appendix — and `ROOT_TOTALS_HINT` is the sentence that connects the two, so
 * a reader who meets both words learns they are one idea rather than
 * suspecting they are two.
 *
 * "Target ALE" spells out the unit on the last figure only. All three are
 * annualised loss expectancies; naming it once, on the figure a reader is
 * most likely to quote, is the compromise between a row of unexplained
 * numbers and a row of repeated units.
 */
export const ROOT_TOTAL_LABELS: Record<Baseline, string> = {
    inherent: 'Base',
    current: 'Current',
    target: 'Target ALE',
};

export const ROOT_TOTALS_HINT =
    'Base is the inherent loss, before any defence is applied. Current counts implemented defences; Target adds the planned (todo) ones. All three are annualised loss expectancies.';

/** "in the current baseline" — for hints that name the baseline mid-sentence. */
function inBaseline(baseline: Baseline): string {
    return `in the ${BASELINE_LABELS[baseline].toLowerCase()} baseline`;
}

// --- labels ---------------------------------------------------------------

export const VALUE_LABELS = {
    rootTotal: 'Total risk',
    rootReduction: 'Reduction',
    riskImpact: 'Impact',
    riskAle: 'Annual loss',
    attackLikelihood: 'Likelihood',
    attackAle: 'Annual loss',
    defenceReduction: 'Reduces',
    defenceCost: 'Cost',
    defenceSaving: 'Saves',
} as const;

// --- missing values -------------------------------------------------------

/**
 * The two `?` chips mean genuinely different things, and the difference is
 * the part worth saying out loud: a missing impact or likelihood removes the
 * pair from the total, while a missing strength is treated as no reduction at
 * all. One understates nothing; the other deliberately overstates residual
 * risk, which is the safe direction to be wrong in.
 */
export const MISSING_HINTS = {
    impact: 'No impact entered. This risk is excluded from the total — it is not counted as zero.',
    likelihood:
        'No likelihood entered. This attack is excluded from the total — it is not counted as zero.',
    strength:
        'No strength entered for this link. Treated as no reduction at all, so the residual risk shown is the pessimistic one.',
} as const;

// --- root -----------------------------------------------------------------

export function rootTotalHint(baseline: Baseline, isPartial: boolean): string {
    const base = `Expected loss per year across the whole assessment, ${inBaseline(baseline)}.`;

    return isPartial
        ? `${base} Some pairs have no value entered, so this is a lower bound.`
        : base;
}

export function rootReductionHint(
    baseline: Baseline,
    inherentTotal: string,
    baselineTotal: string,
): string {
    return `Down from ${inherentTotal} inherent to ${baselineTotal} ${inBaseline(baseline)}.`;
}

export function completenessHint(
    pairsValued: number,
    pairsTotal: number,
    coverage: string,
): string {
    return `Values entered for ${pairsValued} of ${pairsTotal} risk/attack pairs (${coverage}). Pairs with a missing value are left out of the total.`;
}

// --- risk -----------------------------------------------------------------

export const RISK_IMPACT_HINT =
    'Loss each time this risk occurs — not the annual loss. Entered by hand.';

/**
 * Takes the band's **label**, not its code (W87, D44). `scale_levels.code` is
 * internal and frozen on rename, so quoting it here named a band by a string
 * the band table had stopped showing.
 */
export function riskImpactLevelHint(levelLabel: string): string {
    return `Impact band "${levelLabel}", from this assessment's impact scale. ${RISK_IMPACT_HINT}`;
}

export function riskAleHint(baseline: Baseline): string {
    return `Expected loss per year from this risk ${inBaseline(baseline)}: impact × likelihood of each attack, after the defences that count. Computed.`;
}

export function contributionHint(
    share: string,
    riskAle: string,
    total: string,
): string {
    return `${share} of the assessment's annual loss (${riskAle} of ${total}).`;
}

// --- attack ---------------------------------------------------------------

/**
 * The list card's version (W36): what the user entered, and nothing about a
 * baseline. The residual half of `attackLikelihoodHint` below is
 * baseline-dependent, and with no selector in the editor, naming one
 * baseline's residual would be an arbitrary choice stated as a fact. All
 * three residuals appear together in the modal's Statistics tab.
 */
export function attackLikelihoodEnteredHint(
    aroBase: string,
    approxProbability: string,
): string {
    return `${aroBase} entered — about a ${approxProbability} chance of at least one occurrence this year.`;
}

/** The band's label, not its code — see `riskImpactLevelHint` (W87, D44). */
export function attackLikelihoodLevelHint(levelLabel: string): string {
    return `Likelihood band "${levelLabel}", from this assessment's likelihood scale. A rate per year, not a probability.`;
}

export function attackAleHint(baseline: Baseline): string {
    return `Expected loss per year from this attack ${inBaseline(baseline)}, across every risk it belongs to. Computed.`;
}

// --- defence --------------------------------------------------------------

const REDUCTION_TARGET_NOUNS: Record<ReductionTarget | 'total', string> = {
    likelihood: 'likelihood',
    impact: 'impact',
    total: 'overall loss',
};

/**
 * The merged chip: strength and reduction target were two chips saying one
 * thing ("40%" next to "Reduces likelihood"), which left the reader to guess
 * that the percentage belonged to the words beside it.
 */
export function reductionChipLabel(
    reductionTarget: ReductionTarget | null,
    strength: string | null,
): string {
    const noun = REDUCTION_TARGET_NOUNS[reductionTarget ?? 'total'];

    return `Reduces ${noun} by ${strength ?? '?'}`;
}

export function reductionChipHint(
    reductionTarget: ReductionTarget | null,
    strength: string | null,
): string {
    if (strength === null) return MISSING_HINTS.strength;

    const noun = REDUCTION_TARGET_NOUNS[reductionTarget ?? 'total'];
    const base = `This defence removes ${strength} of the ${noun} of this attack. Strength is set per link — the same defence can be rated differently against another attack.`;

    return reductionTarget === null
        ? base
        : `${base} Choosing ${noun} changes the intermediate figures shown on the attack and risk, not the assessment total, because all reductions multiply together.`;
}

/**
 * A defence can be on screen and not counting: a todo defence under the
 * current baseline, anything shelved, anything at all under inherent. Saying
 * nothing would read as "this control does nothing"; this says which.
 */
export function notAppliedHint(
    status: DefenceStatus,
    baseline: Baseline,
): string | null {
    if (baseline === 'inherent') {
        return 'Not counted in the inherent baseline, which is the risk before any defence.';
    }

    if (status === 'shelved') {
        return 'Shelved, so it is never counted in any baseline.';
    }

    if (status === 'todo' && baseline === 'current') {
        return 'Planned, not implemented — counted in the target baseline, not in current.';
    }

    return null;
}

export const DEFENCE_COST_HINT =
    'Annual cost of this defence: recurring cost plus the one-off cost spread over the assessment’s amortization period.';

export function defenceSavingHint(baseline: Baseline): string {
    return `Annual loss this defence removes ${inBaseline(baseline)} — the difference the assessment total would show without it. Computed.`;
}
