<?php

namespace App\Http\Middleware;

use App\Domain\Accounts\InstanceSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the admin-configured `session_lifetime_minutes` (ui.md §10) govern
 * the session's actual lifetime, closing the W8 gap where the setting was
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
 * config/session.php itself is outside this package's file ownership, so
 * this is the only available hook: override the value it resolves to at
 * runtime rather than changing the file.
 */
class ApplyInstanceSessionLifetime
{
    public function handle(Request $request, Closure $next): Response
    {
        config(['session.lifetime' => InstanceSettings::all()['session_lifetime_minutes']]);

        return $next($request);
    }
}
