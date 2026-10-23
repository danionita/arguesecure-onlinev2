<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\TwoFactor\Events\TwoFactorChallengeFailed;
use App\Domain\Identity\TwoFactor\PendingLogin;
use App\Domain\Identity\TwoFactor\TwoFactor;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The second step of a sign-in with a second factor (W116, D77): the
 * account was parked in the session by AuthenticatedSessionController::store
 * after a correct password, and nothing here trusts anything but that.
 */
class TwoFactorChallengeController extends Controller
{
    public function __construct(private readonly TwoFactor $twoFactor) {}

    public function create(Request $request): Response|RedirectResponse
    {
        if (PendingLogin::get($request->session()) === null) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $pending = PendingLogin::get($request->session());

        // Expired (five minutes), or never started: the password step has to
        // be repeated. Nothing about the account is said either way.
        if ($pending === null) {
            return redirect()->route('login');
        }

        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:64', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'max:64', 'required_without:code'],
        ]);

        $this->ensureIsNotRateLimited($request, $pending['user_id']);

        $user = User::query()->find($pending['user_id']);

        // The account may have been deactivated, deleted or lost its factor
        // since the password step. Treated as a failed code: same message,
        // and it counts against the limiter.
        $accepted = $user !== null
            && $user->is_active
            && $user->hasTwoFactorEnabled()
            && $this->twoFactor->acceptAny($user, (string) ($validated['code'] ?? $validated['recovery_code']));

        if (! $accepted) {
            RateLimiter::hit($this->accountKey($pending['user_id']));
            RateLimiter::hit($this->ipKey($request));

            event(new TwoFactorChallengeFailed($pending['user_id'], $request->ip()));

            throw ValidationException::withMessages([
                'code' => 'That code was not accepted.',
            ]);
        }

        RateLimiter::clear($this->accountKey($pending['user_id']));

        PendingLogin::forget($request->session());

        // login() migrates the session id (security.md §5: a new id once the
        // account is really authenticated), as the password-only path does.
        Auth::guard('web')->login($user, $pending['remember']);

        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * 5/min per pending account and per IP (security.md §9) — the same two
     * buckets the password step has, for the same reason: the per-account one
     * bounds guesses at one account, the per-address one bounds a spray.
     *
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(Request $request, int $userId): void
    {
        foreach ([$this->accountKey($userId), $this->ipKey($request)] as $key) {
            if (RateLimiter::tooManyAttempts($key, 5)) {
                $seconds = RateLimiter::availableIn($key);

                throw ValidationException::withMessages([
                    'code' => "Too many attempts. Please try again in {$seconds} seconds.",
                ]);
            }
        }
    }

    private function accountKey(int $userId): string
    {
        return 'two-factor-user|'.$userId;
    }

    private function ipKey(Request $request): string
    {
        return 'two-factor-ip|'.$request->ip();
    }
}
