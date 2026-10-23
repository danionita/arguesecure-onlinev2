<?php

namespace App\Http\Controllers;

use App\Domain\Scales\AssessmentScaleApplier;
use App\Domain\Structure\ScaleValidationException;
use App\Http\Requests\Assessment\UpdateAssessmentScalesRequest;
use App\Models\Assessment;
use Illuminate\Http\RedirectResponse;

/**
 * Saving an assessment's scales (W65; relocated in W87, ui.md §8,
 * PLAN-2.5.md D46; one request for all three kinds since W126, D94).
 *
 * ## There is no screen here
 *
 * The three editors live on Assessment settings, which builds the payload
 * through the same `ScaleDescriber` the admin Defaults tab uses. W87 deleted
 * the page and its GET route rather than leaving it reachable (D46); W126
 * likewise deleted the per-kind save routes and "Reset to default" — choosing
 * a scale in the dropdown fills the editors, and Save stores them.
 *
 * ## `manage`, and the spec says so outright
 *
 * calculation-engine.md §8.4 pitfall 6: "Editing a scale changes every number
 * computed from it. Bump `calc_version` and require the `manager` role." There
 * is no lower-gated route to the same effect, and no undo entry. The check is
 * `UpdateAssessmentScalesRequest::authorize()`.
 */
class ScaleCalibrationController extends Controller
{
    public function update(UpdateAssessmentScalesRequest $request, Assessment $assessment, AssessmentScaleApplier $applier): RedirectResponse
    {
        try {
            $applied = $applier->apply($assessment, $request->bands());
        } catch (ScaleValidationException $e) {
            // The five invariants, as the sentences ScaleValidator writes.
            // The editor keeps bands contiguous as they are edited, so reaching
            // this means something the UI could not prevent. Nothing was
            // written for any kind.
            return back()->withErrors(['bands' => $e->getMessage()]);
        }

        $message = 'Scales saved.';

        if ($applied->clearedValues > 0) {
            $message .= " {$applied->clearedValues} stored value(s) used a band you removed and are now unset — they will need choosing again.";
        }

        return redirect()->route('assessments.settings.edit', $assessment->uuid)->with('success', $message);
    }
}
