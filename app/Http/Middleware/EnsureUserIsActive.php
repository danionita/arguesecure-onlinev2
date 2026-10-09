<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces `is_active` on every authenticated request, not only at login
 * (security.md §4). Session invalidation alone is reactive — it depends
 * on the deactivating admin action reaching every one of that user's
 * sessions — so this middleware re-checks the flag on the request itself,
 * meaning a deactivated user is rejected the very next request even if a
 * session survived elsewhere.
 *
 * Deliberately separate from AssessmentPolicy: account status is not an
 * assessment relationship, and putting it in the policy would leave every
 * non-assessment route (including this admin area and the profile page)
 * uncovered. Placed on the authenticated route group instead.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(403, 'Your account has been deactivated.');
        }

        return $next($request);
    }
}
