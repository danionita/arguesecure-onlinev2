<?php

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Hash;

require_once __DIR__.'/Capture.php';

test('a sign-in is logged with its actor, target and address', function () {
    [$user, $password] = userWithKnownPassword();
    $log = captureSecurityLog();

    $this->post('/login', ['email' => $user->email, 'password' => $password])->assertRedirect();

    $line = securityLine($log, 'auth.login');
    expect($line['actor'])->toBe(['id' => $user->id, 'email' => $user->email])
        ->and($line['target'])->toBe(['type' => 'user', 'id' => $user->id, 'email' => $user->email])
        ->and($line['ip'])->toBe('127.0.0.1')
        ->and($line['level'])->toBe('INFO');
});

test('a failed sign-in is logged as a warning naming the account tried, never the password', function () {
    [$user] = userWithKnownPassword();
    $log = captureSecurityLog();

    $this->post('/login', ['email' => $user->email, 'password' => 'not-the-password-xyz']);

    $line = securityLine($log, 'auth.login_failed');
    expect($line['actor'])->toBeNull()
        ->and($line['target']['email'])->toBe($user->email)
        ->and($line['target']['id'])->toBe($user->id)
        ->and($line['details'])->toBe(['reason' => 'credentials'])
        ->and($line['ip'])->toBe('127.0.0.1')
        ->and($line['level'])->toBe('WARNING')
        ->and(json_encode(securityLines($log)))->not->toContain('not-the-password-xyz');
});

test('a sign-in for an account that does not exist is logged by the email typed, with no id', function () {
    $log = captureSecurityLog();

    $this->post('/login', ['email' => 'Nobody@Example.com', 'password' => 'whatever-123456']);

    $line = securityLine($log, 'auth.login_failed');
    expect($line['target'])->toBe(['type' => 'user', 'id' => null, 'email' => 'nobody@example.com']);
});

test('a deactivated account that signs in is logged as refused, not as a sign-in', function () {
    [$user, $password] = userWithKnownPassword(['is_active' => false]);
    $log = captureSecurityLog();

    $this->post('/login', ['email' => $user->email, 'password' => $password]);

    $events = array_column(securityLines($log), 'event');
    expect($events)->not->toContain('auth.login')
        ->and($events)->not->toContain('auth.logout');
    $line = securityLine($log, 'auth.login_failed');
    expect($line['target']['id'])->toBe($user->id)
        ->and($line['details'])->toBe(['reason' => 'inactive']);
});

test('the sixth attempt is logged as a lockout', function () {
    $log = captureSecurityLog();

    foreach (range(1, 6) as $i) {
        $this->post('/login', ['email' => 'locked@example.com', 'password' => 'wrong-password-'.$i]);
    }

    $line = securityLine($log, 'auth.lockout');
    expect($line['target']['email'])->toBe('locked@example.com')
        ->and($line['level'])->toBe('WARNING')
        ->and($line['ip'])->toBe('127.0.0.1');
});

test('signing out is logged', function () {
    $user = User::factory()->create();
    $log = captureSecurityLog();

    $this->actingAs($user)->post('/logout');

    $line = securityLine($log, 'auth.logout');
    expect($line['actor']['id'])->toBe($user->id)
        ->and($line['target']['id'])->toBe($user->id);
});

test('changing your own password is logged as a password change by the same account', function () {
    [$user, $password] = userWithKnownPassword();
    $log = captureSecurityLog();
    $new = 'a-Brand-New-Passphrase-'.bin2hex(random_bytes(4));

    $this->actingAs($user)->put('/password', [
        'current_password' => $password,
        'password' => $new,
        'password_confirmation' => $new,
    ])->assertSessionHasNoErrors();

    $line = securityLine($log, 'auth.password_changed');
    expect($line['actor']['id'])->toBe($user->id)
        ->and($line['target']['id'])->toBe($user->id)
        ->and(json_encode(securityLines($log)))->not->toContain($new)->not->toContain($password);
});

test('the re-hash Laravel does at sign-in is not logged as a password change or reset', function () {
    [$user, $password] = userWithKnownPassword();
    $log = captureSecurityLog();
    // The stored hash is now out of date, so the sign-in re-hashes it.
    config(['hashing.driver' => 'bcrypt', 'hashing.bcrypt.rounds' => 6, 'hashing.bcrypt.verify' => false]);
    Hash::forgetDrivers();
    $user->forceFill(['password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4])])->saveQuietly();
    $stale = $user->fresh()->password;

    $this->post('/login', ['email' => $user->email, 'password' => $password])->assertRedirect();

    // Guards the test itself: without a re-hash it would prove nothing.
    expect($user->fresh()->password)->not->toBe($stale);
    $events = array_column(securityLines($log), 'event');
    expect($events)->toContain('auth.login')
        ->and($events)->not->toContain('account.password_reset')
        ->and($events)->not->toContain('auth.password_changed');
});

/*
 * W116 parks an MFA account's sign-in after the password step, which fires
 * Login and then Logout, under the session key `two_factor.pending`.
 */
test('the Login and Logout of a parked two-factor sign-in are not logged', function () {
    $user = User::factory()->create();
    $log = captureSecurityLog();
    session()->put('two_factor.pending', $user->id);
    request()->setLaravelSession(session()->driver());

    event(new Login('web', $user, false));
    event(new Logout('web', $user));

    expect(array_values(array_filter(array_column(securityLines($log), 'event'), fn ($e) => str_starts_with($e, 'auth.'))))->toBe([]);
});

test('once the challenge has passed and the pending key is gone, the sign-in is logged', function () {
    $user = User::factory()->create();
    $log = captureSecurityLog();
    request()->setLaravelSession(session()->driver());

    event(new Login('web', $user, false));

    expect(array_values(array_filter(array_column(securityLines($log), 'event'), fn ($e) => str_starts_with($e, 'auth.'))))->toBe(['auth.login']);
});
