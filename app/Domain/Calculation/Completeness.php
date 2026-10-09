<?php

namespace App\Domain\Calculation;

/**
 * The §6.3 completeness block, one per baseline (`total` and `is_partial`
 * live on the enclosing CalculationResult instead of being duplicated here).
 *
 * `linksMissingStrength` and `implausibleStrength` are scoped to links whose
 * defence participates in *this* baseline's defence set S, so they differ
 * across the three CalculationResults. Every other field is structural
 * (depends only on the graph, not on which defences are active) and is
 * therefore identical across baselines.
 */
final readonly class Completeness
{
    /**
     * @param  list<string>  $risksMissingImpact
     * @param  list<string>  $attacksMissingLikelihood
     * @param  list<array{attack_uuid: string, defence_uuid: string}>  $linksMissingStrength
     * @param  list<string>  $risksWithoutAttacks
     * @param  list<string>  $orphanAttacks
     * @param  list<string>  $orphanDefences
     * @param  list<array{uuid: string, risk_count: int}>  $sharedAttacks
     * @param  list<array{attack_uuid: string, defence_uuid: string}>  $implausibleStrength
     */
    public function __construct(
        public bool $isPartial,
        public int $pairsTotal,
        public int $pairsValued,
        public float $coverage,
        public array $risksMissingImpact,
        public array $attacksMissingLikelihood,
        public array $linksMissingStrength,
        public array $risksWithoutAttacks,
        public array $orphanAttacks,
        public array $orphanDefences,
        public array $sharedAttacks,
        public array $implausibleStrength,
    ) {}
}
