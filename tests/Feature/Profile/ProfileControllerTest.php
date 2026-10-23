<?php

use App\Http\Controllers\ProfileController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Replaces the flat tests/Feature/ProfileTest.php Breeze scaffold test
 * (moved here under tests/Feature/Profile/**, which this package owns,
 * since ProfileController's behaviour changed: email changes now require
 * the current password — security.md §4 — which the old assertions did
 * not account for).
 */

test('anonymous user cannot view the profile page', function () {
    $this->get('/profile')->assertRedirect('/login');
});

test('deactivated user cannot view the profile page', function () {
    $user = User::factory()->inactive()->create();

    $response = $this->actingAs($user)->get('/profile');

    $response->assertForbidden();
    $this->assertGuest();
});

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/profile');

    $response->assertOk();
});

test('profile name can be updated without a password', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response->assertSessionHasNoErrors()->assertRedirect('/profile');

    $this->assertSame('Test User', $user->refresh()->name);
});

test('changing email requires the current password', function () {
    [$user, $password] = userWithKnownPassword();

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->patch('/profile', [
            'name' => $user->name,
            'email' => 'new-address@example.com',
        ]);

    $response->assertSessionHasErrors('current_password')->assertRedirect('/profile');
    $this->assertNotSame('new-address@example.com', $user->refresh()->email);

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => $user->name,
            'email' => 'new-address@example.com',
            'current_password' => $password,
        ]);

    $response->assertSessionHasNoErrors()->assertRedirect('/profile');
    $this->assertSame('new-address@example.com', $user->refresh()->email);
    $this->assertNull($user->email_verified_at);
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response->assertSessionHasNoErrors()->assertRedirect('/profile');
    $this->assertNotNull($user->refresh()->email_verified_at);
});

test('user can delete their account', function () {
    [$user, $password] = userWithKnownPassword();

    $response = $this
        ->actingAs($user)
        ->delete('/profile', ['password' => $password]);

    $response->assertSessionHasNoErrors()->assertRedirect('/');

    $this->assertGuest();
    $this->assertNull($user->fresh());
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->delete('/profile', ['password' => Str::random(24)]);

    $response->assertSessionHasErrors('password')->assertRedirect('/profile');
    $this->assertNotNull($user->fresh());
});

test('user can revoke another one of their own sessions', function () {
    // A seeded row stands in for the other device; SessionRevocationTest
    // signs in on two devices and checks the revoked one is signed out.
    $user = User::factory()->create();

    DB::table('sessions')->insert([
        'id' => 'other-device-session-id',
        'user_id' => $user->id,
        'ip_address' => '10.0.0.5',
        'user_agent' => 'Other Browser',
        'payload' => base64_encode('irrelevant'),
        'last_activity' => now()->timestamp,
    ]);

    $this->actingAs($user)->get('/profile')->assertOk();

    $this->delete('/profile/sessions/other-device-session-id');
    $this->assertDatabaseMissing('sessions', ['id' => 'other-device-session-id']);
});

test('user cannot revoke a session belonging to someone else', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    DB::table('sessions')->insert([
        'id' => 'someone-elses-session-id',
        'user_id' => $other->id,
        'ip_address' => '10.0.0.5',
        'user_agent' => 'Other Browser',
        'payload' => base64_encode('irrelevant'),
        'last_activity' => now()->timestamp,
    ]);

    $this->actingAs($user)->delete('/profile/sessions/someone-elses-session-id');

    $this->assertDatabaseHas('sessions', ['id' => 'someone-elses-session-id']);
});

test('revoking the current session id is rejected, not silently deleted', function () {
    // SessionRevocationTest covers this over HTTP; this exercises
    // ProfileController's rule directly: it must refuse to delete the id
    // matching the request's own session rather than deferring to
    // SessionRegistry.
    $user = User::factory()->create();

    // Store::isValidId() requires a 40-char alphanumeric id (Laravel's
    // real session id shape) or it silently generates a random one
    // instead of using ours — so this has to look like a real one.
    $sessionId = Str::random(40);

    DB::table('sessions')->insert([
        'id' => $sessionId,
        'user_id' => $user->id,
        'ip_address' => '10.0.0.1',
        'user_agent' => 'This Browser',
        'payload' => base64_encode('irrelevant'),
        'last_activity' => now()->timestamp,
    ]);

    $store = new Store('test_session', new ArraySessionHandler(120), $sessionId);

    $request = Request::create("/profile/sessions/{$sessionId}", 'DELETE');
    $request->setLaravelSession($store);
    $request->setUserResolver(fn () => $user);

    (new ProfileController)->revokeSession($request, $sessionId);

    $this->assertDatabaseHas('sessions', ['id' => $sessionId]);
});
