<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
 * security.md §4: "No public registration. Login only, throttled to
 * 5 attempts per minute per IP and per email, with a generic failure
 * message that does not reveal whether the account exists."
 *
 * Rate-limit state lives in the cache; RefreshDatabase's per-test
 * transaction rolls back the database cache driver's rows along with
 * everything else, so no manual clearing is needed between tests here.
 */

test('a wrong password and a nonexistent email produce the identical failure message', function () {
    $user = User::factory()->create();

    $wrongPassword = $this->post('/login', [
        'email' => $user->email,
        'password' => 'definitely-wrong',
    ]);

    $noSuchAccount = $this->post('/login', [
        'email' => 'nonexistent@example.com',
        'password' => 'whatever',
    ]);

    expect($wrongPassword->getSession()->get('errors')->get('email'))
        ->toBe($noSuchAccount->getSession()->get('errors')->get('email'));
});

test('login is rate limited after five attempts', function () {
    $user = User::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
    }

    $response = $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);

    $response->assertSessionHasErrors('email');
    $message = $response->getSession()->get('errors')->get('email')[0];
    expect($message)->toContain('Too many login attempts');
});

test('a deactivated user cannot log in even with the correct password', function () {
    [$user, $password] = userWithKnownPassword(['is_active' => false]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => $password,
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors('email');
});

test('no registration route exists anywhere', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [])->assertNotFound();
    expect(Route::has('register'))->toBeFalse();
});
