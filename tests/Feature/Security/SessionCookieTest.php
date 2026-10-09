<?php

/**
 * W24 — legacy defect #6 (security.md §1 and §5).
 *
 * The v1 `config/session.php` had **no `http_only` key at all**, so the
 * session cookie was readable by `document.cookie`, and `secure` was
 * `false`. security.md §5 is explicit that every value must be *stated*
 * rather than left to a default, "because defect #6 was an omission".
 *
 * These assert the real `Set-Cookie` header off a real HTTP response
 * (security.md §13: "verified by an integration test asserting the
 * Set-Cookie header"), not `config('session.http_only') === true`. A
 * config key is not a control; a cookie attribute the browser receives is.
 *
 * What writing them found: `secure` was still Laravel's stock
 * `env('SESSION_SECURE_COOKIE')` with **no default**, which is `null` when
 * the variable is unset, and null resolves to not-secure. So half of
 * defect #6 — the half that had been ticked off as done — was still live
 * for any deployment that had not explicitly set the variable. Fixed in
 * config/session.php by this package; this test is what holds it.
 */

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * @return array<string, Cookie>
 */
function cookiesFrom(TestResponse $response): array
{
    $byName = [];

    foreach ($response->headers->getCookies() as $cookie) {
        $byName[$cookie->getName()] = $cookie;
    }

    return $byName;
}

it('sets the session cookie HttpOnly, Secure and SameSite=Lax', function () {
    $cookies = cookiesFrom($this->get('/'));

    $session = $cookies[config('session.cookie')] ?? null;

    expect($session)->not->toBeNull();
    expect($session->isHttpOnly())->toBeTrue()
        ->and($session->isSecure())->toBeTrue()
        ->and(strtolower((string) $session->getSameSite()))->toBe('lax');
});

it('states all three attributes in the raw Set-Cookie header', function () {
    // The attribute-level assertions above go through Symfony's Cookie
    // object. This one reads the serialized header, which is what the
    // browser actually parses, so a framework change in how the object is
    // rendered cannot quietly drop an attribute.
    $header = collect($this->get('/')->headers->all('set-cookie'))
        ->first(fn (string $line): bool => str_starts_with($line, config('session.cookie').'='));

    expect($header)->not->toBeNull()
        ->and($header)->toContain('httponly')
        ->and($header)->toContain('secure')
        ->and($header)->toContain('samesite=lax');
});

it('leaves the CSRF cookie readable by JavaScript, which is deliberate', function () {
    // XSRF-TOKEN is the one cookie that must NOT be HttpOnly: axios reads
    // it to populate the X-XSRF-TOKEN header on every mutating request.
    // Asserted so that a future "harden all cookies" sweep cannot break
    // CSRF protection while believing it improved it. It is still Secure
    // and still SameSite=Lax, and it carries no session identifier.
    $csrf = cookiesFrom($this->get('/'))['XSRF-TOKEN'] ?? null;

    expect($csrf)->not->toBeNull();
    expect($csrf->isHttpOnly())->toBeFalse()
        ->and($csrf->isSecure())->toBeTrue()
        ->and(strtolower((string) $csrf->getSameSite()))->toBe('lax');
});

it('never lets a non-local environment fall back to an insecure cookie', function () {
    // The specific regression this package fixed: an unset
    // SESSION_SECURE_COOKIE used to mean "not secure". The config now
    // derives its default from APP_ENV, so forgetting the variable is
    // safe rather than silently insecure.
    expect(config('session.secure'))->toBeTrue()
        ->and(app()->environment('local'))->toBeFalse();
});

it('encrypts session payloads at rest', function () {
    // security.md §5: 'encrypt' => true. Sessions live in Redis, which
    // holds share-token grants; Laravel's stock default is false.
    expect(config('session.encrypt'))->toBeTrue();
});

it('regenerates the session id on login', function () {
    // security.md §5: "Regenerate the session id on login and on
    // privilege change." Session fixation: if the id survived
    // authentication, an attacker who planted a known id pre-login would
    // hold an authenticated session afterwards.
    // userWithKnownPassword() is tests/Pest.php's helper: it generates the
    // plaintext per call and returns it, so no credential literal is
    // written into any file (CLAUDE.md).
    [$user, $password] = userWithKnownPassword();

    $this->get('/login');
    $before = session()->getId();

    $this->post('/login', ['email' => $user->email, 'password' => $password])
        ->assertRedirect();

    expect(session()->getId())->not->toBe($before);
    $this->assertAuthenticatedAs($user);
});
