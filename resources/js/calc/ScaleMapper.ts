import type { ScaleLevel } from './types';

/**
 * Qualitative <-> quantitative conversion (calculation-engine.md §8). Pure:
 * takes and returns plain values, no framework/DOM dependency. Mirrors
 * App\Domain\Calculation\ScaleMapper exactly, including the §8.3 unit-
 * agreement assertion and the §8.2 `[lower, upper)` band-inversion search.
 */
export class ScaleMapper {
    /** §8.1 — forward. The representative *is* the number; no computation. */
    toNumber(level: ScaleLevel): number {
        return level.representative;
    }

    /**
     * §8.2 — reverse (band inversion) with the §8.3 unit-agreement
     * assertion. `levels` must be every level of exactly one scale (any
     * order — this sorts by lowerBound itself, then binary-searches).
     * Returns null for `x === null` (⊥ → null, never the lowest band).
     */
    toLevel(
        x: number | null,
        levels: ScaleLevel[],
        unit: string,
    ): ScaleLevel | null {
        if (x === null) {
            return null;
        }

        if (levels.length === 0) {
            throw new Error('Cannot band-map a value against an empty scale.');
        }

        const scaleUnit = levels[0].unit;

        // §8.3 / §15 item 13: the most likely implementation mistake —
        // mixing up which scale a value belongs to — must throw, not
        // silently produce a wrong band.
        if (scaleUnit !== unit) {
            throw new Error(
                `Unit mismatch: caller supplied '${unit}' but the scale is '${scaleUnit}'.`,
            );
        }

        const sorted = [...levels].sort((a, b) => a.lowerBound - b.lowerBound);
        const count = sorted.length;

        // Binary search for the largest index whose lowerBound <= x. Bands
        // are contiguous by schema invariant, so that band contains x.
        let lo = 0;
        let hi = count - 1;
        let found = -1;

        while (lo <= hi) {
            const mid = Math.floor((lo + hi) / 2);

            if (sorted[mid].lowerBound <= x) {
                found = mid;
                lo = mid + 1;
            } else {
                hi = mid - 1;
            }
        }

        if (found === -1) {
            throw new Error(`Value ${x} falls below the scale's lowest band.`);
        }

        const isTop = found === count - 1;

        // Fraction scale's top band closes inclusively at 1 (schema.md §3.2
        // invariant 4); every other top band is open-ended.
        if (isTop && unit === 'fraction' && x > 1.0) {
            throw new Error(
                `Value ${x} exceeds the fraction scale's inclusive upper bound of 1.`,
            );
        }

        return sorted[found];
    }
}
