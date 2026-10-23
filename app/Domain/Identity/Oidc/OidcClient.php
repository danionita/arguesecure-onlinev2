<?php

namespace App\Domain\Identity\Oidc;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A narrow OpenID Connect client for the authorization-code flow with PKCE
 * (D74, D75): discovery, the authorization URL, the code exchange and the
 * ID-token checks. Nothing else. Built on Laravel's HTTP client so tests fake
 * the provider with `Http::fake()`, and on `firebase/php-jwt` for the
 * signature only: every claim check is written out here.
 */
final class OidcClient
{
    /** Signature algorithms accepted; never `none`, never an HMAC (the client secret is not a signing key). */
    public const ALGORITHMS = ['RS256', 'RS384', 'RS512', 'PS256', 'PS384', 'PS512', 'ES256', 'ES384'];

    private const LEEWAY_SECONDS = 60;

    /** @return array<string, mixed> */
    public function discovery(bool $fresh = false): array
    {
        $issuer = OidcConfig::issuer();
        $host = parse_url($issuer, PHP_URL_HOST);

        if (! str_starts_with($issuer, 'https://') && ! in_array($host, ['localhost', '127.0.0.1'], true)) {
            throw new OidcException('The issuer is not an https URL.');
        }

        $cacheKey = 'oidc.discovery.'.md5($issuer);

        $fetch = function () use ($issuer): array {
            $response = $this->http()->get($issuer.'/.well-known/openid-configuration');

            return $response->successful() ? (array) $response->json() : [];
        };

        // W133, D107: the admin's "Check provider" must see the provider as it
        // is now, not as it was an hour ago. Fetched directly and stored, not
        // forgotten and re-remembered, so a sign-in between the two cannot
        // refill the key with the old document.
        if ($fresh) {
            $document = $fetch();
            if ($document !== []) {
                Cache::put($cacheKey, $document, 3600);
            }
        } else {
            $document = Cache::remember($cacheKey, 3600, $fetch);
        }

        foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
            if (! isset($document[$key]) || ! is_string($document[$key])) {
                Cache::forget($cacheKey);
                throw new OidcException("Discovery document lacks {$key}.");
            }
        }

        if (rtrim($document['issuer'], '/') !== $issuer) {
            throw new OidcException('Discovery issuer does not match the configured issuer.');
        }

        return $document;
    }

    /**
     * @param  array<string, string>  $extra  e.g. prompt=login, max_age=0 (D79)
     */
    public function authorizationUrl(string $redirectUri, string $state, string $nonce, string $verifier, array $extra = []): string
    {
        $query = http_build_query([
            'response_type' => 'code',
            'response_mode' => 'query',
            'client_id' => OidcConfig::clientId(),
            'redirect_uri' => $redirectUri,
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::challenge($verifier),
            'code_challenge_method' => 'S256',
            ...$extra,
        ], '', '&', PHP_QUERY_RFC3986);

        $endpoint = $this->discovery()['authorization_endpoint'];

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').$query;
    }

    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Trade the code for tokens and return the validated ID-token claims.
     *
     * @return array<string, mixed>
     */
    public function claimsFor(string $code, string $verifier, string $redirectUri, string $nonce): array
    {
        $discovery = $this->discovery();

        $methods = $discovery['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic'];
        $useBasic = is_array($methods) && ! in_array('client_secret_post', $methods, true);

        $request = $this->http()->asForm();
        $body = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $verifier,
            'client_id' => OidcConfig::clientId(),
        ];

        if ($useBasic) {
            $request = $request->withBasicAuth(rawurlencode(OidcConfig::clientId()), rawurlencode(OidcConfig::clientSecret()));
        } else {
            $body['client_secret'] = OidcConfig::clientSecret();
        }

        try {
            $response = $request->post($discovery['token_endpoint'], $body);
        } catch (ConnectionException) {
            throw new OidcException('The token endpoint could not be reached.');
        }

        $idToken = $response->json('id_token');

        if (! $response->successful() || ! is_string($idToken)) {
            throw new OidcException('The token endpoint refused the code.');
        }

        return $this->validateIdToken($idToken, $nonce, $discovery['jwks_uri'], $discovery['issuer']);
    }

    /**
     * @return array<string, mixed>
     */
    public function validateIdToken(string $jwt, string $nonce, string $jwksUri, string $issuer): array
    {
        $parts = explode('.', $jwt);
        $header = count($parts) === 3 ? json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true) : null;
        $alg = is_array($header) ? ($header['alg'] ?? null) : null;

        if (! is_string($alg) || ! in_array($alg, self::ALGORITHMS, true)) {
            throw new OidcException('The ID token uses a signature algorithm that is not accepted.');
        }

        JWT::$leeway = self::LEEWAY_SECONDS;

        try {
            $claims = (array) JWT::decode($jwt, JWK::parseKeySet($this->jwks($jwksUri, false), $alg));
        } catch (Throwable $first) {
            // An unknown kid may mean the provider rotated its keys: one refetch.
            try {
                $claims = (array) JWT::decode($jwt, JWK::parseKeySet($this->jwks($jwksUri, true), $alg));
            } catch (Throwable) {
                throw new OidcException('The ID token did not verify: '.$first::class);
            }
        }

        if (($claims['iss'] ?? null) !== $issuer) {
            throw new OidcException('Wrong issuer.');
        }

        $audience = (array) ($claims['aud'] ?? []);
        if (! in_array(OidcConfig::clientId(), $audience, true)) {
            throw new OidcException('The token is not for this client.');
        }
        if (count($audience) > 1 && ($claims['azp'] ?? null) !== OidcConfig::clientId()) {
            throw new OidcException('Wrong authorized party.');
        }

        if (! is_int($claims['exp'] ?? null) || ! is_int($claims['iat'] ?? null)) {
            throw new OidcException('The token lacks exp or iat.');
        }

        if (! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])) {
            throw new OidcException('Wrong nonce.');
        }

        if (! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new OidcException('The token has no subject.');
        }

        return $claims;
    }

    /** @return array<string, mixed> */
    private function jwks(string $uri, bool $refresh): array
    {
        $key = 'oidc.jwks.'.md5($uri);

        if ($refresh) {
            Cache::forget($key);
        }

        $keys = Cache::remember($key, 3600, function () use ($uri): array {
            $response = $this->http()->get($uri);

            return $response->successful() ? (array) $response->json() : [];
        });

        if (! isset($keys['keys']) || ! is_array($keys['keys'])) {
            Cache::forget($key);
            throw new OidcException('The key set could not be read.');
        }

        return $keys;
    }

    private function http(): PendingRequest
    {
        return Http::timeout(10)->acceptJson();
    }
}
