<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounts\InstanceSettings;
use App\Domain\Scales\ScaleCalibrator;
use App\Domain\Scales\ScaleDescriber;
use App\Domain\Scales\ScaleSets;
use App\Domain\Scales\SystemScaleCalibrator;
use App\Domain\Structure\ScaleValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDefaultBandsRequest;
use App\Http\Requests\Admin\UpdateDefaultCurrencyRequest;
use App\Http\Requests\Assessment\UpdateAssessmentSettingsRequest;
use App\Models\Scale;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin **Defaults** tab (W87, item 10, PLAN-2.5.md D47; 2.5.1).
 *
 * ## Why this is not part of Instance settings
 *
 * Instance settings is about the *deployment* — whether assessments may be
 * public, how long a session lasts. Defaults are about the *content* a new
 * assessment starts with: the currency, and the impact, likelihood and
 * strength bands.
 *
 * ## Defaults, not global settings (2.5.1)
 *
 * The bands shown are the default system set's, and saving edits those rows.
 * Through 2.5 that reached every assessment that had never calibrated its own
 * scales — they were still reading the shared rows — and a warning box on the
 * page said so. Now `SystemScaleCalibrator` first gives each of those
 * assessments its own copy of the bands as they were, so a change here is
 * used only by assessments created afterwards and by "Reset to default" on an
 * assessment's settings, which is what a default is.
 *
 * There is one default set. `default_scale_calibration`, which chose between
 * seeded sets, was removed in the same change.
 */
class DefaultsController extends Controller
{
    public function edit(): Response
    {
        $settings = InstanceSettings::all();
        $key = ScaleSets::DEFAULT_KEY;

        return Inertia::render('Admin/Defaults', [
            'flashSuccess' => session('success'),
            'defaultCurrency' => $settings['default_currency'],
            'currencies' => UpdateAssessmentSettingsRequest::currencies(),
            'scales' => ScaleDescriber::forSystemSet($key),
            'topBandMultiplier' => ScaleDescriber::topBandMultiplier(),
            'missingKinds' => ScaleSets::missingKinds($key),
        ]);
    }

    public function update(UpdateDefaultBandsRequest $request, string $kind, SystemScaleCalibrator $calibrator): RedirectResponse
    {
        abort_unless(in_array($kind, ScaleCalibrator::kinds(), true), 404);

        $scale = Scale::query()
            ->whereNull('assessment_id')
            ->where('is_system', true)
            ->where('set_key', ScaleSets::DEFAULT_KEY)
            ->where('kind', $kind)
            ->first();

        if ($scale === null) {
            return back()->withErrors(['bands' => "This instance has no seeded default {$kind} scale to edit."]);
        }

        try {
            $calibrator->calibrate($scale, $request->bands());
        } catch (ScaleValidationException $e) {
            return back()->withErrors(['bands' => $e->getMessage()]);
        }

        return redirect()->route('admin.defaults.edit')->with(
            'success',
            ucfirst($kind).' default saved. It applies to assessments created from now on; existing assessments keep their bands.',
        );
    }

    public function updateCurrency(UpdateDefaultCurrencyRequest $request): RedirectResponse
    {
        InstanceSettings::update(['default_currency' => $request->currency()]);

        return redirect()->route('admin.defaults.edit')->with('success', 'Default currency saved.');
    }
}
