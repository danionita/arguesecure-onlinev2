<?php

namespace App\Domain\Calculation;

/**
 * One (risk, attack) pair row (calculation-engine.md §13). `aroResidual` is
 * attack-level (identical for every risk sharing the attack) and
 * `impactResidual` is pair-level (varies per risk because Imp(r) does), but
 * both are surfaced here because a pair row is the natural drill-down unit.
 *
 * `aroResidual`/`impactResidual` are populated whenever the *underlying*
 * value (ARO(a) / Imp(r) respectively) is known, even if the pair as a whole
 * is unvalued because the other side is missing — a partially-known pair
 * still shows what it can. `ale` is null exactly when `valued` is false.
 */
final readonly class PairResult
{
    public function __construct(
        public string $riskUuid,
        public string $attackUuid,
        public ?float $aroResidual,
        public ?float $impactResidual,
        public ?float $ale,
        public bool $valued,
    ) {}
}
