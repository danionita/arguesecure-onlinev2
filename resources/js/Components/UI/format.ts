/**
 * Currency and percentage formatting, in one place.
 *
 * Money.vue and Percent.vue formatted inline until W29, which was fine while
 * they were the only things rendering a figure. They no longer are: the node
 * card's tooltips (constants/valueHints.ts) quote the same numbers back in a
 * sentence — "18% of total annual loss (€22,500 of €125,000)" — and a tooltip
 * that rounds differently from the chip it explains reads as a second, wrong
 * number rather than as an explanation of the first.
 *
 * `null`/`undefined` return `null` rather than a string: the display layer
 * renders missing as the grey `?` chip, never as `0` and never as blank
 * (CLAUDE.md / calculation-engine.md — a missing value is excluded from the
 * total, so showing it as zero would understate risk).
 */

export const DEFAULT_LOCALE = 'en-GB';

export interface FormatMoneyOptions {
    currency?: string;
    /** Prefix a partial/undercounted total with "≥" (docs/specs/ui.md §9). */
    atLeast?: boolean;
    locale?: string;
}

export function formatMoney(
    value: number | null | undefined,
    options: FormatMoneyOptions = {},
): string | null {
    if (value === null || value === undefined) return null;

    const formatter = new Intl.NumberFormat(options.locale ?? DEFAULT_LOCALE, {
        style: 'currency',
        currency: options.currency ?? 'EUR',
        maximumFractionDigits: 0,
    });

    return `${options.atLeast ? '≥' : ''}${formatter.format(value)}`;
}

export interface FormatPercentOptions {
    digits?: number;
    signed?: boolean;
}

/** `value` is a 0-1 fraction: 0.42 formats as "42%". */
export function formatPercent(
    value: number | null | undefined,
    options: FormatPercentOptions = {},
): string | null {
    if (value === null || value === undefined) return null;

    const percent = value * 100;
    const sign = options.signed && percent > 0 ? '+' : '';

    return `${sign}${percent.toFixed(options.digits ?? 0)}%`;
}

/** Likelihood is a rate per year and may exceed 1 (calculation-engine.md). */
export function formatRate(value: number | null | undefined): string | null {
    if (value === null || value === undefined) return null;

    return `${value.toFixed(2)}/yr`;
}

/*
 * ---------------------------------------------------------------------------
 * Short forms (W69)
 * ---------------------------------------------------------------------------
 *
 * The chart card's bottom row is a strip roughly forty pixels wide inside a
 * 176px card, and it has to hold a figure without wrapping or truncating —
 * a figure that truncates is worse than the checkmark it replaced, because a
 * clipped "€400,00…" reads as a different number rather than as an unreadable
 * one. These are therefore *display* abbreviations for that strip only. Every
 * surface with room for the exact figure — the list card, the modal's
 * Statistics tab, the report — keeps using the full-precision helpers above,
 * and the exact figure is always one hover or one click away.
 */

const MONEY_UNITS = [
    { threshold: 1e9, divisor: 1e9, suffix: 'B' },
    { threshold: 1e6, divisor: 1e6, suffix: 'M' },
    { threshold: 1e3, divisor: 1e3, suffix: 'k' },
] as const;

/**
 * `400000` → `€400k`, `1250000` → `€1.3M`, `750` → `€750`.
 *
 * One decimal below ten and none above it, so the string never exceeds six
 * characters: `€9.9M` needs the decimal to say anything, `€400M` does not,
 * and rounding `€412k` to `€400k` would lose the only digits that
 * distinguish it from its neighbours on the board.
 */
export function formatMoneyShort(
    value: number | null | undefined,
    options: Omit<FormatMoneyOptions, 'atLeast'> = {},
): string | null {
    if (value === null || value === undefined) return null;

    const unit = MONEY_UNITS.find(
        (candidate) => Math.abs(value) >= candidate.threshold,
    );
    const scaled = unit ? value / unit.divisor : value;

    const formatter = new Intl.NumberFormat(options.locale ?? DEFAULT_LOCALE, {
        style: 'currency',
        currency: options.currency ?? 'EUR',
        minimumFractionDigits: 0,
        maximumFractionDigits: unit && Math.abs(scaled) < 10 ? 1 : 0,
    });

    return `${formatter.format(scaled)}${unit?.suffix ?? ''}`;
}

/**
 * `0.4` → `0.4/yr`, `12` → `12/yr`, `0.005` → `0.01/yr`.
 *
 * Unlike `formatRate` this drops trailing zeros, because a rate per year is
 * routinely a single significant digit and `0.40/yr` spends two characters of
 * a very short strip saying nothing. It still may exceed 1
 * (calculation-engine.md), so there is no upper clamp here.
 */
export function formatRateShort(
    value: number | null | undefined,
): string | null {
    if (value === null || value === undefined) return null;

    return `${Number(value.toFixed(2))}/yr`;
}

/*
 * `formatLevelCode()` was removed in W87.
 *
 * It turned a level's `code` into card text (`very_strong` → `very strong`),
 * and its only callers were the chart and tree cards. D44 made
 * `scale_levels.code` internal — derived from the label on create and then
 * frozen, so a catalogue fixture naming `moderate` keeps resolving after
 * somebody renames the band — which means the code and the label diverge on
 * the first rename. Both cards now render the band's **label**, and this
 * helper had no caller left.
 *
 * Deleted rather than kept, and this note is here instead of a deprecation:
 * a dead formatter whose name reads like the obvious way to put a level on a
 * card is an invitation to reintroduce the defect. `ReportOptions::$figures`
 * (CLAUDE.md) is what happens to a dead thing that is merely left alone —
 * four releases, and a control that stated something false about the document
 * it claimed to configure.
 */
