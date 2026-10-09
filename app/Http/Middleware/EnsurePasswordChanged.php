<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces security.md §4's "forces a change on next login" half of an
 * admin password reset: a user flagged `must_change_password` is redirected
 * to the profile page — where W8's password-change form already lives — on
 * every request, until they set a new password (which clears the flag; see
 * User::booted()).
 *
 * Registered globally on the 'web' middleware group in bootstrap/app.php
 * rather than attached to a specific route group, because routes/web.php
 * and routes/auth.php are owned by another package mid-edit in this
 * worktree and this package must not touch them (W8b brief). That means
 * this middleware has to make its own allow/deny decision by route name
 * instead of relying on route placement — hence the explicit whitelist
 * below, which exists specifically to avoid a redirect loop: the
 * password-change endpoint and logout must both stay reachable while the
 * flag is set, and the redirect target itself (profile.edit, where the
 * password form is) must never redirect to itself.
 */
class EnsurePasswordChanged
{
    /**
     * Route names a flagged user may still reach. `profile.edit` is the
     * redirect target and the page hosting the password-change form;
     * `password.update` is that form's submit endpoint (routes/auth.php);
     * `logout` must always be reachable so a flagged user isn't trapped in
     * the account.
     *
     * @var list<string>
     */
    private const ALLOWED_ROUTES = [
        'profile.edit',
        'password.update',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->must_change_password) {
            $routeName = $request->route()?->getName();

            if (! in_array($routeName, self::ALLOWED_ROUTES, true)) {
                return redirect()->route('profile.edit')
                    ->with('status', 'password-change-required');
            }
        }

        return $next($request);
    }
}
