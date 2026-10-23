<?php

use App\Domain\Sharing\ShareTokenService;
use App\Models\Assessment;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Reporting\ExportFixture;

/*
 * Authorization for the five export routes (W74, D34).
 *
 * CLAUDE.md: "Every route touching an assessment goes through
 * AssessmentPolicy. No exceptions." An export is the highest-consequence
 * read in the application — it produces a file that leaves the server, is
 * attached to an email and is opened somewhere this deployment will never
 * see — so the five new URLs get the same coverage the report page got in
 * W17, over AssessmentPolicy::view()'s full resolution order (is_admin ->
 * owner -> assessment_user.role -> is_public -> share token). This is the
 * class of route legacy defect #4 lived in (security.md).
 *
 * Every case below iterates `ExportFixture::routeNames()` rather than naming
 * one route, so a sixth format cannot be added with authorization coverage
 * for the other five and none of its own.
 */

function exportMember(Assessment $assessment, User $user, string $role): void
{
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

test('an anonymous guest is rejected from every export of a private assessment', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);

    expect(ExportFixture::routeNames())->not->toBeEmpty();

    foreach (ExportFixture::routeNames() as $name) {
        $this->get(route($name, $assessment))
            ->assertForbidden();
    }
});

test('a logged-in stranger with no share is rejected from every export', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $stranger = User::factory()->create();

    foreach (ExportFixture::routeNames() as $name) {
        $this->actingAs($stranger)
            ->get(route($name, $assessment))
            ->assertForbidden();
    }
});

test('the owner can take every export', function () {
    $assessment = ExportFixture::assessment();

    foreach (ExportFixture::routeNames() as $name) {
        $this->actingAs($assessment->owner)
            ->get(route($name, $assessment))
            ->assertOk();
    }
});

test('a viewer collaborator can take every export', function () {
    $assessment = ExportFixture::assessment();
    $viewer = User::factory()->create();
    exportMember($assessment, $viewer, 'viewer');

    foreach (ExportFixture::routeNames() as $name) {
        $this->actingAs($viewer)
            ->get(route($name, $assessment))
            ->assertOk();
    }
});

test('an admin can take every export of any assessment', function () {
    $assessment = ExportFixture::assessment();
    $assessment->update(['is_public' => false]);
    $admin = User::factory()->create(['is_admin' => true]);

    foreach (ExportFixture::routeNames() as $name) {
        $this->actingAs($admin)
            ->get(route($name, $assessment))
            ->assertOk();
    }
});

/*
 * `is_public` grants read only. In v1 it meant world-writable, which was a
 * defect and not a feature (CLAUDE.md, security.md defect #4). Exports are
 * reads, so a public assessment exports for anyone — and the pair of
 * assertions below is the whole point: the same anonymous session that may
 * download the file may not use a mutating route on the same assessment.
 * Asserting only the first half would leave a public export route that had
 * quietly become a write surface indistinguishable from a correct one.
 */
test('a public assessment exports for an anonymous guest, and grants nothing more', function () {
    $assessment = ExportFixture::assessment();
    $assessment->update(['is_public' => true]);

    foreach (ExportFixture::routeNames() as $name) {
        $this->get(route($name, $assessment))->assertOk();
    }

    // 401 rather than 403, because the node routes sit behind `auth` and an
    // anonymous caller never reaches the policy. Either status is the right
    // answer to "may a public reader write?"; what must never happen is a
    // 2xx. Asserting the pair rather than one of them keeps this true if the
    // node routes are ever moved outside `auth` the way the report ones are.
    $write = $this->postJson(route('assessments.nodes.store', $assessment), [
        'type' => 'risk',
        'title' => 'Written by a public reader',
    ]);

    expect($write->getStatusCode())->toBeIn([401, 403]);
});

test('a valid share-link session grants anonymous access to every export', function () {
    $assessment = ExportFixture::assessment();
    $assessment->update(['is_public' => false]);
    $token = ShareTokenService::generate($assessment);

    $this->get('/s/'.$token)->assertRedirect(route('assessments.show', $assessment));

    foreach (ExportFixture::routeNames() as $name) {
        $this->get(route($name, $assessment))->assertOk();
    }
});

/*
 * The rate limit that guards them.
 *
 * security.md §9 sets report generation at 10/min per user, and an export is
 * a report generation — five formats of a large assessment is the most
 * expensive thing an unauthenticated public reader can ask this server to
 * do. W68 found a budget test that stayed green while the thing it guarded
 * was removed, and the pinned-defect note in CLAUDE.md is explicit about the
 * shape of that mistake: assert the *specified value*, not that something is
 * present. `throttle:reports` would still match a `str_contains($m,
 * 'throttle')` check after being loosened to 10,000/min.
 */
test('every export route carries the reports rate limiter by name', function () {
    foreach (ExportFixture::routeNames() as $name) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull();
        expect($route->gatherMiddleware())->toContain('throttle:reports');
    }
});

test('the reports limiter is the 10-per-minute one security.md specifies', function () {
    $limiter = app(RateLimiter::class)->limiter('reports');

    expect($limiter)->not->toBeNull();

    $limits = $limiter(request());
    $limit = is_array($limits) ? $limits[0] : $limits;

    expect($limit->maxAttempts)->toBe(10)
        ->and($limit->decaySeconds)->toBe(60);
});
