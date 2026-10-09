<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

test('login screen can be rendered', function () {
    // An empty users table redirects '/login' to first-run setup (W8) —
    // seed one user so this test exercises the ordinary login screen.
    User::factory()->create();

    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('login screen redirects to first-run setup when no user exists', function () {
    $response = $this->get('/login');

    $response->assertRedirect(route('setup'));
});

test('no self-service password reset route is exposed', function () {
    // Accounts are admin-managed (docs/specs/security.md §4). Login.vue used to
    // link at route('password.request'); Ziggy throws on an unknown route name,
    // so a reappearing reference would break the login page at runtime.
    expect(Route::has('password.request'))->toBeFalse();
    expect(Route::has('register'))->toBeFalse();

    $this->get('/forgot-password')->assertNotFound();
    $this->get('/register')->assertNotFound();
});

test('users can authenticate using the login screen', function () {
    [$user, $password] = userWithKnownPassword();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => $password,
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});
