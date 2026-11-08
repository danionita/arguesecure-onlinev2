<?php

use App\Domain\Identity\TwoFactor\PendingLogin;
use App\Models\User;

require_once __DIR__.'/TwoFactor/helpers.php';

/*
 * security.md §4: an admin account gets no remember-me cookie. The login
 * page offers the toggle to everyone (it cannot know the role before the
 * password is checked), so the server ignores it for an admin, on the
 * password-only path and after the two-factor challenge.
 */

test('an admin who ticks remember me gets no remember-me cookie', function () {
    [$admin, $password] = userWithKnownPassword(['is_admin' => true]);
    $tokenBefore = $admin->remember_token;

    $response = $this->post('/login', ['email' => $admin->email, 'password' => $password, 'remember' => true]);

    $this->assertAuthenticatedAs($admin);
    expect(rememberCookies($response))->toBe([]);
    expect($admin->refresh()->remember_token)->toBe($tokenBefore);
});

test('a non-admin who ticks remember me still gets the cookie', function () {
    [$user, $password] = userWithKnownPassword();

    $response = $this->post('/login', ['email' => $user->email, 'password' => $password, 'remember' => true]);

    expect(rememberCookies($response))->not->toBe([]);
    expect($user->refresh()->remember_token)->not->toBeNull();
});

test('an admin with two-factor gets no remember-me cookie after the challenge', function () {
    [$admin, $password, $secret] = userWithTwoFactor(['is_admin' => true]);

    passwordStep($this, $admin, $password, remember: true);
    // The pending sign-in carries no remember choice for an admin ...
    expect(session(PendingLogin::SESSION_KEY.'.remember'))->toBeFalse();

    $response = $this->post('/two-factor-challenge', ['code' => totpCode($secret)]);

    $this->assertAuthenticatedAs($admin);
    expect(rememberCookies($response))->toBe([]);
});

test('an account promoted between the password step and the challenge gets no remember-me cookie', function () {
    [$user, $password, $secret] = userWithTwoFactor();

    passwordStep($this, $user, $password, remember: true);
    $user->forceFill(['is_admin' => true])->save();

    $response = $this->post('/two-factor-challenge', ['code' => totpCode($secret)]);

    $this->assertAuthenticatedAs($user);
    expect(rememberCookies($response))->toBe([]);
});

test('the upgrade ends remember-me cookies already issued to admins and leaves other accounts alone', function () {
    $admin = User::factory()->admin()->create(['remember_token' => 'issued-before']);
    $user = User::factory()->create(['remember_token' => 'issued-before']);

    (require database_path('migrations/2026_10_24_000002_end_admin_remember_cookies.php'))->up();

    expect($admin->refresh()->remember_token)->toBeNull()
        ->and($user->refresh()->remember_token)->toBe('issued-before');
});
