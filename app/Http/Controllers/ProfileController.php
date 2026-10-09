<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\SessionRegistry;
use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => session('status'),
            'sessions' => SessionRegistry::forUser($user->id, $request->session()->getId()),
            'ownedAssessmentCount' => $user->ownedAssessments()->count(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $emailChanging = $request->validated()['email'] !== $user->email;

        // security.md §4: "Changing an email requires the current
        // password." ProfileUpdateRequest (the Breeze scaffold this
        // controller extends) is outside this package's file ownership
        // (app/Http/Requests/Auth/** and .../Admin/** are owned, but not
        // the bare app/Http/Requests/ path it lives in), so the extra
        // check is added here inline rather than in that request class —
        // reported as a gap in the W8 report.
        if ($emailChanging) {
            $validator = Validator::make($request->only('current_password'), [
                'current_password' => ['required', 'current_password'],
            ]);

            if ($validator->fails()) {
                throw ValidationException::withMessages([
                    'current_password' => 'The current password is required to change your email.',
                ]);
            }
        }

        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanging) {
            SessionRegistry::revokeAllExcept($user->id, $request->session()->getId());
        }

        return Redirect::route('profile.edit');
    }

    /**
     * Revoke one of the user's own sessions (not the current one, which
     * would just log the user out of the page they're on).
     */
    public function revokeSession(Request $request, string $sessionId): RedirectResponse
    {
        $user = $request->user();

        if ($sessionId === $request->session()->getId()) {
            return back()->withErrors(['session' => 'Use "Log out" to end your current session.']);
        }

        SessionRegistry::revoke($user->id, $sessionId);

        return back();
    }

    /**
     * Delete the user's account. The confirmation of exactly what will be
     * deleted (owned assessments, etc.) is rendered on the profile page
     * from `ownedAssessmentCount` above; this method re-validates the
     * password server-side regardless of what the client displayed.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
