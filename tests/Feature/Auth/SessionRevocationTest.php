<?php

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/*
 * W107 (D71). Every revocation path deletes rows of the `sessions` table
 * (SessionRegistry), so it only revokes anything when that table is where
 * the sessions live. These tests sign in on two "devices" through the real
 * login route, revoke one from the other, and send the revoked device's
 * cookie back: it must arrive as a guest.
 *
 * The test client shares one application between requests, so a device
 * switch drops the guard's cached user, the session store and the cookies
 * the previous request left behind; what reaches the next request is only
 * the cookie that device holds, as with a real browser.
 */

/**
 * Make the next request come from a browser holding $sessionId (or no
 * session cookie at all).
 */
function onDevice(TestCase $test, ?string $sessionId): void
{
    app('auth')->forgetGuards();
    app()->forgetInstance('auth.driver');
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');

    (fn () => $this->defaultCookies = [])->call($test);

    if ($sessionId !== null) {
        $test->withCookie(config('session.cookie'), $sessionId);
    }
}

/**
 * Sign in through POST /login on a fresh browser and return the session id
 * its cookie now carries.
 */
function signInOnNewDevice(TestCase $test, User $user, string $password, bool $remember = false): string
{
    onDevice($test, null);

    $test->post('/login', [
        'email' => $user->email,
        'password' => $password,
        'remember' => $remember,
    ])->assertSessionHasNoErrors();

    $test->assertAuthenticatedAs($user);

    return app('session')->driver()->getId();
}

/**
 * The device holding $sessionId asks for its profile page: true when it is
 * still signed in.
 */
function deviceIsSignedIn(TestCase $test, string $sessionId): bool
{
    onDevice($test, $sessionId);

    $response = $test->get('/profile');

    if (! auth()->check()) {
        $response->assertRedirect(route('login'));

        return false;
    }

    return true;
}

beforeEach(function () {
    // The point of these tests: on the array driver, which the suite used
    // before D71, they pass whether or not anything is revoked.
    expect(config('session.driver'))->toBe('database');
});

test('sessions are stored in the database whatever SESSION_DRIVER says', function () {
    // D71. Installs before 2.7 copied SESSION_DRIVER=redis from .env.example
    // into their own .env, which compose loads over anything the image
    // sets; only a value config/session.php does not read from the
    // environment reaches them on upgrade.
    $previous = getenv('SESSION_DRIVER');
    putenv('SESSION_DRIVER=redis');
    $_ENV['SESSION_DRIVER'] = $_SERVER['SESSION_DRIVER'] = 'redis';

    try {
        $config = require config_path('session.php');
    } finally {
        $previous === false ? putenv('SESSION_DRIVER') : putenv("SESSION_DRIVER={$previous}");
        unset($_ENV['SESSION_DRIVER'], $_SERVER['SESSION_DRIVER']);
    }

    expect($config['driver'])->toBe('database')
        ->and($config['table'])->toBe('sessions');
});

test('the example environment does not offer a session driver', function () {
    // A SESSION_DRIVER line would read as a setting that does something.
    expect(file_get_contents(base_path('.env.example')))->not->toMatch('/^\s*#?\s*SESSION_DRIVER\s*=/m');
});

test('signing in writes a session row the profile page lists', function () {
    [$user, $password] = userWithKnownPassword();

    $laptop = signInOnNewDevice($this, $user, $password);
    $phone = signInOnNewDevice($this, $user, $password);

    expect($laptop)->not->toBe($phone);
    $this->assertDatabaseHas('sessions', ['id' => $laptop, 'user_id' => $user->id]);
    $this->assertDatabaseHas('sessions', ['id' => $phone, 'user_id' => $user->id]);

    onDevice($this, $laptop);
    $this->get('/profile')->assertInertia(fn ($page) => $page
        ->has('sessions', 2)
        ->where('sessions', fn ($sessions) => collect($sessions)->firstWhere('id', $laptop)['is_current'] === true
            && collect($sessions)->firstWhere('id', $phone)['is_current'] === false));
});

test('changing the password signs out every other session', function () {
    [$user, $password] = userWithKnownPassword();
    $replacement = Str::random(24);

    $laptop = signInOnNewDevice($this, $user, $password);
    $phone = signInOnNewDevice($this, $user, $password);

    onDevice($this, $laptop);
    $this->from('/profile')->put('/password', [
        'current_password' => $password,
        'password' => $replacement,
        'password_confirmation' => $replacement,
    ])->assertSessionHasNoErrors();

    expect(deviceIsSignedIn($this, $phone))->toBeFalse()
        ->and(deviceIsSignedIn($this, $laptop))->toBeTrue();
});

test('changing the email signs out every other session', function () {
    [$user, $password] = userWithKnownPassword();

    $laptop = signInOnNewDevice($this, $user, $password);
    $phone = signInOnNewDevice($this, $user, $password);

    onDevice($this, $laptop);
    $this->patch('/profile', [
        'name' => $user->name,
        'email' => 'moved-'.$user->email,
        'current_password' => $password,
    ])->assertSessionHasNoErrors();

    expect(deviceIsSignedIn($this, $phone))->toBeFalse()
        ->and(deviceIsSignedIn($this, $laptop))->toBeTrue();
});

test('revoking a session on the profile page signs that device out', function () {
    [$user, $password] = userWithKnownPassword();

    $laptop = signInOnNewDevice($this, $user, $password);
    $phone = signInOnNewDevice($this, $user, $password);
    $tablet = signInOnNewDevice($this, $user, $password);

    onDevice($this, $laptop);
    $this->from('/profile')->delete("/profile/sessions/{$phone}")->assertRedirect('/profile');

    expect(deviceIsSignedIn($this, $phone))->toBeFalse()
        ->and(deviceIsSignedIn($this, $tablet))->toBeTrue()
        ->and(deviceIsSignedIn($this, $laptop))->toBeTrue();
});

test('the profile page will not revoke the session it is asked from', function () {
    [$user, $password] = userWithKnownPassword();

    $laptop = signInOnNewDevice($this, $user, $password);

    onDevice($this, $laptop);
    $this->from('/profile')->delete("/profile/sessions/{$laptop}")->assertRedirect('/profile');

    expect(deviceIsSignedIn($this, $laptop))->toBeTrue();
    $this->assertDatabaseHas('sessions', ['id' => $laptop]);
});

test('an admin password reset signs out every session of that user', function () {
    [$admin, $adminPassword] = userWithKnownPassword(['is_admin' => true]);
    [$user, $password] = userWithKnownPassword();

    $laptop = signInOnNewDevice($this, $user, $password);
    $phone = signInOnNewDevice($this, $user, $password);
    $console = signInOnNewDevice($this, $admin, $adminPassword);

    onDevice($this, $console);
    $this->post("/admin/users/{$user->id}/reset-password")->assertRedirect(route('admin.users.index'));
    $this->assertDatabaseMissing('sessions', ['id' => $laptop]);
    $this->assertDatabaseHas('sessions', ['id' => $console]);

    expect(deviceIsSignedIn($this, $laptop))->toBeFalse()
        ->and(deviceIsSignedIn($this, $phone))->toBeFalse()
        ->and(deviceIsSignedIn($this, $console))->toBeTrue();
});

test('deactivating a user deletes their session rows', function () {
    // The 'active' middleware rejects a deactivated user on any session
    // (EnsureUserIsActiveTest), so the row deletion is what this pins.
    [$admin, $adminPassword] = userWithKnownPassword(['is_admin' => true]);
    [$user, $password] = userWithKnownPassword();

    $laptop = signInOnNewDevice($this, $user, $password);
    $console = signInOnNewDevice($this, $admin, $adminPassword);

    onDevice($this, $console);
    $this->put("/admin/users/{$user->id}", [
        'name' => $user->name,
        'email' => $user->email,
        'is_admin' => false,
        'is_active' => false,
    ])->assertSessionHasNoErrors()->assertRedirect(route('admin.users.index'));

    $this->assertDatabaseMissing('sessions', ['id' => $laptop]);
    $this->assertDatabaseHas('sessions', ['id' => $console]);
});

/**
 * The cookie a "remember me" login left on the device the last sign-in came
 * from, as the browser would send it back.
 */
function rememberCookie(): Cookie
{
    return collect(app('cookie')->getQueuedCookies())
        ->first(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web'));
}

test('a revoked device cannot sign itself back in with its remember-me cookie', function () {
    [$user, $password] = userWithKnownPassword();

    $laptop = signInOnNewDevice($this, $user, $password);
    $phone = signInOnNewDevice($this, $user, $password, remember: true);
    $recaller = rememberCookie();
    expect($recaller)->not->toBeNull();

    onDevice($this, $laptop);
    $this->from('/profile')->delete("/profile/sessions/{$phone}")->assertRedirect('/profile');

    onDevice($this, null);
    $this->withCookie($recaller->getName(), $recaller->getValue());
    $this->get('/profile')->assertRedirect(route('login'));
    expect(auth()->check())->toBeFalse();
});

test('changing the email also ends remembered devices', function () {
    [$user, $password] = userWithKnownPassword();

    $laptop = signInOnNewDevice($this, $user, $password);
    signInOnNewDevice($this, $user, $password, remember: true);
    $recaller = rememberCookie();

    onDevice($this, $laptop);
    $this->patch('/profile', [
        'name' => $user->name,
        'email' => 'moved-'.$user->email,
        'current_password' => $password,
    ])->assertSessionHasNoErrors();

    onDevice($this, null);
    $this->withCookie($recaller->getName(), $recaller->getValue());
    $this->get('/profile')->assertRedirect(route('login'));
    expect(auth()->check())->toBeFalse();
});
