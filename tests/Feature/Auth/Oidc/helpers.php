<?php

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * A fake OpenID Connect provider: a key pair generated for the test, its
 * discovery document and key set served through Http::fake(), and a token
 * endpoint that hands back whatever the test last put in `$idp->idToken`.
 * Nothing here is a credential written down anywhere.
 */
const IDP = 'https://idp.example.test';
const CLIENT_ID = 'arguesecure-test-client';

function fakeIdp(array $oidcConfig = []): stdClass
{
    Cache::flush();

    $idp = new stdClass;
    $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($resource, $idp->privatePem);
    $details = openssl_pkey_get_details($resource);
    $b64 = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    $idp->jwks = ['keys' => [['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => 'k1', 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])]]];
    $idp->idToken = null;
    $idp->tokenStatus = 200;
    $idp->tokenRequests = [];

    config(['arguesecure.oidc' => array_merge([
        'issuer' => IDP,
        'client_id' => CLIENT_ID,
        'client_secret' => 'test-only-'.bin2hex(random_bytes(8)),
        'button_label' => 'Test IdP',
        'allowed_domains' => '',
        'trust_email' => false,
    ], $oidcConfig)]);

    Http::fake([
        IDP.'/.well-known/openid-configuration' => Http::response([
            'issuer' => IDP,
            'authorization_endpoint' => IDP.'/authorize',
            'token_endpoint' => IDP.'/token',
            'jwks_uri' => IDP.'/jwks',
            'token_endpoint_auth_methods_supported' => ['client_secret_post'],
        ]),
        IDP.'/jwks' => Http::response($idp->jwks),
        IDP.'/token' => function ($request) use ($idp) {
            $idp->tokenRequests[] = $request->data();

            return $idp->tokenStatus === 200
                ? Http::response(['id_token' => $idp->idToken, 'token_type' => 'Bearer'])
                : Http::response(['error' => 'invalid_grant'], $idp->tokenStatus);
        },
    ]);

    return $idp;
}

/** Sign an ID token with the provider's key; `$claims` override the defaults. */
function idToken(stdClass $idp, string $nonce, array $claims = [], string $alg = 'RS256'): string
{
    $payload = array_merge([
        'iss' => IDP,
        'sub' => 'subject-'.bin2hex(random_bytes(4)),
        'aud' => CLIENT_ID,
        'iat' => time(),
        'exp' => time() + 300,
        'nonce' => $nonce,
        'email' => 'someone@corp.example',
        'email_verified' => true,
        'name' => 'Some One',
    ], $claims);

    // A claim set to null is a claim left out.
    $payload = array_filter($payload, fn ($v) => $v !== null);

    return JWT::encode($payload, $idp->privatePem, $alg, 'k1');
}

/**
 * Start a flow and return what the provider would have been sent.
 *
 * @return array{state: string, nonce: string, url: string}
 */
function startOidc($test, array $query = []): array
{
    $response = $test->get('/auth/oidc/redirect'.($query ? '?'.http_build_query($query) : ''));
    $response->assertRedirectContains(IDP.'/authorize?');
    $url = $response->headers->get('Location');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

    return ['state' => $params['state'], 'nonce' => $params['nonce'], 'url' => $url];
}

/** Run a whole flow, with `$claims` in the ID token; returns the callback response. */
function signInThroughIdp($test, stdClass $idp, array $claims = [], ?array $flow = null)
{
    $flow ??= startOidc($test);
    $idp->idToken = idToken($idp, $flow['nonce'], $claims);

    return $test->get('/auth/oidc/callback?'.http_build_query(['state' => $flow['state'], 'code' => 'the-code']));
}

const GENERIC_REFUSAL = 'These credentials do not match our records.';
