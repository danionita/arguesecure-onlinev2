<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounts\InstanceSettings;
use App\Domain\Assistant\Availability;
use App\Domain\Assistant\Health;
use App\Domain\Assistant\ModelCatalog;
use App\Domain\Assistant\RuntimeModel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAssistantSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin **AI assistant** tab (2.5.1).
 *
 * The switches used to sit at the bottom of Instance settings, inside a panel
 * that — on the ordinary deployment with no inference runtime — explained in a
 * paragraph of docker commands how to install one. Installation belongs in
 * the README, read by whoever holds the compose file; this tab says whether
 * the service is installed, and offers the switches only where it is.
 *
 * Nothing here can make the assistant work where the runtime is absent:
 * `Availability` still requires the deployment flag, these switches and a
 * healthy probe together, and UpdateAssistantSettingsRequest refuses a `true`
 * on a deployment without one.
 */
class AssistantController extends Controller
{
    public function edit(Availability $availability, RuntimeModel $runtimeModel, ModelCatalog $catalog): Response
    {
        $settings = InstanceSettings::all();
        $configured = Availability::configured();

        return Inertia::render('Admin/Assistant', [
            'settings' => [
                'assistant_enabled' => $settings['assistant_enabled'],
                'assistant_help_enabled' => $settings['assistant_help_enabled'],
                'assistant_suggestions_enabled' => $settings['assistant_suggestions_enabled'],
                'assistant_summary_enabled' => $settings['assistant_summary_enabled'],
            ],
            // Facts about the deployment, not settings: an admin cannot
            // change them from here (D25).
            'assistant' => [
                'configured' => $configured,
                'state' => $configured ? $availability->state() : null,
                // What the runtime says it loaded (D55), asked whether or not
                // an admin has switched the feature on: which model is
                // installed is worth knowing *before* deciding to enable it.
                // Nothing is asked of a deployment with no runtime.
                'model' => $configured ? $runtimeModel->current() : null,
                'presets' => $configured
                    ? array_values(array_map(fn (array $preset): array => [
                        'name' => $preset['name'],
                        'label' => $preset['label'],
                        'memory_gib' => $preset['memory_gib'],
                        'download_gb' => round($preset['bytes'] / 1e9, 1),
                    ], $catalog->presets()))
                    : [],
            ],
        ]);
    }

    public function update(UpdateAssistantSettingsRequest $request, Health $health, RuntimeModel $runtimeModel): RedirectResponse
    {
        InstanceSettings::update($request->validated());

        // The probe from thirty seconds ago was taken while the feature was
        // off, or while the container was still loading several gigabytes of
        // weights. Judging a freshly-enabled assistant by it would show
        // "runtime is not responding" to an admin who just started it
        // correctly, for as long as the TTL lasts. The same goes for which
        // model it reported: an admin who has just restarted the container
        // with the other preset should see that preset.
        $health->forget();
        $runtimeModel->forget();

        return redirect()->route('admin.assistant.edit');
    }
}
