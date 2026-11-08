<?php

use App\Domain\Mail\EmailVerification;
use App\Domain\Mail\Invitations;
use App\Domain\Mail\MailSettings;
use App\Domain\Mail\MailTokens;
use App\Events\EmailAddressVerified;
use App\Jobs\SendTemplatedMail;
use App\Models\InstanceSetting;
use App\Models\MailToken;
use App\Models\SecurityEventRecord;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

/*
 * Account mail: the invitation with a one-time link (never a password), its
 * two reminders, and the address-verification service other features use.
 */

const GOOD_PASSWORD = 'correct horse battery staple 42';

function mailOn(): void
{
    config(['arguesecure.mail' => [
        'host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'starttls', 'username' => null,
        'password' => null, 'from_address' => 'argue@example.test', 'from_name' => null,
    ]]);
    InstanceSetting::singleton()->forceFill(['mail_tested_fingerprint' => MailSettings::fingerprint()])->save();
}

/** The link in the one job that was queued, as its path. */
function queuedLink(string $key): string
{
    $link = null;
    Bus::assertDispatched(SendTemplatedMail::class, function (SendTemplatedMail $job) use (&$link, $key) {
        $link = $job->values[$key];

        return true;
    });

    return parse_url($link, PHP_URL_PATH);
}

test('with mail off, creating a user keeps the temporary-password flow and queues nothing', function () {
    Bus::fake();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/admin/users', ['name' => 'New', 'email' => 'new@example.test', 'is_admin' => false])
        ->assertRedirect();

    $user = User::query()->where('email', 'new@example.test')->firstOrFail();
    expect($user->must_change_password)->toBeTrue()
        ->and($user->invited_at)->toBeNull();
    Bus::assertNotDispatched(SendTemplatedMail::class);
});

test('with mail on, creating a user mails an invitation and generates no password anyone sees', function () {
    Bus::fake();
    mailOn();
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->post('/admin/users', ['name' => 'New', 'email' => 'new@example.test', 'is_admin' => false]);

    $response->assertRedirect(route('admin.users.index'))->assertSessionMissing('generatedPassword');
    $user = User::query()->where('email', 'new@example.test')->firstOrFail();
    expect($user->invited_at)->not->toBeNull()->and($user->must_change_password)->toBeFalse();
    Bus::assertDispatched(SendTemplatedMail::class, fn (SendTemplatedMail $j) => $j->kind === 'invitation' && $j->email === 'new@example.test');
    $this->assertDatabaseHas('security_events', ['event' => 'account.invited']);
});

test('an admin can turn the invitation off, or type a password, and then nothing is mailed', function () {
    Bus::fake();
    mailOn();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/admin/users', ['name' => 'A', 'email' => 'a@example.test', 'is_admin' => false, 'send_invitation' => false]);
    $this->actingAs($admin)->post('/admin/users', ['name' => 'B', 'email' => 'b@example.test', 'is_admin' => false, 'password' => GOOD_PASSWORD, 'password_confirmation' => GOOD_PASSWORD]);

    Bus::assertNotDispatched(SendTemplatedMail::class);
});

test('following the link only shows a page; the form spends it and signs the person in', function () {
    Bus::fake();
    mailOn();
    $user = User::factory()->create(['last_login_at' => null]);
    Invitations::send($user, null);
    $path = queuedLink('set_password_link');

    $this->get($path)->assertOk()->assertInertia(fn ($page) => $page->where('valid', true));
    $this->get($path)->assertInertia(fn ($page) => $page->where('valid', true));

    $this->post($path, ['password' => GOOD_PASSWORD, 'password_confirmation' => GOOD_PASSWORD])->assertRedirect(route('dashboard'));

    $user->refresh();
    expect(Hash::check(GOOD_PASSWORD, $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->last_login_at)->not->toBeNull();
    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('security_events', ['event' => 'account.invitation_used']);
});

test('a link works once, and a spent, replaced, expired or made-up one all look the same', function () {
    Bus::fake();
    mailOn();
    $user = User::factory()->create(['last_login_at' => null]);

    Invitations::send($user, null);
    $first = queuedLink('set_password_link');
    Bus::fake();
    Invitations::send($user, null);
    $second = queuedLink('set_password_link');

    // The new link replaced the old one.
    $this->get($first)->assertInertia(fn ($page) => $page->where('valid', false));
    $this->post($first, ['password' => GOOD_PASSWORD, 'password_confirmation' => GOOD_PASSWORD])->assertRedirect($first);
    $this->assertGuest();

    $this->post($second, ['password' => GOOD_PASSWORD, 'password_confirmation' => GOOD_PASSWORD])->assertRedirect(route('dashboard'));
    auth()->logout();
    $this->post($second, ['password' => 'another correct horse 99', 'password_confirmation' => 'another correct horse 99']);
    $this->assertGuest();
    expect(Hash::check(GOOD_PASSWORD, $user->fresh()->password))->toBeTrue();

    $this->get('/invitation/'.str_repeat('x', 48))->assertInertia(fn ($page) => $page->where('valid', false));

    $other = User::factory()->create(['last_login_at' => null]);
    Bus::fake();
    Invitations::send($other, null);
    $path = queuedLink('set_password_link');
    MailToken::query()->where('user_id', $other->id)->update(['expires_at' => now()->subMinute()]);
    $this->get($path)->assertInertia(fn ($page) => $page->where('valid', false));
});

test('a link does nothing for an account that was deactivated or already used', function () {
    Bus::fake();
    mailOn();
    $user = User::factory()->create(['last_login_at' => null]);
    Invitations::send($user, null);
    $path = queuedLink('set_password_link');

    $user->forceFill(['is_active' => false])->save();
    $this->get($path)->assertInertia(fn ($page) => $page->where('valid', false));

    $user->forceFill(['is_active' => true, 'last_login_at' => now()])->save();
    $this->post($path, ['password' => GOOD_PASSWORD, 'password_confirmation' => GOOD_PASSWORD]);
    $this->assertGuest();
});

test('the password must follow the policy', function () {
    Bus::fake();
    mailOn();
    $user = User::factory()->create(['last_login_at' => null]);
    Invitations::send($user, null);
    $path = queuedLink('set_password_link');

    $this->post($path, ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
    $this->get($path)->assertInertia(fn ($page) => $page->where('valid', true));
});

test('only a hash of the token is stored, and no password is in any queued message', function () {
    Bus::fake();
    mailOn();
    $user = User::factory()->create(['last_login_at' => null]);
    Invitations::send($user, null);
    $token = basename(queuedLink('set_password_link'));

    $row = MailToken::query()->firstOrFail();
    expect($row->token_hash)->toBe(hash('sha256', $token))->and($row->token_hash)->not->toBe($token);
    Bus::assertDispatched(SendTemplatedMail::class, fn (SendTemplatedMail $j) => ! array_key_exists('password', $j->values));
});

test('mail off or SSO-only or a used account sends no invitation', function () {
    Bus::fake();
    $user = User::factory()->create(['last_login_at' => null]);

    expect(Invitations::send($user, null))->toBeFalse();

    mailOn();
    $used = User::factory()->create(['last_login_at' => now()]);
    expect(Invitations::send($used, null))->toBeFalse();

    Bus::assertNotDispatched(SendTemplatedMail::class);
});

test('reminders go out at day 3 and day 7 and then never again', function () {
    Bus::fake();
    mailOn();
    $user = User::factory()->create(['last_login_at' => null, 'invited_at' => now()->subDays(2)]);

    expect(Invitations::sendDueReminders())->toBe(0);

    $user->forceFill(['invited_at' => now()->subDays(3)->subMinute()])->save();
    expect(Invitations::sendDueReminders())->toBe(1)
        // The same day again: not due for the second yet.
        ->and(Invitations::sendDueReminders())->toBe(0);

    $user->forceFill(['invited_at' => now()->subDays(7)->subMinute()])->save();
    expect(Invitations::sendDueReminders())->toBe(1);

    $user->forceFill(['invited_at' => now()->subDays(60)])->save();
    expect(Invitations::sendDueReminders())->toBe(0)
        ->and($user->fresh()->invitation_reminders_sent)->toBe(2);
    Bus::assertDispatchedTimes(SendTemplatedMail::class, 2);
    $this->assertDatabaseHas('security_events', ['event' => 'account.reminder_sent']);
});

test('no reminder for someone who signed in, an inactive account, or one never invited', function () {
    Bus::fake();
    mailOn();
    User::factory()->create(['last_login_at' => now(), 'invited_at' => now()->subDays(10)]);
    User::factory()->create(['last_login_at' => null, 'is_active' => false, 'invited_at' => now()->subDays(10)]);
    User::factory()->create(['last_login_at' => null, 'invited_at' => null]);

    expect(Invitations::sendDueReminders())->toBe(0);
    Bus::assertNotDispatched(SendTemplatedMail::class);
});

test('no reminder when mail is off', function () {
    Bus::fake();
    User::factory()->create(['last_login_at' => null, 'invited_at' => now()->subDays(10)]);

    expect(Invitations::sendDueReminders())->toBe(0);
});

test('the reminder command runs and is scheduled daily', function () {
    $this->artisan('arguesecure:send-reminders')->expectsOutputToContain('Sent 0 reminder')->assertSuccessful();

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command, 'arguesecure:send-reminders'));
    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 8 * * *');
});

test('when the invitation cannot go, the admin still gets a password to hand over', function () {
    Bus::fake();
    mailOn();
    $oidc = ['issuer' => 'https://idp.example.test', 'client_id' => 'c', 'client_secret' => 's'];
    config(['arguesecure.oidc' => $oidc + ['button_label' => null, 'allowed_domains' => '', 'trust_email' => null]]);
    InstanceSetting::singleton()->forceFill(['sso_only' => true])->save();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/admin/users', ['name' => 'New', 'email' => 'new@example.test', 'is_admin' => false]);

    $user = User::query()->where('email', 'new@example.test')->firstOrFail();
    expect($user->must_change_password)->toBeTrue();
    Bus::assertNotDispatched(SendTemplatedMail::class);
});

test('a resend starts the reminder clock again', function () {
    Bus::fake();
    mailOn();
    $user = User::factory()->create(['last_login_at' => null, 'invited_at' => now()->subDays(20), 'invitation_reminders_sent' => 2]);

    Invitations::send($user, null);

    $fresh = $user->fresh();
    expect($fresh->invitation_reminders_sent)->toBe(0)
        ->and($fresh->invited_at->isToday())->toBeTrue();
});

test('no reminder and no link once sign-in became SSO-only for a non-admin', function () {
    Bus::fake();
    mailOn();
    $user = User::factory()->create(['last_login_at' => null, 'invited_at' => now()->subDays(10)]);
    config(['arguesecure.oidc' => ['issuer' => 'https://idp.example.test', 'client_id' => 'c', 'client_secret' => 's', 'button_label' => null, 'allowed_domains' => '', 'trust_email' => null]]);
    InstanceSetting::singleton()->forceFill(['sso_only' => true])->save();

    expect(Invitations::sendDueReminders())->toBe(0);
    Bus::assertNotDispatched(SendTemplatedMail::class);
});

test('a verification link for an address the account no longer has verifies nothing', function () {
    Bus::fake();
    mailOn();
    $user = User::factory()->create(['email' => 'old@example.test', 'email_verified_at' => null]);
    EmailVerification::send($user->email, $user->name, $user);
    $path = queuedLink('verify_link');

    $user->forceFill(['email' => 'new@example.test'])->save();

    $this->get($path)->assertInertia(fn ($page) => $page->where('valid', false));
    $this->post($path);
    expect($user->fresh()->email_verified_at)->toBeNull();
});

test('an admin resends an invitation, throttled, and only where it would do something', function () {
    Bus::fake();
    mailOn();
    $admin = User::factory()->admin()->create();
    $pending = User::factory()->create(['last_login_at' => null]);
    $used = User::factory()->create(['last_login_at' => now()]);

    $this->actingAs($admin)->post("/admin/users/{$pending->id}/invitation")->assertRedirect();
    Bus::assertDispatchedTimes(SendTemplatedMail::class, 1);

    $this->actingAs($admin)->post("/admin/users/{$used->id}/invitation")->assertSessionHasErrors(['invitation' => 'No invitation was sent. The account has already been used.']);
    Bus::assertDispatchedTimes(SendTemplatedMail::class, 1);

    $this->actingAs(User::factory()->create())->post("/admin/users/{$pending->id}/invitation")->assertForbidden();
});

test('resending an invitation is throttled per target account', function () {
    Bus::fake();
    mailOn();
    $admin = User::factory()->admin()->create();
    $first = User::factory()->create(['last_login_at' => null]);
    $second = User::factory()->create(['last_login_at' => null]);

    foreach (range(1, 3) as $i) {
        $this->actingAs($admin)->post("/admin/users/{$first->id}/invitation")->assertRedirect();
    }
    $this->actingAs($admin)->post("/admin/users/{$first->id}/invitation")->assertStatus(429);

    // Another target is counted apart, so it is still accepted.
    $this->actingAs($admin)->post("/admin/users/{$second->id}/invitation")->assertRedirect();
});

test('the verification service mails a link, shows a button, and only the button spends it', function () {
    Bus::fake();
    Event::fake([EmailAddressVerified::class]);
    mailOn();

    expect(EmailVerification::send('who@example.test', 'Who', null, 'pending-7'))->toBeTrue();
    $path = queuedLink('verify_link');

    $this->get($path)->assertInertia(fn ($page) => $page->where('valid', true));
    $this->get($path)->assertInertia(fn ($page) => $page->where('valid', true));
    Event::assertNotDispatched(EmailAddressVerified::class);

    $this->post($path)->assertRedirect(route('login'));
    Event::assertDispatched(EmailAddressVerified::class, fn ($e) => $e->email === 'who@example.test' && $e->reference === 'pending-7');

    $this->post($path)->assertRedirect($path);
    Event::assertDispatchedTimes(EmailAddressVerified::class, 1);
});

test('verifying an existing account sets email_verified_at', function () {
    Bus::fake();
    mailOn();
    $user = User::factory()->create(['email_verified_at' => null]);

    EmailVerification::send($user->email, $user->name, $user);
    $this->post(queuedLink('verify_link'));

    expect($user->fresh()->email_verified_at)->not->toBeNull();
    $this->assertDatabaseHas('security_events', ['event' => 'account.email_verified']);
});

test('the verification service sends nothing when mail is off', function () {
    Bus::fake();

    expect(EmailVerification::available())->toBeFalse()
        ->and(EmailVerification::send('who@example.test', 'Who'))->toBeFalse();
    Bus::assertNotDispatched(SendTemplatedMail::class);
});

test('a verification link is not an invitation link and the other way round', function () {
    Bus::fake();
    mailOn();
    EmailVerification::send('who@example.test', 'Who', null, 'r1');
    $verify = basename(queuedLink('verify_link'));

    $this->get('/invitation/'.$verify)->assertInertia(fn ($page) => $page->where('valid', false));
    expect(MailTokens::peek(MailTokens::INVITE, $verify))->toBeNull();
});

test('the job renders at send time, and a failure is logged without the address or the link', function () {
    mailOn();
    $job = new SendTemplatedMail('invitation', 'secret.person@example.test', ['name' => 'A', 'set_password_link' => 'https://x.test/invitation/tok', 'expires_days' => '7']);

    $job->failed(new RuntimeException('boom'));

    $line = json_encode(SecurityEventRecord::query()->where('event', 'mail.failed')->firstOrFail()->toArray());
    expect($line)->toContain('example.test')
        ->and($line)->not->toContain('secret.person')
        ->and($line)->not->toContain('tok');
});

test('the queued message is encrypted, so a failed job leaves no usable link behind', function () {
    $job = new SendTemplatedMail('invitation', 'a@example.test', ['set_password_link' => 'https://x.test/invitation/secrettoken']);

    expect($job)->toBeInstanceOf(ShouldBeEncrypted::class);
});

test('two overlapping reminder runs mail a reminder once', function () {
    Bus::fake();
    mailOn();
    $user = User::factory()->create(['last_login_at' => null, 'invited_at' => now()->subDays(4)]);

    // The second run loaded the user before the first one claimed the slot.
    $stale = User::query()->find($user->id);
    expect(Invitations::sendDueReminders())->toBe(1);

    $claimed = User::query()->whereKey($stale->id)->where('invitation_reminders_sent', $stale->invitation_reminders_sent)->update(['invitation_reminders_sent' => $stale->invitation_reminders_sent + 1]);
    expect($claimed)->toBe(0);
    Bus::assertDispatchedTimes(SendTemplatedMail::class, 1);
});
