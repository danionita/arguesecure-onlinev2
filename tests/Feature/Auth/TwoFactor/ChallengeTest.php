<?php

use App\Domain\Identity\TwoFactor\Events\RecoveryCodeUsed;
use App\Domain\Identity\TwoFactor\Events\TwoFactorChallengeFailed;
use App\Domain\Identity\TwoFactor\PendingLogin;
use App\Domain\Identity\TwoFactor\Totp;
use App\Domain\Identity\TwoFactor\TwoFactor;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    // Fixed mid-step, so a code cannot roll over between two lines of a test.
    Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:10'));
});

afterEach(fn () => Carbon::setTestNow());

test('a correct password alone does not sign in an account with a second factor', function () {
    [$user, $password] = userWithTwoFactor();

    passwordStep($this, $user, $password)->assertRedirect(route('two-factor.challenge'));

    $this->assertGuest();
    // The next request to an authenticated route is still a guest's.
    $this->get('/dashboard')->assertRedirect(route('login'));
    $this->assertGuest();
});

test('an account without a second factor signs in with the password alone, as before', function () {
    [$user, $password] = userWithKnownPassword();

    passwordStep($this, $user, $password)->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('the challenge page needs a pending sign-in', function () {
    $this->get('/two-factor-challenge')->assertRedirect(route('login'));

    [$user, $password] = userWithTwoFactor();
    passwordStep($this, $user, $password);

    $this->get('/two-factor-challenge')->assertOk();
});

test('a pending sign-in does not leave a remember-me cookie behind', function () {
    [$user, $password] = userWithTwoFactor();

    $response = passwordStep($this, $user, $password, remember: true);

    expect(rememberCookies($response))->toBe([]);
});

test('the current code signs in, and remember-me is honoured only now', function () {
    [$user, $password, $secret] = userWithTwoFactor();
    passwordStep($this, $user, $password, remember: true);

    $response = $this->post('/two-factor-challenge', ['code' => totpCode($secret)]);

    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
    expect(rememberCookies($response))->not->toBe([]);
    expect($user->refresh()->last_login_at)->not->toBeNull();
});

test('a code from the adjacent step is accepted', function (int $steps) {
    [$user, $password, $secret] = userWithTwoFactor();
    passwordStep($this, $user, $password);

    $this->post('/two-factor-challenge', ['code' => totpCode($secret, $steps)])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
})->with([-1, 1]);

test('a code two steps away is refused', function (int $steps) {
    [$user, $password, $secret] = userWithTwoFactor();
    passwordStep($this, $user, $password);

    $this->post('/two-factor-challenge', ['code' => totpCode($secret, $steps)])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
})->with([-2, 2]);

test('a code that was already accepted is refused again (replay)', function () {
    [$user, $password, $secret] = userWithTwoFactor();
    $code = totpCode($secret);

    passwordStep($this, $user, $password);
    $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect();
    $this->post('/logout');

    // The same code, still inside its validity, from a fresh sign-in.
    passwordStep($this, $user, $password);
    $this->post('/two-factor-challenge', ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();

    // An older step's code is refused too, not only the very same one.
    $this->post('/two-factor-challenge', ['code' => totpCode($secret, -1)])->assertSessionHasErrors('code');
    $this->assertGuest();
});

test('two requests carrying one code race, and only one gets in', function () {
    [$user, , $secret] = userWithTwoFactor();
    $code = totpCode($secret);

    // Both read the account before either wrote: the replay check on the
    // stale copy passes for both, so only the conditional UPDATE decides.
    $first = User::query()->findOrFail($user->id);
    $second = User::query()->findOrFail($user->id);

    expect(app(TwoFactor::class)->acceptCode($first, $code))->toBeTrue();
    expect(app(TwoFactor::class)->acceptCode($second, $code))->toBeFalse();
});

test('the code that confirmed enrolment cannot be the first sign-in', function () {
    [$user, $password] = userWithKnownPassword();
    $totp = app(Totp::class);
    $secret = $totp->newSecret();
    app(TwoFactor::class)->enable($user, $secret, $totp->currentStep());

    passwordStep($this, $user, $password);

    $this->post('/two-factor-challenge', ['code' => totpCode($secret)])->assertSessionHasErrors('code');
    $this->assertGuest();
});

test('a recovery code works once', function () {
    Event::fake([RecoveryCodeUsed::class]);
    [$user, $password, , $codes] = userWithTwoFactor();

    passwordStep($this, $user, $password);
    $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
    Event::assertDispatched(RecoveryCodeUsed::class);
    $this->post('/logout');

    passwordStep($this, $user, $password);
    $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]])->assertSessionHasErrors('code');
    $this->assertGuest();

    // Another code still works, typed in capitals.
    $this->post('/two-factor-challenge', ['recovery_code' => strtoupper($codes[1])])
        ->assertRedirect(route('dashboard', absolute: false));
});

test('a recovery code is also accepted in the code field, and the count drops by one', function () {
    [$user, $password, , $codes] = userWithTwoFactor();
    expect($user->two_factor_recovery_codes)->toHaveCount(10);

    passwordStep($this, $user, $password);
    $this->post('/two-factor-challenge', ['code' => $codes[3]])->assertRedirect();

    expect($user->refresh()->two_factor_recovery_codes)->toHaveCount(9);
    expect(app(TwoFactor::class)->recoveryCodesRemaining($user))->toBe(9);
});

test('recovery codes are not stored in plain text', function () {
    [$user, , , $codes] = userWithTwoFactor();

    $raw = DB::table('users')->where('id', $user->id)->value('two_factor_recovery_codes');

    foreach ($codes as $code) {
        expect($raw)->not->toContain($code);
        expect($user->two_factor_recovery_codes)->not->toContain($code);
        expect($user->two_factor_recovery_codes)->not->toContain(str_replace('-', '', $code));
    }
    // What is held, even decrypted, is one hash per code.
    expect($user->two_factor_recovery_codes)->toHaveCount(10);
    foreach ($user->two_factor_recovery_codes as $stored) {
        expect($stored)->toMatch('/^[0-9a-f]{64}$/');
    }
});

test('the secret is encrypted at rest', function () {
    [$user, , $secret] = userWithTwoFactor();

    expect(DB::table('users')->where('id', $user->id)->value('two_factor_secret'))->not->toContain($secret);
    expect($user->two_factor_secret)->toBe($secret);
});

test('five wrong codes lock the pending account out of the sixth, even a right one', function () {
    Event::fake([TwoFactorChallengeFailed::class]);
    [$user, $password, $secret] = userWithTwoFactor();
    passwordStep($this, $user, $password);

    for ($i = 0; $i < 5; $i++) {
        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
    }
    Event::assertDispatchedTimes(TwoFactorChallengeFailed::class, 5);

    $this->post('/two-factor-challenge', ['code' => totpCode($secret)])
        ->assertSessionHasErrors(['code' => 'Too many attempts. Please try again in 60 seconds.']);
    $this->assertGuest();
});

test('the per-IP bucket holds across different accounts', function () {
    [$one, $passwordOne] = userWithTwoFactor();
    [$two, $passwordTwo, $secretTwo] = userWithTwoFactor();

    // Four wrong codes against one account, one against another: five from one address.
    passwordStep($this, $one, $passwordOne);
    for ($i = 0; $i < 4; $i++) {
        $this->post('/two-factor-challenge', ['code' => '000000']);
    }
    $this->flushSession();
    passwordStep($this, $two, $passwordTwo);
    $this->post('/two-factor-challenge', ['code' => '000000']);

    $this->post('/two-factor-challenge', ['code' => totpCode($secretTwo)])
        ->assertSessionHasErrors('code');
    $this->assertGuest();
});

test('the pending sign-in expires after five minutes', function () {
    [$user, $password, $secret] = userWithTwoFactor();
    passwordStep($this, $user, $password);

    Carbon::setTestNow(now()->addSeconds(301));

    $this->post('/two-factor-challenge', ['code' => totpCode($secret)])->assertRedirect(route('login'));
    $this->assertGuest();
    $this->get('/two-factor-challenge')->assertRedirect(route('login'));
});

test('the pending sign-in is still good just inside five minutes', function () {
    [$user, $password, $secret] = userWithTwoFactor();
    passwordStep($this, $user, $password);

    Carbon::setTestNow(now()->addSeconds(295));

    $this->post('/two-factor-challenge', ['code' => totpCode($secret)])
        ->assertRedirect(route('dashboard', absolute: false));
});

test('the session id changes on success and the pending account is gone from it', function () {
    [$user, $password, $secret] = userWithTwoFactor();
    passwordStep($this, $user, $password);
    $before = session()->getId();
    expect(session()->has(PendingLogin::SESSION_KEY))->toBeTrue();

    $this->post('/two-factor-challenge', ['code' => totpCode($secret)]);

    expect(session()->getId())->not->toBe($before);
    expect(session()->has(PendingLogin::SESSION_KEY))->toBeFalse();
});

test('the password step also moves the session off whatever id it arrived with', function () {
    [$user, $password] = userWithTwoFactor();
    $this->get('/login');
    $before = session()->getId();

    passwordStep($this, $user, $password);

    expect(session()->getId())->not->toBe($before);
});

test('a deactivated account with a second factor is refused at the password step, before any challenge', function () {
    [$user, $password] = userWithTwoFactor(['is_active' => false]);

    passwordStep($this, $user, $password)->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);

    expect(session()->has(PendingLogin::SESSION_KEY))->toBeFalse();
    $this->get('/two-factor-challenge')->assertRedirect(route('login'));
});

test('an account deactivated between the steps is refused at the challenge', function () {
    [$user, $password, $secret] = userWithTwoFactor();
    passwordStep($this, $user, $password);
    $user->forceFill(['is_active' => false])->save();

    $this->post('/two-factor-challenge', ['code' => totpCode($secret)])->assertSessionHasErrors('code');
    $this->assertGuest();
});

test('a wrong password never reaches the challenge', function () {
    [$user] = userWithTwoFactor();

    $this->post('/login', ['email' => $user->email, 'password' => 'not-the-password-at-all'])
        ->assertSessionHasErrors('email');

    expect(session()->has(PendingLogin::SESSION_KEY))->toBeFalse();
});

test('the challenge needs a code or a recovery code', function () {
    [$user, $password] = userWithTwoFactor();
    passwordStep($this, $user, $password);

    $this->post('/two-factor-challenge', [])->assertSessionHasErrors(['code', 'recovery_code']);
});

test('an authenticated user is sent away from the challenge', function () {
    $this->actingAs(User::factory()->create())->get('/two-factor-challenge')->assertRedirect();
});
