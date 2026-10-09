<?php

/**
 * W24 — security.md §3.3's first required test, which no package had
 * written:
 *
 *   "A route-coverage test enumerating Route::getRoutes() and asserting
 *    every non-public route has authentication middleware and that every
 *    route touching an assessment invokes the policy. This is the test
 *    that would have caught defect #4."
 *
 * Defect #4 is the legacy app's `show` actions on risks, attacks and
 * defences having no `authorize()` call and no global scope — a direct
 * object reference that read private data. Every package since W6 has
 * *claimed* its routes are policy-guarded; `grep -rn "getRoutes()" tests/`
 * returned nothing before this file, so nothing had ever checked.
 *
 * The test walks the real route table rather than a hand-maintained list,
 * which is the whole point: a route appended by a future package is
 * covered the moment it is registered, and a route added without
 * authorization fails here rather than in a pen test. Routes that are
 * deliberately reachable by a guest are named explicitly below, so adding
 * one is a visible, reviewable diff rather than an omission.
 */

use App\Models\Assessment;
use App\Models\User;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Routes a visitor with no account may reach at all. Everything else must
 * carry `auth`, or must take an {assessment} and therefore run
 * AssessmentPolicy itself (which is what grants is_public / share-token
 * visitors `view` without an account — security.md §3.1).
 *
 * @return list<string>
 */
function guestReachableRouteNames(): array
{
    return [
        '/',                // the unauthenticated landing page — but see
        // PinnedDefectsTest, which pins the framework
        // and PHP versions it currently ships to it
        'login',            // the login form and its POST
        'setup',            // first-run admin creation, guarded by an empty users table
        'setup.store',
        'share.resolve',    // /s/{token} — presenting a token must work with no account
        'help.index',       // product documentation, not assessment data
        'help.show',
    ];
}

/**
 * @return list<RoutingRoute>
 */
function applicationRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(function (RoutingRoute $route): bool {
            $uri = $route->uri();

            // Framework/infrastructure endpoints, not application surface.
            // /up is Laravel's health check (registered by withRouting);
            // storage/{path} is the filesystem symlink route; the
            // broadcasting auth endpoint carries its own explicit
            // middleware list in bootstrap/app.php and is asserted
            // separately below.
            return ! in_array($uri, ['up', 'storage/{path}', 'broadcasting/auth'], true)
                && ! str_starts_with($uri, '_');
        })
        ->values()
        ->all();
}

it('gives every route either authentication or an assessment the policy can gate', function () {
    $unguarded = [];

    foreach (applicationRoutes() as $route) {
        $middleware = $route->gatherMiddleware();
        $name = $route->getName() ?? $route->uri();

        if (in_array('auth', $middleware, true)) {
            continue;
        }

        // No `auth`. That is only acceptable if the route is either on the
        // explicit guest list, or takes an {assessment} — in which case
        // AssessmentPolicy is the authorization boundary, and the next
        // test proves it actually runs.
        if (in_array($name, guestReachableRouteNames(), true)) {
            continue;
        }

        if (str_contains($route->uri(), '{assessment')) {
            continue;
        }

        $unguarded[] = $route->methods()[0].' /'.$route->uri().'  ('.$name.')';
    }

    expect($unguarded)->toBe([]);
});

it('keeps every deactivated account out of every authenticated route', function () {
    // security.md §4: "is_active is enforced on every request, not only at
    // login." A route with `auth` but without `active` would let a
    // deactivated user keep working until their session happened to be
    // invalidated.
    $missing = [];

    foreach (applicationRoutes() as $route) {
        $middleware = $route->gatherMiddleware();

        if (in_array('auth', $middleware, true) && ! in_array('active', $middleware, true)) {
            $missing[] = $route->methods()[0].' /'.$route->uri();
        }
    }

    expect($missing)->toBe([]);
});

it('runs AssessmentPolicy on every assessment-scoped route, for a stranger', function () {
    // The IDOR sweep security.md §3.3 asks for, applied to every GET route
    // that takes an {assessment}, driven off the route table rather than a
    // list someone has to remember to extend.
    //
    // A stranger — authenticated, but with no relationship to the
    // assessment at all — must never get a 200 from any of them. 403 and
    // 404 are both fine; 200 is defect #4.
    $stranger = User::factory()->create();
    $assessment = Assessment::factory()->create();
    $node = $assessment->rootNode;

    $leaked = [];

    foreach (applicationRoutes() as $route) {
        if (! str_contains($route->uri(), '{assessment')) {
            continue;
        }

        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $uri = str_replace(
            ['{assessment:uuid}', '{assessment}', '{node:uuid}', '{node}'],
            [$assessment->uuid, $assessment->uuid, $node->uuid, $node->uuid],
            $route->uri(),
        );

        // Anything still holding an unresolved parameter (a snapshot id, a
        // revision id) cannot be built without seeding that record; those
        // controllers are covered by their own packages' IDOR tests.
        if (str_contains($uri, '{')) {
            continue;
        }

        $status = $this->actingAs($stranger)->get('/'.$uri)->getStatusCode();

        if ($status === 200) {
            $leaked[] = 'GET /'.$uri.'  ('.($route->getName() ?? '').')';
        }
    }

    expect($leaked)->toBe([]);
});

it('denies the same routes to a guest with no session at all', function () {
    $assessment = Assessment::factory()->create();
    $node = $assessment->rootNode;

    $leaked = [];

    foreach (applicationRoutes() as $route) {
        if (! str_contains($route->uri(), '{assessment') || ! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $uri = str_replace(
            ['{assessment:uuid}', '{assessment}', '{node:uuid}', '{node}'],
            [$assessment->uuid, $assessment->uuid, $node->uuid, $node->uuid],
            $route->uri(),
        );

        if (str_contains($uri, '{')) {
            continue;
        }

        $status = $this->get('/'.$uri)->getStatusCode();

        if ($status === 200) {
            $leaked[] = 'GET /'.$uri;
        }
    }

    expect($leaked)->toBe([]);
});

it('requires auth and an active account on the broadcasting auth endpoint', function () {
    // security.md §8: a share-token-only guest must never reach the
    // channel-authorization closure. bootstrap/app.php registers this
    // explicitly rather than via withRouting()'s `channels:` shortcut,
    // whose framework default is `['web']` alone — no auth, no active.
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn (RoutingRoute $r): bool => $r->uri() === 'broadcasting/auth');

    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())
        ->toContain('auth')
        ->toContain('active');
});

it('exposes no self-service registration or password-reset route', function () {
    // security.md §4: "No public registration. Accounts are admin-created."
    foreach (['register', 'password.request', 'password.reset', 'password.email'] as $name) {
        expect(Route::has($name))->toBeFalse($name);
    }
});
