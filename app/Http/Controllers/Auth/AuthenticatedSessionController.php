<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view — or the one-time first-run setup screen if
     * no account exists yet (security.md §4, PLAN.md Phase 3 item 2).
     */
    public function create(): Response|RedirectResponse
    {
        if (User::query()->count() === 0) {
            return redirect()->route('setup');
        }

        return Inertia::render('Auth/Login', [
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        // Reject a deactivated account at the login gate itself, not only
        // via the 'active' middleware on later requests — there is no
        // reason to hand out a session that would be rejected on its very
        // next request. Same generic message as a failed credential check
        // (security.md §4): the attacker already proved the credentials
        // correct, but nothing here should read differently to a
        // password-manager autofill probing many emails.
        if (! Auth::user()->is_active) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        Auth::user()->forceFill(['last_login_at' => now()])->save();

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
