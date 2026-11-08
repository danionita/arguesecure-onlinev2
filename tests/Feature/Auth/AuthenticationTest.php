<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

test('login screen can be rendered', function () {
    // An empty users table redirects '/login' to first-run setup —
    // seed one user so this test exercises the ordinary login screen.
    User::factory()->create();

    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('login screen redirects to first-run setup when no user exists', function () {
    $response = $this->get('/login');

    $response->assertRedirect(route('setup'));
});

test('password reset by email and registration are exposed only behind their switches', function () {
    // Login.vue links at route('password.request'); Ziggy throws on an unknown
    // route name, so the route must exist, and answer 404 while mail is off.
    expect(Route::has('password.request'))->toBeTrue();
    // Registration is off by default: the route exists and answers 404.
    expect(Route::has('register'))->toBeTrue();

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
