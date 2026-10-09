<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the /admin area on `is_admin` (PLAN.md Phase 3 item 3). Kept as
 * its own middleware, distinct from EnsureUserIsActive, so the two
 * concerns — "is this session even allowed to exist" and "is this user
 * an admin" — stay independently testable and independently composable
 * on other route groups later.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->is_admin) {
            abort(403);
        }

        return $next($request);
    }
}
