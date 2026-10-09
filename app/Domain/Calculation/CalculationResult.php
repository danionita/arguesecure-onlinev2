<?php

namespace App\Domain\Calculation;

/**
 * One baseline's worth of computed results (calculation-engine.md §13).
 * `total` is the sum of `ale` over valued pairs only (§3, §6.2.1) — never
 * band-mapped (§8.3).
 */
final readonly class CalculationResult
{
    /**
     * @param  'inherent'|'current'|'target'  $baseline
     * @param  list<RiskResult>  $risks
     * @param  list<AttackResult>  $attacks
     * @param  list<PairResult>  $pairs
     * @param  list<DefenceResult>  $defences
     * @param  list<Warning>  $warnings
     */
    public function __construct(
        public string $baseline,
        public float $total,
        public array $risks,
        public array $attacks,
        public array $pairs,
        public array $defences,
        public Completeness $completeness,
        public array $warnings,
    ) {}
}
