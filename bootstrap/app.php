<?php

use App\Http\Middleware\ApplyInstanceSessionLifetime;
use App\Http\Middleware\EnsureAssistantConfigured;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Models\Attack;
use App\Models\AttackDefence;
use App\Models\Defence;
use App\Models\Risk;
use App\Models\RiskAttack;
use App\Models\RootNode;
use App\Models\ScaleLevel;
use App\Observers\AttackDefenceObserver;
use App\Observers\NodeObserver;
use App\Observers\RiskAttackObserver;
use App\Observers\ScaleLevelObserver;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // W16 (PLAN.md Phase 6, security.md §8): registered here rather than
    // via withRouting()'s own `channels:` parameter, because that
    // convenience always calls Broadcast::routes() with its framework
    // default (`['middleware' => ['web']]`) — no `auth`, no `active` — and
    // that default would leave POST /broadcasting/auth reachable by a
    // guest with no account at all, defeating the very check
    // routes/channels.php performs. A share-token-only guest must never
    // reach that closure (security.md §8: "token-only sessions do not join
    // presence channels"), so this explicit call is the one place that is
    // enforced.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth', 'active']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // W8b: enforced globally rather than per-route-group because
            // routes/web.php and routes/auth.php are owned by another
            // package mid-edit in this worktree — see the middleware's own
            // docblock for the route-name whitelist that prevents a
            // redirect loop.
            EnsurePasswordChanged::class,
            // W116 (D78): after the password check, so an account that owes
            // both does the password first. Does nothing unless an admin has
            // switched "require two-factor" on.
            EnsureTwoFactorEnrolled::class,
        ]);

        // W8b: must run before Illuminate\Session\Middleware\StartSession
        // (part of the framework's own default 'web' group) so the
        // configured session lifetime is in effect before the session
        // cookie's expiry is computed — see the middleware's docblock.
        $middleware->web(prepend: [
            ApplyInstanceSessionLifetime::class,
        ]);

        // W24 (security.md §6). This file is outside W24's declared
        // ownership (`config/**`, `app/Http/Middleware/**`,
        // `tests/Feature/Security/**`), and this is the package's only
        // edit to it: a middleware that is not registered does nothing, so
        // the OWASP pass could not otherwise deliver a CSP at all. Same
        // narrow exception, for the same reason, that W16's
        // ->withBroadcasting() block above documents for itself.
        //
        // Registered as *global* middleware — outside `$middleware->web()`
        // — rather than only on the `web` group, closing a gap the pinned
        // defect in tests/Feature/Security/PinnedDefectsTest.php recorded:
        // `health: '/up'` (below) registers with no group at all, so a
        // web-group-only registration never touched it. Global placement
        // makes it the outermost middleware for every request without
        // needing to also join the `web` group, and it still runs before
        // the response is *rendered* — Vite::useCspNonce() is what puts
        // the nonce on the asset tags, see the middleware's docblock — so
        // a redirect or an early return from any inner middleware
        // (EnsurePasswordChanged, EnsureUserIsActive) still leaves with
        // the full header set.
        //
        // This does not cover a 404 for a URL matching no route at all:
        // that throws NotFoundHttpException from inside the router, which
        // propagates past this (and every other) middleware's "after"
        // code and is rendered by the exception handler outside the
        // pipeline entirely. The ->withExceptions() hook below reapplies
        // the same headers to that response using the nonce this
        // middleware already fixed for the request — see
        // SecurityHeaders::apply()'s docblock for why it must reuse that
        // nonce rather than generate a second one.
        $middleware->append(SecurityHeaders::class);

        // W8: 'active' rejects a deactivated user on every authenticated
        // request (security.md §4); 'admin' gates the /admin area
        // (PLAN.md Phase 3 item 3). Registered as aliases, applied on the
        // relevant route groups in routes/web.php and routes/auth.php —
        // not globally, since guest routes (login, first-run setup) must
        // stay reachable with no authenticated user at all.
        // W50: 'assistant' 404s every assistant route on a deployment that
        // runs no inference runtime (PLAN-2.3-ai.md D25). See the middleware's
        // docblock for why it is a middleware rather than a condition around
        // the route block — briefly, a boot-time branch cannot be tested in a
        // suite that boots once.
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'admin' => EnsureUserIsAdmin::class,
            'assistant' => EnsureAssistantConfigured::class,
        ]);

        // Behind the SWAG reverse proxy, which terminates TLS and forwards
        // plain HTTP, Laravel can't tell a request was HTTPS unless it
        // trusts the proxy's X-Forwarded-Proto header - otherwise
        // asset()/url() generate http:// links and the browser blocks them
        // as mixed content. Only private/internal ranges are trusted, never
        // '*', so an untrusted client can't spoof X-Forwarded-*.
        // X-Forwarded-Host is deliberately excluded to prevent Host-header
        // injection via a poisoned absolute URL.
        $middleware->trustProxies(
            at: ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // W24 (security.md §6), completing the SecurityHeaders registration
        // above: a 404 for a URL matching no route is rendered here,
        // outside the middleware pipeline, so it needs the header set
        // applied directly rather than inherited from `$next()`'s return.
        // `respond()` runs after Laravel has already built the outgoing
        // response for *any* unhandled exception, so this reapplies the
        // headers to every one of them — not just the 404 case the pinned
        // test asserts — matching security.md §11's "generic error pages
        // ... [carry the same protections as] every response".
        $exceptions->respond(function (Response $response, Throwable $e, Request $request): Response {
            return app(SecurityHeaders::class)->apply($response, $request);
        });
    })
    // W16 (PLAN.md Phase 6 item 4): registered here, not in a service
    // provider, per this package's file ownership ("bootstrap/app.php —
    // only to register an observer or middleware if genuinely required").
    // Each concrete STI subclass is observed individually — Node.php's own
    // docblock explains why Node itself carries no type scope and, for the
    // same underlying reason, no observer registered on `Node::class` would
    // ever see a Risk/Attack/Defence/RootNode event: Eloquent fires model
    // events under the runtime class, not the declared base class.
    ->booted(function (): void {
        RootNode::observe(NodeObserver::class);
        Risk::observe(NodeObserver::class);
        Attack::observe(NodeObserver::class);
        Defence::observe(NodeObserver::class);
        RiskAttack::observe(RiskAttackObserver::class);
        AttackDefence::observe(AttackDefenceObserver::class);

        // Added when the calculation cache's read path was wired: a scale
        // recalibration changes every computed figure, and until this was
        // registered it was the one such change that did not bump
        // calc_version. See ScaleLevelObserver's docblock.
        ScaleLevel::observe(ScaleLevelObserver::class);
    })
    ->create();
