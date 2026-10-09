<?php

namespace App\Observers;

use App\Models\Assessment;
use App\Models\ScaleLevel;
use App\Observers\Concerns\TriggersRecalculation;

/**
 * Closes the one invalidation gap TriggersRecalculation's own docblock
 * admitted to: "scale changes are schema.md territory this package does not
 * own a model for". Node, risk_attack and attack_defence mutations bumped
 * `calc_version`; a scale recalibration did not.
 *
 * That was harmless only for as long as nothing read the calculation cache.
 * A level's `representative` is the number every qualitative value resolves
 * to (calculation-engine.md), so editing one changes the computed result of
 * every assessment calibrated against that scale — while leaving
 * `calc_version` untouched, and therefore leaving the cache key pointing at
 * figures computed under the old calibration. Wiring the read path without
 * this observer would have served stale money amounts after a
 * recalibration, which is the specific failure the cache was held back for.
 *
 * A system scale (`assessment_id IS NULL`) is shared, so every assessment
 * referencing it through any of its three scale FKs is bumped. That is a
 * deliberately blunt instrument: recalibration is rare and administrative,
 * whereas a missed invalidation is silent and shows someone a number that
 * has already gone into a report. The bump is one indexed UPDATE per
 * affected assessment.
 *
 * `saved` rather than `updated` so a level *added* to an existing scale —
 * which shifts band boundaries and therefore the reverse number-to-level
 * mapping — counts too. `deleted` for the same reason.
 */
class ScaleLevelObserver
{
    public function saved(ScaleLevel $level): void
    {
        $this->bumpAffectedAssessments($level);
    }

    public function deleted(ScaleLevel $level): void
    {
        $this->bumpAffectedAssessments($level);
    }

    private function bumpAffectedAssessments(ScaleLevel $level): void
    {
        Assessment::query()
            ->where('impact_scale_id', $level->scale_id)
            ->orWhere('likelihood_scale_id', $level->scale_id)
            ->orWhere('strength_scale_id', $level->scale_id)
            ->pluck('id')
            ->each(function (int $assessmentId): void {
                TriggersRecalculation::bumpCalcVersionAndDebounce($assessmentId);
            });
    }
}
