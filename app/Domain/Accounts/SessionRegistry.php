<?php

namespace App\Domain\Accounts;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lists and revokes a user's active sessions for the profile page's
 * session/device list (ui.md §10). Reads the `sessions` table directly
 * via the query builder rather than an Eloquent model, since
 * `app/Models/**` is outside this package's file ownership and a session
 * row is infrastructure, not a domain entity.
 *
 * Only meaningful when `SESSION_DRIVER=database` (the project's current
 * default — see .env). If the deployment later switches to the redis
 * session driver from security.md §5, this table stops being populated
 * and the list will simply show nothing; that migration is out of scope
 * for W8.
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
        return DB::table('sessions')
            ->where('user_id', $userId)
            ->where('id', $sessionId)
            ->delete() > 0;
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
            ->when($keepSessionId, fn ($query) => $query->where('id', '!=', $keepSessionId))
            ->delete();
    }
}
