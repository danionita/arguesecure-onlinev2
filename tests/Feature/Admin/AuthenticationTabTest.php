<?php

use App\Domain\Accounts\InstanceSettings;
use App\Domain\Identity\Oidc\OidcConfig;
use App\Models\InstanceSetting;
use App\Models\SecurityEventRecord;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * W133, D105-D108: the Authentication tab. Settings come from the environment
 * (config) first and from the database otherwise, field by field; the secret
 * is encrypted at rest and never leaves the server.
 */

const ISSUER = 'https://idp.example.test';

/** The environment's side of the OIDC block, as a deployment without OIDC_* has it. */
function noEnvironmentOidc(): void
{
    config(['arguesecure.oidc' => [
        'issuer' => null, 'client_id' => null, 'client_secret' => null,
        'button_label' => null, 'allowed_domains' => '', 'trust_email' => null,
    ]]);
}

function authForm(array $overrides = []): array
{
    return array_merge([
        'require_two_factor' => false,
        'sso_only' => false,
        'oidc_issuer' => ISSUER,
        'oidc_client_id' => 'client-abc',
        'oidc_client_secret' => 'sentinel-secret-value-1234',
        'oidc_button_label' => 'Acme login',
        'oidc_allowed_domains' => 'Example.org, example.com',
        'oidc_trust_email' => false,
    ], $overrides);
}

beforeEach(function () {
    noEnvironmentOidc();
    Cache::flush();
});

test('only an admin reaches the tab, its save and its check', function () {
    $this->get('/admin/authentication')->assertRedirect('/login');
    $this->put('/admin/authentication', [])->assertRedirect('/login');
    $this->post('/admin/authentication/check')->assertRedirect('/login');

    $user = User::factory()->create();
    $this->actingAs($user)->get('/admin/authentication')->assertForbidden();
    $this->actingAs($user)->put('/admin/authentication', authForm())->assertForbidden();
    $this->actingAs($user)->post('/admin/authentication/check')->assertForbidden();

    expect(InstanceSetting::singleton()->oidc_issuer)->toBeNull();
});

test('an admin stores a provider, and OidcConfig uses it when the environment is empty', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put('/admin/authentication', authForm())->assertSessionHasNoErrors();

    expect(OidcConfig::enabled())->toBeTrue()
        ->and(OidcConfig::issuer())->toBe(ISSUER)
        ->and(OidcConfig::clientId())->toBe('client-abc')
        ->and(OidcConfig::clientSecret())->toBe('sentinel-secret-value-1234')
        ->and(OidcConfig::buttonLabel())->toBe('Acme login')
        ->and(OidcConfig::allowedDomains())->toBe(['example.org', 'example.com'])
        ->and(OidcConfig::source('issuer'))->toBe('stored');

    // The login page now offers the link, and the redirect route exists.
    $this->post('/logout');
    $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('sso.label', 'Acme login'));
});

test('a field set in the environment wins over a stored one, field by field', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put('/admin/authentication', authForm())->assertSessionHasNoErrors();

    config(['arguesecure.oidc.issuer' => 'https://env.example.test', 'arguesecure.oidc.trust_email' => true]);

    expect(OidcConfig::issuer())->toBe('https://env.example.test')
        ->and(OidcConfig::source('issuer'))->toBe('environment')
        ->and(OidcConfig::trustEmail())->toBeTrue()
        // Not set in the environment, so the stored value still applies.
        ->and(OidcConfig::clientId())->toBe('client-abc')
        ->and(OidcConfig::source('client_id'))->toBe('stored');

    $this->actingAs($admin)->get('/admin/authentication')->assertInertia(fn (Assert $page) => $page
        ->where('oidc.issuer.source', 'environment')
        ->where('oidc.issuer.value', 'https://env.example.test')
        ->where('oidc.client_id.source', 'stored'));
});

test('a save that names an environment-fixed field leaves the stored value alone', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put('/admin/authentication', authForm())->assertSessionHasNoErrors();

    config(['arguesecure.oidc.issuer' => 'https://env.example.test']);
    $this->actingAs($admin)->put('/admin/authentication', authForm(['oidc_issuer' => 'https://attacker.example.test']))
        ->assertSessionHasNoErrors();

    expect(InstanceSetting::singleton()->oidc_issuer)->toBe(ISSUER)
        ->and(OidcConfig::issuer())->toBe('https://env.example.test');
});

test('the client secret is encrypted at rest and never reaches a page', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put('/admin/authentication', authForm())->assertSessionHasNoErrors();

    $raw = DB::table('instance_settings')->value('oidc_client_secret');
    expect($raw)->not->toBe('sentinel-secret-value-1234')
        ->and($raw)->not->toContain('sentinel-secret')
        ->and(decrypt($raw, false))->toBe('sentinel-secret-value-1234')
        ->and(InstanceSetting::singleton()->toArray())->not->toHaveKey('oidc_client_secret');

    $response = $this->actingAs($admin)->get('/admin/authentication');
    $response->assertInertia(fn (Assert $page) => $page
        ->where('oidc.client_secret.set', true)
        ->where('oidc.client_secret.source', 'stored'));
    expect($response->getContent())->not->toContain('sentinel-secret');
});

test('a blank secret keeps the stored one, and remove clears it', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put('/admin/authentication', authForm())->assertSessionHasNoErrors();

    $this->actingAs($admin)->put('/admin/authentication', authForm(['oidc_client_secret' => '']))->assertSessionHasNoErrors();
    expect(OidcConfig::clientSecret())->toBe('sentinel-secret-value-1234');

    $this->actingAs($admin)->put('/admin/authentication', authForm(['oidc_client_secret' => '', 'clear_client_secret' => true]))
        ->assertSessionHasNoErrors();
    expect(OidcConfig::clientSecret())->toBe('')
        ->and(OidcConfig::enabled())->toBeFalse();
});

test('removing the provider turns SSO off and the redirect route answers 404', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put('/admin/authentication', authForm())->assertSessionHasNoErrors();
    $this->actingAs($admin)->put('/admin/authentication', authForm(['oidc_issuer' => '']))->assertSessionHasNoErrors();

    expect(OidcConfig::enabled())->toBeFalse();
    $this->get('/auth/oidc/redirect')->assertNotFound();
});

test('SSO only is refused without a provider, and works with one', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put('/admin/authentication', authForm(['sso_only' => true, 'oidc_issuer' => '']))
        ->assertSessionHasErrors('sso_only');
    // Rolled back whole: the switch is off and nothing else was written.
    expect(InstanceSettings::all()['sso_only'])->toBeFalse()
        ->and(InstanceSetting::singleton()->oidc_client_id)->toBeNull();

    $this->actingAs($admin)->put('/admin/authentication', authForm(['sso_only' => true]))->assertSessionHasNoErrors();
    expect(InstanceSettings::ssoOnly())->toBeTrue();
});

test('the issuer must be https, or http on this machine', function () {
    $admin = User::factory()->admin()->create();

    foreach (['http://idp.example.test', 'ftp://x.test', 'idp.example.test', 'javascript:alert(1)', 'https://?x', 'https:///x'] as $bad) {
        $this->actingAs($admin)->put('/admin/authentication', authForm(['oidc_issuer' => $bad]))
            ->assertSessionHasErrors('oidc_issuer');
    }

    foreach (['http://localhost:8080/realms/x', 'http://localhost:8080', 'http://localhost', 'https://idp.example.test/'] as $good) {
        $this->actingAs($admin)->put('/admin/authentication', authForm(['oidc_issuer' => $good]))
            ->assertSessionHasNoErrors();
    }
});

test('a key left out of the request is left alone, not cleared', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put('/admin/authentication', authForm(['oidc_trust_email' => true]))->assertSessionHasNoErrors();

    // Asserted as a redirect: a 500 would also leave the stored values as they were.
    $this->actingAs($admin)->put('/admin/authentication', ['require_two_factor' => true, 'sso_only' => false])
        ->assertRedirect(route('admin.authentication.edit'));

    $row = InstanceSetting::singleton();
    expect($row->oidc_issuer)->toBe(ISSUER)
        ->and($row->oidc_client_id)->toBe('client-abc')
        ->and($row->oidc_trust_email)->toBeTrue()
        ->and(OidcConfig::clientSecret())->toBe('sentinel-secret-value-1234');
});

test('an invalid environment value does not block saving the rest of the tab', function () {
    $admin = User::factory()->admin()->create();
    config(['arguesecure.oidc.issuer' => 'not a url '.str_repeat('x', 300)]);

    $this->actingAs($admin)->put('/admin/authentication', authForm(['require_two_factor' => true, 'oidc_issuer' => str_repeat('x', 300)]))
        ->assertSessionHasNoErrors();
    expect(InstanceSettings::requireTwoFactor())->toBeTrue();
});

test('an environment label shows as the effective label, and a stored secret that cannot be decrypted reads as not set', function () {
    $admin = User::factory()->admin()->create();
    config(['arguesecure.oidc.button_label' => 'Acme SSO']);

    $this->actingAs($admin)->get('/admin/authentication')->assertInertia(fn (Assert $page) => $page
        ->where('oidc.button_label.value', 'Acme SSO')
        ->where('oidc.button_label.source', 'environment'));

    // A secret encrypted under another key, as after an APP_KEY change.
    // Built here, not pasted, so no scanner mistakes the payload for a secret.
    $foreign = base64_encode(json_encode(['iv' => 'bad', 'value' => 'bad', 'mac' => 'bad']));
    DB::table('instance_settings')->update(['oidc_client_secret' => $foreign]);
    expect(OidcConfig::clientSecret())->toBe('')
        ->and(OidcConfig::source('client_secret'))->toBe('none');
    $this->actingAs($admin)->get('/admin/authentication')->assertOk();
});

test('SSO only already on does not block saving the other switch once the provider is gone', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put('/admin/authentication', authForm(['sso_only' => true]))->assertSessionHasNoErrors();
    DB::table('instance_settings')->update(['oidc_issuer' => null]);

    $this->actingAs($admin)->put('/admin/authentication', authForm(['sso_only' => true, 'oidc_issuer' => '', 'require_two_factor' => true]))
        ->assertSessionHasNoErrors();
    expect(InstanceSettings::requireTwoFactor())->toBeTrue()
        ->and(InstanceSettings::ssoOnly())->toBeFalse();
});

test('allowed domains are checked and stored lower-case without duplicates', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put('/admin/authentication', authForm(['oidc_allowed_domains' => 'a@b.org']))
        ->assertSessionHasErrors('oidc_allowed_domains');

    $this->actingAs($admin)->put('/admin/authentication', authForm(['oidc_allowed_domains' => 'Example.org, example.org ,B.example.org']))
        ->assertSessionHasNoErrors();
    expect(InstanceSetting::singleton()->oidc_allowed_domains)->toBe('example.org,b.example.org');
});

test('every save is one settings event naming fields, never values', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put('/admin/authentication', authForm(['require_two_factor' => true]))->assertSessionHasNoErrors();

    $row = SecurityEventRecord::query()->where('event', 'admin.settings_updated')->latest('id')->firstOrFail();
    $json = json_encode($row->details);

    expect($row->details['settings']['tab'])->toBe('authentication')
        ->and($row->details['settings']['changed'])->toContain('require_two_factor', 'oidc_issuer', 'oidc_client_id', 'oidc_client_secret')
        ->and($json)->not->toContain('sentinel-secret')
        ->and($json)->not->toContain('client-abc')
        ->and($json)->not->toContain(ISSUER);
});

test('require two-factor saved here sends an account without one to enrolment', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put('/admin/authentication', authForm(['require_two_factor' => true]))->assertSessionHasNoErrors();

    $user = User::factory()->create();
    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('profile.edit'));
});

test('Check provider reports what the discovery document says', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put('/admin/authentication', authForm())->assertSessionHasNoErrors();

    Http::fake([ISSUER.'/.well-known/openid-configuration' => Http::response([
        'issuer' => ISSUER,
        'authorization_endpoint' => ISSUER.'/authorize',
        'token_endpoint' => ISSUER.'/token',
        'jwks_uri' => ISSUER.'/jwks',
    ])]);

    $this->actingAs($admin)->postJson('/admin/authentication/check')
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('endpoints.token', ISSUER.'/token');
});

test('Check provider gives a readable reason when it fails', function () {
    $admin = User::factory()->admin()->create();

    // Nothing saved.
    $this->actingAs($admin)->postJson('/admin/authentication/check')->assertJsonPath('ok', false);

    $this->actingAs($admin)->put('/admin/authentication', authForm())->assertSessionHasNoErrors();

    // Issuer mismatch.
    Http::fake([ISSUER.'/.well-known/openid-configuration' => Http::response([
        'issuer' => 'https://other.example.test',
        'authorization_endpoint' => 'x', 'token_endpoint' => 'y', 'jwks_uri' => 'z',
    ])]);
    $this->actingAs($admin)->postJson('/admin/authentication/check')
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'Discovery issuer does not match the configured issuer.');

    // A malformed document.
    Cache::flush();
    Http::fake([ISSUER.'/.well-known/openid-configuration' => Http::response('nope', 200)]);
    $this->actingAs($admin)->postJson('/admin/authentication/check')->assertJsonPath('ok', false);

    // Unreachable.
    Http::fake([ISSUER.'/.well-known/openid-configuration' => fn () => throw new ConnectionException('timeout')]);
    $this->actingAs($admin)->postJson('/admin/authentication/check')
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'The provider could not be reached.');
});

test('Check provider ignores a cached document and is limited to six a minute', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put('/admin/authentication', authForm())->assertSessionHasNoErrors();
    $good = ['issuer' => ISSUER, 'authorization_endpoint' => 'a', 'token_endpoint' => 'b', 'jwks_uri' => 'c'];

    // Successive Http::fake() calls add stubs and the first match wins, so
    // the provider changing its answer is a sequence.
    Http::fake([ISSUER.'/.well-known/openid-configuration' => Http::sequence()
        ->push($good)
        ->push(['issuer' => ISSUER])
        ->whenEmpty(Http::response($good))]);
    $this->actingAs($admin)->postJson('/admin/authentication/check')->assertJsonPath('ok', true);
    $this->actingAs($admin)->postJson('/admin/authentication/check')->assertJsonPath('ok', false);

    foreach (range(1, 4) as $i) {
        $this->actingAs($admin)->postJson('/admin/authentication/check');
    }
    $this->actingAs($admin)->postJson('/admin/authentication/check')->assertStatus(429);
});
