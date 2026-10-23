<?php

namespace App\Domain\Scales;

/** What `AssessmentScaleApplier` did (W126). */
final readonly class AppliedScales
{
    public function __construct(public int $clearedValues, public ?string $scaleSetKey) {}
}
