<?php

namespace App\Http\Middleware;

use App\Domain\Assistant\Availability;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses every assistant route on a deployment that has no inference runtime
 * (PLAN-2.3-ai.md D25).
 *
 * **404, not 403.** The difference is the whole point: 403 says "this exists
 * and you may not have it", which is false and invites someone to go looking
 * for a way in. 404 says the feature is not here, which is true — this
 * installation has no runtime and never served the feature to anyone.
 *
 * ## Why this is middleware rather than an `if` around the route block
 *
 * Wrapping the routes in `if (Availability::configured())` reads better and
 * was the first implementation. It is also, in this suite, untestable:
 * `routes/web.php` is evaluated once when the application boots, long before
 * any test can set the config value it branches on, so "the route is absent
 * when unconfigured" and "the route is present when configured" cannot both
 * be exercised in one run. A guard that cannot be tested — and cannot be
 * mutation-tested — is exactly what CLAUDE.md's "a presence assertion is not a
 * guard" warns about, and this project has already been bitten once by a
 * security check that no test could see.
 *
 * The observable behaviour is identical for every client: the endpoint 404s.
 * The one real difference is that the route *name* still exists, so Ziggy
 * ships it to the browser. That is acceptable — a name is not an endpoint, the
 * request behind it 404s, and no assistant control is rendered in this state
 * anyway.
 */
class EnsureAssistantConfigured
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Availability::configured(), 404);

        return $next($request);
    }
}
