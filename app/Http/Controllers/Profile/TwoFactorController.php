<?php

namespace App\Http\Controllers\Profile;

use App\Domain\Identity\TwoFactor\Enrolment;
use App\Domain\Identity\TwoFactor\Totp;
use App\Domain\Identity\TwoFactor\TwoFactor;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The profile page's *Two-factor authentication* panel (W116, D77, D78).
 *
 * Starting, regenerating and turning off all take the account's current
 * password: a stolen session cookie must not be enough to make an attacker
 * the holder of an account's second factor. Turning it off also takes a code.
 */
class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactor $twoFactor,
        private readonly Totp $totp,
        private readonly Enrolment $enrolment,
    ) {}

    /** Step 1: password confirmed, a secret is generated and held in the session — nothing is stored on the account. */
    public function store(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        if ($request->user()->hasTwoFactorEnabled()) {
            return back()->withErrors(['password' => 'Two-factor authentication is already on.']);
        }

        $this->enrolment->begin($request->session());

        return back();
    }

    /** Step 2: a code from the new secret proves the app holds it; only now is anything saved. */
    public function confirm(Request $request): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:16']]);

        $secret = $this->enrolment->secret($request->session());

        if ($secret === null || $request->user()->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['code' => 'Start again: the setup expired.']);
        }

        $step = $this->totp->matchingStep($secret, $validated['code'], null);

        if ($step === null) {
            throw ValidationException::withMessages(['code' => 'That code was not accepted. Check the time on your phone and try the next one.']);
        }

        $codes = $this->twoFactor->enable($request->user(), $secret, $step);

        $this->enrolment->forget($request->session());

        // Shown once, on the page this redirects to, and never again.
        return back()->with('recoveryCodes', $codes);
    }

    /** Leave an enrolment unfinished. */
    public function cancel(Request $request): RedirectResponse
    {
        $this->enrolment->forget($request->session());

        return back();
    }

    /** New recovery codes; the old ones stop working. Password and a code, as turning it off takes. */
    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'current_password'],
            'code' => ['required', 'string', 'max:64'],
        ]);

        abort_unless($request->user()->hasTwoFactorEnabled(), 404);

        if (! $this->twoFactor->acceptAny($request->user(), $validated['code'])) {
            throw ValidationException::withMessages(['code' => 'That code was not accepted.']);
        }

        return back()->with('recoveryCodes', $this->twoFactor->regenerateRecoveryCodes($request->user()));
    }

    /** Turn it off: the password and a current code (or recovery code). Other sessions end. */
    public function destroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'current_password'],
            'code' => ['required', 'string', 'max:64'],
        ]);

        $user = $request->user();

        abort_unless($user->hasTwoFactorEnabled(), 404);

        if (! $this->twoFactor->acceptAny($user, $validated['code'])) {
            throw ValidationException::withMessages(['code' => 'That code was not accepted.']);
        }

        $this->twoFactor->disable($user, $request->session()->getId());

        return back();
    }
}
