<?php

use App\Domain\Mail\MailTokens;
use App\Domain\Mail\PasswordResets;
use App\Jobs\SendPasswordResetLink;
use App\Jobs\SendTemplatedMail;
use App\Models\InstanceSetting;
use App\Models\MailToken;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/AccountMailTest.php';
require_once __DIR__.'/../Auth/TwoFactor/helpers.php';

/*
 * "Forgot password?" by email: the same answer for every address, a link that
 * works once, every session ended, and no automatic sign-in.
 */

const NEW_PASSWORD = 'brand new horse staple 77';

function requestReset(string $email): void
{
    test()->post('/forgot-password', ['email' => $email])->assertRedirect(route('login'));
}

beforeEach(fn () => RateLimiter::clear('password-reset:'.hash('sha256', 'a@example.test')));

test('with mail off the routes do not exist for the visitor and the login page offers no link', function () {
    $this->get('/forgot-password')->assertNotFound();
    $this->post('/forgot-password', ['email' => 'a@example.test'])->assertNotFound();
    User::factory()->create();
    $this->get('/login')->assertInertia(fn ($page) => $page->where('canReset', false));
});

test('with mail on the login page offers the link', function () {
    mailOn();
    User::factory()->create();

    $this->get('/login')->assertInertia(fn ($page) => $page->where('canReset', true));
    $this->get('/forgot-password')->assertOk();
});

test('a known and an unknown address get the same answer; only the known one is mailed', function () {
    Bus::fake([SendTemplatedMail::class]);
    mailOn();
    User::factory()->create(['email' => 'a@example.test']);

    $known = $this->post('/forgot-password', ['email' => 'A@Example.test']);
    $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.test']);

    expect($known->headers->get('Location'))->toBe($unknown->headers->get('Location'))
        ->and(session('status'))->toBe('If that address has an account, we sent a link to reset the password.');
    Bus::assertDispatchedTimes(SendTemplatedMail::class, 1);
    expect(DB::table('security_events')->where('event', 'auth.password_reset_requested')->count())->toBe(2);
    $this->assertDatabaseHas('security_events', ['event' => 'auth.password_reset_requested']);
});

test('the link shows a page, the form spends it once, sessions end and nobody is signed in', function () {
    Bus::fake([SendTemplatedMail::class]);
    mailOn();
    $user = User::factory()->create(['email' => 'a@example.test']);
    requestReset('a@example.test');
    $path = queuedLink('reset_link');

    $this->get($path)->assertInertia(fn ($page) => $page->where('valid', true));
    $this->get($path)->assertInertia(fn ($page) => $page->where('valid', true));

    $this->post($path, ['password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD])->assertRedirect(route('login'));

    $user->refresh();
    expect(Hash::check(NEW_PASSWORD, $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();
    $this->assertGuest();
    $this->assertDatabaseHas('security_events', ['event' => 'account.password_reset_by_email']);

    // Spent: the same link now looks like any other bad one and changes nothing.
    $this->get($path)->assertInertia(fn ($page) => $page->where('valid', false));
    $this->post($path, ['password' => 'another correct horse 99', 'password_confirmation' => 'another correct horse 99'])->assertRedirect($path);
    expect(Hash::check(NEW_PASSWORD, $user->fresh()->password))->toBeTrue();
});

test('the other sessions of the account are deleted', function () {
    Bus::fake([SendTemplatedMail::class]);
    mailOn();
    $user = User::factory()->create(['email' => 'a@example.test']);
    DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    requestReset('a@example.test');

    $this->post(queuedLink('reset_link'), ['password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD]);

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

test('an expired, made-up or invitation link resets nothing', function () {
    Bus::fake([SendTemplatedMail::class]);
    mailOn();
    $user = User::factory()->create(['email' => 'a@example.test']);
    requestReset('a@example.test');
    $path = queuedLink('reset_link');
    MailToken::query()->where('user_id', $user->id)->update(['expires_at' => now()->subMinute()]);

    $this->get($path)->assertInertia(fn ($page) => $page->where('valid', false));
    $this->post($path, ['password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD]);
    $this->get('/reset-password/'.str_repeat('x', 48))->assertInertia(fn ($page) => $page->where('valid', false));
    expect(Hash::check(NEW_PASSWORD, $user->fresh()->password))->toBeFalse();
});

test('a reset link is not an invitation link', function () {
    Bus::fake([SendTemplatedMail::class]);
    mailOn();
    $user = User::factory()->create(['email' => 'a@example.test']);
    requestReset('a@example.test');
    $token = basename(queuedLink('reset_link'));

    $this->get('/invitation/'.$token)->assertInertia(fn ($page) => $page->where('valid', false));
});

test('the password must follow the policy', function () {
    Bus::fake([SendTemplatedMail::class]);
    mailOn();
    $user = User::factory()->create(['email' => 'a@example.test']);
    requestReset('a@example.test');
    $path = queuedLink('reset_link');

    $this->post($path, ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
    expect(MailToken::query()->where('user_id', $user->id)->whereNull('used_at')->count())->toBe(1);
});

test('a deactivated account and an SSO-only non-admin get no link, and an admin does', function () {
    Bus::fake([SendTemplatedMail::class]);
    mailOn();
    User::factory()->create(['email' => 'off@example.test', 'is_active' => false]);
    $member = User::factory()->create(['email' => 'a@example.test']);
    $admin = User::factory()->admin()->create(['email' => 'admin@example.test']);

    requestReset('off@example.test');
    Bus::assertNothingDispatched();

    // sso_only counts only while a provider is configured.
    config(['arguesecure.oidc.issuer' => 'https://idp.example.test', 'arguesecure.oidc.client_id' => 'client', 'arguesecure.oidc.client_secret' => 'secret']);
    InstanceSetting::singleton()->forceFill(['sso_only' => true])->save();
    requestReset('a@example.test');
    Bus::assertNothingDispatched();

    requestReset('admin@example.test');
    Bus::assertDispatchedTimes(SendTemplatedMail::class, 1);
    expect(PasswordResets::eligible($member))->toBeFalse()->and(PasswordResets::eligible($admin))->toBeTrue();
});

test('a link issued before the account was deactivated no longer works', function () {
    Bus::fake([SendTemplatedMail::class]);
    mailOn();
    $user = User::factory()->create(['email' => 'a@example.test']);
    requestReset('a@example.test');
    $path = queuedLink('reset_link');
    $user->forceFill(['is_active' => false])->save();

    $this->post($path, ['password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD]);

    expect(Hash::check(NEW_PASSWORD, $user->fresh()->password))->toBeFalse();
});

test('a fourth request for one address in an hour sends nothing and answers the same', function () {
    Bus::fake([SendTemplatedMail::class]);
    mailOn();
    User::factory()->create(['email' => 'a@example.test']);

    foreach (range(1, 4) as $i) {
        // A link sent in the last ten minutes is kept, not replaced; age it so
        // each request is one the hourly limit, not that rule, decides.
        MailToken::query()->update(['created_at' => now()->subMinutes(11)]);
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.$i"])->post('/forgot-password', ['email' => 'a@example.test'])->assertRedirect(route('login'));
    }

    Bus::assertDispatchedTimes(SendTemplatedMail::class, 3);
});

test('a signed-in user is turned away from the forgot-password page', function () {
    mailOn();

    $this->actingAs(User::factory()->create())->get('/forgot-password')->assertRedirect();
});

test('only a hash of the reset token is stored', function () {
    Bus::fake([SendTemplatedMail::class]);
    mailOn();
    User::factory()->create(['email' => 'a@example.test']);
    requestReset('a@example.test');
    $token = basename(queuedLink('reset_link'));

    expect(MailToken::query()->where('purpose', MailTokens::RESET)->where('token_hash', $token)->exists())->toBeFalse()
        ->and(MailToken::query()->where('purpose', MailTokens::RESET)->count())->toBe(1);
});

test('a reset clears the forced-change flag and voids the account\'s other unused links', function () {
    Bus::fake([SendTemplatedMail::class]);
    mailOn();
    $user = User::factory()->create(['email' => 'a@example.test', 'must_change_password' => true]);
    MailTokens::issue(MailTokens::VERIFY, $user->email, $user, new DateInterval('PT1H'));
    requestReset('a@example.test');

    $this->post(queuedLink('reset_link'), ['password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD]);

    expect($user->fresh()->must_change_password)->toBeFalse()
        ->and(MailToken::query()->where('user_id', $user->id)->whereNull('used_at')->count())->toBe(0);
});

test('an account with an unused invitation gets no reset link', function () {
    Bus::fake([SendTemplatedMail::class]);
    mailOn();
    User::factory()->create(['email' => 'a@example.test', 'invited_at' => now(), 'last_login_at' => null]);

    requestReset('a@example.test');

    Bus::assertNothingDispatched();
});

test('a dead link says so before any password rule is checked', function () {
    $path = '/reset-password/'.str_repeat('x', 48);
    mailOn();

    $this->post($path, ['password' => 'short', 'password_confirmation' => 'x'])->assertRedirect($path)->assertSessionHasNoErrors();
});

test('every address queues the same job, and the lookup is left to it', function () {
    Bus::fake([SendPasswordResetLink::class]);
    mailOn();
    User::factory()->create(['email' => 'a@example.test']);

    requestReset('a@example.test');
    requestReset('nobody@example.test');

    Bus::assertDispatchedTimes(SendPasswordResetLink::class, 2);
});

test('with mail off the reset page answers 404 too', function () {
    $this->get('/reset-password/'.str_repeat('x', 48))->assertNotFound();
});

test('a second request within ten minutes does not replace the link', function () {
    Bus::fake([SendTemplatedMail::class]);
    mailOn();
    $user = User::factory()->create(['email' => 'a@example.test']);

    requestReset('a@example.test');
    $first = queuedLink('reset_link');
    requestReset('a@example.test');

    Bus::assertDispatchedTimes(SendTemplatedMail::class, 1);
    $this->get($first)->assertInertia(fn ($page) => $page->where('valid', true));

    $this->travel(PasswordResets::KEEP_MINUTES + 1)->minutes();
    requestReset('a@example.test');
    Bus::assertDispatchedTimes(SendTemplatedMail::class, 2);
    $this->get($first)->assertInertia(fn ($page) => $page->where('valid', false));
});

test('the per-IP limit stops a fourth request in a minute', function () {
    mailOn();

    foreach (range(1, 3) as $i) {
        $this->post('/forgot-password', ['email' => "n$i@example.test"])->assertRedirect(route('login'));
    }
    $this->post('/forgot-password', ['email' => 'n4@example.test'])->assertStatus(429);
});

test('after a reset an account with a second factor still needs it to sign in', function () {
    Bus::fake([SendTemplatedMail::class]);
    mailOn();
    [$user] = userWithTwoFactor();
    $user->forceFill(['email' => 'a@example.test'])->save();
    requestReset('a@example.test');

    $this->post(queuedLink('reset_link'), ['password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD])->assertRedirect(route('login'));

    passwordStep($this, $user->fresh(), NEW_PASSWORD)->assertRedirect(route('two-factor.challenge'));
    $this->assertGuest();
});

test('an account created by SSO sign-in can reset to a local password while SSO only is off', function () {
    Bus::fake([SendTemplatedMail::class]);
    mailOn();
    $user = User::factory()->create(['email' => 'a@example.test', 'last_login_at' => now()]);
    $identity = new UserIdentity;
    $identity->user_id = $user->id;
    $identity->issuer = 'https://idp.example.test';
    $identity->subject = 'subject-1';
    $identity->save();

    requestReset('a@example.test');

    $this->post(queuedLink('reset_link'), ['password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD])->assertRedirect(route('login'));
    expect(Hash::check(NEW_PASSWORD, $user->fresh()->password))->toBeTrue()
        ->and($user->identities()->count())->toBe(1);
});
