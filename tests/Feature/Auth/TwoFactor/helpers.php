<?php

use App\Domain\Identity\TwoFactor\Totp;
use App\Domain\Identity\TwoFactor\TwoFactor;
use App\Models\User;

/**
 * An account with a confirmed second factor, and everything a test needs to
 * use it. The secret and the recovery codes are generated per call: nothing
 * here is a credential written down anywhere.
 *
 * @return array{0: User, 1: string, 2: string, 3: list<string>} user, password, TOTP secret, recovery codes
 */
function userWithTwoFactor(array $attributes = []): array
{
    [$user, $password] = userWithKnownPassword($attributes);

    $secret = app(Totp::class)->newSecret();
    $codes = app(TwoFactor::class)->enable($user, $secret, 0);

    return [$user->refresh(), $password, $secret, $codes];
}

/** The code an authenticator app shows `$steps` 30-second steps from now. */
function totpCode(string $secret, int $steps = 0): string
{
    $totp = app(Totp::class);

    return $totp->codeAt($secret, $totp->currentStep() + $steps);
}

/** Do the password step; leaves the account parked at the challenge. */
function passwordStep($test, User $user, string $password, bool $remember = false)
{
    return $test->post('/login', ['email' => $user->email, 'password' => $password, 'remember' => $remember]);
}

/** @return list<string> the names of the remember-me cookies a response sets */
function rememberCookies($response): array
{
    $names = array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());

    return array_values(array_filter($names, fn ($name) => str_starts_with($name, 'remember_web')));
}
