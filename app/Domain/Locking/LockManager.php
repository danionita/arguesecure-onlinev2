<?php

namespace App\Domain\Locking;

use Illuminate\Support\Facades\Redis;

/**
 * Field-level pessimistic locking, entirely in Redis, no table, no polling
 * (PLAN.md Phase 6 item 3, calculation-engine.md's sibling doc on the
 * legacy `HeartbeatController` this replaces). Key
 * `lock:{assessment}:{node_uuid}:{field}`, acquired with `SET NX PX 45000`.
 * **The TTL is the sweep** — there is deliberately no cleanup job, no
 * cron, no table row to go stale. A holder who never comes back (closed
 * tab, dead connection) simply stops being the holder after
 * {@see self::TTL_MS} milliseconds, which is also what "released ... on
 * presence-leave" reduces to in practice: there is no separate leave-event
 * handler because the TTL already guarantees release within that window,
 * and a table-backed sweep is exactly the design this replaces.
 *
 * Every mutation here compares the *caller's own, server-resolved* user id
 * against whatever is currently stored before acting — a refresh or a
 * release can never be forged by naming someone else's lock, because
 * nothing here ever takes "whose lock is this" as an argument from the
 * caller. That is what makes the compare-and-extend/compare-and-delete
 * Lua scripts the actual authorization boundary for a refresh, not merely
 * an optimization: LockController resolves the acting user from the
 * authenticated request, never from anything the client sends about lock
 * ownership.
 */
final class LockManager
{
    /**
     * ui.md §6 / PLAN.md Phase 6 item 3: acquired for 45s, refreshed by a
     * client heartbeat every 15s — three refreshes' margin before a lock
     * a user is actively holding could expire out from under them.
     */
    public const TTL_MS = 45000;

    private const COMPARE_AND_EXTEND = <<<'LUA'
        local raw = redis.call('GET', KEYS[1])
        if raw == false then
            return 0
        end
        local data = cjson.decode(raw)
        if tostring(data.user_id) == ARGV[1] then
            redis.call('PEXPIRE', KEYS[1], ARGV[2])
            return 1
        end
        return 0
        LUA;

    private const COMPARE_AND_DELETE = <<<'LUA'
        local raw = redis.call('GET', KEYS[1])
        if raw == false then
            return 0
        end
        local data = cjson.decode(raw)
        if tostring(data.user_id) == ARGV[1] then
            redis.call('DEL', KEYS[1])
            return 1
        end
        return 0
        LUA;

    public static function key(string $assessmentUuid, string $nodeUuid, string $field): string
    {
        return "lock:{$assessmentUuid}:{$nodeUuid}:{$field}";
    }

    /**
     * `SET NX PX` — atomic on its own, no Lua needed: either nobody holds
     * the key and this call creates it, or somebody does and this call is a
     * no-op. Returns the holder that ends up owning it either way, so the
     * caller can always tell the truth to the client ("you got it" vs
     * "Dana has it").
     *
     * @return array{user_id: int, name: string} the resolved holder (this
     *                                           caller if it won the race, otherwise whoever already held it)
     */
    public function acquire(string $assessmentUuid, string $nodeUuid, string $field, int $userId, string $userName): array
    {
        $key = self::key($assessmentUuid, $nodeUuid, $field);
        $value = ['user_id' => $userId, 'name' => $userName];
        $encoded = json_encode($value, JSON_THROW_ON_ERROR);

        // Deliberately Redis::connection()->command('set', [...]) rather
        // than Redis::set(...): the facade's typed `set()` resolves to
        // Illuminate\Redis\Connections\PhpRedisConnection's own
        // re-signatured override — (key, value, expireResolution,
        // expireTTL, flag) — which cannot express NX and PX together and
        // whose static-analysis stubs disagree with it besides.
        // ->command() forwards straight to the phpredis extension's native
        // `set($key, $value, array $options)`, the documented way to
        // combine NX and PX in one atomic call.
        $acquired = Redis::connection()->command('set', [$key, $encoded, ['NX', 'PX' => self::TTL_MS]]);

        if ($acquired) {
            return $value;
        }

        return $this->currentHolder($assessmentUuid, $nodeUuid, $field) ?? $value;
    }

    /**
     * Compare-and-extend: only PEXPIREs the key if it is still this exact
     * user's lock. A stranger's forged "refresh my own lock" whisper (or
     * HTTP call) for a field they never held simply fails — see the class
     * docblock.
     */
    public function refresh(string $assessmentUuid, string $nodeUuid, string $field, int $userId): bool
    {
        $key = self::key($assessmentUuid, $nodeUuid, $field);

        $result = Redis::connection()->command('eval', [self::COMPARE_AND_EXTEND, [$key, (string) $userId, (string) self::TTL_MS], 1]);

        return (bool) $result;
    }

    /**
     * Compare-and-delete: releasing a lock you do not hold (already
     * expired, already reassigned, or simply never yours) is a safe no-op,
     * never someone else's release.
     */
    public function release(string $assessmentUuid, string $nodeUuid, string $field, int $userId): bool
    {
        $key = self::key($assessmentUuid, $nodeUuid, $field);

        $result = Redis::connection()->command('eval', [self::COMPARE_AND_DELETE, [$key, (string) $userId], 1]);

        return (bool) $result;
    }

    /** @return array{user_id: int, name: string}|null */
    public function currentHolder(string $assessmentUuid, string $nodeUuid, string $field): ?array
    {
        $raw = Redis::get(self::key($assessmentUuid, $nodeUuid, $field));

        if ($raw === null || $raw === false) {
            return null;
        }

        /** @var array{user_id: int, name: string} $decoded */
        $decoded = json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
