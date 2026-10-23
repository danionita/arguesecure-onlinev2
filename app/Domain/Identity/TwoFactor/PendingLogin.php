<?php

namespace App\Domain\Identity\TwoFactor;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Carbon;

/**
 * The half-finished sign-in between a correct password and a correct code.
 *
 * It lives in the session as an account id and the *remember me* choice, and
 * nowhere else: the user is not authenticated while it exists, and it expires
 * after five minutes (W116 acceptance 4), after which the password step has
 * to be repeated.
 */
final class PendingLogin
{
    public const SESSION_KEY = 'two_factor.pending';

    public const TTL_SECONDS = 300;

    public static function begin(Session $session, int $userId, bool $remember): void
    {
        $session->put(self::SESSION_KEY, [
            'user_id' => $userId,
            'remember' => $remember,
            'expires_at' => Carbon::now()->getTimestamp() + self::TTL_SECONDS,
        ]);
    }

    /** @return array{user_id: int, remember: bool}|null */
    public static function get(Session $session): ?array
    {
        $pending = $session->get(self::SESSION_KEY);

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < Carbon::now()->getTimestamp()) {
            self::forget($session);

            return null;
        }

        return ['user_id' => (int) $pending['user_id'], 'remember' => (bool) $pending['remember']];
    }

    public static function forget(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }
}
