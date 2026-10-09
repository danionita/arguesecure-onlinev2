<?php

namespace App\Domain\Structure;

use App\Models\ScaleLevel;
use Illuminate\Support\Collection;

/**
 * Enforces schema.md §3.2's band invariants on save — deliberately not
 * database-enforceable, because they require comparing sibling rows:
 *
 *   1. Ordinals are contiguous 1..n.
 *   2. level[i].upper_bound == level[i+1].lower_bound exactly. No gaps, no overlaps.
 *   3. level[1].lower_bound == 0.
 *   4. level[n].upper_bound IS NULL, except unit = 'fraction' where the top
 *      band closes at 1 (the lookup then treats it as [lower, 1] inclusive).
 *   5. lower_bound <= representative < upper_bound (top band: representative
 *      only needs lower_bound <= representative; the fraction top band's
 *      inclusive upper 1 permits representative == 1).
 *
 * Invariant 5 is load-bearing: it is the only thing that makes the
 * qualitative -> numeric -> qualitative round trip lossless.
 *
 * Every message names a band by its **label** (W87, D44). It used to name it
 * by `code`, which is internal, derived and frozen on rename — so the one
 * sentence a user read when a calibration was rejected pointed at a string the
 * band table no longer shows.
 */
class ScaleValidator
{
    /**
     * @param  iterable<ScaleLevel>  $levels
     * @return list<string> validation error messages; empty means the set is valid
     */
    public function validate(iterable $levels, string $unit): array
    {
        /** @var Collection<int, ScaleLevel> $sorted */
        $sorted = collect($levels)->sortBy('ordinal')->values();

        if ($sorted->isEmpty()) {
            return ['A scale must have at least one level.'];
        }

        $errors = [];
        $isFraction = $unit === 'fraction';
        $count = $sorted->count();

        foreach ($sorted as $index => $level) {
            $expectedOrdinal = $index + 1;
            if ((int) $level->ordinal !== $expectedOrdinal) {
                $errors[] = "Ordinals must be contiguous starting at 1; expected {$expectedOrdinal} at position {$index}, found {$level->ordinal}.";
            }
        }

        $first = $sorted->first();
        if ((float) $first->lower_bound !== 0.0) {
            $errors[] = "The first level ('{$first->label}') must start at lower_bound 0.";
        }

        foreach ($sorted as $index => $level) {
            $isLast = $index === $count - 1;

            if (! $isLast) {
                $next = $sorted[$index + 1];
                if ($level->upper_bound === null || (float) $level->upper_bound !== (float) $next->lower_bound) {
                    $errors[] = "Level '{$level->label}' upper_bound must equal the next level's lower_bound exactly (no gap or overlap).";
                }
            } elseif ($isFraction) {
                if ($level->upper_bound === null || (float) $level->upper_bound !== 1.0) {
                    $errors[] = "The top band of a fraction scale ('{$level->label}') must close at upper_bound 1.";
                }
            } elseif ($level->upper_bound !== null) {
                $errors[] = "The top band ('{$level->label}') must be open-ended (upper_bound NULL).";
            }

            $errors = [...$errors, ...$this->validateRepresentative($level, $isLast, $isFraction)];
        }

        return $errors;
    }

    /** @return list<string> */
    private function validateRepresentative(ScaleLevel $level, bool $isLast, bool $isFraction): array
    {
        $lower = (float) $level->lower_bound;
        $representative = (float) $level->representative;
        $errors = [];

        if ($representative < $lower) {
            $errors[] = "Level '{$level->label}' representative ({$representative}) must be >= its lower_bound ({$lower}).";
        }

        if ($level->upper_bound !== null) {
            $upper = (float) $level->upper_bound;
            // Every band is [lower, upper) except the fraction scale's top
            // band, which is [lower, 1] inclusive (schema.md §3.2 invariant 4).
            $inclusiveTop = $isLast && $isFraction;
            $violatesUpper = $inclusiveTop ? $representative > $upper : $representative >= $upper;

            if ($violatesUpper) {
                $errors[] = "Level '{$level->label}' representative ({$representative}) must fall within its band [{$lower}, {$upper}".($inclusiveTop ? ']' : ')').'.';
            }
        }

        return $errors;
    }

    /** @param  iterable<ScaleLevel>  $levels */
    public function assertValid(iterable $levels, string $unit): void
    {
        $errors = $this->validate($levels, $unit);

        if ($errors !== []) {
            throw new ScaleValidationException(implode(' ', $errors));
        }
    }
}
