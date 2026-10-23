<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\SecurityEvent;
use App\Domain\Audit\SecurityLog;
use App\Domain\Scales\ScaleSetManager;
use App\Domain\Structure\ScaleValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RenameScaleRequest;
use App\Http\Requests\Admin\StoreScaleRequest;
use App\Http\Requests\Admin\UpdateScaleBandsRequest;
use App\Models\ScaleSet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Admin-defined scales (W126, D92-D94): create, save, rename, delete.
 *
 * Inside the `admin` middleware group, so gated on `is_admin` like the rest of
 * the tab; each FormRequest repeats the check, as every admin request does.
 */
class ScaleController extends Controller
{
    public function store(StoreScaleRequest $request, ScaleSetManager $scales): RedirectResponse
    {
        try {
            $set = $scales->create($request->string('name')->toString(), $request->string('from')->toString());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        SecurityLog::record(SecurityEvent::SettingsUpdated, $request->user(), null, ['settings' => ['scale_created' => $set->key]]);

        return redirect()->route('admin.defaults.edit')->with('success', "Scale “{$set->name}” created.");
    }

    public function update(UpdateScaleBandsRequest $request, ScaleSet $scaleSet, ScaleSetManager $scales): RedirectResponse
    {
        try {
            $scales->save($scaleSet, $request->bands());
        } catch (ScaleValidationException $e) {
            // Keyed by scale, because the page lists several and the message
            // belongs under the one that was saved.
            return back()->withErrors(["bands_{$scaleSet->key}" => $e->getMessage()]);
        }

        SecurityLog::record(SecurityEvent::SettingsUpdated, $request->user(), null, ['settings' => ['scale' => $scaleSet->key]]);

        return redirect()->route('admin.defaults.edit')->with(
            'success',
            "“{$scaleSet->name}” saved. It applies to assessments created from it from now on; existing assessments keep their bands.",
        );
    }

    public function rename(RenameScaleRequest $request, ScaleSet $scaleSet, ScaleSetManager $scales): RedirectResponse
    {
        try {
            $scales->rename($scaleSet, $request->string('name')->toString());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(["name_{$scaleSet->key}" => $e->getMessage()]);
        }

        SecurityLog::record(SecurityEvent::SettingsUpdated, $request->user(), null, ['settings' => ['scale_renamed' => $scaleSet->key]]);

        return redirect()->route('admin.defaults.edit')->with('success', 'Scale renamed.');
    }

    public function destroy(Request $request, ScaleSet $scaleSet, ScaleSetManager $scales): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        try {
            $kept = $scales->delete($scaleSet);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(["bands_{$scaleSet->key}" => $e->getMessage()]);
        }

        SecurityLog::record(SecurityEvent::SettingsUpdated, $request->user(), null, ['settings' => ['scale_deleted' => $scaleSet->key]]);

        return redirect()->route('admin.defaults.edit')->with(
            'success',
            $kept === 0
                ? 'Scale deleted.'
                : "Scale deleted. {$kept} assessment(s) that were using it keep their own copy of its bands.",
        );
    }
}
