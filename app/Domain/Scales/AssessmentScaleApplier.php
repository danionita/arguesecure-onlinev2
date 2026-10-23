<?php

namespace App\Domain\Scales;

use App\Domain\Structure\ScaleValidationException;
use App\Models\Assessment;
use App\Observers\Concerns\TriggersRecalculation;
use Illuminate\Support\Facades\DB;

/**
 * Stores bands for an assessment's scales together (W126, D94).
 *
 * One transaction around `ScaleCalibrator::calibrate()` per kind: a rejected
 * likelihood leaves the impact bands unwritten too, where three requests left
 * a half-saved scale. The server also decides which admin scale the result
 * equals (`ScaleSetMatcher`), so a hand-made request cannot label Custom bands
 * with a scale's name.
 */
final class AssessmentScaleApplier
{
    public function __construct(private readonly ScaleCalibrator $calibrator) {}

    /**
     * @param  array<string, list<array<string, mixed>>>  $bandsByKind  one to three kinds
     *
     * @throws ScaleValidationException when any kind's bands break an invariant; nothing is written
     */
    public function apply(Assessment $assessment, array $bandsByKind): AppliedScales
    {
        return DB::transaction(fn (): AppliedScales => TriggersRecalculation::coalesce(function () use ($assessment, $bandsByKind): AppliedScales {
            // Two managers saving at once would otherwise label the result with
            // a scale the final bands do not equal.
            DB::table('assessments')->where('id', $assessment->id)->lockForUpdate()->first();

            $cleared = 0;

            foreach (ScaleCalibrator::kinds() as $kind) {
                if (! isset($bandsByKind[$kind])) {
                    continue;
                }

                // Reloaded: a first calibration repoints the column at a copy.
                $assessment->refresh();
                $cleared += $this->calibrator->calibrate($assessment, $kind, $bandsByKind[$kind])->clearedValues;
            }

            $assessment->refresh();
            $assessment->scale_set_key = ScaleSetMatcher::keyFor($assessment);
            $assessment->save();

            return new AppliedScales(clearedValues: $cleared, scaleSetKey: $assessment->scale_set_key);
        }));
    }
}
