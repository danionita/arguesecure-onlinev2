<?php

use App\Models\Assessment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * The sidebar's shared props (W41).
 *
 * The pair worth stating plainly, because W41 is the package that introduces
 * the temptation to confuse them: `nav.isAdmin` decides whether a link is
 * *drawn*. `EnsureUserIsAdmin` decides whether the route *answers*. The
 * sidebar spec asserts the first, this file asserts the second, and they are
 * deliberately in different suites so that deleting one cannot quietly look
 * like it still covers the other.
 *
 * The rest is scoping: a sidebar that listed someone else's assessments, or
 * that leaked an email address into a "shared with me" row, would do so on
 * every page in the application at once.
 */

function navMember(Assessment $assessment, User $user, string $role = 'editor'): void
{
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

test('the admin route 403s for a non-admin even though the link is hidden', function () {
    // Hiding a link is courtesy. This is the guard.
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
});

test('the admin route answers for an admin', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
});

test('the nav prop reports admin status, which is what draws the link', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $plain = User::factory()->create(['is_admin' => false]);

    $this->actingAs($admin)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('nav.isAdmin', true));

    $this->actingAs($plain)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('nav.isAdmin', false));
});

test('my assessments are mine, and nobody else’s', function () {
    $user = User::factory()->create();
    $mine = Assessment::factory()->create(['owner_user_id' => $user->id]);
    Assessment::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('nav.myAssessments', 1)
            ->where('nav.myAssessments.0.uuid', $mine->uuid)
        );
});

test('an assessment shared with me is listed separately, not among my own', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create(['name' => 'Dana']);
    $shared = Assessment::factory()->create(['owner_user_id' => $owner->id]);
    navMember($shared, $user);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('nav.myAssessments', 0)
            ->has('nav.sharedWithMe', 1)
            ->where('nav.sharedWithMe.0.ownerName', 'Dana')
        );
});

test('a shared row names the owner and never their address', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create(['name' => 'Dana', 'email' => 'dana@example.test']);
    $shared = Assessment::factory()->create(['owner_user_id' => $owner->id]);
    navMember($shared, $user);

    $response = $this->actingAs($user)->get(route('dashboard'));

    // An assessment list is a page about other people's work, which is
    // exactly where an address leaks — and this one renders on every page.
    $response->assertInertia(fn ($page) => $page
        ->missing('nav.sharedWithMe.0.ownerEmail')
    );
    expect($response->getContent())->not->toContain('dana@example.test');
});

test('a public assessment I have nothing to do with is not in my sidebar', function () {
    // `is_public` grants read to whoever holds the link (CLAUDE.md). It does
    // not put the assessment in a stranger's navigation.
    $user = User::factory()->create();
    Assessment::factory()->create(['is_public' => true]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('nav.myAssessments', 0)
            ->has('nav.sharedWithMe', 0)
        );
});

test('the lists are capped, because a sidebar is scanned rather than read', function () {
    $user = User::factory()->create();
    // Eleven, not fifty: each assessment drags a full scale calibration
    // behind it and ScaleLevelFactory's unique() generator runs dry long
    // before the number gets interesting. One over the cap proves the cap.
    Assessment::factory()->count(11)->create(['owner_user_id' => $user->id]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->has('nav.myAssessments', 10));
});

test('a guest page carries no nav lists at all', function () {
    // The login screen shares the same middleware. Building the lists for a
    // null user would be a query per anonymous request, and a crash.
    //
    // A user has to exist first: an empty `users` table redirects /login to
    // the first-run setup screen (CLAUDE.md — accounts are admin-created,
    // there is no default one).
    User::factory()->create();

    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('nav.authenticated', false));
});

test('the sidebar does not issue a query per assessment', function () {
    $user = User::factory()->create();
    Assessment::factory()->count(6)->create(['owner_user_id' => $user->id]);

    DB::enableQueryLog();
    $this->actingAs($user)->get(route('dashboard'));
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // Titles live on the root node (schema.md), so an unguarded map over the
    // list is an N+1 on every page in the application — the exact shape
    // CLAUDE.md names as the legacy tree render's defining defect. The bound
    // is loose on purpose: this is a page load, not a unit, and the assertion
    // that matters is "does not grow with the number of assessments".
    expect(count($queries))->toBeLessThan(20);
});
