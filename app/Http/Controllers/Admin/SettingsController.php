<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounts\InstanceSettings;
use App\Domain\Audit\SecurityEvent;
use App\Domain\Audit\SecurityLog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(): Response
    {
        // The assistant's switches have their own tab since 2.5.1
        // (AssistantController), and `default_scale_calibration` is gone —
        // the Defaults tab edits the one default set directly.
        return Inertia::render('Admin/Settings', [
            'settings' => [
                'public_assessments_allowed' => InstanceSettings::all()['public_assessments_allowed'],
                'session_lifetime_minutes' => InstanceSettings::all()['session_lifetime_minutes'],
            ],
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        InstanceSettings::update($validated);

        SecurityLog::record(SecurityEvent::SettingsUpdated, $request->user(), null, ['settings' => $validated]);

        return redirect()->route('admin.settings.edit');
    }
}
