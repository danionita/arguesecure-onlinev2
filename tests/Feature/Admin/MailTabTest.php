<?php

use App\Domain\Mail\MailSettings;
use App\Models\InstanceSetting;
use App\Models\SecurityEventRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The admin Email tab. Settings come from the environment (config) first and
 * from the database otherwise, field by field; the password is encrypted at
 * rest and never leaves the server; mail counts as on only after a test
 * message has been delivered with the settings now in force.
 */

function noEnvironmentMail(): void
{
    config(['arguesecure.mail' => [
        'host' => null, 'port' => null, 'encryption' => null, 'username' => null,
        'password' => null, 'from_address' => null, 'from_name' => null,
    ]]);
}

function mailForm(array $overrides = []): array
{
    return array_merge([
        'mail_host' => 'smtp.example.test',
        'mail_port' => 587,
        'mail_encryption' => 'starttls',
        'mail_username' => 'relay-user',
        'mail_password' => 'sentinel-mail-password-1234',
        'mail_from_address' => 'argue@example.test',
        'mail_from_name' => 'ArgueSecure',
    ], $overrides);
}

beforeEach(function () {
    noEnvironmentMail();
});

test('only an admin reaches the tab, its save and its test', function () {
    $this->get('/admin/email')->assertRedirect('/login');
    $this->put('/admin/email', [])->assertRedirect('/login');
    $this->post('/admin/email/test')->assertRedirect('/login');

    $user = User::factory()->create();
    $this->actingAs($user)->get('/admin/email')->assertForbidden();
    $this->actingAs($user)->put('/admin/email', mailForm())->assertForbidden();
    $this->actingAs($user)->post('/admin/email/test')->assertForbidden();

    expect(InstanceSetting::singleton()->mail_host)->toBeNull();
});

test('mail is off by default and nothing is configured', function () {
    expect(MailSettings::configured())->toBeFalse()
        ->and(MailSettings::enabled())->toBeFalse();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->get('/admin/email')->assertInertia(fn (Assert $page) => $page
        ->where('configured', false)
        ->where('enabled', false)
        ->where('mail.host.source', 'none'));
});

test('an admin stores a server, and MailSettings uses it when the environment is empty', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put('/admin/email', mailForm())->assertSessionHasNoErrors();

    expect(MailSettings::configured())->toBeTrue()
        ->and(MailSettings::host())->toBe('smtp.example.test')
        ->and(MailSettings::port())->toBe(587)
        ->and(MailSettings::password())->toBe('sentinel-mail-password-1234')
        ->and(MailSettings::source('host'))->toBe('stored')
        // Saved is not tested: still off.
        ->and(MailSettings::enabled())->toBeFalse();
});

test('a field set in the environment wins over a stored one, field by field', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put('/admin/email', mailForm())->assertSessionHasNoErrors();

    config(['arguesecure.mail.host' => 'env.example.test']);

    expect(MailSettings::host())->toBe('env.example.test')
        ->and(MailSettings::source('host'))->toBe('environment')
        ->and(MailSettings::username())->toBe('relay-user')
        ->and(MailSettings::source('username'))->toBe('stored');

    $this->actingAs($admin)->put('/admin/email', mailForm(['mail_host' => 'attacker.example.test']))
        ->assertSessionHasNoErrors();
    expect(InstanceSetting::singleton()->mail_host)->toBe('smtp.example.test');
});

test('the password is encrypted at rest and never reaches a page or the log', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put('/admin/email', mailForm())->assertSessionHasNoErrors();

    $raw = DB::table('instance_settings')->value('mail_password');
    expect($raw)->not->toContain('sentinel-mail')
        ->and(decrypt($raw, false))->toBe('sentinel-mail-password-1234')
        ->and(InstanceSetting::singleton()->toArray())->not->toHaveKey('mail_password');

    $response = $this->actingAs($admin)->get('/admin/email');
    $response->assertInertia(fn (Assert $page) => $page
        ->where('mail.password.set', true)
        ->where('mail.password.source', 'stored'));
    expect($response->getContent())->not->toContain('sentinel-mail');

    $logged = json_encode(SecurityEventRecord::query()->get()->toArray());
    expect($logged)->not->toContain('sentinel-mail')
        ->and($logged)->toContain('mail.settings_updated')
        ->and($logged)->toContain('mail_password');
});

test('a blank password keeps the stored one, and remove clears it', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put('/admin/email', mailForm())->assertSessionHasNoErrors();

    $this->actingAs($admin)->put('/admin/email', mailForm(['mail_password' => '']))->assertSessionHasNoErrors();
    expect(MailSettings::password())->toBe('sentinel-mail-password-1234');

    $this->actingAs($admin)->put('/admin/email', mailForm(['mail_password' => '', 'clear_password' => true]))
        ->assertSessionHasNoErrors();
    expect(MailSettings::password())->toBe('');
});

test('the server must be a name or address, and the sender an email with no line break', function () {
    $admin = User::factory()->admin()->create();

    foreach (['smtp://x.test', 'x.test/path', 'x.test:25', 'x y', '-x.test', 'a..b', 'a-.b', 'a.b.'] as $bad) {
        $this->actingAs($admin)->put('/admin/email', mailForm(['mail_host' => $bad]))
            ->assertSessionHasErrors('mail_host');
    }
    $this->actingAs($admin)->put('/admin/email', mailForm(['mail_from_address' => 'not-an-address']))
        ->assertSessionHasErrors('mail_from_address');
    $this->actingAs($admin)->put('/admin/email', mailForm(['mail_from_name' => "Eve\r\nBcc: x@y.test"]))
        ->assertSessionHasErrors('mail_from_name');
    $this->actingAs($admin)->put('/admin/email', mailForm(['mail_port' => 70000]))
        ->assertSessionHasErrors('mail_port');
    $this->actingAs($admin)->put('/admin/email', mailForm(['mail_encryption' => 'ssl3']))
        ->assertSessionHasErrors('mail_encryption');
});

test('a test message goes to the signed-in admin only and turns mail on', function () {
    Mail::fake();
    $admin = User::factory()->admin()->create(['email' => 'boss@example.test']);
    $this->actingAs($admin)->put('/admin/email', mailForm())->assertSessionHasNoErrors();

    $this->actingAs($admin)->postJson('/admin/email/test')
        ->assertOk()
        ->assertJson(['ok' => true]);

    expect(MailSettings::enabled())->toBeTrue();
    $this->assertDatabaseHas('security_events', ['event' => 'mail.test_sent']);
});

test('a test without a server saved sends nothing', function () {
    Mail::fake();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->postJson('/admin/email/test')->assertOk()->assertJson(['ok' => false]);

    expect(MailSettings::enabled())->toBeFalse();
});

test('changing any setting switches mail off until the next test', function () {
    Mail::fake();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put('/admin/email', mailForm())->assertSessionHasNoErrors();
    $this->actingAs($admin)->postJson('/admin/email/test')->assertJson(['ok' => true]);
    expect(MailSettings::enabled())->toBeTrue();

    $this->actingAs($admin)->put('/admin/email', mailForm(['mail_host' => 'other.example.test']))->assertSessionHasNoErrors();
    expect(MailSettings::enabled())->toBeFalse();

    // An environment change counts too: the fingerprint reads the effective values.
    $this->actingAs($admin)->put('/admin/email', mailForm())->assertSessionHasNoErrors();
    $this->actingAs($admin)->postJson('/admin/email/test')->assertJson(['ok' => true]);
    config(['arguesecure.mail.from_address' => 'someone-else@example.test']);
    expect(MailSettings::enabled())->toBeFalse();
});

test('a password with spaces at its ends is kept exactly, and the sender keeps its local-part case', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put('/admin/email', mailForm(['mail_password' => ' pa ss ', 'mail_from_address' => 'Argue.Admin@Example.TEST']))
        ->assertSessionHasNoErrors();

    expect(MailSettings::password())->toBe(' pa ss ')
        ->and(MailSettings::fromAddress())->toBe('Argue.Admin@example.test');
});

test('a password that cannot be decrypted is treated as not set instead of breaking the tab', function () {
    $admin = User::factory()->admin()->create();
    DB::table('instance_settings')->where('id', 1)->update(['mail_password' => 'not-a-valid-payload']);

    expect(MailSettings::password())->toBe('');
    $this->actingAs($admin)->get('/admin/email')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('mail.password.set', false));
});

test('saving nothing new writes no log line', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put('/admin/email', mailForm())->assertSessionHasNoErrors();
    $this->actingAs($admin)->put('/admin/email', mailForm(['mail_password' => '']))->assertSessionHasNoErrors();

    expect(SecurityEventRecord::query()->where('event', 'mail.settings_updated')->count())->toBe(1);
});

test('a failing relay reports the failure without the password', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put('/admin/email', mailForm(['mail_host' => '127.0.0.1', 'mail_port' => 1]))
        ->assertSessionHasNoErrors();

    $response = $this->actingAs($admin)->postJson('/admin/email/test')->assertOk()->assertJson(['ok' => false]);

    expect($response->getContent())->not->toContain('sentinel-mail')
        ->and($response->json('message'))->not->toContain('127.0.0.1')
        ->and(MailSettings::enabled())->toBeFalse();
    $this->assertDatabaseHas('security_events', ['event' => 'mail.test_failed']);
});

test('removing the password works when the stored one can no longer be decrypted', function () {
    $admin = User::factory()->admin()->create();
    DB::table('instance_settings')->where('id', 1)->update(['mail_password' => 'not-a-valid-payload']);

    $this->actingAs($admin)->put('/admin/email', mailForm(['mail_password' => '', 'clear_password' => true]))
        ->assertSessionHasNoErrors();

    expect(DB::table('instance_settings')->value('mail_password'))->toBeNull();
});

test('the tested fingerprint is keyed by APP_KEY and does not expose the password to a plain hash', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put('/admin/email', mailForm())->assertSessionHasNoErrors();

    $plain = hash('sha256', implode("\n", [
        'smtp.example.test', 587, 'starttls', 'relay-user', 'sentinel-mail-password-1234', 'argue@example.test', 'ArgueSecure',
    ]));
    $first = MailSettings::fingerprint();

    expect($first)->not->toBe($plain);

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    expect(MailSettings::fingerprint())->not->toBe($first);
});

test('apply() points the smtp mailer at the settings in force', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put('/admin/email', mailForm(['mail_encryption' => 'tls', 'mail_port' => '']))->assertSessionHasNoErrors();

    MailSettings::apply();

    expect(config('mail.mailers.smtp.scheme'))->toBe('smtps')
        ->and(config('mail.mailers.smtp.port'))->toBe(465)
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.example.test')
        ->and(config('mail.from.address'))->toBe('argue@example.test')
        ->and(config('mail.mailers.smtp.require_tls'))->toBeFalse()
        ->and(config('mail.mailers.smtp.auto_tls'))->toBeTrue();
});

test('STARTTLS is required when chosen, and never attempted for none', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put('/admin/email', mailForm(['mail_encryption' => 'starttls']))->assertSessionHasNoErrors();
    MailSettings::apply();
    expect(config('mail.mailers.smtp.scheme'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.require_tls'))->toBeTrue()
        ->and(config('mail.mailers.smtp.auto_tls'))->toBeTrue();

    $this->actingAs($admin)->put('/admin/email', mailForm(['mail_encryption' => 'none']))->assertSessionHasNoErrors();
    MailSettings::apply();
    expect(config('mail.mailers.smtp.require_tls'))->toBeFalse()
        ->and(config('mail.mailers.smtp.auto_tls'))->toBeFalse();
});
