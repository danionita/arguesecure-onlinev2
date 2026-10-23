<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * D78's *Require two-factor authentication for password sign-in*: with the
 * instance switch on, an account that has no second factor reaches only the
 * profile page (where enrolment lives), the routes enrolment needs, and
 * logout. It is sent there rather than refused, so the switch can be turned
 * on without an admin enrolling every account by hand.
 *
 * Registered on the `web` group like EnsurePasswordChanged, and for the same
 * reason decides by route name; the two share `profile.edit` as the place to
 * be sent, so an account that owes both a new password and an enrolment does
 * the password first and cannot loop.
 *
 * With the switch off (the default) this does nothing at all.
 */
class EnsureTwoFactorEnrolled
{
    /** @var list<string> */
    private const ALLOWED_ROUTES = [
        'profile.edit',
        'profile.two-factor.store',
        'profile.two-factor.confirm',
        'profile.two-factor.cancel',
        'password.update',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->hasTwoFactorEnabled() || ! config('arguesecure.require_two_factor')) {
            return $next($request);
        }

        // D78: not asked after a provider sign-in; the provider's own second
        // factor is the organisation's to enforce.
        if ($request->hasSession() && $request->session()->get('auth.method') === 'oidc') {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        return redirect()->route('profile.edit')->with('status', 'Your administrator requires two-factor authentication. Set it up below to continue.');
    }
}
