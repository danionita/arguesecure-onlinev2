<?php

namespace App\Http\Controllers;

use App\Domain\Scales\ScaleCalibrator;
use App\Domain\Scales\ScaleDescriber;
use App\Domain\Scales\ScaleSets;
use App\Domain\Structure\ScaleValidationException;
use App\Models\Assessment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Saving a calibration for one of an assessment's three scales (W65; relocated
 * in W87, ui.md §8, PLAN-2.5.md D46).
 *
 * ## There is no screen here any more
 *
 * W65 gave this controller a page of its own at `/assessments/{uuid}/scales`.
 * W87 moved the three cards onto Assessment settings, where the rest of "what
 * do these values mean" already lives, and **deleted the page and its GET
 * route** rather than leaving it reachable. D46: a screen that still answers
 * its URL after its entry points are removed is how a second, divergent editor
 * survives a consolidation. `AssessmentSettingsController::edit()` now builds
 * the payload, through the same `ScaleDescriber` the admin Defaults tab uses.
 *
 * What is left is the two writes: save a scale, and reset one to the instance
 * default.
 *
 * ## `manage`, and the spec says so outright
 *
 * calculation-engine.md §8.4 pitfall 6: "Editing a scale changes every number
 * computed from it. Bump `calc_version` and require the `manager` role." Unlike
 * the snapshot restore in W61 — where `manage` looked right and was wrong,
 * because undo reached the same place through `update` — there is no lower-
 * gated route to the same effect here. An editor can change one node's value; a
 * recalibration silently moves every figure in the assessment and every report
 * already computed from it, and there is no undo entry for it.
 *
 * No FormRequest class: this package owns this controller and not
 * `app/Http/Requests/**`, matching LockController's and SnapshotController's
 * own documented reason for validating inline.
 */
class ScaleCalibrationController extends Controller
{
    public function update(Request $request, Assessment $assessment, string $kind, ScaleCalibrator $calibrator): RedirectResponse
    {
        $this->authorizeManage($request, $assessment);
        $this->assertKind($kind);

        $validated = $request->validate([
            'bands' => ['required', 'array', 'min:1', 'max:12'],
            /*
             * Nullable, and never rendered (W87, D44).
             *
             * The editor has no code field. An existing band carries back the
             * code it was sent, which is what freezes a code across a rename;
             * a band the editor has just created sends `null` and
             * `LevelCodeDeriver::assign()` derives one from the label.
             *
             * The regex stays because a carried code still has to match the
             * shape the column and the catalogue rely on — this rule is what
             * stops a hand-made request writing a code no fixture could name.
             * A code that matches the shape but names no level on this scale
             * is treated as a new band, not an error; see LevelCodeDeriver::assign().
             */
            'bands.*.code' => ['nullable', 'string', 'max:32', 'regex:/^[a-z][a-z0-9_]*$/'],
            'bands.*.label' => ['required', 'string', 'max:64'],
            /*
             * No `color` rule, because there is no colour input (D45). The
             * column keeps its `#9CA3AF` default and nothing outside this
             * calibrator ever read it. Accepting the field anyway would leave
             * a writable surface for a value the interface has decided not to
             * have.
             */
            'bands.*.lower_bound' => ['required', 'numeric', 'min:0'],
            // Nullable is the open top band. Every other band's bound is
            // checked against its neighbour by ScaleValidator, which is where
            // the invariants live — this rule only says what shape a number is.
            'bands.*.upper_bound' => ['nullable', 'numeric', 'min:0'],
            'bands.*.representative' => ['required', 'numeric', 'min:0'],
        ]);

        return $this->applyBands($assessment, $kind, $validated['bands'], $calibrator);
    }

    /**
     * Puts one scale back to the instance default (W87, item 10; D47).
     *
     * The bands come from the default system set — the one a new assessment
     * is created with, and the one the admin Defaults tab edits (2.5.1: the
     * only default; `default_scale_calibration` is gone). So
     * "default" means one thing on both screens rather than naming a constant
     * on one and a setting on the other.
     *
     * It goes through `calibrate()` like any other save, which matters: the
     * assessment keeps its own scale (this does **not** repoint it back at the
     * shared system row, which would make a later admin edit silently move
     * this assessment's figures), stored values are re-pointed by code, and a
     * band the default set does not have is cleared and counted.
     */
    public function reset(Request $request, Assessment $assessment, string $kind, ScaleCalibrator $calibrator): RedirectResponse
    {
        $this->authorizeManage($request, $assessment);
        $this->assertKind($kind);

        $default = ScaleDescriber::forSystemSet(ScaleSets::DEFAULT_KEY)[$kind] ?? null;

        if ($default === null) {
            // The instance has no seeded set to reset to. Said rather than
            // 500ing: it is a deployment that has migrated but not seeded, and
            // the person reading it can act on that sentence.
            return back()->withErrors(['bands' => 'This instance has no seeded default scale to reset to.']);
        }

        /** @var list<array<string, mixed>> $bands */
        $bands = $default['bands'];

        return $this->applyBands($assessment, $kind, $bands, $calibrator, reset: true);
    }

    /**
     * @param  list<array<string, mixed>>  $bands
     */
    private function applyBands(Assessment $assessment, string $kind, array $bands, ScaleCalibrator $calibrator, bool $reset = false): RedirectResponse
    {
        try {
            $outcome = $calibrator->calibrate($assessment, $kind, $bands);
        } catch (ScaleValidationException $e) {
            // The five invariants, reported as the sentences ScaleValidator
            // writes. The editor keeps bands contiguous as they are edited, so
            // reaching this means something the UI could not prevent.
            return back()->withErrors(['bands' => $e->getMessage()]);
        }

        return redirect()
            ->route('assessments.settings.edit', $assessment->uuid)
            ->with('success', $this->summarise($kind, $outcome->cloned, $outcome->clearedValues, $reset));
    }

    private function summarise(string $kind, bool $cloned, int $clearedValues, bool $reset): string
    {
        $parts = [$reset
            ? ucfirst($kind).' scale reset to the instance default.'
            : ucfirst($kind).' scale saved.'];

        if ($cloned) {
            // Said, not assumed. Before this save the assessment shared the
            // system default with every other assessment on the instance; now
            // it has its own copy and will not follow later changes to that
            // default. Someone who thought they were editing the shared one
            // needs to learn it here.
            $parts[] = 'This assessment now has its own copy of the scale, so it no longer follows the instance default.';
        }

        if ($clearedValues > 0) {
            $parts[] = "{$clearedValues} stored value(s) used a band you removed and are now unset — they will need choosing again.";
        }

        return implode(' ', $parts);
    }

    /**
     * The kind comes from the URL, so it is checked against the fixed list
     * before it reaches anything that could use it to pick a column. A 404
     * rather than a 422: `/scales/colour` is a route that does not exist, not
     * a form filled in wrongly.
     */
    private function assertKind(string $kind): void
    {
        abort_unless(in_array($kind, ScaleCalibrator::kinds(), true), 404);
    }

    private function authorizeManage(Request $request, Assessment $assessment): void
    {
        if (Gate::forUser($request->user())->denies('manage', $assessment)) {
            abort(403);
        }
    }
}
