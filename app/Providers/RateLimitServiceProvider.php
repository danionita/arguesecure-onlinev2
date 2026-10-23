<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * The named rate limiters behind security.md §9.
 *
 * They must be *named*. An anonymous `throttle:120,1` looks like it creates
 * a limiter for the routes it is attached to, and does not:
 * ThrottleRequests::resolveRequestSignature() returns `sha1($user->id)`
 * alone — no route, no method, no name — so every anonymous throttle in the
 * application reads and writes one single counter per user. Three
 * consequences, all of which this codebase actually hit:
 *
 *   1. §9's per-group limits were not independent. The whole application
 *      was bounded by whichever limit the request happened to pass through
 *      first, against a total that every other route had been adding to.
 *   2. Nesting `throttle:120,1` inside `throttle:300,1` incremented that one
 *      counter *twice* per request, so the effective ceiling was 60/min,
 *      not 120.
 *   3. The pre-existing `throttle:60,1` on collaborator search read the same
 *      counter as ordinary page loads, so a user who had merely been
 *      clicking around got a 429 the first time they opened the share
 *      dialog. That is how this was found: the end-to-end suite went red on
 *      its first CI run, at scenario 5, with 429 where it expected 200.
 *
 * A named limiter is keyed `md5($limiterName.$key)` (ThrottleRequests line
 * ~134), which is what actually gives each group its own bucket. Anything
 * added here must therefore be referenced as `throttle:<name>`, never as
 * `throttle:<n>,<m>`.
 *
 * Login is deliberately absent: it needs two limiters on different keys —
 * per IP and per email — which a single middleware alias cannot express, so
 * LoginRequest drives RateLimiter directly. See its docblock.
 */
class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // "Everything else authenticated: 300/min per user." The default
        // for every authenticated group; the tighter limiters below stack
        // on top of it, in their own buckets, and bind first.
        RateLimiter::for('authenticated', fn (Request $request) => Limit::perMinute(300)->by($this->identify($request)));

        // "Node and link mutations: 120/min per user."
        RateLimiter::for('mutations', fn (Request $request) => Limit::perMinute(120)->by($this->identify($request)));

        // "Password change / email change: 5/min per user." Password
        // confirmation is included: it takes the account's current password
        // and answers whether it was right, which is the same surface.
        RateLimiter::for('credentials', fn (Request $request) => Limit::perMinute(5)->by($this->identify($request)));

        // "Search: 60/min per user."
        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(60)->by($this->identify($request)));

        // "Report generation: 10/min per user." The five exports: each builds
        // a file from the whole assessment.
        RateLimiter::for('reports', fn (Request $request) => Limit::perMinute(10)->by($this->identify($request)));

        // W128, D99: downloading the stored security event log. Admin only;
        // 6/min per account bounds how often 50,000 rows are read.
        RateLimiter::for('security-log-export', fn (Request $request) => Limit::perMinute(6)->by($this->identify($request)));

        // "Report views: 60/min per user, 10/min per address for a guest"
        // (W110, D70). The report page, which every option toggle
        // re-requests. It shared `reports` until the owner found the eleventh
        // toggle in a minute answered 429. Assembling the page costs about
        // what an export does, so the relief goes only to signed-in users,
        // who are accountable for their requests; a public or share-link
        // reader keeps the 10/min the page always had.
        RateLimiter::for('report-views', fn (Request $request) => $request->user() === null
            ? Limit::perMinute(10)->by($request->ip())
            : Limit::perMinute(60)->by($this->identify($request)));

        // W51 — the assistant: 6/min per user.
        //
        // Tighter than report generation, and for a different reason. A report
        // is a database read; a generation occupies physical CPU cores for
        // tens of seconds, on a machine that is simultaneously serving
        // everybody else. Six is already more than a person can consume: the
        // spike measured 28s for a suggestion and 94s for the first help
        // answer of a session, so a user asking in earnest cannot reach this
        // ceiling, and one that does is not asking in earnest.
        //
        // This limits how many *per minute*. It cannot see concurrency — six
        // requests in one second are six requests — so it does not, on its
        // own, stop one person putting six generations on the cores at once.
        // App\Domain\Assistant\Generation\SingleFlight covers that half.
        RateLimiter::for('assistant', fn (Request $request) => Limit::perMinute(6)->by($this->identify($request)));

        // "First-run setup: 5/min per IP." Always a guest, by definition —
        // it is the screen that exists because no account does yet.
        RateLimiter::for('setup', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        // W117: "SSO redirect and callback: 30/min per IP." One sign-in is two
        // requests (redirect, callback), so this is about 15 sign-ins a minute
        // from one address: an office behind one NAT at nine o'clock. 10 was
        // found, driving a real Keycloak, to allow only five.
        RateLimiter::for('oidc', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        // W133, D107: "Check provider" makes the server fetch an address an
        // admin typed. Per signed-in user, so it cannot be used to hammer a host.
        RateLimiter::for('oidc-check', fn (Request $request) => Limit::perMinute(6)->by($this->identify($request)));

        // W116: a coarse per-IP ceiling on the sign-in challenge. The real
        // limit (5 failed codes/min per pending account and per IP) is counted
        // in TwoFactorChallengeController; this only bounds raw request volume.
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        // "Share-token resolution /s/{token}: 10/min per IP — this is the
        // brute-force surface for tokens." Keyed on IP rather than user
        // because the caller typically has no account at all, which is the
        // point of a share link.
        RateLimiter::for('share-token', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }

    /**
     * The per-user key, falling back to the address for a caller with no
     * session. Without the fallback an unauthenticated request to a
     * user-keyed limiter would bucket every anonymous caller together under
     * a null key, turning a per-user limit into a global one that any
     * visitor could exhaust for everybody.
     */
    private function identify(Request $request): string
    {
        return (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());
    }
}
