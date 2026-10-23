<?php

use App\Domain\Identity\TwoFactor\Events\RecoveryCodesRegenerated;
use App\Domain\Identity\TwoFactor\Events\TwoFactorDisabled;
use App\Domain\Identity\TwoFactor\Events\TwoFactorEnabled;
use App\Domain\Identity\TwoFactor\Totp;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:10'));
});

afterEach(fn () => Carbon::setTestNow());

/** Start an enrolment as `$user` and return the secret the page now shows. */
function startEnrolment($test, User $user, string $password): string
{
    $test->actingAs($user)->post('/profile/two-factor', ['password' => $password])->assertSessionHasNoErrors();

    return $test->actingAs($user)->get('/profile')->inertiaProps('twoFactor.pending.secret');
}

test('starting needs the current password', function () {
    [$user] = userWithKnownPassword();

    $this->actingAs($user)->post('/profile/two-factor', ['password' => 'not-my-password-at-all'])
        ->assertSessionHasErrors('password');

    $this->actingAs($user)->get('/profile')->assertInertia(fn (Assert $page) => $page
        ->where('twoFactor.enabled', false)
        ->where('twoFactor.pending', null));
});

test('enrolment stores nothing until a code from the new secret is confirmed', function () {
    [$user, $password] = userWithKnownPassword();

    $secret = startEnrolment($this, $user, $password);

    expect($secret)->toBeString()->not->toBeEmpty();
    $row = DB::table('users')->where('id', $user->id)->first();
    expect($row->two_factor_secret)->toBeNull();
    expect($row->two_factor_recovery_codes)->toBeNull();
    expect($row->two_factor_confirmed_at)->toBeNull();
    expect($user->refresh()->hasTwoFactorEnabled())->toBeFalse();

    // A wrong code leaves it that way, and the user still signs in on the password alone.
    $this->actingAs($user)->post('/profile/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
    expect($user->refresh()->hasTwoFactorEnabled())->toBeFalse();
});

test('an abandoned enrolment leaves two-factor authentication off', function () {
    [$user, $password] = userWithKnownPassword();
    startEnrolment($this, $user, $password);

    // Signing out and in again: the half-done secret is gone with the session and the password still suffices.
    $this->post('/logout');
    passwordStep($this, $user, $password)->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
});

test('cancelling drops the pending secret', function () {
    [$user, $password] = userWithKnownPassword();
    startEnrolment($this, $user, $password);

    $this->actingAs($user)->delete('/profile/two-factor/pending')->assertRedirect();

    $this->actingAs($user)->get('/profile')->assertInertia(fn (Assert $page) => $page->where('twoFactor.pending', null));
});

test('the pending page carries the secret and a QR code that encodes it', function () {
    [$user, $password] = userWithKnownPassword();
    $secret = startEnrolment($this, $user, $password);

    $props = $this->actingAs($user)->get('/profile')->inertiaProps('twoFactor.pending');

    expect($secret)->toMatch('/^[A-Z2-7]{32}$/');
    expect($props['qr'])->toStartWith('data:image/svg+xml;base64,');
    expect(base64_decode(substr($props['qr'], strlen('data:image/svg+xml;base64,'))))->toContain('<svg');
});

test('a right code turns it on, shows ten recovery codes once, and spends the code', function () {
    Event::fake([TwoFactorEnabled::class]);
    [$user, $password] = userWithKnownPassword();
    $secret = startEnrolment($this, $user, $password);

    $response = $this->actingAs($user)->post('/profile/two-factor/confirm', ['code' => totpCode($secret)]);

    $response->assertSessionHasNoErrors()->assertSessionHas('recoveryCodes');
    expect(session('recoveryCodes'))->toHaveCount(10);
    Event::assertDispatched(TwoFactorEnabled::class);

    $user->refresh();
    expect($user->hasTwoFactorEnabled())->toBeTrue();
    expect($user->two_factor_secret)->toBe($secret);
    expect($user->two_factor_last_step)->toBe(app(Totp::class)->currentStep());

    // On the page once, and not on the next visit.
    $first = $this->actingAs($user)->get('/profile');
    $first->assertInertia(fn (Assert $page) => $page
        ->where('twoFactor.enabled', true)
        ->where('twoFactor.recovery_codes_remaining', 10)
        ->where('twoFactor.pending', null)
        ->has('recoveryCodes', 10));
    $this->actingAs($user)->get('/profile')->assertInertia(fn (Assert $page) => $page->where('recoveryCodes', null));
});

test('confirming with no enrolment started fails', function () {
    [$user] = userWithKnownPassword();

    $this->actingAs($user)->post('/profile/two-factor/confirm', ['code' => '123456'])->assertSessionHasErrors('code');
    expect($user->refresh()->hasTwoFactorEnabled())->toBeFalse();
});

test('starting again while it is on is refused', function () {
    [$user, $password] = userWithTwoFactor();

    $this->actingAs($user)->post('/profile/two-factor', ['password' => $password])->assertSessionHasErrors('password');
});

test('regenerating recovery codes needs the password and replaces the old codes', function () {
    Event::fake([RecoveryCodesRegenerated::class]);
    [$user, $password, $secret, $old] = userWithTwoFactor();

    $this->actingAs($user)->post('/profile/two-factor/recovery-codes', ['password' => 'wrong-password-entirely', 'code' => totpCode($secret)])
        ->assertSessionHasErrors('password');
    // A password without a code is not enough, as turning it off needs both.
    $this->actingAs($user)->post('/profile/two-factor/recovery-codes', ['password' => $password])
        ->assertSessionHasErrors('code');
    $this->actingAs($user)->post('/profile/two-factor/recovery-codes', ['password' => $password, 'code' => '000000'])
        ->assertSessionHasErrors('code');
    expect($user->refresh()->two_factor_recovery_codes)->toHaveCount(10);

    $this->actingAs($user)->post('/profile/two-factor/recovery-codes', ['password' => $password, 'code' => totpCode($secret)])
        ->assertSessionHas('recoveryCodes');
    Event::assertDispatched(RecoveryCodesRegenerated::class);

    $new = session('recoveryCodes');
    expect($new)->toHaveCount(10)->and(array_intersect($new, $old))->toBe([]);

    // The old ones no longer sign in.
    $this->post('/logout');
    passwordStep($this, $user, $password);
    $this->post('/two-factor-challenge', ['recovery_code' => $old[0]])->assertSessionHasErrors('code');
    $this->assertGuest();
});

test('turning it off needs the password and a code, and ends the other sessions', function () {
    Event::fake([TwoFactorDisabled::class]);
    [$user, $password, $secret] = userWithTwoFactor();
    DB::table('sessions')->insert([
        'id' => 'other-session-id', 'user_id' => $user->id, 'ip_address' => '10.0.0.9',
        'user_agent' => 'phone', 'payload' => '', 'last_activity' => now()->getTimestamp(),
    ]);

    // Password alone, then a code alone: neither suffices.
    $this->actingAs($user)->delete('/profile/two-factor', ['password' => $password, 'code' => '000000'])
        ->assertSessionHasErrors('code');
    $this->actingAs($user)->delete('/profile/two-factor', ['password' => 'wrong-password-entirely', 'code' => totpCode($secret)])
        ->assertSessionHasErrors('password');
    expect($user->refresh()->hasTwoFactorEnabled())->toBeTrue();
    expect(DB::table('sessions')->where('id', 'other-session-id')->exists())->toBeTrue();

    $this->actingAs($user)->delete('/profile/two-factor', ['password' => $password, 'code' => totpCode($secret)])
        ->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->hasTwoFactorEnabled())->toBeFalse();
    $row = DB::table('users')->where('id', $user->id)->first();
    expect([$row->two_factor_secret, $row->two_factor_recovery_codes, $row->two_factor_confirmed_at, $row->two_factor_last_step])
        ->toBe([null, null, null, null]);
    expect(DB::table('sessions')->where('id', 'other-session-id')->exists())->toBeFalse();
    Event::assertDispatched(TwoFactorDisabled::class);
});

test('a recovery code can turn it off', function () {
    [$user, $password, , $codes] = userWithTwoFactor();

    $this->actingAs($user)->delete('/profile/two-factor', ['password' => $password, 'code' => $codes[0]])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->hasTwoFactorEnabled())->toBeFalse();
});

test('turning it off or regenerating when it is not on is a 404', function () {
    [$user, $password] = userWithKnownPassword();

    $this->actingAs($user)->delete('/profile/two-factor', ['password' => $password, 'code' => '123456'])->assertNotFound();
    $this->actingAs($user)->post('/profile/two-factor/recovery-codes', ['password' => $password, 'code' => '123456'])->assertNotFound();
});

test('turning it on, off or removing it by an admin invalidates remember-me cookies', function () {
    [$user, $password] = userWithKnownPassword();
    $user->forceFill(['remember_token' => 'old-remember-token'])->save();
    $secret = startEnrolment($this, $user, $password);

    $this->actingAs($user)->post('/profile/two-factor/confirm', ['code' => totpCode($secret)]);
    expect($user->refresh()->remember_token)->not->toBe('old-remember-token');

    $user->forceFill(['remember_token' => 'old-remember-token'])->save();
    $this->actingAs($user)->delete('/profile/two-factor', ['password' => $password, 'code' => totpCode($secret, 1)]);
    expect($user->refresh()->remember_token)->not->toBe('old-remember-token');

    [$other] = userWithTwoFactor();
    [$admin] = userWithKnownPassword(['is_admin' => true]);
    $other->forceFill(['remember_token' => 'old-remember-token'])->save();
    $this->actingAs($admin)->delete("/admin/users/{$other->id}/two-factor");
    expect($other->refresh()->remember_token)->not->toBe('old-remember-token');
});

test('the stored secret and the recovery codes appear in no prop, JSON body or serialisation', function () {
    [$user, $password, $secret, $codes] = userWithTwoFactor();
    [$admin] = userWithKnownPassword(['is_admin' => true]);

    $needles = [$secret, ...$codes, ...array_map(fn ($c) => str_replace('-', '', $c), $codes), ...($user->two_factor_recovery_codes)];

    $bodies = [
        $this->actingAs($user)->get('/profile')->getContent(),
        $this->actingAs($user)->get('/dashboard')->getContent(),
        $this->actingAs($admin)->get("/admin/users/{$user->id}/edit")->getContent(),
        $this->actingAs($admin)->get('/admin/users')->getContent(),
        json_encode($user->toArray()),
        $user->toJson(),
    ];

    foreach ($bodies as $body) {
        foreach ($needles as $needle) {
            expect($body)->not->toContain($needle);
        }
    }

    $this->actingAs($user)->get('/profile')->assertInertia(fn (Assert $page) => $page
        ->missing('auth.user.two_factor_secret')
        ->missing('auth.user.two_factor_recovery_codes')
        ->missing('auth.user.two_factor_confirmed_at')
        ->missing('auth.user.two_factor_last_step'));
});

test('nobody can read or change another account\'s enrolment', function () {
    [$mine, $myPassword] = userWithKnownPassword();
    [$theirs, , $theirSecret, $theirCodes] = userWithTwoFactor();

    // Every action acts on the signed-in account; there is no id to point at someone else.
    $this->actingAs($mine)->delete("/profile/two-factor/{$theirs->id}")->assertStatus(404);
    $this->actingAs($mine)->post('/profile/two-factor', ['password' => $myPassword, 'user_id' => $theirs->id]);
    $this->actingAs($mine)->post('/profile/two-factor/confirm', ['code' => totpCode($theirSecret), 'user_id' => $theirs->id])
        ->assertSessionHasErrors('code');

    $theirs->refresh();
    expect($theirs->two_factor_secret)->toBe($theirSecret);
    expect($theirs->two_factor_recovery_codes)->toHaveCount(10);
    expect($mine->refresh()->hasTwoFactorEnabled())->toBeFalse();
    expect($theirCodes)->toHaveCount(10);
});

test('every profile two-factor route needs a signed-in user', function () {
    $this->post('/profile/two-factor', ['password' => 'x'])->assertRedirect(route('login'));
    $this->post('/profile/two-factor/confirm', ['code' => '123456'])->assertRedirect(route('login'));
    $this->delete('/profile/two-factor/pending')->assertRedirect(route('login'));
    $this->post('/profile/two-factor/recovery-codes', ['password' => 'x'])->assertRedirect(route('login'));
    $this->delete('/profile/two-factor', ['password' => 'x', 'code' => '1'])->assertRedirect(route('login'));
});
