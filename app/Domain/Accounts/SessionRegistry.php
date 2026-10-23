<?php

namespace App\Domain\Accounts;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lists and revokes a user's active sessions for the profile page's
 * session/device list (ui.md §10). Reads the `sessions` table directly
 * via the query builder rather than an Eloquent model, since
 * `app/Models/**` is outside this package's file ownership and a session
 * row is infrastructure, not a domain entity.
 *
 * Every revocation path (password and email change, admin reset,
 * deactivation and deletion, the profile page's revoke button) deletes
 * rows here, so it revokes nothing unless the sessions live in this table.
 * Until 2.7 the shipped environment chose the redis driver, under which
 * the table stayed empty and every one of those paths was a no-op; D71
 * fixed the driver to `database` in config/session.php, and
 * tests/Feature/Auth/SessionRevocationTest.php drives each path through
 * real sign-ins on that driver.
 */
class SessionRegistry
{
    /**
     * @return list<array{id: string, ip_address: ?string, user_agent: ?string, last_active_at: CarbonInterface, is_current: bool}>
     */
    public static function forUser(int $userId, string $currentSessionId): array
    {
        return DB::table('sessions')
            ->where('user_id', $userId)
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'ip_address' => $row->ip_address,
                'user_agent' => $row->user_agent,
                'last_active_at' => Carbon::createFromTimestamp($row->last_activity),
                'is_current' => $row->id === $currentSessionId,
            ])
            ->all();
    }

    /**
     * Revoke a single session belonging to the given user. Scoped by
     * user_id so a user cannot revoke another user's session by guessing
     * a session id.
     */
    public static function revoke(int $userId, string $sessionId): bool
    {
        $revoked = DB::table('sessions')
            ->where('user_id', $userId)
            ->where('id', $sessionId)
            ->delete() > 0;

        if ($revoked) {
            self::invalidateRememberCookies($userId);
        }

        return $revoked;
    }

    /**
     * Revoke every session for a user except the given one. Used when
     * changing password/email (security.md §4: "Both invalidate other
     * sessions.") and on deactivation.
     */
    public static function revokeAllExcept(int $userId, ?string $keepSessionId = null): void
    {
        DB::table('sessions')
            ->where('user_id', $userId)
            ->when($keepSessionId !== null, fn ($query) => $query->where('id', '!=', $keepSessionId))
            ->delete();

        self::invalidateRememberCookies($userId);
    }

    /**
     * A "remember me" cookie signs its device straight back in with a fresh
     * session row, which would undo the revocation. The cookie is checked
     * against the user's remember token (and, by the guard, their password
     * hash), so replacing the token ends every remembered device; the
     * caller's own session row is untouched and keeps working.
     */
    private static function invalidateRememberCookies(int $userId): void
    {
        DB::table('users')->where('id', $userId)->update(['remember_token' => Str::random(60)]);
    }
}
