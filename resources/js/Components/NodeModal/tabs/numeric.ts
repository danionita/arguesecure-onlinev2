/**
 * Shared blank-vs-zero handling for every numeric Values-tab input
 * (calculation-engine.md §6, CLAUDE.md "blank is not zero"). Input.vue's
 * native <input> always emits its value as a string — `''` when the user
 * has cleared the field, a numeric literal otherwise — so the empty string
 * is the one and only signal for "the user has not typed a number", and it
 * must become `null` (missing), never `0` (a known value meaning "cannot
 * happen"/"no loss"). A bare `Number('')` would silently coerce to `0` and
 * is deliberately never used here.
 */
export function stringToNullableNumber(value: string): number | null {
    if (value.trim() === '') return null;
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
}

export function nullableNumberToString(value: number | null): string {
    return value === null ? '' : String(value);
}
