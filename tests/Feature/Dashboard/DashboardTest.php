<?php

use App\Models\Assessment;
use App\Models\User;

/*
 * The dashboard (W42 item 17).
 *
 * Two of its four sections have no data source yet, so most of what is worth
 * asserting today is the shape of the seam: the props exist, they are lists,
 * and W46/W47 fill them without the page changing. The rest is scoping, which
 * is the part that would actually leak.
 */

function dashboardMember(Assessment $assessment, User $user, string $role = 'editor'): void
{
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

test('the dashboard is its own page, not a second copy of the assessment list', function () {
    // `/dashboard` used to render Assessment/Index — the same page at two
    // URLs, and the one Laravel sends people to after login.
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard'));
});

test('a guest is sent to log in', function () {
    User::factory()->create();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('all four sections are present even when every one of them is empty', function () {
    // The empty case is the first thing a new user sees, and it is the case
    // the page is designed around: four explained sections, not four gaps.
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('assignedToMe', 0)
            ->has('mentions', 0)
            ->has('myAssessments', 0)
            ->has('sharedWithMe', 0)
        );
});

test('owned and shared assessments land in different sections', function () {
    $user = User::factory()->create();
    $mine = Assessment::factory()->create(['owner_user_id' => $user->id]);

    $owner = User::factory()->create(['name' => 'Dana']);
    $shared = Assessment::factory()->create(['owner_user_id' => $owner->id]);
    dashboardMember($shared, $user);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('myAssessments', 1)
            ->where('myAssessments.0.uuid', $mine->uuid)
            ->has('sharedWithMe', 1)
            ->where('sharedWithMe.0.uuid', $shared->uuid)
        );
});

test('every shared row names the owner — the thing that was missing', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create(['name' => 'Dana']);
    $shared = Assessment::factory()->create(['owner_user_id' => $owner->id]);
    dashboardMember($shared, $user);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('sharedWithMe.0.owner_name', 'Dana')
        );
});

test('an owned row carries no owner name, because it would say your own', function () {
    $user = User::factory()->create();
    Assessment::factory()->create(['owner_user_id' => $user->id]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->missing('myAssessments.0.owner_name')
        );
});

test('an owner’s address never reaches the page', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create(['name' => 'Dana', 'email' => 'dana@example.test']);
    $shared = Assessment::factory()->create(['owner_user_id' => $owner->id]);
    dashboardMember($shared, $user);

    $response = $this->actingAs($user)->get(route('dashboard'));

    // A dashboard is a page about other people's work, which is exactly
    // where an address leaks (security.md, and the W17 report cover).
    expect($response->getContent())->not->toContain('dana@example.test');
});

test('a public assessment I have nothing to do with is not on my dashboard', function () {
    // `is_public` grants read to whoever holds the link. It does not
    // advertise the assessment on a stranger's arrival page.
    $user = User::factory()->create();
    Assessment::factory()->create(['is_public' => true]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('myAssessments', 0)
            ->has('sharedWithMe', 0)
        );
});

test('each section is an excerpt, not the whole list', function () {
    $user = User::factory()->create();
    Assessment::factory()->count(8)->create(['owner_user_id' => $user->id]);

    // A dashboard that paginates has stopped being a dashboard; the full
    // list is one click away on /assessments.
    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->has('myAssessments', 6));
});

test('the most recently touched assessments come first', function () {
    $user = User::factory()->create();
    $old = Assessment::factory()->create(['owner_user_id' => $user->id]);
    $recent = Assessment::factory()->create(['owner_user_id' => $user->id]);

    $old->forceFill(['updated_at' => now()->subYear()])->saveQuietly();
    $recent->forceFill(['updated_at' => now()])->saveQuietly();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('myAssessments.0.uuid', $recent->uuid)
        );
});

// D91 (W125): the tab counts are the real totals, not the six-row excerpt.
test('the Mine count is every owned assessment, not the excerpt', function () {
    $user = User::factory()->create();
    Assessment::factory()->count(8)->create(['owner_user_id' => $user->id]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('myAssessments', 6)
            ->where('counts.mine', 8)
        );
});

test('the Mine count leaves out a trashed assessment, as /assessments does', function () {
    $user = User::factory()->create();
    Assessment::factory()->count(6)->create(['owner_user_id' => $user->id]);
    Assessment::factory()->create(['owner_user_id' => $user->id])->delete();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('counts.mine', 6));

    $listed = $this->actingAs($user)->get(route('assessments.index'))
        ->viewData('page')['props']['assessments'];

    expect($listed)->toHaveCount(6);
});

test('the Shared count is every assessment shared with the user, and only those', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();

    foreach (range(1, 7) as $_) {
        $shared = Assessment::factory()->create(['owner_user_id' => $owner->id]);
        $shared->members()->attach($user->id, [
            'role' => 'viewer',
            'invited_by' => $owner->id,
            'invited_at' => now(),
            'accepted_at' => now(),
        ]);
    }

    // Public, but never shared with this user: reachable by link, not listed.
    Assessment::factory()->create(['owner_user_id' => $owner->id, 'is_public' => true]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('sharedWithMe', 6)
            ->where('counts.shared', 7)
        );

    $listed = $this->actingAs($user)->get(route('assessments.shared'))
        ->viewData('page')['props']['assessments'];

    expect($listed)->toHaveCount(7);
});
