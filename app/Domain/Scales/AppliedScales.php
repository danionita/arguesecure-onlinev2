<?php

namespace App\Domain\Scales;

/** What `AssessmentScaleApplier` did. */
final readonly class AppliedScales
{
    public function __construct(public int $clearedValues, public ?string $scaleSetKey) {}
}
