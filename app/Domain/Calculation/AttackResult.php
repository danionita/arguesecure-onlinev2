<?php

namespace App\Domain\Calculation;

/**
 * One attack row (calculation-engine.md §13). `factor` is `factor(a,S)`
 * (§3, §7) — the full multiplicative reduction from every active defence on
 * this attack, independent of reduction_target. `aroResidual` is the §5
 * step-2 value (likelihood-target defences only); it is what gets
 * band-mapped for `aroLevelCode` (§11.2 step 8), not `factor`.
 */
final readonly class AttackResult
{
    public function __construct(
        public string $uuid,
        public float $ale,
        public ?float $aroBase,
        public ?float $aroResidual,
        public ?string $aroLevelCode,
        public float $factor,
        public int $riskCount,
    ) {}
}
