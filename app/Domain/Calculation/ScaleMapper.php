<?php

namespace App\Domain\Calculation;

use App\Domain\Graph\AssessmentGraph;
use InvalidArgumentException;

/**
 * Qualitative <-> quantitative conversion (calculation-engine.md §8).
 * Pure: takes and returns plain arrays/floats, no Eloquent.
 *
 * @phpstan-import-type ScaleLevelData from AssessmentGraph
 */
final class ScaleMapper
{
    /**
     * §8.1 — forward. The representative *is* the number; no computation.
     *
     * @param  ScaleLevelData  $level
     */
    public function toNumber(array $level): float
    {
        return $level['representative'];
    }

    /**
     * §8.2 — reverse (band inversion) with the §8.3 unit-agreement assertion.
     *
     * `$levels` must be every level of exactly one scale (any order — this
     * sorts by lower_bound itself, then binary-searches, per the spec's
     * explicit implementation instruction). Returns null for `$x === null`
     * (⊥ → null, rendered as a grey "?" chip, never the lowest band).
     *
     * @param  list<ScaleLevelData>  $levels
     * @return ScaleLevelData|null
     */
    public function toLevel(?float $x, array $levels, string $unit): ?array
    {
        if ($x === null) {
            return null;
        }

        if ($levels === []) {
            throw new InvalidArgumentException('Cannot band-map a value against an empty scale.');
        }

        $scaleUnit = $levels[0]['unit'];

        // §8.3 / §15 item 13: the most likely implementation mistake — mixing
        // up which scale a value belongs to — must be a thrown type error,
        // not a silently wrong band.
        if ($scaleUnit !== $unit) {
            throw new InvalidArgumentException(
                "Unit mismatch: caller supplied '{$unit}' but the scale is '{$scaleUnit}'."
            );
        }

        $sorted = $levels;
        usort($sorted, fn (array $a, array $b): int => $a['lower_bound'] <=> $b['lower_bound']);

        $count = count($sorted);

        // Binary search for the largest index whose lower_bound <= x. Bands
        // are contiguous by schema invariant, so that band contains x.
        $lo = 0;
        $hi = $count - 1;
        $found = -1;

        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);

            if ($sorted[$mid]['lower_bound'] <= $x) {
                $found = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        if ($found === -1) {
            throw new InvalidArgumentException("Value {$x} falls below the scale's lowest band.");
        }

        $isTop = $found === $count - 1;

        // Fraction scale's top band closes inclusively at 1 (schema.md §3.2
        // invariant 4); every other top band is open-ended.
        if ($isTop && $unit === 'fraction' && $x > 1.0) {
            throw new InvalidArgumentException("Value {$x} exceeds the fraction scale's inclusive upper bound of 1.");
        }

        return $sorted[$found];
    }
}
