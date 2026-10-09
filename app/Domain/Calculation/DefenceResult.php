<?php

namespace App\Domain\Calculation;

/**
 * One defence row (calculation-engine.md §13, §9, §10).
 *
 * For a defence active in this baseline's S (status matches the baseline):
 * `mc` is populated, `mcAdd` is null. For a defence *not* active in S
 * (todo/shelved against `current`; shelved against `target`): `mcAdd` is
 * populated, `mc` is null — mirroring the worked example's combined
 * "MC / MC add" cost-benefit column (§14). `attribution` (log-share, §9.5)
 * is only meaningful for active defences and is null otherwise.
 *
 * `costBucket` is one of `'priced' | 'free' | 'unpriced'` (§10). `cbr` is
 * null for both `free` (no cost to divide by — never render Infinity) and
 * `unpriced` (cost not estimated) buckets; the two are distinguished by
 * `costBucket`, not by any sentinel value in `cbr`.
 */
final readonly class DefenceResult
{
    public function __construct(
        public string $uuid,
        public string $status,
        public ?float $mc,
        public ?float $mcAdd,
        public ?float $attribution,
        public ?float $annualCost,
        public ?float $cbr,
        public ?float $rosi,
        public ?float $netBenefit,
        public string $costBucket,
    ) {}
}
