<?php

namespace App\Domain\Calculation;

/**
 * One risk row (calculation-engine.md §13). `impactValue`/`impactLevelCode`
 * describe the raw, baseline-invariant `Imp(r)` — not the pair-specific
 * retained impact `Imp'(r,a)`, which lives on PairResult because it can
 * differ per attack when impact-target defences differ per attack (§5.2).
 *
 * `annualProbability` is the §4 display-only secondary metric
 * `1 - exp(-Σ ARO'(a,S))`, summed over every attack linked to this risk
 * whose ARO is known (regardless of whether the paired impact is also
 * known) — it never enters a total, so it is not gated by pair valuation.
 */
final readonly class RiskResult
{
    public function __construct(
        public string $uuid,
        public float $ale,
        public ?float $impactValue,
        public ?string $impactLevelCode,
        public float $annualProbability,
        public int $pairsValued,
        public int $pairsTotal,
    ) {}
}
