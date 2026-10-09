/**
 * Field-level locking, client side (PLAN.md Phase 6 item 3, ui.md §6).
 *
 * Every state-changing call here goes through LockController over normal
 * authenticated HTTP — acquire on focus, a 15s heartbeat refresh, release
 * on blur/save/navigate — never through a Reverb "client event" (a
 * whisper): app/Domain/Locking/LockManager.php's compare-and-extend can
 * only be a real authorization boundary if the identity it compares
 * against comes from the server-resolved session, and a client event
 * never reaches this app's PHP at all (see useRealtime.ts's docblock).
 * "Locked by Dana" for a *third* viewer, however, is pure display — a
 * forged whisper claiming a lock could only ever mislead that badge, never
 * grant or deny the real HTTP-backed lock — so lock-acquired/lock-released
 * whispers are used purely to make the badge appear instantly for everyone
 * else instead of only after their own next refresh cycle.
 */
import type { InjectionKey } from 'vue';
import { onScopeDispose, reactive } from 'vue';
import type { RealtimeConnection } from './useRealtime';

export interface LockHolder {
    user_id: number;
    name: string;
}

function fieldKey(nodeUuid: string, field: string): string {
    return `${nodeUuid}:${field}`;
}

function isConflict(
    error: unknown,
): error is { response: { status: 409; data: { holder: LockHolder } } } {
    return (
        typeof error === 'object' &&
        error !== null &&
        'response' in error &&
        (error as { response?: { status?: number } }).response?.status === 409
    );
}

export interface LockWhisper {
    action: 'acquired' | 'released';
    nodeUuid: string;
    field: string;
    holder: LockHolder | null;
}

export function useLocks(realtime: RealtimeConnection, assessmentUuid: string) {
    /** Every field this browser tab currently holds. */
    const mine = reactive(new Set<string>());
    /** Every field known to be held by someone else (from a 409 or a whisper). */
    const holders = reactive(new Map<string, LockHolder>());
    const refreshTimers = new Map<
        string,
        ReturnType<typeof window.setInterval>
    >();

    function stopRefresh(key: string) {
        const timer = refreshTimers.get(key);
        if (timer !== undefined) {
            window.clearInterval(timer);
            refreshTimers.delete(key);
        }
    }

    function startRefresh(nodeUuid: string, field: string) {
        const key = fieldKey(nodeUuid, field);
        stopRefresh(key);
        // PLAN.md Phase 6 item 3: "refreshed ... every 15s" against a 45s
        // TTL — three refreshes' margin before an actively-held lock could
        // lapse under normal network jitter.
        refreshTimers.set(
            key,
            window.setInterval(() => {
                window.axios
                    .patch(
                        route('assessments.locks.update', [
                            assessmentUuid,
                            nodeUuid,
                            field,
                        ]),
                    )
                    .catch(() => {
                        // Someone else legitimately holds it now (compare-
                        // and-extend failed server-side) — stop pretending
                        // we do.
                        mine.delete(key);
                        stopRefresh(key);
                    });
            }, 15000),
        );
    }

    async function acquire(nodeUuid: string, field: string): Promise<boolean> {
        const key = fieldKey(nodeUuid, field);
        try {
            const response = await window.axios.post<{ holder: LockHolder }>(
                route('assessments.locks.store', [
                    assessmentUuid,
                    nodeUuid,
                    field,
                ]),
            );
            mine.add(key);
            holders.delete(key);
            startRefresh(nodeUuid, field);
            realtime.whisper('lock', {
                action: 'acquired',
                nodeUuid,
                field,
                holder: response.data.holder,
            } satisfies LockWhisper);
            return true;
        } catch (error) {
            if (isConflict(error)) {
                holders.set(key, error.response.data.holder);
            }
            return false;
        }
    }

    async function release(nodeUuid: string, field: string): Promise<void> {
        const key = fieldKey(nodeUuid, field);
        stopRefresh(key);
        mine.delete(key);
        try {
            await window.axios.delete(
                route('assessments.locks.destroy', [
                    assessmentUuid,
                    nodeUuid,
                    field,
                ]),
            );
        } catch {
            // Best-effort: navigating away or an already-expired lock both
            // land here. The TTL is the real backstop (LockManager's own
            // docblock), so nothing further is done about a failed release.
        }
        realtime.whisper('lock', {
            action: 'released',
            nodeUuid,
            field,
            holder: null,
        } satisfies LockWhisper);
    }

    /** Null if unlocked, or if this tab is the holder (nothing to show, the field is simply editable). */
    function lockedBy(nodeUuid: string, field: string): LockHolder | null {
        const key = fieldKey(nodeUuid, field);
        if (mine.has(key)) return null;
        return holders.get(key) ?? null;
    }

    const stopWhisperListener = realtime.on('client-lock', (raw) => {
        const { action, nodeUuid, field, holder } = raw as LockWhisper;
        const key = fieldKey(nodeUuid, field);
        if (mine.has(key)) return; // never let a whisper override our own held lock
        if (action === 'acquired' && holder) {
            holders.set(key, holder);
        } else {
            holders.delete(key);
        }
    });

    onScopeDispose(() => {
        for (const key of Array.from(refreshTimers.keys())) stopRefresh(key);
        stopWhisperListener();
    });

    return { mine, acquire, release, lockedBy };
}

export type LocksHandle = ReturnType<typeof useLocks>;

export const LOCKS_KEY: InjectionKey<LocksHandle> = Symbol('arguesecure.locks');
