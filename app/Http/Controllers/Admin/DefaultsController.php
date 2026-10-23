<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounts\InstanceSettings;
use App\Domain\Audit\SecurityEvent;
use App\Domain\Audit\SecurityLog;
use App\Domain\Scales\ScaleDescriber;
use App\Domain\Scales\ScaleSets;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDefaultCurrencyRequest;
use App\Http\Requests\Assessment\UpdateAssessmentSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin **Defaults** tab (W87, item 10, PLAN-2.5.md D47; 2.5.1; W126).
 *
 * ## Why this is not part of Instance settings
 *
 * Instance settings is about the *deployment* — whether assessments may be
 * public, how long a session lasts. Defaults are about the *content* a new
 * assessment starts with: the currency and the scales.
 *
 * ## Scales (W126)
 *
 * An admin keeps any number of named scales; the writes are in
 * `ScaleController`. A change to one is used only by assessments created from
 * it afterwards and by assessments that pick it again in their settings: every
 * assessment still on the shared rows is first given its own copy
 * (`SystemScaleCalibrator`).
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
            'scaleSets' => ScaleDescriber::allSets(),
            'topBandMultiplier' => ScaleDescriber::topBandMultiplier(),
            'missingKinds' => ScaleSets::missingKinds($key),
        ]);
    }

    public function updateCurrency(UpdateDefaultCurrencyRequest $request): RedirectResponse
    {
        InstanceSettings::update(['default_currency' => $request->currency()]);

        SecurityLog::record(SecurityEvent::SettingsUpdated, $request->user(), null, ['settings' => ['default_currency' => $request->currency()]]);

        return redirect()->route('admin.defaults.edit')->with('success', 'Default currency saved.');
    }
}
