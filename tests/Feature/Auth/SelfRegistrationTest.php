<?php

use App\Domain\Accounts\SelfRegistration;
use App\Domain\Mail\MailSettings;
use App\Jobs\SendTemplatedMail;
use App\Models\InstanceSetting;
use App\Models\PendingRegistration;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Oidc/helpers.php';

/*
 * Self-registration: off by default, a pending row until the emailed link is
 * followed, and an answer that never says whether an address has an account.
 */

const REGISTRATION_PASSWORD = 'correct horse battery staple 42';

function registrationOn(array $settings = []): void
{
    config(['arguesecure.mail' => [
        'host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'starttls', 'username' => null,
        'password' => null, 'from_address' => 'argue@example.test', 'from_name' => null,
    ]]);
    InstanceSetting::singleton()->forceFill(array_merge([
        'mail_tested_fingerprint' => MailSettings::fingerprint(),
        'registration_enabled' => true,
    ], $settings))->save();
}

function registerForm(string $email = 'new@example.test'): array
{
    return ['email' => $email];
}

function registrationLink(): string
{
    $link = null;
    Bus::assertDispatched(SendTemplatedMail::class, function (SendTemplatedMail $job) use (&$link) {
        $link = $job->values['verify_link'];

        return true;
    });

    return parse_url($link, PHP_URL_PATH);
}

test('registration is off by default: both routes answer 404 and the login page has no link', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', registerForm())->assertNotFound();
    User::factory()->create(); // with no user at all, /login goes to first-run setup
    $this->get('/login')->assertInertia(fn ($page) => $page->where('canRegister', false));
    // Only the user made above for the login page: the 404'd POST created nothing.
    expect(User::query()->count())->toBe(1)
        ->and(PendingRegistration::query()->count())->toBe(0);
});

test('switched on without working mail, registration stays closed', function () {
    InstanceSetting::singleton()->forceFill(['registration_enabled' => true])->save();

    $this->get('/register')->assertNotFound();
});

test('an admin cannot switch registration on while mail is off', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put('/admin/authentication', [
        'require_two_factor' => false, 'sso_only' => false, 'registration_enabled' => true,
    ])->assertSessionHasErrors('registration_enabled');

    expect((bool) InstanceSetting::singleton()->registration_enabled)->toBeFalse();
});

test('a request creates only a pending row with no name or password', function () {
    Bus::fake();
    registrationOn();

    $this->post('/register', registerForm())->assertRedirect(route('register'));

    expect(User::query()->count())->toBe(0)
        ->and(PendingRegistration::query()->count())->toBe(1);
    expect(Schema::hasColumn('pending_registrations', 'password_hash'))->toBeFalse()
        ->and(Schema::hasColumn('pending_registrations', 'name'))->toBeFalse();
});

test('the mail greets with a fixed word, never anything the requester typed', function () {
    Bus::fake();
    registrationOn();

    $this->post('/register', ['email' => 'new@example.test', 'name' => 'Evil text']);

    Bus::assertDispatched(SendTemplatedMail::class, fn (SendTemplatedMail $job) => $job->values['name'] === 'there');
});

test('following the link creates a verified account nobody can sign in to, and leads to choosing a password', function () {
    Bus::fake();
    registrationOn();
    $this->post('/register', registerForm());

    $response = $this->post(registrationLink());

    $user = User::query()->where('email', 'new@example.test')->firstOrFail();
    expect($user->is_admin)->toBeFalse()
        ->and($user->is_active)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->self_registered_at)->not->toBeNull()
        ->and($user->last_login_at)->toBeNull();
    expect(PendingRegistration::query()->count())->toBe(0);
    $this->assertDatabaseHas('security_events', ['event' => 'account.self_registered']);
    $response->assertRedirectContains('/invitation/');

    // The requester never chose a password, so no password they could have
    // sent works.
    foreach ([REGISTRATION_PASSWORD, 'password', ''] as $guess) {
        $this->post('/login', ['email' => 'new@example.test', 'password' => $guess]);
        $this->assertGuest();
    }
});

test('the person who followed the link chooses the password and is signed in', function () {
    Bus::fake();
    registrationOn();
    $this->post('/register', registerForm());
    $setPassword = parse_url($this->post(registrationLink())->headers->get('Location'), PHP_URL_PATH);

    $this->post($setPassword, ['password' => REGISTRATION_PASSWORD, 'password_confirmation' => REGISTRATION_PASSWORD])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();
    $user = User::query()->where('email', 'new@example.test')->firstOrFail();
    expect(Hash::check(REGISTRATION_PASSWORD, $user->password))->toBeTrue();
});

test('registering an existing address answers like a new one, mails nothing and creates nothing', function () {
    Bus::fake();
    registrationOn();
    User::factory()->create(['email' => 'taken@example.test']);

    $existing = $this->post('/register', registerForm('taken@example.test'));
    $fresh = $this->post('/register', registerForm('fresh@example.test'));

    expect($existing->status())->toBe($fresh->status());
    expect($existing->headers->get('Location'))->toBe($fresh->headers->get('Location'));
    expect(PendingRegistration::query()->where('email', 'taken@example.test')->exists())->toBeFalse();
    Bus::assertDispatchedTimes(SendTemplatedMail::class, 1);
});

test('an address outside the allowed domains is refused, and an empty list allows any', function () {
    Bus::fake();
    registrationOn(['registration_domains' => 'corp.example']);

    $this->post('/register', registerForm('someone@other.example'))->assertSessionHasErrors('email');
    expect(PendingRegistration::query()->count())->toBe(0);

    $this->post('/register', registerForm('someone@corp.example'))->assertSessionDoesntHaveErrors();
    expect(PendingRegistration::query()->count())->toBe(1);

    InstanceSetting::singleton()->forceFill(['registration_domains' => null])->save();
    $this->post('/register', registerForm('anyone@anywhere.example'))->assertSessionDoesntHaveErrors();
    expect(PendingRegistration::query()->count())->toBe(2);
});

test('at most three mails an hour go to one address, with an unchanged response', function () {
    Bus::fake();
    registrationOn();

    foreach (range(1, 4) as $i) {
        $this->post('/register', registerForm('victim@example.test'))->assertRedirect(route('register'));
    }

    Bus::assertDispatchedTimes(SendTemplatedMail::class, 3);
});

test('the daily cap counts accounts made by following a link, not pending requests', function () {
    Bus::fake();
    registrationOn(['registration_daily_cap' => 2]);

    $this->post('/register', registerForm('a@example.test'));
    $this->post('/register', registerForm('b@example.test'));
    $this->post('/register', registerForm('c@example.test'))->assertSessionDoesntHaveErrors();
    expect(PendingRegistration::query()->count())->toBe(3)
        ->and(SelfRegistration::completedToday())->toBe(0);

    User::factory()->count(2)->create(['self_registered_at' => now()]);

    $this->post('/register', registerForm('d@example.test'))->assertSessionHasErrors('email');
    expect(PendingRegistration::query()->where('email', 'd@example.test')->exists())->toBeFalse();
});

test('an account made yesterday does not count against today', function () {
    registrationOn(['registration_daily_cap' => 1]);
    User::factory()->create(['self_registered_at' => now()->utc()->subDay()->startOfDay()->addHour()]);

    expect(SelfRegistration::capReached())->toBeFalse();
});

test('confirming twice, or after the pending row is gone, creates nothing more', function () {
    Bus::fake();
    registrationOn();
    $this->post('/register', registerForm());
    $reference = 'registration:'.PendingRegistration::query()->firstOrFail()->id;

    SelfRegistration::complete('new@example.test', $reference);
    SelfRegistration::complete('new@example.test', $reference);

    expect(User::query()->where('email', 'new@example.test')->count())->toBe(1);
});

test('a reference that is not ours, or whose address differs, creates nothing', function () {
    Bus::fake();
    registrationOn();
    $this->post('/register', registerForm());
    $id = PendingRegistration::query()->firstOrFail()->id;

    SelfRegistration::complete('new@example.test', (string) $id);
    SelfRegistration::complete('other@example.test', 'registration:'.$id);

    expect(User::query()->count())->toBe(0);
});

test('a verified single sign-on for a pending address wins and the pending row is discarded', function () {
    Bus::fake();
    registrationOn();
    $this->post('/register', registerForm('person@corp.example'));
    expect(PendingRegistration::query()->count())->toBe(1);

    $idp = fakeIdp(['allowed_domains' => 'corp.example']);
    signInThroughIdp($this, $idp, ['email' => 'person@corp.example', 'email_verified' => true]);

    expect(PendingRegistration::query()->count())->toBe(0);
    expect(User::query()->where('email', 'person@corp.example')->exists())->toBeTrue();
});

test('expired pending registrations are pruned', function () {
    Bus::fake();
    registrationOn();
    $this->post('/register', registerForm());
    PendingRegistration::query()->update(['updated_at' => now()->subHours(25)]);

    expect(SelfRegistration::prune())->toBe(1);
});

test('registration is unavailable while sign-in is limited to SSO', function () {
    registrationOn(['sso_only' => true]);
    fakeIdp();

    $this->get('/register')->assertNotFound();
});

test('a link followed after registration was switched off creates nothing', function () {
    Bus::fake();
    registrationOn();
    $this->post('/register', registerForm());
    $link = registrationLink();
    InstanceSetting::singleton()->forceFill(['registration_enabled' => false])->save();

    $this->post($link)->assertRedirect(route('login'))->assertSessionHas('status', 'Registration is closed right now.');

    expect(User::query()->count())->toBe(0)
        ->and(PendingRegistration::query()->count())->toBe(0);
});

test('a link followed after the domain list excludes the address creates nothing', function () {
    Bus::fake();
    registrationOn(['registration_domains' => 'corp.example']);
    $this->post('/register', registerForm('someone@corp.example'));
    $link = registrationLink();
    InstanceSetting::singleton()->forceFill(['registration_domains' => 'other.example'])->save();

    $this->post($link);

    expect(User::query()->count())->toBe(0);
});

test('the daily cap is checked again when the link is followed', function () {
    Bus::fake();
    registrationOn(['registration_daily_cap' => 1]);
    $this->post('/register', registerForm());
    $link = registrationLink();
    User::factory()->create(['self_registered_at' => now()]);

    $this->post($link)->assertSessionHas('status');

    expect(User::query()->where('email', 'new@example.test')->exists())->toBeFalse();
});

test('saving other settings is not blocked when registration is already on and mail has broken', function () {
    $admin = User::factory()->admin()->create();
    InstanceSetting::singleton()->forceFill(['registration_enabled' => true])->save();

    $this->actingAs($admin)->put('/admin/authentication', [
        'require_two_factor' => true, 'sso_only' => false, 'registration_enabled' => true,
    ])->assertSessionHasNoErrors();

    expect((bool) InstanceSetting::singleton()->require_two_factor)->toBeTrue();
});

test('no more than ten mails a day go to one address, even when the hourly limit has passed', function () {
    Bus::fake();
    registrationOn();

    // Three an hour is the hourly limit; moving past the hour each time shows the daily limit.
    foreach (range(1, 5) as $batch) {
        foreach (range(1, 3) as $request) {
            $this->post('/register', registerForm('victim@example.test'))->assertRedirect(route('register'));
        }
        $this->travel(61)->minutes();
    }

    Bus::assertDispatchedTimes(SendTemplatedMail::class, 10);
});

test('the account made at link time has a password nobody could know, and only the owner\'s works', function () {
    Bus::fake();
    registrationOn();
    $sent = 'requester-chosen-password-1';
    $this->post('/register', ['email' => 'new@example.test', 'password' => $sent, 'password_confirmation' => $sent]);

    $response = $this->post(registrationLink());

    $user = User::query()->where('email', 'new@example.test')->firstOrFail();
    foreach ([$sent, REGISTRATION_PASSWORD, 'password', 'new@example.test', ''] as $guess) {
        expect(Hash::check($guess, $user->password))->toBeFalse();
    }

    $setPassword = parse_url($response->headers->get('Location'), PHP_URL_PATH);
    $this->post($setPassword, ['password' => REGISTRATION_PASSWORD, 'password_confirmation' => REGISTRATION_PASSWORD]);

    $user->refresh();
    expect(Hash::check(REGISTRATION_PASSWORD, $user->password))->toBeTrue()
        ->and(Hash::check($sent, $user->password))->toBeFalse();
});

test('the registration form is limited to five requests a minute from one address', function () {
    Bus::fake();
    registrationOn();

    foreach (range(1, 5) as $i) {
        $this->post('/register', registerForm("person{$i}@example.test"))->assertRedirect(route('register'));
    }

    $this->post('/register', registerForm('person6@example.test'))->assertStatus(429);
});
