<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\SecurityEvent;
use App\Domain\Audit\SecurityLog;
use App\Domain\Mail\PasswordResets;
use App\Http\Controllers\Controller;
use App\Jobs\SendPasswordResetLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Forgot password?" The answer is the same whether or not the address has an
 * account, an account that may be sent a link, or whether mail was sent at
 * all; only the security log knows.
 */
class ForgotPasswordController extends Controller
{
    public function create(): Response
    {
        abort_unless(PasswordResets::available(), 404);

        return Inertia::render('Auth/ForgotPassword');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(PasswordResets::available(), 404);

        $email = mb_strtolower(trim($request->validate(['email' => ['required', 'string', 'email', 'max:255']])['email']));

        // 3 an hour per address on top of the per-IP limit on the route, so a
        // mailbox cannot be flooded from many addresses. Over the limit it
        // still answers the same, and sends nothing.
        $key = 'password-reset:'.hash('sha256', $email);
        $limited = RateLimiter::tooManyAttempts($key, 3);

        if (! $limited) {
            RateLimiter::hit($key, 3600);

            // The lookup happens in the job, so every address costs the same.
            SendPasswordResetLink::dispatch($email);
        }

        SecurityLog::record(SecurityEvent::PasswordResetRequested, null, ['type' => 'user', 'id' => null, 'email' => $email], ['limited' => $limited]);

        return redirect()->route('login')->with('status', 'If that address has an account, we sent a link to reset the password.');
    }
}
