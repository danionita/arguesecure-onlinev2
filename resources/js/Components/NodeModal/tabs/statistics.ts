/**
 * The Statistics tab's figures, as data (W39, PLAN-2.2.md item 11).
 *
 * Pure: it takes `CalculationResults` — the engine's own output, already on
 * the store — and returns rows. No store, no component, no formatting
 * decisions of its own. Two reasons that matters here:
 *
 * 1. The tab's whole claim is "these numbers are the engine's numbers". A
 *    test can feed it a computed `results` object and assert the rows cell by
 *    cell, which is not something you can do convincingly through a mounted
 *    component full of formatted strings.
 * 2. `RiskCalculator` is not touched by this package. Every figure below is a
 *    field that already exists on a result object, or a subtraction of two of
 *    them. If a figure ever seems to need an engine change, that is a
 *    stop-and-report, not a quiet addition here.
 *
 * ## Why the numbers are laid out per baseline
 *
 * W36 took the computed figures off the list card and W37 off the chart card,
 * because both of those surfaces had to pick *one* baseline to show and the
 * selector that picked it is gone (PLAN-2.2.md D8). The figures did not become
 * uninteresting; they became figures that only make sense next to their two
 * siblings. This is the surface where all three stand side by side, so no
 * choice has to be made on the reader's behalf.
 *
 * ## `—` is not `0`
 *
 * A null cell renders as the missing chip, never as zero. That is the
 * calculation engine's rule (CLAUDE.md; calculation-engine.md §6.1) applied at
 * the display layer, and it carries real meaning in three places here:
 * `attribution` is null for a defence outside the baseline's active set,
 * `cbr` is null for an unpriced defence, and a share-of-total is null rather
 * than 0% when the total itself is zero.
 */
import type { Baseline, CalculationResults, DefenceStatus } from '@/calc/types';
import {
    attackAleHint,
    completenessHint,
    contributionHint,
    defenceSavingHint,
    notAppliedHint,
    riskAleHint,
    rootReductionHint,
} from '@/constants/valueHints';

/** Left to right, always. Inherent first, so the two reductions read downhill. */
export const BASELINE_ORDER = ['inherent', 'current', 'target'] as const;

export type StatCellKind = 'money' | 'percent' | 'rate' | 'text';

export interface StatCell {
    kind: StatCellKind;
    /** `null` renders as the missing chip. For `text`, an already-formatted string. */
    value: number | string | null;
    /** Prefix a partial total with "≥" (ui.md §9). */
    atLeast?: boolean;
    /** Why this cell is what it is. Per-column, so it cannot live on the row. */
    note?: string;
}

export interface StatRow {
    key: string;
    label: string;
    /** Explains the row for every column at once; shown on the label. */
    hint?: string;
    /** One cell per `BASELINE_ORDER` entry, in that order. */
    cells: StatCell[];
}

/** A figure that is not baseline-dependent: cost, mostly. */
export interface StatLine {
    key: string;
    label: string;
    hint?: string;
    cell: StatCell;
}

/**
 * Formatters are injected rather than imported so the currency — which lives
 * on the assessment, not in this module — is applied once by the caller, and
 * so a sentence quotes character-for-character the same string as the figure
 * it explains. That is the reason `valueHints.ts` takes formatted strings at
 * all, and the reason this module does not reach for `formatMoney` itself.
 */
export interface StatFormatters {
    money: (value: number | null | undefined) => string;
    percent: (value: number | null | undefined) => string;
}

function money(value: number | null): StatCell {
    return { kind: 'money', value };
}

function percent(value: number | null): StatCell {
    return { kind: 'percent', value };
}

function rate(value: number | null | undefined): StatCell {
    return { kind: 'rate', value: value ?? null };
}

/** Nothing has been subtracted yet in the inherent column, and "0" would read as "the defences achieved nothing". */
const NOT_APPLICABLE: StatCell = { kind: 'text', value: '—' };

/** A share is null, not 0%, when there is no total to be a share of. */
function shareOf(part: number | null, total: number): number | null {
    if (part === null || total <= 0) return null;

    return part / total;
}

// --- root -----------------------------------------------------------------

export function rootStatRows(
    results: CalculationResults,
    fmt: StatFormatters,
): StatRow[] {
    const inherentTotal = results.inherent.total;

    return [
        {
            key: 'total',
            label: 'Total risk',
            hint: 'Expected loss per year across the whole assessment. Computed.',
            cells: BASELINE_ORDER.map((baseline) => ({
                kind: 'money' as const,
                value: results[baseline].total,
                atLeast: results[baseline].completeness.isPartial,
                note: results[baseline].completeness.isPartial
                    ? 'Lower bound — some pairs have no value entered.'
                    : undefined,
            })),
        },
        {
            key: 'reduction',
            label: 'Reduction vs base',
            cells: BASELINE_ORDER.map((baseline) =>
                baseline === 'inherent'
                    ? NOT_APPLICABLE
                    : {
                          kind: 'money' as const,
                          value: inherentTotal - results[baseline].total,
                          note: rootReductionHint(
                              baseline,
                              fmt.money(inherentTotal),
                              fmt.money(results[baseline].total),
                          ),
                      },
            ),
        },
        {
            key: 'reduction-percent',
            label: 'Reduction vs base, %',
            cells: BASELINE_ORDER.map((baseline) =>
                baseline === 'inherent'
                    ? NOT_APPLICABLE
                    : percent(
                          shareOf(
                              inherentTotal - results[baseline].total,
                              inherentTotal,
                          ),
                      ),
            ),
        },
    ];
}

/**
 * The one comparison the three columns cannot make: what moving from current
 * to target would buy. It sits *between* two columns, so it reads as a
 * sentence rather than as a mostly-empty fourth row.
 */
export function plannedImprovementNote(
    results: CalculationResults,
    fmt: StatFormatters,
): string | null {
    const delta = results.current.total - results.target.total;
    if (delta <= 0) return null;

    return `Completing the planned defences would remove a further ${fmt.money(delta)} a year — ${fmt.percent(shareOf(delta, results.current.total))} of what you are exposed to today.`;
}

export function completenessNote(results: CalculationResults): string {
    const { pairsValued, pairsTotal, coverage } = results.current.completeness;

    return completenessHint(
        pairsValued,
        pairsTotal,
        `${Math.round(coverage * 100)}%`,
    );
}

// --- risk -----------------------------------------------------------------

export function riskStatRows(
    results: CalculationResults,
    uuid: string,
    fmt: StatFormatters,
): StatRow[] {
    const aleIn = (baseline: Baseline): number | null =>
        results[baseline].risks.find((r) => r.uuid === uuid)?.ale ?? null;

    const inherentAle = aleIn('inherent');

    return [
        {
            key: 'ale',
            label: 'Annual loss',
            cells: BASELINE_ORDER.map((baseline) => ({
                kind: 'money' as const,
                value: aleIn(baseline),
                note: riskAleHint(baseline),
            })),
        },
        {
            key: 'share',
            label: 'Share of total',
            cells: BASELINE_ORDER.map((baseline) => {
                const share = shareOf(aleIn(baseline), results[baseline].total);

                return {
                    kind: 'percent' as const,
                    value: share,
                    note:
                        share === null
                            ? undefined
                            : contributionHint(
                                  fmt.percent(share),
                                  fmt.money(aleIn(baseline)),
                                  fmt.money(results[baseline].total),
                              ),
                };
            }),
        },
        {
            key: 'reduction',
            label: 'Reduction vs base',
            cells: BASELINE_ORDER.map((baseline) =>
                baseline === 'inherent' || inherentAle === null
                    ? NOT_APPLICABLE
                    : money(inherentAle - (aleIn(baseline) ?? inherentAle)),
            ),
        },
    ];
}

// --- attack ---------------------------------------------------------------

export function attackStatRows(
    results: CalculationResults,
    uuid: string,
): StatRow[] {
    const resultIn = (baseline: Baseline) =>
        results[baseline].attacks.find((a) => a.uuid === uuid);

    const inherentAle = resultIn('inherent')?.ale ?? null;

    return [
        {
            key: 'ale',
            label: 'Annual loss',
            cells: BASELINE_ORDER.map((baseline) => ({
                kind: 'money' as const,
                value: resultIn(baseline)?.ale ?? null,
                note: attackAleHint(baseline),
            })),
        },
        {
            key: 'aro-base',
            label: 'Likelihood entered',
            // Repeated identically across the three columns on purpose: the
            // row below is the same quantity after defences, and reading
            // "entered, then residual" down a single column is the point.
            hint: 'What was typed in, before any defence. A rate per year, not a probability — it may exceed 1.',
            cells: BASELINE_ORDER.map((baseline) =>
                rate(resultIn(baseline)?.aroBase),
            ),
        },
        {
            key: 'aro-residual',
            label: 'Residual likelihood',
            hint: 'The same rate after the defences that count in that baseline have reduced it.',
            cells: BASELINE_ORDER.map((baseline) =>
                rate(resultIn(baseline)?.aroResidual),
            ),
        },
        {
            key: 'reduction',
            label: 'Reduction vs base',
            cells: BASELINE_ORDER.map((baseline) =>
                baseline === 'inherent' || inherentAle === null
                    ? NOT_APPLICABLE
                    : money(
                          inherentAle -
                              (resultIn(baseline)?.ale ?? inherentAle),
                      ),
            ),
        },
    ];
}

// --- defence --------------------------------------------------------------

/** `2.4` renders as `2.4×`. A ratio is neither money nor a percentage. */
function ratioCell(value: number | null | undefined): StatCell {
    return {
        kind: 'text',
        value:
            value === null || value === undefined
                ? null
                : `${value.toFixed(2)}×`,
    };
}

/**
 * `mc` and `mcAdd` are mutually exclusive per baseline (calculation-engine.md
 * §9.3): a defence that is counting has a removal delta, one that is not has
 * an addition delta. The node card used to collapse them into one "Saves"
 * chip with `mc ?? mcAdd`, which was the right call for a chip and the wrong
 * one here — "what we would lose by switching this off" and "what we would
 * gain by doing it" are different questions, and the collapsed chip silently
 * answered whichever one happened to apply.
 */
export function defenceStatRows(
    results: CalculationResults,
    uuid: string,
    status: DefenceStatus,
): StatRow[] {
    const resultIn = (baseline: Baseline) =>
        results[baseline].defences.find((d) => d.uuid === uuid);

    return [
        {
            key: 'mc',
            label: 'If switched off, loses',
            hint: `${defenceSavingHint('current')} Removal deltas understate — with multiplicative strengths they do not sum to the joint reduction, so this is not a share of anything.`,
            cells: BASELINE_ORDER.map((baseline) => ({
                kind: 'money' as const,
                value: resultIn(baseline)?.mc ?? null,
                note: notAppliedHint(status, baseline) ?? undefined,
            })),
        },
        {
            key: 'mc-add',
            label: 'If done, would save',
            hint: 'The other half of the same question, for a defence that is not counting yet. At most one of these two rows has a figure in any given column.',
            cells: BASELINE_ORDER.map((baseline) =>
                money(resultIn(baseline)?.mcAdd ?? null),
            ),
        },
        {
            key: 'attribution',
            label: 'Share of reduction',
            hint: 'Credit for the reduction actually achieved, split between the defences that achieved it. Unlike the rows above, these do add up.',
            cells: BASELINE_ORDER.map((baseline) =>
                money(resultIn(baseline)?.attribution ?? null),
            ),
        },
        {
            key: 'net-benefit',
            label: 'Net annual benefit',
            hint: 'What it saves in a year, minus what it costs in a year.',
            cells: BASELINE_ORDER.map((baseline) =>
                money(resultIn(baseline)?.netBenefit ?? null),
            ),
        },
        {
            key: 'cbr',
            label: 'Risk removed per unit spent',
            hint: 'Above 1.00× it pays for itself. Blank when no cost has been recorded — a free control is not infinitely good value, the question simply does not apply.',
            cells: BASELINE_ORDER.map((baseline) =>
                ratioCell(resultIn(baseline)?.cbr),
            ),
        },
    ];
}

/**
 * Cost does not vary by baseline — it is what the thing costs — so it sits
 * outside the three-column table rather than being printed three times.
 */
export function defenceCostLines(
    results: CalculationResults,
    uuid: string,
    node: { capex_amount: number | null; opex_annual_amount: number | null },
    amortizationYears: number,
): StatLine[] {
    const annual = results.current.defences.find(
        (d) => d.uuid === uuid,
    )?.annualCost;

    return [
        {
            key: 'opex',
            label: 'Recurring, per year',
            cell: money(node.opex_annual_amount),
        },
        {
            key: 'capex',
            label: 'One-off',
            cell: money(node.capex_amount),
        },
        {
            key: 'annual',
            label: 'Annualised total',
            hint: `Recurring cost plus the one-off spread over ${amortizationYears} year${amortizationYears === 1 ? '' : 's'}, this assessment's amortization period.`,
            cell: money(annual ?? null),
        },
    ];
}

export function defenceCostBucketNote(
    results: CalculationResults,
    uuid: string,
): string | null {
    const bucket = results.current.defences.find(
        (d) => d.uuid === uuid,
    )?.costBucket;

    if (bucket === 'unpriced') {
        return 'No cost has been estimated, so the value-for-money figures are left blank rather than assumed to be zero.';
    }

    if (bucket === 'free') {
        return 'Recorded as costing nothing, so there is no cost to pay back.';
    }

    return null;
}
