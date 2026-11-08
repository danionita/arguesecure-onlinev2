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

test('registration is off by default: its routes answer 404', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [])->assertNotFound();
    expect(Route::has('register'))->toBeTrue();
});

/*
 * The lockout is per email-and-address pair (and per address), by decision: a
 * bucket on the email alone, across addresses, would let anyone keep a named
 * account's owner out by sending bad passwords. These attempts come from
 * different addresses to pin that the pair is what locks.
 */
function failedLoginFrom(string $ip, string $email)
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->post('/login', ['email' => $email, 'password' => 'wrong']);
}

test('five failures from one address lock that email and address pair, not the account for everyone', function () {
    [$user, $password] = userWithKnownPassword();

    for ($i = 0; $i < 5; $i++) {
        failedLoginFrom('203.0.113.1', $user->email);
    }

    $locked = failedLoginFrom('203.0.113.1', $user->email);
    expect($locked->getSession()->get('errors')->get('email')[0])->toContain('Too many login attempts');

    // The owner, from another address, is not locked out by someone else's guesses.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
        ->post('/login', ['email' => $user->email, 'password' => $password]);
    $this->assertAuthenticatedAs($user);
});

test('failures against one account from many addresses lock none of them', function () {
    $user = User::factory()->create();

    for ($i = 1; $i <= 30; $i++) {
        $response = failedLoginFrom("203.0.113.{$i}", $user->email);
        expect($response->getSession()->get('errors')->get('email')[0])->toContain('do not match');
    }
});

test('the email part of the pair key ignores case, so changing case does not reset the lock', function () {
    $user = User::factory()->create(['email' => 'owner@example.com']);

    for ($i = 0; $i < 5; $i++) {
        failedLoginFrom('203.0.113.1', $i % 2 ? 'OWNER@example.com' : 'owner@Example.com');
    }

    $response = failedLoginFrom('203.0.113.1', 'Owner@EXAMPLE.com');

    expect($response->getSession()->get('errors')->get('email')[0])->toContain('Too many login attempts');
});
