<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\InstanceSettings;
use App\Domain\Accounts\SessionRegistry;
use App\Domain\Identity\TwoFactor\Enrolment;
use App\Http\Controllers\Auth\OidcController;
use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request, Enrolment $enrolment): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => session('status'),
            'sessions' => SessionRegistry::forUser($user->id, $request->session()->getId()),
            'ownedAssessmentCount' => $user->ownedAssessments()->count(),
            // W116: the two-factor panel. `recoveryCodes` is flashed by the
            // action that made them and is on the page exactly once.
            'twoFactor' => $enrolment->panel($user, $request->session()),
            'recoveryCodes' => session('recoveryCodes'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse|SymfonyResponse
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
        // W117 (D79): in SSO-only mode a non-admin has no password to give, so
        // an email change asks for a provider sign-in within the last five
        // minutes instead, sending them to the provider if there is none.
        if ($emailChanging && InstanceSettings::ssoOnly() && ! $user->is_admin) {
            $at = (int) $request->session()->get(OidcController::SESSION_REAUTH, 0);

            if (Carbon::now()->getTimestamp() - $at > OidcController::REAUTH_WINDOW_SECONDS) {
                return Inertia::location(route('oidc.redirect', ['reauth' => 1]));
            }

            $request->session()->forget(OidcController::SESSION_REAUTH);
        } elseif ($emailChanging) {
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
