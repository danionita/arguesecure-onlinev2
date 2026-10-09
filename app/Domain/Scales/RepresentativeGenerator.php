<?php

namespace App\Domain\Scales;

/**
 * The default `representative` for a band (schema.md §3.2.1, W65).
 *
 * The representative is the number every qualitative value actually resolves
 * to — `ScaleMapper::toNumber()` returns it unchanged — so it is not a display
 * detail. Picking it badly makes every figure in the report wrong in a way no
 * warning would catch, which is why the rule is specified rather than left to
 * whoever builds the screen.
 *
 * ## Why the rule differs by unit
 *
 * - **currency and rate_per_year** — the geometric mean `√(lower × upper)`.
 *   Loss and frequency distributions are heavy-tailed, so log-uniform is the
 *   right uninformative prior *within* a band. The arithmetic midpoint of
 *   10k–100k is 55,000, which is nearly six times the low end and barely half
 *   the high end; the geometric mean, 31,623, sits in the middle of the range
 *   as someone reasoning in orders of magnitude actually perceives it.
 * - **fraction** — the arithmetic midpoint. A fraction is bounded on both
 *   sides and roughly uniform inside a band, and a geometric mean would
 *   collapse toward zero for the low bands where most controls sit.
 *
 * Two edge cases the geometric mean cannot express, both specified:
 * `lower = 0` makes it zero regardless of the upper bound, so the rule is
 * `upper / 4`; and the open top band has no upper bound at all, so it is
 * `lower × arguesecure.scales.top_band_multiplier`.
 *
 * **Everything this produces is a pre-fill and nothing more.** It is offered
 * when a band is created or its bounds move, the user may overwrite it, and
 * `ScaleValidator` re-checks invariant 5 on save whatever the number came
 * from. This class is never the last word on a representative.
 */
final class RepresentativeGenerator
{
    public function __construct(private readonly float $topBandMultiplier) {}

    public static function fromConfig(): self
    {
        return new self((float) config('arguesecure.scales.top_band_multiplier', 3));
    }

    /**
     * @param  string  $unit  the scale's unit — `currency`, `rate_per_year` or `fraction`
     * @param  ?float  $upper  null for the open top band
     */
    public function for(string $unit, float $lower, ?float $upper): float
    {
        if ($unit === 'fraction') {
            // A fraction scale's top band closes at 1 (invariant 4), so the
            // null-upper case below cannot arise here. Guarded anyway rather
            // than assumed: this method is public, and a caller reaching it
            // with a malformed band should get a defensible number rather than
            // a division by null.
            return $upper === null
                ? min(1.0, $lower + (1.0 - $lower) / 2)
                : ($lower + $upper) / 2;
        }

        if ($upper === null) {
            return $lower * $this->topBandMultiplier;
        }

        if ($lower <= 0.0) {
            // √(0 × upper) is 0, which would sit *on* the lower bound and make
            // every "negligible" value resolve to nothing at all. A quarter of
            // the upper bound keeps it inside the band and below the midpoint,
            // which is where a log-uniform prior puts it.
            return $upper / 4;
        }

        return sqrt($lower * $upper);
    }
}
