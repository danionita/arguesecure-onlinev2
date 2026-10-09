<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Content Security Policy with per-request nonces, plus the rest of
 * security.md §6's header set. W24.
 *
 * Before this middleware existed the application sent **no** security
 * headers at all — no CSP, no nosniff, no Referrer-Policy, no
 * frame-ancestors. security.md §6 has specified all of them since Phase 0;
 * nothing had implemented them.
 *
 * ## The nonce
 *
 * One nonce per request, 16 bytes of `random_bytes` base64-encoded. It is
 * handed to Laravel's Vite helper via `Vite::useCspNonce()` *before* the
 * response is rendered, which is what makes `@vite(...)` emit
 * `nonce="…"` on the `<script>` and `<link rel=stylesheet>` tags it
 * generates. `Vite::cspNonce()` then returns the same value anywhere else
 * that needs it, including Blade.
 *
 * The nonce must be generated on the way *in*, not on the way out: by the
 * time a response object exists the HTML has already been rendered, and a
 * nonce in the header that does not appear in the markup blocks every
 * script on the page.
 *
 * ## Known gap — the Ziggy @routes script (NOT fixed here)
 *
 * `resources/views/app.blade.php` calls `@routes`, which compiles to
 * `app(BladeRouteGenerator::class)->generate($group)`. Ziggy's generator
 * accepts a nonce as its *second* argument and emits none when it is
 * omitted, and it does not fall back to `Vite::cspNonce()`. So that one
 * inline `<script>` — the one carrying the entire route table that all 92
 * `route()` call sites in resources/js depend on — has no nonce and is
 * blocked by the policy below when it is enforced.
 *
 * `resources/views/**` is outside this package's file ownership, so this
 * is reported rather than patched (CLAUDE.md: "If you need to change a
 * file another package owns, stop and report rather than editing it").
 * The fix is one line in that template: pass the Vite CSP nonce as the
 * directive's second argument, i.e. `Vite::cspNonce()` in the position
 * after the null group. (Written out rather than shown literally because
 * a docblock line starting with that directive name is read as a PHPDoc
 * annotation and fails static analysis.)
 *
 * `tests/Feature/Security/PinnedDefectsTest.php` pins the current
 * state so the gap cannot be forgotten: it asserts that the Ziggy script
 * is presently un-nonced, and will go red the moment that template is
 * fixed, forcing whoever fixes it to flip the assertion and drop
 * `CSP_REPORT_ONLY` from `.env.example`. That is the same "pin the defect
 * you do not own as a passing test" convention W20 used in
 * tests/Feature/Catalog/AssessmentClonerTest.php.
 *
 * Until then `CSP_REPORT_ONLY=true` is set in `.env.example` so a fresh
 * deployment gets the policy reported and logged by the browser rather
 * than enforced against a page it would break. The header, the nonce and
 * every other header below are live and enforced regardless of that flag.
 */
class SecurityHeaders
{
    /**
     * Placeholder substituted with this request's nonce.
     */
    private const NONCE_PLACEHOLDER = '{nonce}';

    /**
     * Placeholder substituted with the websocket origin Reverb is proxied
     * under (security.md §6's `connect-src 'self' wss://{app_host}`).
     */
    private const WS_PLACEHOLDER = '{ws}';

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = $this->generateNonce();

        // Must happen before $next(): this is what puts nonce="…" on the
        // tags @vite renders, and the response is rendered inside $next().
        Vite::useCspNonce($nonce);

        $response = $next($request);

        return $this->apply($response, $request, $nonce);
    }

    /**
     * Sets the full security.md §6 header set on a response.
     *
     * Split out from handle() so bootstrap/app.php's `->withExceptions(...)`
     * hook can reuse it. An unmatched URL throws `NotFoundHttpException`
     * from inside the router, which propagates past every middleware's
     * "after" code (nothing after `$next()` in `handle()` above ever runs)
     * and is rendered by Laravel's exception handler entirely outside the
     * middleware pipeline. This class still ran first — it is global
     * middleware, so its "before" half (the `Vite::useCspNonce()` call
     * above) already executed and fixed this request's nonce — so the
     * exception hook calls this method with no `$nonce` argument and it
     * falls back to that already-established value via `currentNonce()`
     * rather than generating a second one. Generating a fresh nonce here
     * would desync the header from any markup that already rendered with
     * the first one, and would defeat the "one nonce per request" contract
     * this class documents at the top of the file.
     */
    public function apply(Response $response, Request $request, ?string $nonce = null): Response
    {
        $nonce ??= self::currentNonce();

        foreach ($this->headers() as $header => $value) {
            $response->headers->set($header, $value);
        }

        $response->headers->set($this->cspHeaderName(), $this->policy($nonce, $request));

        $hsts = $this->strictTransportSecurity($request);

        if ($hsts !== null) {
            $response->headers->set('Strict-Transport-Security', $hsts);
        }

        return $response;
    }

    /**
     * 16 bytes is CSP Level 3's own recommendation (128 bits of entropy);
     * base64 rather than hex keeps the header shorter. `random_bytes` is
     * the CSPRNG — a nonce a page author can predict is not a nonce.
     */
    private function generateNonce(): string
    {
        return base64_encode(random_bytes(16));
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        /** @var array<string, string> $headers */
        $headers = config('security.headers', []);

        return $headers;
    }

    private function cspHeaderName(): string
    {
        return config('security.csp.report_only') === true
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';
    }

    /**
     * Builds the policy string from config, substituting this request's
     * nonce and the websocket origin. Nothing here comes from request
     * input — the host is taken from `app.url` rather than from the Host
     * header, which is exactly the header-injection surface bootstrap's
     * TrustProxies block deliberately refuses to trust.
     */
    private function policy(string $nonce, Request $request): string
    {
        /** @var array<string, list<string>> $directives */
        $directives = config('security.csp.directives', []);

        $websocketOrigins = $this->websocketOrigins($request);

        $parts = [];

        foreach ($directives as $directive => $sources) {
            $resolved = [];

            foreach ($sources as $source) {
                if ($source === self::WS_PLACEHOLDER) {
                    // Drop the placeholder entirely rather than emit an
                    // empty token: `connect-src 'self' ` with a trailing
                    // blank is a parse error in some browsers, and 'self'
                    // already covers same-origin XHR.
                    if ($websocketOrigins === []) {
                        continue;
                    }

                    foreach ($websocketOrigins as $origin) {
                        $resolved[] = $origin;
                    }

                    continue;
                }

                $resolved[] = str_replace(self::NONCE_PLACEHOLDER, $nonce, $source);
            }

            if ($resolved === []) {
                continue;
            }

            $parts[] = $directive.' '.implode(' ', $resolved);
        }

        return implode('; ', $parts);
    }

    /**
     * `wss://{app_host}` — Reverb is proxied under the app's own hostname
     * at /app so it inherits TLS and the same origin (security.md §8), so
     * the websocket host is always the app host and never a separate one.
     * Falls back to `ws://` for a plain-HTTP local run, where `wss://`
     * would be unusable.
     *
     * Two origins, not one, because neither source is right on its own and
     * a CSP origin match includes the **port**:
     *
     * - `config('app.url')` alone breaks the documented local deployment.
     *   `.env.example` ships `APP_URL` without a port while `docker compose
     *   up` publishes 8000, which emitted `ws://localhost` against a
     *   browser dialling `ws://localhost:8000`. The socket was blocked
     *   outright, so presence never appeared and edits never arrived live —
     *   with nothing in any server log, because a CSP refusal happens
     *   entirely in the browser.
     * - The request alone breaks the proxied deployment. TrustProxies
     *   deliberately does not trust `X-Forwarded-Host` (deployment.md §4),
     *   so behind SWAG `getHost()` is the internal container name, not the
     *   hostname the browser used.
     *
     * Emitting both costs nothing — they are alternatives in a source list,
     * and each is an origin this application is genuinely served on — and
     * it means one built image works in both without configuration.
     *
     * @return list<string>
     */
    private function websocketOrigins(Request $request): array
    {
        $origins = [];

        // The origin the browser actually used for this request, port and
        // all. This is what makes the local http://localhost:8000 case work.
        $requestHost = $request->getHost();

        if ($requestHost !== '') {
            $port = $request->getPort();
            $scheme = $request->isSecure() ? 'wss://' : 'ws://';
            $isDefaultPort = ($request->isSecure() && $port === 443)
                || (! $request->isSecure() && $port === 80);

            $origins[] = $scheme.$requestHost.($isDefaultPort || $port === null ? '' : ':'.$port);
        }

        // The configured public URL, which is the only correct answer behind
        // a reverse proxy whose forwarded host is not trusted.
        $configuredHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (is_string($configuredHost) && $configuredHost !== '') {
            $configuredPort = parse_url((string) config('app.url'), PHP_URL_PORT);
            $configuredScheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) === 'https'
                ? 'wss://'
                : 'ws://';

            $origins[] = $configuredScheme.$configuredHost
                .(is_int($configuredPort) ? ':'.$configuredPort : '');
        }

        return array_values(array_unique($origins));
    }

    /**
     * Withheld on a non-secure request — see config/security.php's `hsts`
     * block for why pinning HTTPS on a local http:// dev host is worth
     * avoiding. Behind the reverse proxy `isSecure()` is true only because
     * TrustProxies honours X-Forwarded-Proto (deployment.md §4).
     */
    private function strictTransportSecurity(Request $request): ?string
    {
        if (config('security.hsts.enabled') !== true || ! $request->isSecure()) {
            return null;
        }

        $value = 'max-age='.(int) config('security.hsts.max_age');

        if (config('security.hsts.include_subdomains') === true) {
            $value .= '; includeSubDomains';
        }

        return $value;
    }

    /**
     * Exposed for tests and for any caller that needs the current
     * request's nonce without reaching for the Vite facade.
     */
    public static function currentNonce(): string
    {
        return (string) Str::of(Vite::cspNonce());
    }
}
