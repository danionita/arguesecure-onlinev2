/**
 * The short-form helpers the chart card's value row is built on (W69).
 *
 * These live with the chart rather than with the rest of `format.ts` because
 * the chart is the only thing that abbreviates: everywhere with room for the
 * exact figure — the list card, the modal's Statistics tab, the report — uses
 * the full-precision helpers, and a short form leaking into one of those would
 * be a wrong number rather than a small one. The boundaries are pinned here
 * because they are where an abbreviation stops being a rounding and starts
 * being a different figure.
 */
import { describe, expect, it } from 'vitest';
import {
    formatMoneyShort,
    formatRateShort,
} from '../../../resources/js/Components/UI/format';

describe('formatMoneyShort', () => {
    it('abbreviates at each thousand, keeping the currency symbol', () => {
        expect(formatMoneyShort(400_000)).toBe('€400k');
        expect(formatMoneyShort(2_400_000)).toBe('€2.4M');
        expect(formatMoneyShort(3_000_000_000)).toBe('€3B');
    });

    it('leaves anything under a thousand alone', () => {
        expect(formatMoneyShort(750)).toBe('€750');
        expect(formatMoneyShort(999)).toBe('€999');
        expect(formatMoneyShort(1_000)).toBe('€1k');
    });

    /**
     * One decimal below ten and none above it, so the string never grows past
     * six characters — the width the bottom row can spare — while still
     * distinguishing €1.2M from €1.9M, which a bare "€1M" would not.
     */
    it('keeps one decimal only where it says something', () => {
        expect(formatMoneyShort(1_250_000)).toBe('€1.3M');
        expect(formatMoneyShort(412_000)).toBe('€412k');
        expect(formatMoneyShort(1_000_000)).toBe('€1M');
    });

    it('honours the assessment currency rather than assuming euros', () => {
        // "US$" rather than "$": DEFAULT_LOCALE is en-GB, which disambiguates
        // a foreign dollar. The same thing `formatMoney` has always done.
        expect(formatMoneyShort(50_000, { currency: 'USD' })).toBe('US$50k');
    });

    /**
     * A missing value is excluded from the total (calculation-engine.md), so
     * it must never come back as a string a card could print as `€0`.
     */
    it('returns null for a missing amount, never zero', () => {
        expect(formatMoneyShort(null)).toBeNull();
        expect(formatMoneyShort(undefined)).toBeNull();
        expect(formatMoneyShort(0)).toBe('€0');
    });
});

describe('formatRateShort', () => {
    it('drops trailing zeros a very short strip cannot afford', () => {
        expect(formatRateShort(0.4)).toBe('0.4/yr');
        expect(formatRateShort(12)).toBe('12/yr');
    });

    /** Likelihood is a rate, not a probability, and may exceed 1. */
    it('does not clamp at 1', () => {
        expect(formatRateShort(30)).toBe('30/yr');
    });

    it('returns null for a missing rate', () => {
        expect(formatRateShort(null)).toBeNull();
    });
});

/*
 * The `formatLevelCode` block was removed with the function in W87 (D44).
 *
 * A card renders a band's **label** now, never its code, so what used to be
 * asserted here — that `very_strong` becomes "very strong" — is a behaviour
 * the application no longer has. The guards that replaced it are
 * `tests/js/chart/ChartView.spec.ts` and
 * `tests/js/scales/NodeCardLevelLabel.spec.ts`, both of which use a fixture
 * whose label and code deliberately differ.
 */
