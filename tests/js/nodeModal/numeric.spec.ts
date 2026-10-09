import { describe, expect, it } from 'vitest';
import {
    nullableNumberToString,
    stringToNullableNumber,
} from '../../../resources/js/Components/NodeModal/tabs/numeric';

/**
 * The Values tab's blank-vs-zero contract (calculation-engine.md §6.1,
 * CLAUDE.md "blank is not zero"): an empty impact/likelihood/strength input
 * must become `null` (missing — excluded from every total), never `0` (a
 * known value meaning "cannot happen"/"no loss"). This is the one piece of
 * client logic every numeric field in RiskValuesPanel, AttackValuesPanel,
 * DefenceValuesPanel and LinksTab shares, so it is tested in isolation
 * rather than once per component.
 */
describe('stringToNullableNumber', () => {
    it('treats an empty string as missing, not zero', () => {
        expect(stringToNullableNumber('')).toBeNull();
    });

    it('treats whitespace-only input as missing', () => {
        expect(stringToNullableNumber('   ')).toBeNull();
    });

    it('preserves an explicit zero as a known value', () => {
        expect(stringToNullableNumber('0')).toBe(0);
    });

    it('parses a positive decimal', () => {
        expect(stringToNullableNumber('0.5')).toBe(0.5);
    });

    it('parses a likelihood rate above 1 without rejecting it (schema.md §3.4)', () => {
        expect(stringToNullableNumber('3.16')).toBeCloseTo(3.16);
    });

    it('treats a non-numeric string as missing rather than NaN', () => {
        expect(stringToNullableNumber('abc')).toBeNull();
    });
});

describe('nullableNumberToString', () => {
    it('round-trips null back to an empty string, never "0"', () => {
        expect(nullableNumberToString(null)).toBe('');
    });

    it('round-trips a known zero back to "0", not blank', () => {
        expect(nullableNumberToString(0)).toBe('0');
    });

    it('round-trips through stringToNullableNumber losslessly', () => {
        for (const value of [0, 0.5, 1, 3.16, 1_000_000]) {
            expect(stringToNullableNumber(nullableNumberToString(value))).toBe(
                value,
            );
        }
    });
});
