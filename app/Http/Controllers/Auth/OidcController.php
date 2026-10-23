<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\Oidc\Events\SsoLoggedIn;
use App\Domain\Identity\Oidc\Events\SsoLoginFailed;
use App\Domain\Identity\Oidc\OidcClient;
use App\Domain\Identity\Oidc\OidcConfig;
use App\Domain\Identity\Oidc\OidcException;
use App\Domain\Identity\Oidc\SsoAccounts;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Sign in with the instance's OpenID Connect provider (W117, D74-D76).
 *
 * Both routes are plain GETs: the flow starts from a link (CSP's
 * `form-action 'self'` would block a form redirecting to the provider) and
 * the callback is a top-level navigation, which the `same_site=lax` session
 * cookie accompanies. `state`, `nonce` and the PKCE verifier live in the
 * session. Every refusal looks the same to the browser as a wrong password.
 */
class OidcController extends Controller
{
    /** How long a started flow may take: the user is typing a password at the provider. */
    private const FLOW_TTL_SECONDS = 600;

    /** D79: a provider sign-in this recent counts as proof of presence. */
    public const REAUTH_WINDOW_SECONDS = 300;

    public const SESSION_FLOW = 'oidc.flow';

    public const SESSION_REAUTH = 'oidc.reauthenticated_at';

    public const SESSION_METHOD = 'auth.method';

    public function __construct(private readonly OidcClient $client, private readonly SsoAccounts $accounts) {}

    public function redirect(Request $request): RedirectResponse
    {
        abort_unless(OidcConfig::enabled(), 404);

        $reauth = $request->boolean('reauth') && $request->user() !== null;

        // Already signed in and not stepping up: nothing to do here.
        if ($request->user() !== null && ! $reauth) {
            return redirect()->route('dashboard');
        }

        $flow = [
            'state' => Str::random(48),
            'nonce' => Str::random(48),
            'verifier' => Str::random(96),
            'expires_at' => Carbon::now()->getTimestamp() + self::FLOW_TTL_SECONDS,
            'reauth' => $reauth,
        ];

        try {
            $url = $this->client->authorizationUrl(
                route('oidc.callback'),
                $flow['state'],
                $flow['nonce'],
                $flow['verifier'],
                // D79: ask the provider to authenticate again, not to reuse its session.
                $reauth ? ['prompt' => 'login', 'max_age' => '0'] : [],
            );
        } catch (OidcException $e) {
            return $this->refuse($request, $e->getMessage(), $reauth);
        }

        $request->session()->put(self::SESSION_FLOW, $flow);

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(OidcConfig::enabled(), 404);

        // Consumed whatever happens: a state is good for one callback.
        $flow = $request->session()->pull(self::SESSION_FLOW);
        $reauth = is_array($flow) && ($flow['reauth'] ?? false) === true;

        try {
            if (! is_array($flow) || ($flow['expires_at'] ?? 0) < Carbon::now()->getTimestamp()) {
                throw new OidcException('No flow in progress, or it expired.');
            }

            $state = $request->query('state');
            if (! is_string($state) || ! hash_equals($flow['state'], $state)) {
                throw new OidcException('State mismatch.');
            }

            if ($request->query('error') !== null) {
                throw new OidcException('The provider returned an error.');
            }

            $code = $request->query('code');
            if (! is_string($code) || $code === '') {
                throw new OidcException('No authorization code.');
            }

            $claims = $this->client->claimsFor($code, $flow['verifier'], route('oidc.callback'), $flow['nonce']);
            $user = $this->accounts->resolve($claims);

            if ($reauth && $request->user()?->isNot($user)) {
                throw new OidcException('The re-authentication was for a different account.');
            }
        } catch (OidcException $e) {
            return $this->refuse($request, $e->getMessage(), $reauth);
        }

        if ($reauth) {
            $request->session()->put(self::SESSION_REAUTH, Carbon::now()->getTimestamp());

            return redirect()->route('profile.edit')->with('status', 'You signed in again. Save your change now.');
        }

        // Signed in by the provider: the provider's own second factor is the
        // organisation's to enforce (D78), so the session says how it began.
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_METHOD, 'oidc');

        $user->forceFill(['last_login_at' => now()])->save();

        event(new SsoLoggedIn($user));

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function refuse(Request $request, string $reason, bool $reauth): RedirectResponse
    {
        event(new SsoLoginFailed($reason, $request->ip()));

        if ($reauth) {
            return redirect()->route('profile.edit')->withErrors(['current_password' => 'Signing in again was not accepted.']);
        }

        return redirect()->route('login')->withErrors(['email' => 'These credentials do not match our records.']);
    }
}
