<?php

use App\Domain\Accounts\InstanceSettings;
use App\Domain\Identity\Oidc\Events\SsoAccountCreated;
use App\Domain\Identity\Oidc\Events\SsoIdentityLinked;
use App\Domain\Identity\Oidc\Events\SsoIdentityUnlinked;
use App\Domain\Identity\Oidc\Events\SsoLoggedIn;
use App\Domain\Identity\Oidc\Events\SsoLoginFailed;
use App\Domain\Identity\Oidc\OidcClient;
use App\Http\Controllers\Admin\UserIdentityController;
use App\Models\User;
use App\Models\UserIdentity;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/helpers.php';

function linkedUser(string $subject, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $identity = new UserIdentity;
    $identity->user_id = $user->id;
    $identity->issuer = IDP;
    $identity->subject = $subject;
    $identity->save();

    return $user;
}

function assertRefused($test, $response): void
{
    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['email' => GENERIC_REFUSAL]);
    $test->assertGuest();
}

test('a valid callback signs in the linked account, with a new session id and the SSO marker', function () {
    Event::fake([SsoLoggedIn::class]);
    $idp = fakeIdp();
    $user = linkedUser('sub-1', ['email' => 'a@corp.example']);
    $flow = startOidc($this);
    $before = session()->getId();

    signInThroughIdp($this, $idp, ['sub' => 'sub-1'], $flow)->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($before);
    expect(session('auth.method'))->toBe('oidc');
    expect($user->refresh()->last_login_at)->not->toBeNull();
    expect($user->identities()->first()->last_used_at)->not->toBeNull();
    Event::assertDispatched(SsoLoggedIn::class);
});

test('the authorization request carries PKCE S256 and state/nonce, and never the verifier or the secret', function () {
    $idp = fakeIdp();
    $flow = startOidc($this);
    parse_str((string) parse_url($flow['url'], PHP_URL_QUERY), $params);
    $verifier = session('oidc.flow.verifier');

    expect($params['code_challenge_method'])->toBe('S256');
    expect($params['code_challenge'])->toBe(OidcClient::challenge($verifier));
    expect($params['response_type'])->toBe('code');
    expect($params['client_id'])->toBe(CLIENT_ID);
    expect($params['redirect_uri'])->toBe(route('oidc.callback'));
    expect($flow['url'])->not->toContain($verifier);
    expect($flow['url'])->not->toContain(config('arguesecure.oidc.client_secret'));
    expect($params)->not->toHaveKey('prompt');
});

test('the code exchange sends the verifier and the secret to the token endpoint, not to the browser', function () {
    $idp = fakeIdp();
    $response = signInThroughIdp($this, $idp, ['sub' => 'x']);

    expect($idp->tokenRequests)->toHaveCount(1);
    expect($idp->tokenRequests[0]['code_verifier'])->not->toBeEmpty();
    expect($idp->tokenRequests[0]['client_secret'])->toBe(config('arguesecure.oidc.client_secret'));
    expect($response->getContent().json_encode($response->headers->all()))
        ->not->toContain(config('arguesecure.oidc.client_secret'))
        ->not->toContain($idp->tokenRequests[0]['code_verifier']);
});

test('each of these is refused with the generic message and nobody is signed in', function (string $case) {
    $idp = fakeIdp();
    linkedUser('sub-1', ['email' => 'a@corp.example']);
    // An unlinked account the default token's email would link to: any check
    // that fails to stop a bad token would sign this one in.
    User::factory()->create(['email' => 'someone@corp.example']);
    $flow = startOidc($this);
    $claims = ['sub' => 'sub-1'];
    $query = ['state' => $flow['state'], 'code' => 'c'];

    $token = match ($case) {
        'different nonce' => idToken($idp, 'not-the-nonce', $claims),
        'another key' => (function () use ($flow, $claims) {
            $other = fakeIdpKeyOnly();

            return idToken($other, $flow['nonce'], $claims);
        })(),
        'alg none' => rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=').'.'
            .rtrim(strtr(base64_encode(json_encode(['iss' => IDP, 'sub' => 'sub-1', 'aud' => CLIENT_ID, 'iat' => time(), 'exp' => time() + 300, 'nonce' => $flow['nonce']])), '+/', '-_'), '=').'.',
        'alg outside the allow-list' => (function () use ($flow, $claims) {
            return JWT::encode(array_merge(['iss' => IDP, 'aud' => CLIENT_ID, 'iat' => time(), 'exp' => time() + 300, 'nonce' => $flow['nonce']], $claims), str_repeat('k', 40), 'HS256');
        })(),
        'wrong issuer' => idToken($idp, $flow['nonce'], $claims + ['iss' => 'https://evil.example.test']),
        'wrong audience' => idToken($idp, $flow['nonce'], $claims + ['aud' => 'someone-else']),
        'audience list without us' => idToken($idp, $flow['nonce'], $claims + ['aud' => ['a', 'b']]),
        'several audiences, wrong azp' => idToken($idp, $flow['nonce'], $claims + ['aud' => [CLIENT_ID, 'b'], 'azp' => 'b']),
        'expired' => idToken($idp, $flow['nonce'], $claims + ['iat' => time() - 4000, 'exp' => time() - 3000]),
        'no nonce' => idToken($idp, $flow['nonce'], $claims + ['nonce' => null]),
        'no subject' => idToken($idp, $flow['nonce'], ['sub' => '']),
        default => idToken($idp, $flow['nonce'], $claims),
    };
    $idp->idToken = $token;

    if ($case === 'token endpoint rejects the code') {
        $idp->tokenStatus = 400;
    }
    if ($case === 'different state') {
        $query['state'] = 'not-the-state';
    }
    if ($case === 'no state') {
        unset($query['state']);
    }
    if ($case === 'provider error') {
        $query = ['state' => $flow['state'], 'error' => 'access_denied'];
    }
    if ($case === 'no code') {
        unset($query['code']);
    }

    assertRefused($this, $this->get('/auth/oidc/callback?'.http_build_query($query)));
})->with([
    'different state', 'no state', 'different nonce', 'another key', 'alg none', 'alg outside the allow-list',
    'wrong issuer', 'wrong audience', 'audience list without us', 'several audiences, wrong azp', 'expired',
    'no nonce', 'no subject', 'token endpoint rejects the code', 'provider error', 'no code',
]);

/** A second, unrelated key pair for the "signed by another key" case. */
function fakeIdpKeyOnly(): stdClass
{
    $other = new stdClass;
    $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($resource, $other->privatePem);

    return $other;
}

test('a callback with no flow in progress, or replayed, is refused', function () {
    $idp = fakeIdp();
    linkedUser('sub-1', ['email' => 'a@corp.example']);

    $idp->idToken = idToken($idp, 'n', ['sub' => 'sub-1']);
    assertRefused($this, $this->get('/auth/oidc/callback?state=s&code=c'));

    $flow = startOidc($this);
    signInThroughIdp($this, $idp, ['sub' => 'sub-1'], $flow)->assertRedirect();
    $this->post('/logout');
    // The same state again: it was good for one callback.
    assertRefused($this, $this->get('/auth/oidc/callback?'.http_build_query(['state' => $flow['state'], 'code' => 'c'])));
});

test('a flow older than ten minutes is refused', function () {
    $idp = fakeIdp();
    linkedUser('sub-1', ['email' => 'a@corp.example']);
    $flow = startOidc($this);

    // The token itself stays valid (the JWT library reads the real clock);
    // only the flow's own ten-minute allowance has run out.
    Carbon::setTestNow(now()->addSeconds(601));
    try {
        assertRefused($this, signInThroughIdp($this, $idp, ['sub' => 'sub-1'], $flow));
    } finally {
        Carbon::setTestNow();
    }
});

test('a state is good for one callback, even when that callback failed', function () {
    $idp = fakeIdp();
    linkedUser('sub-1', ['email' => 'a@corp.example']);
    $flow = startOidc($this);

    $idp->tokenStatus = 400;
    assertRefused($this, signInThroughIdp($this, $idp, ['sub' => 'sub-1'], $flow));

    $idp->tokenStatus = 200;
    assertRefused($this, signInThroughIdp($this, $idp, ['sub' => 'sub-1'], $flow));
});

test('a first sign-in links by email only when it is verified, or the operator trusts the provider', function (mixed $verified, bool $trust, bool $linked) {
    Event::fake([SsoIdentityLinked::class]);
    $idp = fakeIdp(['trust_email' => $trust]);
    $user = User::factory()->create(['email' => 'a@corp.example']);

    $claims = ['sub' => 'sub-9', 'email' => 'A@Corp.Example', 'email_verified' => $verified];
    $response = signInThroughIdp($this, $idp, $claims);

    if ($linked) {
        $this->assertAuthenticatedAs($user);
        expect(UserIdentity::query()->where('user_id', $user->id)->where('subject', 'sub-9')->exists())->toBeTrue();
        Event::assertDispatched(SsoIdentityLinked::class);
    } else {
        assertRefused($this, $response);
        expect(UserIdentity::query()->count())->toBe(0);
    }
})->with([
    'verified' => [true, false, true],
    'verified as the string true' => ['true', false, true],
    'not verified' => [false, false, false],
    'claim absent' => [null, false, false],
    'absent but trusted' => [null, true, true],
    'false but trusted' => [false, true, true],
]);

test('once linked, the same subject with another email signs in the same account', function () {
    $idp = fakeIdp();
    $user = linkedUser('sub-1', ['email' => 'a@corp.example']);

    signInThroughIdp($this, $idp, ['sub' => 'sub-1', 'email' => 'renamed@corp.example'])->assertRedirect();

    $this->assertAuthenticatedAs($user);
    expect($user->refresh()->email)->toBe('a@corp.example');
});

test('another subject with a linked account\'s email does not sign in', function () {
    $idp = fakeIdp();
    linkedUser('sub-1', ['email' => 'a@corp.example']);

    assertRefused($this, signInThroughIdp($this, $idp, ['sub' => 'sub-2', 'email' => 'a@corp.example']));

    expect(UserIdentity::query()->count())->toBe(1);
});

test('a listed domain with a verified email creates one non-admin account that owns and belongs to nothing', function () {
    Event::fake([SsoAccountCreated::class]);
    $idp = fakeIdp(['allowed_domains' => ' Corp.Example , other.example ']);
    $before = User::query()->count();

    signInThroughIdp($this, $idp, ['sub' => 'new-1', 'email' => 'New.Person@CORP.example', 'name' => 'New Person'])
        ->assertRedirect(route('dashboard', absolute: false));

    expect(User::query()->count())->toBe($before + 1);
    $user = User::query()->where('email', 'new.person@corp.example')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($user->is_admin)->toBeFalse()
        ->and($user->is_active)->toBeTrue()
        ->and($user->name)->toBe('New Person')
        ->and($user->ownedAssessments()->count())->toBe(0)
        ->and($user->sharedAssessments()->count())->toBe(0)
        ->and($user->identities()->where('subject', 'new-1')->exists())->toBeTrue();
    Event::assertDispatched(SsoAccountCreated::class);

    // No password anyone knows: nothing signs in through Auth::attempt.
    $this->post('/logout');
    foreach (['', 'password', 'new.person@corp.example', 'New Person', Str::random(12)] as $guess) {
        expect(Auth::attempt(['email' => $user->email, 'password' => $guess]))->toBeFalse();
    }
});

test('a name falls back to the email', function () {
    $idp = fakeIdp(['allowed_domains' => 'corp.example']);

    signInThroughIdp($this, $idp, ['email' => 'noname@corp.example', 'name' => null]);

    expect(User::query()->where('email', 'noname@corp.example')->value('name'))->toBe('noname@corp.example');
});

test('no account is created for an unlisted domain, a subdomain, an empty list or an unverified email', function (string $list, string $email, mixed $verified) {
    $idp = fakeIdp(['allowed_domains' => $list]);
    User::factory()->count(2)->create();
    $before = DB::table('users')->pluck('email', 'id')->all();

    assertRefused($this, signInThroughIdp($this, $idp, ['email' => $email, 'email_verified' => $verified]));

    expect(DB::table('users')->pluck('email', 'id')->all())->toBe($before);
    expect(UserIdentity::query()->count())->toBe(0);
})->with([
    'domain not listed' => ['corp.example', 'x@elsewhere.example', true],
    'a subdomain of a listed one' => ['corp.example', 'x@eng.corp.example', true],
    'the list is empty' => ['', 'x@corp.example', true],
    'listed but unverified' => ['corp.example', 'x@corp.example', false],
    'listed but the claim is absent' => ['corp.example', 'x@corp.example', null],
    'a lookalike' => ['corp.example', 'x@corp.example.evil.test', true],
]);

test('a deactivated account is refused, linked or not', function () {
    $idp = fakeIdp();
    linkedUser('sub-1', ['email' => 'a@corp.example', 'is_active' => false]);
    User::factory()->create(['email' => 'b@corp.example', 'is_active' => false]);

    assertRefused($this, signInThroughIdp($this, $idp, ['sub' => 'sub-1']));
    assertRefused($this, signInThroughIdp($this, $idp, ['sub' => 'sub-2', 'email' => 'b@corp.example']));
});

test('deactivating an account ends its SSO sessions like its password ones', function () {
    $idp = fakeIdp();
    $user = linkedUser('sub-1', ['email' => 'a@corp.example']);
    [$admin] = userWithKnownPassword(['is_admin' => true]);
    DB::table('sessions')->insert([
        'id' => 'sso-session', 'user_id' => $user->id, 'ip_address' => '10.0.0.1',
        'user_agent' => 'x', 'payload' => '', 'last_activity' => now()->getTimestamp(),
    ]);

    $this->actingAs($admin)->put("/admin/users/{$user->id}", [
        'name' => $user->name, 'email' => $user->email, 'is_admin' => false, 'is_active' => false,
    ])->assertRedirect();

    expect(DB::table('sessions')->where('id', 'sso-session')->exists())->toBeFalse();
});

test('SSO-only: a non-admin password is refused, an admin password works, off changes nothing', function () {
    fakeIdp();
    [$member, $memberPassword] = userWithKnownPassword();
    [$admin, $adminPassword] = userWithKnownPassword(['is_admin' => true]);

    $this->post('/login', ['email' => $member->email, 'password' => $memberPassword])->assertRedirect(route('dashboard', absolute: false));
    $this->post('/logout');

    InstanceSettings::update(['sso_only' => true]);

    $this->post('/login', ['email' => $member->email, 'password' => $memberPassword])
        ->assertSessionHasErrors(['email' => GENERIC_REFUSAL]);
    $this->assertGuest();

    $this->post('/login', ['email' => $admin->email, 'password' => $adminPassword])->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($admin);
});

test('SSO-only does nothing when no provider is configured', function () {
    config(['arguesecure.oidc.issuer' => '']);
    InstanceSettings::update(['sso_only' => true]);
    [$member, $password] = userWithKnownPassword();

    expect(InstanceSettings::ssoOnly())->toBeFalse();
    $this->post('/login', ['email' => $member->email, 'password' => $password])->assertRedirect(route('dashboard', absolute: false));
});

test('in SSO-only mode an email change without a recent provider sign-in goes to the provider with prompt=login', function () {
    $idp = fakeIdp();
    $user = linkedUser('sub-1', ['email' => 'a@corp.example']);
    InstanceSettings::update(['sso_only' => true]);

    $change = fn () => $this->actingAs($user)->withHeaders(['X-Inertia' => 'true'])->patch('/profile', ['name' => $user->name, 'email' => 'new@corp.example']);

    $change()->assertStatus(409)->assertHeader('X-Inertia-Location', route('oidc.redirect', ['reauth' => 1]));
    expect($user->refresh()->email)->toBe('a@corp.example');

    $flow = startOidc($this, ['reauth' => 1]);
    parse_str((string) parse_url($flow['url'], PHP_URL_QUERY), $params);
    expect($params['prompt'])->toBe('login')->and($params['max_age'])->toBe('0');

    signInThroughIdp($this, $idp, ['sub' => 'sub-1'], $flow)->assertRedirect(route('profile.edit'));
    $this->assertAuthenticatedAs($user);

    $change()->assertRedirect(route('profile.edit'));
    expect($user->refresh()->email)->toBe('new@corp.example');

    // The step-up is used up by the change it allowed.
    $user->identities()->update(['subject' => 'sub-1']);
    $this->actingAs($user)->withHeaders(['X-Inertia' => 'true'])->patch('/profile', ['name' => $user->name, 'email' => 'again@corp.example'])->assertStatus(409);
});

test('a step-up expires after five minutes', function () {
    $user = linkedUser('sub-1', ['email' => 'a@corp.example']);
    InstanceSettings::update(['sso_only' => true]);
    fakeIdp();

    $this->actingAs($user)->withHeaders(['X-Inertia' => 'true'])->withSession(['oidc.reauthenticated_at' => time() - 301])
        ->patch('/profile', ['name' => $user->name, 'email' => 'new@corp.example'])->assertStatus(409);
    $this->actingAs($user)->withSession(['oidc.reauthenticated_at' => time() - 100])
        ->patch('/profile', ['name' => $user->name, 'email' => 'new@corp.example'])->assertRedirect();
});

test('a step-up for a different account is refused', function () {
    $idp = fakeIdp();
    $mine = linkedUser('sub-mine', ['email' => 'mine@corp.example']);
    linkedUser('sub-theirs', ['email' => 'theirs@corp.example']);
    $this->actingAs($mine);

    $flow = startOidc($this, ['reauth' => 1]);
    signInThroughIdp($this, $idp, ['sub' => 'sub-theirs'], $flow)->assertRedirect(route('profile.edit'));

    expect(session('oidc.reauthenticated_at'))->toBeNull();
    $this->assertAuthenticatedAs($mine);
});

test('the routes are rate limited to 30 a minute per address, redirect and callback together', function () {
    fakeIdp();

    for ($i = 0; $i < 30; $i++) {
        $this->get('/auth/oidc/redirect')->assertRedirect();
    }
    $this->get('/auth/oidc/redirect')->assertStatus(429);
    $this->get('/auth/oidc/callback')->assertStatus(429);
});

test('with no issuer configured both routes answer 404 and the login page has no SSO link', function () {
    config(['arguesecure.oidc.issuer' => '']);
    User::factory()->create();

    $this->get('/auth/oidc/redirect')->assertNotFound();
    $this->get('/auth/oidc/callback?state=a&code=b')->assertNotFound();
    $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('sso', null));
});

test('with a provider configured the login page carries the link and the label', function () {
    fakeIdp();
    User::factory()->create();

    $this->get('/login')->assertInertia(fn (Assert $page) => $page
        ->where('sso.label', 'Test IdP')
        ->where('sso.url', route('oidc.redirect'))
        ->where('sso.only', false));
});

test('the provider is never trusted over http, except on localhost', function () {
    fakeIdp(['issuer' => 'http://idp.example.test']);

    assertRefused($this, $this->get('/auth/oidc/redirect'));
});

test('a discovery document for another issuer is refused', function () {
    // Registered first, so it wins over the fake provider's own document.
    Http::fake([IDP.'/.well-known/openid-configuration' => Http::response([
        'issuer' => 'https://evil.example.test', 'authorization_endpoint' => 'https://evil.example.test/a',
        'token_endpoint' => 'https://evil.example.test/t', 'jwks_uri' => 'https://evil.example.test/j',
    ])]);
    $idp = fakeIdp();

    assertRefused($this, $this->get('/auth/oidc/redirect'));
    expect($idp->tokenRequests)->toBe([]);
});

test('failed sign-ins are reported with a reason, not shown to the browser', function () {
    Event::fake([SsoLoginFailed::class]);
    $idp = fakeIdp();
    $flow = startOidc($this);

    $response = $this->get('/auth/oidc/callback?state=wrong&code=c');

    Event::assertDispatched(SsoLoginFailed::class, fn ($e) => $e->reason === 'State mismatch.');
    expect($response->getContent())->not->toContain('State mismatch');
    expect($flow['state'])->not->toBe('wrong');
    expect($idp->tokenRequests)->toBe([]);
});

test('an account signed in by the provider is not sent to enrolment when two-factor is required', function () {
    $idp = fakeIdp();
    linkedUser('sub-1', ['email' => 'a@corp.example']);
    InstanceSettings::update(['require_two_factor' => true]);

    signInThroughIdp($this, $idp, ['sub' => 'sub-1'])->assertRedirect();

    $this->get('/dashboard')->assertOk();
});

test('an admin can see and unlink an identity, which ends the account\'s sessions; nobody else can', function () {
    Event::fake([SsoIdentityUnlinked::class]);
    fakeIdp();
    $user = linkedUser('sub-1', ['email' => 'a@corp.example']);
    [$admin] = userWithKnownPassword(['is_admin' => true]);
    [$member] = userWithKnownPassword();
    DB::table('sessions')->insert([
        'id' => 'linked-session', 'user_id' => $user->id, 'ip_address' => '10.0.0.1',
        'user_agent' => 'x', 'payload' => '', 'last_activity' => now()->getTimestamp(),
    ]);

    $this->actingAs($member)->delete("/admin/users/{$user->id}/identity")->assertForbidden();
    expect($user->identities()->count())->toBe(1);
    $this->flushSession();
    auth()->logout();
    $this->delete("/admin/users/{$user->id}/identity")->assertRedirect(route('login'));

    $this->actingAs($admin)->get("/admin/users/{$user->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('identity.issuer', IDP)
        ->missing('identity.subject'));

    $this->actingAs($admin)->delete("/admin/users/{$user->id}/identity")->assertRedirect();

    expect($user->identities()->count())->toBe(0);
    expect(DB::table('sessions')->where('id', 'linked-session')->exists())->toBeFalse();
    Event::assertDispatched(SsoIdentityUnlinked::class);
});

test('the controller refuses a non-admin on its own, not only behind the route group', function () {
    [$other] = userWithKnownPassword();
    $victim = linkedUser('sub-1');

    $request = Request::create("/admin/users/{$victim->id}/identity", 'DELETE');
    $request->setUserResolver(fn () => $other);
    $request->setLaravelSession(app('session.store'));

    expect(fn () => app(UserIdentityController::class)->destroy($request, $victim))
        ->toThrow(HttpException::class);
    expect($victim->identities()->count())->toBe(1);
});

test('the SSO-only switch needs a provider, and saves once one is configured', function () {
    [$admin] = userWithKnownPassword(['is_admin' => true]);
    $payload = ['require_two_factor' => false, 'sso_only' => true];

    config(['arguesecure.oidc.issuer' => '']);
    $this->actingAs($admin)->get('/admin/authentication')->assertInertia(fn (Assert $page) => $page->where('enabled', false));

    // W133, D108: refused, and nothing stored, while no provider is set.
    $this->actingAs($admin)->put('/admin/authentication', $payload)->assertSessionHasErrors('sso_only');
    expect(InstanceSettings::all()['sso_only'])->toBeFalse();

    fakeIdp();
    $this->actingAs($admin)->get('/admin/authentication')->assertInertia(fn (Assert $page) => $page
        ->where('enabled', true)->where('settings.sso_only', false));

    $this->actingAs($admin)->put('/admin/authentication', $payload)->assertSessionHasNoErrors();
    expect(InstanceSettings::ssoOnly())->toBeTrue();
});
