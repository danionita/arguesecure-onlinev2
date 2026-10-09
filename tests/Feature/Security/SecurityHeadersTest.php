<?php

/**
 * W24 — security.md §6, the response-header pass.
 *
 * Before this package the application sent **no** security headers at all:
 * no CSP, no nosniff, no Referrer-Policy, no frame-ancestors, no
 * Permissions-Policy, no HSTS. `grep -rn "Content-Security-Policy"` over
 * app/, config/, routes/, bootstrap/ and resources/ returned nothing.
 * Everything asserted below is therefore new behaviour introduced by
 * App\Http\Middleware\SecurityHeaders, not a re-assertion of something a
 * previous package had already done.
 *
 * These go through a real HTTP request rather than reading config, because
 * a config value that no middleware reads protects nothing — which is
 * precisely the state the codebase was in.
 */

use App\Models\Assessment;
use App\Models\User;
use Illuminate\Support\Facades\Vite;
use Illuminate\Testing\TestResponse;

/**
 * Whichever of the two CSP headers is live in the current configuration.
 * The tests below assert the policy's *content*, which is identical in
 * both modes; the enforce/report-only switch itself is covered separately.
 */
function cspHeader(TestResponse $response): string
{
    return (string) ($response->headers->get('Content-Security-Policy')
        ?: $response->headers->get('Content-Security-Policy-Report-Only'));
}

it('sends a content security policy on a page response', function () {
    $response = $this->get('/');

    expect(cspHeader($response))->not->toBe('');
});

it('carries a fresh, high-entropy nonce on every request', function () {
    $first = cspHeader($this->get('/'));
    $second = cspHeader($this->get('/'));

    expect($first)->toMatch("/script-src [^;]*'nonce-[A-Za-z0-9+\/]{22}=='/");

    // A nonce reused across requests is not a nonce: an attacker who can
    // read one page's markup could reuse its value in an injected script
    // on the next.
    expect($first)->not->toBe($second);
});

it('exposes that same nonce to the asset tags Vite renders', function () {
    // This is the wiring that makes @vite emit nonce="…" on its <script>
    // and <link rel=stylesheet> tags. Asserted through Vite::cspNonce()
    // rather than by scraping the markup because tests/TestCase.php calls
    // withoutVite() for the whole suite (no public/build/manifest.json is
    // produced before Pest runs), so the tags themselves are stubbed out
    // here. Laravel's Vite applies cspNonce() to every tag it generates
    // unconditionally, so equality here is the whole contract.
    $response = $this->get('/');

    $nonce = Vite::cspNonce();

    expect($nonce)->not->toBeNull()
        ->and(cspHeader($response))->toContain("'nonce-{$nonce}'");
});

it('forbids inline and eval script, and allows no host but self', function () {
    $csp = cspHeader($this->get('/'));

    expect($csp)
        ->not->toContain('unsafe-inline')
        ->not->toContain('unsafe-eval')
        ->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("form-action 'self'")
        ->toContain("frame-ancestors 'none'");
});

it('permits no external origin anywhere in the policy except the app websocket', function () {
    $csp = cspHeader($this->get('/'));

    // security.md §6: "Everything is self-hosted. No CDN, no external
    // font, no external icon set, no remote image." The only scheme-ful
    // source the policy is allowed to name is the app's own websocket
    // origin, which is the app host itself (Reverb is proxied under /app).
    preg_match_all('#\b(?:https?|wss?)://[^\s;]+#', $csp, $matches);

    $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

    foreach ($matches[0] as $origin) {
        expect(parse_url($origin, PHP_URL_HOST))->toBe($appHost);
    }
});

it('names the websocket origin the browser will actually dial, port included', function () {
    // The bug this pins killed real-time collaboration in the *documented*
    // local deployment, and left no trace anywhere on the server.
    //
    // `.env.example` ships APP_URL without a port while `docker compose up`
    // publishes 8000. websocketOrigins() derived the origin from app.url
    // alone, so the policy said `ws://localhost` while the browser dialled
    // `ws://localhost:8000`. A CSP origin match includes the port, so the
    // socket was refused before it opened: no presence avatars, no live
    // edits, and nothing in any log, because a CSP refusal happens entirely
    // in the browser.
    //
    // Both origins are named now — the request's, which is right locally,
    // and app.url's, which is the only right answer behind a proxy whose
    // X-Forwarded-Host is deliberately untrusted (deployment.md §4).
    config()->set('app.url', 'http://localhost');

    $csp = cspHeader($this->get('http://localhost:8000/'));

    preg_match('#connect-src ([^;]+)#', $csp, $matches);
    $sources = preg_split('/\s+/', trim($matches[1] ?? ''));

    // in_array rather than expect()->toContain($needle, $message): Pest
    // reads every argument to toContain as another needle.
    expect(in_array('ws://localhost:8000', $sources ?: [], true))
        ->toBeTrue('connect-src must name the origin the browser dials, got: '.($matches[1] ?? '(none)'));
});

it('sends the rest of the header set on a page response', function () {
    $response = $this->get('/');

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Permissions-Policy'))
        ->toContain('camera=()')
        ->toContain('microphone=()')
        ->toContain('geolocation=()')
        ->toContain('usb=()')
        ->toContain('payment=()');
});

it('withholds HSTS over plain http and sends it over https', function () {
    // Pinning HTTPS for two years on a developer's http://localhost is
    // genuinely hard to undo, so the header is conditional. Behind the
    // reverse proxy isSecure() is true only because TrustProxies honours
    // X-Forwarded-Proto (deployment.md §4).
    expect($this->get('http://localhost/')->headers->get('Strict-Transport-Security'))->toBeNull();

    $secure = $this->get('https://localhost/');

    expect($secure->headers->get('Strict-Transport-Security'))
        ->toBe('max-age=63072000; includeSubDomains');
});

it('protects an error and a redirect response, not only a 200', function () {
    // The middleware is prepended to the `web` group precisely so an
    // early return or a thrown HttpException from an inner middleware
    // still leaves with the headers attached. Three shapes, all of which
    // an unauthenticated or unauthorized visitor actually hits:
    //   - 302, guest bounced to login by the `auth` middleware
    //   - 403, denied by AssessmentPolicy
    //   - 404, route-model binding finding no such assessment
    $user = User::factory()->create();
    $someoneElses = Assessment::factory()->create();

    $responses = [
        'redirect' => $this->get('/dashboard'),
        'forbidden' => $this->actingAs($user)->get('/assessments/'.$someoneElses->uuid),
        'missing' => $this->actingAs($user)->get('/assessments/00000000-0000-0000-0000-000000000000'),
    ];

    expect($responses['redirect']->getStatusCode())->toBe(302)
        ->and($responses['forbidden']->getStatusCode())->toBe(403)
        ->and($responses['missing']->getStatusCode())->toBe(404);

    foreach ($responses as $shape => $response) {
        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff', $shape)
            ->and($response->headers->get('X-Frame-Options'))->toBe('DENY', $shape)
            ->and(cspHeader($response))->not->toBe('', $shape);
    }
});

it('enforces the policy by default, rather than only reporting it', function () {
    // This shipped report-only for exactly as long as the Ziggy route-table
    // script lacked a nonce (see PinnedDefectsTest). That is fixed, so the
    // default is now enforcement: a policy the browser only reports on stops
    // nothing, and it is easy to leave in that state indefinitely.
    $response = $this->get('/');

    expect(config('security.csp.report_only'))->toBeFalse()
        ->and($response->headers->get('Content-Security-Policy'))->not->toBeNull()
        ->and($response->headers->get('Content-Security-Policy-Report-Only'))->toBeNull();
});

it('can still be put in report-only mode deliberately', function () {
    config()->set('security.csp.report_only', true);

    $response = $this->get('/');

    expect($response->headers->get('Content-Security-Policy-Report-Only'))->not->toBeNull()
        ->and($response->headers->get('Content-Security-Policy'))->toBeNull();
});

it('enforces for real when report-only is switched off', function () {
    config()->set('security.csp.report_only', false);

    $response = $this->get('/');

    expect($response->headers->get('Content-Security-Policy'))->not->toBeNull()
        ->and($response->headers->get('Content-Security-Policy-Report-Only'))->toBeNull();
});
