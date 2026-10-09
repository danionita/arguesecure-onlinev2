<?php

// W24 — the response-header half of the OWASP pass (security.md §6).
//
// `env()` is permitted here and nowhere else outside a config file
// (CLAUDE.md: "env() outside a config file returns null once config:cache
// runs"). Everything below is read by
// App\Http\Middleware\SecurityHeaders, which is the only consumer.
//
// The directive list is data rather than a hardcoded string in the
// middleware for one reason: `connect-src` and the enforce/report-only
// switch both have to vary per deployment, and a deployment should be able
// to see the whole policy in one place when a browser console reports a
// violation. Nothing here is user input; it is never interpolated from a
// request.
return [

    /*
    |--------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------
    |
    | security.md §6, reproduced directive-for-directive. `'self'` and the
    | per-request nonce are the only script/style sources: no
    | 'unsafe-inline', no 'unsafe-eval', no CDN, no external font, no
    | remote image (§6: "Everything is self-hosted"). The legacy app
    | loaded socket.io from cdn.socket.io with no SRI; that entire class of
    | dependency is gone, and this policy is what keeps it gone.
    |
    | `{nonce}` is substituted per request by SecurityHeaders. It is the
    | only placeholder; `{ws}` below is resolved from app.url at boot.
    |
    */

    'csp' => [

        // Report-only ships the header as
        // `Content-Security-Policy-Report-Only`, which browsers evaluate
        // and log but never enforce.
        //
        // Enforcing by default. This shipped as report-only for exactly as
        // long as the Ziggy route-table script lacked a nonce: enforcing
        // then would have blocked it and taken every route() call site in
        // resources/js with it. app.blade.php now passes the nonce, and
        // PinnedDefectsTest asserts it stays passed, so the policy can be
        // enforced rather than merely observed.
        //
        // Left overridable: a deployment that adds a third-party embed can
        // set CSP_REPORT_ONLY=true to collect violation reports before
        // tightening the directives, rather than being forced to choose
        // between a broken page and no policy at all.
        'report_only' => (bool) env('CSP_REPORT_ONLY', false),

        'directives' => [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{nonce}'"],
            'style-src' => ["'self'", "'nonce-{nonce}'"],
            'img-src' => ["'self'", 'data:'],
            'font-src' => ["'self'"],
            'connect-src' => ["'self'", '{ws}'],
            'frame-ancestors' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'object-src' => ["'none'"],
        ],
    ],

    /*
    |--------------------------------------------------------------------
    | Additional response headers
    |--------------------------------------------------------------------
    |
    | security.md §6's second paragraph. Sent on every response from the
    | `web` group.
    |
    | X-Frame-Options duplicates `frame-ancestors 'none'` above on
    | purpose: frame-ancestors supersedes it in every browser that
    | implements CSP Level 2, but the older header is what a scanner
    | checks for and costs one line.
    |
    */

    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-Frame-Options' => 'DENY',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), usb=(), payment=()',
    ],

    /*
    |--------------------------------------------------------------------
    | HSTS
    |--------------------------------------------------------------------
    |
    | Two years, includeSubDomains (security.md §6). Emitted only on a
    | request Laravel considers secure, which behind the SWAG reverse
    | proxy depends on TrustProxies being configured — see
    | bootstrap/app.php and deployment.md §4. Sending it over plain HTTP
    | is a no-op per RFC 6797 §7.2, but withholding it keeps a local
    | http://localhost:8000 dev session from pinning HTTPS on `localhost`
    | for two years, which is genuinely painful to undo.
    |
    | `preload` is deliberately absent: submitting to the preload list is
    | an operator decision with a slow reversal, not a default.
    |
    */

    'hsts' => [
        'enabled' => (bool) env('HSTS_ENABLED', true),
        'max_age' => 63072000,
        'include_subdomains' => true,
    ],
];
