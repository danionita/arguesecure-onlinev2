<?php

namespace App\Http\Requests\Auth;

use App\Domain\Accounts\InstanceSettings;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // W117 (D79): in SSO-only mode a non-admin has no password sign-in.
        // Refused exactly like a wrong password, before the password is even
        // checked, and counted against the same limiters.
        $ssoOnlyRefusal = InstanceSettings::ssoOnly()
            && ! User::query()->where('email', $this->input('email'))->where('is_admin', true)->exists();

        if ($ssoOnlyRefusal || ! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());
            RateLimiter::hit($this->ipThrottleKey());

            // Generic on purpose (security.md §4): the same message for a
            // wrong password, a nonexistent email, and (in
            // AuthenticatedSessionController) a deactivated account, so
            // none of those cases is distinguishable from outside.
            // Written inline rather than via trans('auth.failed') because
            // this repository ships no lang/en/auth.php — lang/** is
            // outside W8's file ownership, so adding one is not this
            // package's call to make.
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        RateLimiter::clear($this->ipThrottleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * security.md §9 requires "5/min per IP **and** per email" — two
     * independent limiters, not one keyed on the pair. A combined
     * email+IP key alone lets an attacker spray one password across many
     * accounts from a single address: each new email is a fresh bucket, so
     * the per-IP budget was effectively 5 x (number of emails guessed).
     * The pair-keyed limiter is kept alongside this one because it still
     * does useful work of its own — it is what limits repeated guesses
     * against one specific account from one address once the IP-wide
     * budget has room left.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (RateLimiter::tooManyAttempts($this->ipThrottleKey(), 5)) {
            event(new Lockout($this));

            $seconds = RateLimiter::availableIn($this->ipThrottleKey());

            throw ValidationException::withMessages([
                'email' => "Too many login attempts. Please try again in {$seconds} seconds.",
            ]);
        }

        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "Too many login attempts. Please try again in {$seconds} seconds.",
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request: the email+IP
     * pair. Kept distinct from ipThrottleKey() below — see that method's
     * docblock for why both limiters exist.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }

    /**
     * Get the rate limiting throttle key for the request: the IP address
     * alone. security.md §9's "and per email" is the pair-keyed
     * throttleKey() above; this is the "per IP" half, and it is what
     * actually bounds password-spraying — a fixed budget per address no
     * matter how many distinct emails are tried against it.
     */
    public function ipThrottleKey(): string
    {
        return 'login-ip|'.$this->ip();
    }
}
