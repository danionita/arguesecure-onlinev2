<?php

use App\Models\Assessment;
use App\Models\CatalogEntry;
use App\Models\User;

test('setup screen is shown when no users exist', function () {
    $this->get('/setup')->assertOk();
});

test('setup screen redirects to login once a user exists', function () {
    User::factory()->create();

    $this->get('/setup')->assertRedirect(route('login'));
});

test('setup is not reachable again after every user is deactivated', function () {
    // The reachability guard is "does any user row exist", not "does any
    // active user exist" — deactivating every account must not reopen
    // this bootstrap screen (package brief).
    User::factory()->inactive()->create();

    $this->get('/setup')->assertRedirect(route('login'));
    $this->post('/setup', [
        'name' => 'Attacker',
        'email' => 'attacker@example.com',
        'password' => str_repeat('a', 16),
        'password_confirmation' => str_repeat('a', 16),
    ])->assertForbidden();

    expect(User::query()->count())->toBe(1);
});

test('submitting setup creates the first admin and logs them in', function () {
    $password = str_repeat('correct-horse-', 2);

    $response = $this->post('/setup', [
        'name' => 'First Admin',
        'email' => 'first-admin@example.com',
        'password' => $password,
        'password_confirmation' => $password,
    ]);

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticated();

    $user = User::query()->where('email', 'first-admin@example.com')->firstOrFail();
    expect($user->is_admin)->toBeTrue();
    expect($user->is_active)->toBeTrue();
});

test('setup is rejected once a user already exists, even via direct post', function () {
    User::factory()->create();

    $password = str_repeat('correct-horse-', 2);

    $response = $this->post('/setup', [
        'name' => 'Second Admin',
        'email' => 'second-admin@example.com',
        'password' => $password,
        'password_confirmation' => $password,
    ]);

    $response->assertForbidden();
    $this->assertGuest();
    expect(User::query()->where('email', 'second-admin@example.com')->exists())->toBeFalse();
});

test('setup requires a password meeting the minimum length', function () {
    $response = $this->post('/setup', [
        'name' => 'First Admin',
        'email' => 'first-admin@example.com',
        'password' => 'short',
        'password_confirmation' => 'short',
    ]);

    $response->assertSessionHasErrors('password');
    $this->assertGuest();
});

test('completing first-run setup leaves the instance with the bundled templates', function () {
    // W27: a fresh deployment used to reach the dashboard with no templates
    // at all. TemplateAssessmentSeeder needs an owning admin, which does not
    // exist at first boot (and DatabaseSeeder, deleted in 2.5.1, created a
    // test user, which would have broken the empty-users-table precondition
    // this very screen depends on). Setting up the first administrator is the earliest moment
    // an owner exists, so that is where the reference content is seeded.
    //
    // Generated, not a literal: CLAUDE.md's "never write a password into any
    // file" has no test exemption, and nothing here needs to know the value.
    $password = bin2hex(random_bytes(16));

    $this->post('/setup', [
        'name' => 'First Admin',
        'email' => 'first-admin@example.com',
        'password' => $password,
        'password_confirmation' => $password,
    ])->assertRedirect(route('dashboard'));

    $owner = User::query()->where('email', 'first-admin@example.com')->sole();

    $templates = Assessment::query()->where('is_template', true)->get();
    expect($templates)->not->toBeEmpty();
    expect($templates->pluck('owner_user_id')->unique()->all())->toBe([$owner->id]);

    // 2.5.1: every bundled fixture is a template, the demo web shop
    // included — it used to need a `db:seed`, which no longer exists.
    expect(Assessment::query()->where('is_template', false)->exists())->toBeFalse();
    expect($templates->map(fn (Assessment $a) => $a->rootNode?->title)->filter()->count())
        ->toBe($templates->count());
    // The demo, the four archetypes and the seven standards (2.5.1).
    expect($templates)->toHaveCount(12);

    // The ISO catalogue libraries are reference material too, and arrive
    // with the templates rather than with the demo.
    expect(CatalogEntry::query()->whereNull('owner_user_id')->exists())->toBeTrue();
});
