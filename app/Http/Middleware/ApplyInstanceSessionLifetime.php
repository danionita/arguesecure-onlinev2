<?php

namespace App\Http\Middleware;

use App\Domain\Accounts\InstanceSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the admin-configured `session_lifetime_minutes` (ui.md §10) govern
 * the session's actual lifetime, closing the gap where the setting was
 * stored and displayed but nothing read it back (security.md §5's
 * `'lifetime' => 480, // minutes, configurable` is exactly this value).
 *
 * Must run *before* Illuminate\Session\Middleware\StartSession, which reads
 * config('session.lifetime') to compute the cookie's expiry and the
 * garbage-collection window — setting the config afterwards would be too
 * late to affect the cookie already being built for this response. It is
 * therefore registered with `prepend`, not `append`, in bootstrap/app.php,
 * ahead of the framework's own default web-group middleware.
 *
 * Overriding the value config/session.php resolves to at runtime, rather
 * than changing that file, is the hook that works with the instance
 * setting being stored in the database.
 */
class ApplyInstanceSessionLifetime
{
    public function handle(Request $request, Closure $next): Response
    {
        $settings = InstanceSettings::all();

        config(['session.lifetime' => $settings['session_lifetime_minutes']]);
        // EnsureTwoFactorEnrolled reads this instead of querying the
        // settings row a second time on every request (the report page's
        // query budget counts it).
        config(['arguesecure.require_two_factor' => $settings['require_two_factor']]);

        return $next($request);
    }
}
