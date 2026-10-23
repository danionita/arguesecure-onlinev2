<?php

namespace App\Http\Requests\Assessment;

use App\Http\Requests\Admin\UpdateScaleBandsRequest;
use App\Models\Assessment;

/**
 * Saving an assessment's bands (W126). `manage`, as it has been since W65:
 * editing a scale moves every figure computed from it
 * (calculation-engine.md §8.4 pitfall 6) and has no undo entry.
 */
class UpdateAssessmentScalesRequest extends UpdateScaleBandsRequest
{
    public function authorize(): bool
    {
        $assessment = $this->route('assessment');

        return $assessment instanceof Assessment && (bool) $this->user()?->can('manage', $assessment);
    }
}
