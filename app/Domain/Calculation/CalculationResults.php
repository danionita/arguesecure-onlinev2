<?php

namespace App\Domain\Calculation;

/**
 * The container of all three baselines (calculation-engine.md §2, §11.1),
 * computed together in a single pass over one AssessmentGraph.
 */
final readonly class CalculationResults
{
    public function __construct(
        public CalculationResult $inherent,
        public CalculationResult $current,
        public CalculationResult $target,
    ) {}
}
