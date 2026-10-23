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
use App\Models\AssessmentOperation;
use App\Models\Attack;
use App\Models\AttackDefence;
use App\Models\Defence;
use App\Models\Risk;
use App\Models\RiskAttack;
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
        'two-factor.challenge', // the second sign-in step; the account is not signed in until it passes
        'two-factor.challenge.store',
        'oidc.redirect',        // W117: starting and finishing a provider sign-in
        'oidc.callback',
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

/**
 * Write routes whose body names nodes by uuid, with a request for each
 * branch that resolves them. The policy only ever sees the {assessment} in
 * the URL, so each of these must resolve every body uuid through that
 * assessment (W104). A write route that takes node uuids in its body
 * belongs in this list.
 *
 * @return list<array{0: string, 1: callable(array<string, string>): array<string, mixed>}>
 */
function nodeScopedBodyRequests(): array
{
    return [
        ['assessments.reorder', fn (array $n): array => ['scope' => 'nodes', 'order' => [$n['risk2'], $n['risk']]]],
        ['assessments.reorder', fn (array $n): array => ['scope' => 'risk_attack', 'parent_uuid' => $n['risk'], 'order' => [$n['attack2'], $n['attack']]]],
        ['assessments.reorder', fn (array $n): array => ['scope' => 'attack_defence', 'parent_uuid' => $n['attack'], 'order' => [$n['defence2'], $n['defence']]]],
        ['assessments.move', fn (array $n): array => ['edge' => 'risk_attack', 'from_parent_uuid' => $n['risk'], 'to_parent_uuid' => $n['risk2'], 'child_uuid' => $n['attack']]],
        ['assessments.move', fn (array $n): array => ['edge' => 'attack_defence', 'from_parent_uuid' => $n['attack'], 'to_parent_uuid' => $n['attack2'], 'child_uuid' => $n['defence']]],
        ['assessments.risk-attack-links.store', fn (array $n): array => ['risk_uuid' => $n['risk2'], 'attack_uuid' => $n['attack2']]],
        ['assessments.risk-attack-links.destroy', fn (array $n): array => ['risk_uuid' => $n['risk'], 'attack_uuid' => $n['attack']]],
        ['assessments.attack-defence-links.store', fn (array $n): array => ['attack_uuid' => $n['attack2'], 'defence_uuid' => $n['defence']]],
        ['assessments.attack-defence-links.update', fn (array $n): array => ['attack_uuid' => $n['attack'], 'defence_uuid' => $n['defence'], 'strength_mode' => 'quantitative', 'strength_fraction' => 0.9]],
        ['assessments.attack-defence-links.destroy', fn (array $n): array => ['attack_uuid' => $n['attack'], 'defence_uuid' => $n['defence']]],
    ];
}

it('resolves body node uuids through the route\'s assessment on every node-scoped write route', function () {
    // The caller owns the assessment in the URL and sends the nodes of one
    // it cannot change. 404 or 422 are both fine; a 2xx, or any change to
    // the other assessment's positions, edges or operations log, is the
    // defect.
    $caller = User::factory()->create();
    $own = Assessment::factory()->create(['owner_user_id' => $caller->id]);
    $other = Assessment::factory()->create();

    $risk = Risk::factory()->create(['assessment_id' => $other->id, 'position' => 0]);
    $risk2 = Risk::factory()->create(['assessment_id' => $other->id, 'position' => 1]);
    $attack = Attack::factory()->create(['assessment_id' => $other->id]);
    $attack2 = Attack::factory()->create(['assessment_id' => $other->id]);
    $defence = Defence::factory()->create(['assessment_id' => $other->id]);
    $defence2 = Defence::factory()->create(['assessment_id' => $other->id]);

    foreach ([[$risk, $attack, 0], [$risk, $attack2, 1]] as [$parent, $child, $position]) {
        $link = new RiskAttack;
        $link->risk_node_id = $parent->id;
        $link->attack_node_id = $child->id;
        $link->position = $position;
        $link->save();
    }
    foreach ([[$attack, $defence, 0], [$attack, $defence2, 1]] as [$parent, $child, $position]) {
        $link = new AttackDefence;
        $link->attack_node_id = $parent->id;
        $link->defence_node_id = $child->id;
        $link->position = $position;
        $link->save();
    }

    $uuids = collect(compact('risk', 'risk2', 'attack', 'attack2', 'defence', 'defence2'))
        ->map(fn ($node): string => $node->uuid)
        ->all();

    $state = fn (): array => [
        $other->nodes()->withTrashed()->orderBy('id')->get(['id', 'position', 'deleted_at'])->toArray(),
        RiskAttack::query()->orderBy('id')->get(['id', 'risk_node_id', 'attack_node_id', 'position'])->toArray(),
        AttackDefence::query()->orderBy('id')->get(['id', 'attack_node_id', 'defence_node_id', 'position', 'strength_fraction'])->toArray(),
        AssessmentOperation::query()->where('assessment_id', $other->id)->count(),
    ];
    $before = $state();

    $reached = [];

    foreach (nodeScopedBodyRequests() as $i => [$name, $payload]) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull("route {$name} no longer exists — update this list deliberately");

        $status = $this->actingAs($caller)
            ->json($route->methods()[0], route($name, $own), $payload($uuids))
            ->getStatusCode();

        if (! in_array($status, [404, 422], true)) {
            $reached[] = "#{$i} {$name} answered {$status}";
        }
    }

    expect($reached)->toBe([]);
    expect($state())->toBe($before);
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
