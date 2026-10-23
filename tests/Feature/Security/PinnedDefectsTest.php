<?php

/**
 * W24 — defects this package found but does **not** own.
 *
 * W24's declared file ownership is `config/**`, `app/Http/Middleware/**`
 * and `tests/Feature/Security/**`. Every defect below lives outside those
 * paths, so CLAUDE.md's "if you need to change a file another package owns,
 * stop and report rather than editing it" applies.
 *
 * Each was pinned as a test asserting the **then-current, defective**
 * behaviour, with the fix written out in its docblock. Fixing a defect
 * turns its pin red, which forces whoever fixes it to come here, read the
 * reasoning and flip the assertion — so a fix can neither be forgotten nor
 * land silently. This is the convention W20 established in
 * tests/Feature/Catalog/AssessmentClonerTest.php ("W20 pinned each as a
 * 'currently throws' test rather than patching a class it did not own, so
 * that fixing the class would turn them red and force this flip").
 *
 * Four of the five below are now flipped: their `it(...)` names read
 * "FIXED" and their bodies are regression guards for the fix, not
 * descriptions of the defect. Number 4's flip is partial — see its own
 * docblock for the piece that is still someone else's file to fix, pinned
 * there rather than silently dropped from the assertion.
 *
 * Ordered most to least severe.
 */

use App\Models\Assessment;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 1. The Ziggy route-table script carries no CSP nonce
|--------------------------------------------------------------------------
|
| Owner: resources/views/app.blade.php
| Severity: blocks the whole CSP rollout
*/

it('gives the Ziggy route-table script a nonce, so the CSP can be enforced', function () {
    $html = (string) $this->get('/')->getContent();

    preg_match_all('/<script\b[^>]*>/i', $html, $matches);

    $inlineScripts = array_values(array_filter(
        $matches[0],
        // src= tags are external and governed by 'self', not by a nonce.
        // type="application/json" is data, never executed, so CSP does not
        // apply to it either — that one is Inertia's page payload.
        fn (string $tag): bool => ! str_contains($tag, 'src=')
            && ! str_contains($tag, 'application/json'),
    ));

    // W41 added a second one — the pre-paint theme script, which is the only
    // code that can run before the browser paints and therefore the only
    // thing that can stop a dark-mode user seeing a white flash on every full
    // page load.
    //
    // The assertion changed shape rather than count, and deliberately in the
    // stricter direction: it used to say "there is exactly one inline script
    // and it has a nonce", which would have passed unchanged if someone added
    // a third *without* one. It now says **every** inline executable script
    // carries a nonce, which is the property security.md §6 actually needs
    // and which no future addition can slip past.
    expect($inlineScripts)->not->toBeEmpty();

    foreach ($inlineScripts as $tag) {
        expect($tag)->toContain('nonce=');
    }

    // FIXED. Ziggy's Blade directive compiles to
    // `app(BladeRouteGenerator::class)->generate($group)` — the nonce is
    // its *second* argument and the generator does not fall back to
    // Vite::cspNonce(), so app.blade.php now passes it explicitly. Until
    // that landed this script was blocked the moment `script-src` stopped
    // allowing inline code, which would have taken the route table with it
    // and broken every route() call site in resources/js.
    //
    // This assertion is what keeps the nonce there: drop the argument in
    // app.blade.php and the loop above goes red rather than the CSP silently
    // reverting to something that cannot be enforced.
    // And the route table really is one of them — the regex above captures
    // opening tags only, so this looks in the document rather than in the
    // matched tag. Without it the loop would still pass on a page that had
    // stopped emitting `@routes` altogether.
    expect($html)->toContain('Ziggy');
});

/*
|--------------------------------------------------------------------------
| 2. Login throttling is per IP as well as per email+IP
|--------------------------------------------------------------------------
|
| Owner: app/Http/Requests/Auth/LoginRequest.php
| Severity: password spraying is throttled
*/

it('FIXED: login throttling limits an attacker per IP, not only per email+IP pair', function () {
    // security.md §9 requires "5/min per IP **and** per email" — two
    // independent limiters. LoginRequest::throttleKey() returns the
    // combined `strtolower(email)|ip` key it always has; ipThrottleKey()
    // is new, keyed on the address alone, hit and cleared alongside it.
    //
    // Before this, password spraying was unthrottled: one password tried
    // against many accounts from one address, where each new email was a
    // fresh bucket, made the per-IP budget 5 x (number of emails guessed).
    $victims = User::factory()->count(3)->create();

    foreach ($victims as $victim) {
        // Burn the full allowance for this account. The per-IP limiter
        // trips well before this finishes — 5 failures in, regardless of
        // which account they were aimed at.
        for ($i = 0; $i < 6; $i++) {
            $this->post('/login', [
                'email' => $victim->email,
                'password' => 'not-the-password-'.$i,
            ]);
        }
    }

    // 18 failed attempts from one IP. A fourth, completely untouched
    // account from the same address is now locked out too: THE FIX means
    // the per-IP budget, not the per-account one, is what is exhausted.
    $fresh = User::factory()->create();

    $response = $this->from('/login')->post('/login', [
        'email' => $fresh->email,
        'password' => 'still-not-the-password',
    ]);

    $response->assertRedirect('/login');

    $errors = session('errors')?->getBag('default')?->get('email') ?? [];

    // Guards the fix: if ipThrottleKey() is ever removed, or is no longer
    // hit/checked alongside the pair-keyed limiter, this goes back to the
    // generic credential-failure message and this assertion catches it.
    expect($errors[0] ?? '')->toContain('Too many login attempts');
});

/*
|--------------------------------------------------------------------------
| 3. The landing page no longer publishes the Laravel and PHP versions
|--------------------------------------------------------------------------
|
| Owner: routes/web.php
| Severity: information disclosure to an unauthenticated visitor
*/

it('FIXED: the guest landing page ships no framework version strings', function () {
    // security.md §11: "Generic error pages. No stack trace, no framework
    // version ... ever reaches the browser." The rule is about not
    // handing an attacker a version to match against a CVE list, and the
    // landing page used to volunteer both to anyone, logged in or not.
    //
    // This was inherited from Laravel's stock Welcome scaffold —
    // `'laravelVersion' => Application::VERSION, 'phpVersion' =>
    // PHP_VERSION` in the `/` closure — and nothing in the product ever
    // used either value.
    //
    // THE FIX: both props are gone from the `/` route closure in
    // routes/web.php, and resources/js/Pages/Welcome.vue never declared
    // fields for them in the first place (its `defineProps<>()` only ever
    // took `canLogin`).
    //
    // Guards the fix: if either prop is reintroduced, this goes red.
    $html = (string) $this->get('/')->getContent();

    expect($html)->not->toContain('laravelVersion')
        ->and($html)->not->toContain('phpVersion');
});

/*
|--------------------------------------------------------------------------
| 4. Mutation endpoints in routes/web.php now carry a rate limit
|--------------------------------------------------------------------------
|
| Owner: routes/web.php
| Severity: was no ceiling on write volume per user
*/

it('FIXED: node, link and comment mutations in routes/web.php are rate limited', function () {
    // security.md §9 specifies "Node and link mutations: 120/min per user"
    // and "Everything else authenticated: 300/min per user". Before this,
    // only four routes in the whole table carried `throttle`: first-run
    // setup, the share-token resolver, the collaborator search, and report
    // generation — every write endpoint the tree editor used was
    // unbounded.
    //
    // THE FIX, applied: the W12/W13/W14 mutation groups in routes/web.php
    // now carry `throttle:120,1`, and every other `auth`+`active` group in
    // that file carries `throttle:300,1` as its default.
    //
    // routes/auth.php is covered too. Its authenticated group carries the
    // 300/min default, and the two routes §9 rates separately at "5/min per
    // user" — PUT /password, and POST /confirm-password, which is the same
    // surface under another name since it takes the account's current
    // password and answers whether it was right — carry throttle:5,1. So
    // does PATCH /profile in routes/web.php, §9's "email change".
    //
    // POST /login is the one legitimate survivor of this scan, and it is
    // not a gap: it is rate limited, just not through the `throttle`
    // middleware family. LoginRequest drives RateLimiter directly with its
    // own IP and email+IP keys, which is what the defect immediately above
    // is about — a middleware alias cannot express two limiters on
    // different keys.
    $unthrottled = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var RoutingRoute $route */
        if (in_array($route->methods()[0], ['GET', 'HEAD', 'OPTIONS'], true)) {
            continue;
        }

        // Not an application route: Laravel's local-disk temporary upload
        // URLs, registered by vendor/laravel/framework's own
        // FilesystemServiceProvider. Nothing in routes/**, config/** or
        // app/** put it there, so it is not this defect's to fix.
        if (str_starts_with((string) $route->getName(), 'storage.')) {
            continue;
        }

        $hasThrottle = collect($route->gatherMiddleware())
            ->contains(fn ($m): bool => is_string($m) && str_starts_with($m, 'throttle'));

        if (! $hasThrottle) {
            $unthrottled[] = $route->methods()[0].' /'.$route->uri();
        }
    }

    // One entry, for the documented reason above. A fixed list rather than
    // a count, now that the open-ended "some number of routes are
    // unthrottled" state is gone: a new write route landing without a
    // limiter turns this red on the next run, which is the whole point.
    expect(collect($unthrottled)->sort()->values()->all())->toBe([
        'POST /login',
    ]);
});

it('FIXED: the node and link mutation routes carry §9\'s rate, not merely some rate', function () {
    // The scan above only asks whether a route has *a* throttle. That is
    // not enough on its own, and mutation testing proved it: deleting
    // `throttle:120,1` from the node-mutation group left it green, because
    // the enclosing group's `throttle:300,1` still matched the prefix. The
    // 120/min figure security.md §9 actually specifies could therefore be
    // dropped silently.
    //
    // So assert the rate, not the presence. Named routes rather than a
    // sweep, because "which routes are node and link mutations" is a
    // judgement §9 makes and a pattern match would only guess at.
    $mutations = [
        'assessments.nodes.store',
        'assessments.nodes.update',
        'assessments.nodes.destroy',
        'assessments.reorder',
        'assessments.move',
        'assessments.undo.store',
        'assessments.redo.store',
        'assessments.risk-attack-links.store',
        'assessments.risk-attack-links.destroy',
        'assessments.attack-defence-links.store',
        'assessments.attack-defence-links.update',
        'assessments.attack-defence-links.destroy',
    ];

    foreach ($mutations as $name) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull("route {$name} no longer exists — update this list deliberately");

        $throttles = collect($route?->gatherMiddleware() ?? [])
            ->filter(fn ($m): bool => is_string($m) && str_starts_with($m, 'throttle'))
            ->values()
            ->all();

        // in_array rather than expect()->toContain($needle, $message):
        // Pest reads every argument to toContain as another needle, so the
        // message would be asserted as a substring. That has bitten this
        // codebase twice.
        expect(in_array('throttle:mutations', $throttles, true))
            ->toBeTrue("{$name} must carry throttle:mutations (security.md §9), got: ".implode(', ', $throttles));
    }
});

it('FIXED: the rate limiters are named, so §9\'s groups are independent buckets', function () {
    // The reason every limiter is named rather than `throttle:<n>,<m>`,
    // and the reason this is asserted rather than left to a comment.
    //
    // ThrottleRequests::resolveRequestSignature() returns sha1($user->id)
    // and nothing else — not the route, not the method, not the limit. So
    // every *anonymous* throttle in an application reads and writes one
    // single counter per user. §9's per-group figures were therefore never
    // independent: the application was bounded by whichever limit a request
    // met first, against a total every other route had contributed to, and
    // nesting two anonymous throttles incremented that one counter twice
    // per request, halving the inner limit.
    //
    // It surfaced as the end-to-end suite going red on its first CI run:
    // scenario 5 got a 429 opening the share dialog, because collaborator
    // search's 60/min limiter was reading the same counter as every page
    // load before it.
    //
    // A *named* limiter keys on md5($limiterName.$key), which is what gives
    // each group its own bucket. So: no route may carry a numeric throttle.
    $numeric = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var RoutingRoute $route */
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, 'throttle:')) {
                continue;
            }

            // 'throttle:120,1' — anonymous. 'throttle:mutations' — named.
            if (preg_match('/^throttle:\d/', $middleware) === 1) {
                $numeric[] = $route->methods()[0].' /'.$route->uri().' → '.$middleware;
            }
        }
    }

    expect($numeric)->toBe([]);

    // And every name a route references must actually be registered, or
    // Laravel throws at request time rather than at boot — a limiter typo
    // is a 500 on exactly the route you were trying to protect.
    foreach (['authenticated', 'mutations', 'credentials', 'search', 'reports', 'report-views', 'setup', 'share-token'] as $name) {
        expect(app(RateLimiter::class)->limiter($name))
            ->not->toBeNull("rate limiter '{$name}' is referenced by a route but never registered");
    }
});

/*
|--------------------------------------------------------------------------
| 5. Unmatched URLs and the health check now get security headers too
|--------------------------------------------------------------------------
|
| Owner: bootstrap/app.php (the exception-handling and global middleware
| stack, not the `web` group W24 originally registered SecurityHeaders on)
| Severity: was low
*/

it('FIXED: a 404 for an unmatched URL, and /up, carry the full security header set', function () {
    // SecurityHeaders used to be registered only on the `web` group,
    // which is the right place for every route the application actually
    // serves — and it did cover redirects, policy 403s and
    // route-model-binding 404s, all still asserted in SecurityHeadersTest.
    //
    // A URL matching no route at all never enters any group: the router
    // throws NotFoundHttpException before dispatch. Same for /up, which
    // withRouting() registers with no group. Neither was reachable by a
    // `web`-group-only registration.
    //
    // THE FIX, applied: SecurityHeaders is now registered globally in
    // bootstrap/app.php (`$middleware->append(...)`, outside `->web()`),
    // so it wraps every request the Kernel handles, matched route or not
    // — Laravel's global middleware stack runs through
    // Illuminate\Routing\Pipeline, which catches an exception at the exact
    // pipeline slice it was thrown from and renders it before unwinding
    // back out through each middleware's own "after" code, so this
    // middleware's header-setting logic runs even for a request that
    // never found a route. A `->withExceptions(...)->respond(...)` hook
    // additionally re-applies the same headers (reusing the nonce this
    // request already fixed, via SecurityHeaders::currentNonce() —
    // see that class's apply() docblock) as a second, defense-in-depth
    // pass over every exception response, matching security.md §11's
    // "generic error pages" guarantee for any error shape, not only a 404.
    $notFound = $this->get('/no-such-url-exists-anywhere');
    $up = $this->get('/up');

    $notFound->assertNotFound();
    $up->assertOk();

    foreach (['not-found' => $notFound, 'up' => $up] as $shape => $response) {
        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff', $shape)
            ->and(
                $response->headers->get('Content-Security-Policy')
                    ?? $response->headers->get('Content-Security-Policy-Report-Only')
            )->not->toBeNull($shape);
    }
});

/*
|--------------------------------------------------------------------------
| Guard rail for the above
|--------------------------------------------------------------------------
*/

it('still denies a stranger everything, defects notwithstanding', function () {
    // None of the five defects above is an authorization hole, and this
    // asserts that directly so the pins are not mistaken for tolerated
    // access-control failures.
    $stranger = User::factory()->create();
    $private = Assessment::factory()->create();

    $this->actingAs($stranger)->get('/assessments/'.$private->uuid)->assertForbidden();
    $this->get('/assessments/'.$private->uuid)->assertForbidden();
});
