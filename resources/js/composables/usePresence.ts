/**
 * Presentational projection of useRealtime.ts's presence-channel members
 * (ui.md §6: "presence avatars in the top bar, and a coloured ring on the
 * node each collaborator currently has focused"). Kept separate from
 * useRealtime so the low-level socket plumbing and the UI-facing shape
 * (PresenceUser[], focus-by-node) stay independently testable.
 */
import { computed, reactive, type ComputedRef, type InjectionKey } from 'vue';
// Relative, not '@/Components/UI/types' — see useRealtime.ts's docblock on
// the same '@' alias / Vitest testability point.
import type { PresenceUser } from '../Components/UI/types';
import type { RealtimeConnection } from './useRealtime';

// Deterministic-per-name colours, matching Avatar.vue's own "same name,
// same tone" reasoning, so a person's ring colour is stable across
// components without a colour ever being carried over the wire.
const RING_COLORS = [
    '#6366f1',
    '#0ea5e9',
    '#22c55e',
    '#f59e0b',
    '#ec4899',
    '#14b8a6',
];

function colorFor(name: string): string {
    let hash = 0;
    for (let i = 0; i < name.length; i++)
        hash = (hash * 31 + name.charCodeAt(i)) >>> 0;
    return RING_COLORS[hash % RING_COLORS.length];
}

export interface PresenceHandle {
    /** Everyone currently on the assessment (excluding the local user), for AvatarStack. */
    others: ComputedRef<PresenceUser[]>;
    /** Which occurrence-path each collaborator currently has focused, for the node-ring treatment. */
    focusByPath: Map<string, PresenceUser>;
    /** Broadcasts "I'm focused on this occurrence" — advisory only, see useRealtime.ts. */
    announceFocus(path: string | null): void;
}

export interface CurrentUser {
    id: number | string;
    name: string;
}

export function usePresence(
    realtime: RealtimeConnection,
    currentUser: CurrentUser | null | undefined,
): PresenceHandle {
    const focusByPath = reactive(new Map<string, PresenceUser>());
    const focusByUserId = new Map<number, string>();

    const others = computed<PresenceUser[]>(() =>
        Array.from(realtime.members.values())
            .filter((m) => String(m.id) !== String(currentUser?.id ?? ''))
            .map((m) => ({ name: m.name, color: colorFor(m.name) })),
    );

    realtime.on('client-focus', (raw) => {
        const data = raw as {
            userId?: number;
            userName?: string;
            path?: string | null;
        };
        if (data.userId === undefined || data.userName === undefined) return;
        const previous = focusByUserId.get(data.userId);
        if (previous) focusByPath.delete(previous);
        if (data.path) {
            focusByPath.set(data.path, {
                name: data.userName,
                color: colorFor(data.userName),
            });
            focusByUserId.set(data.userId, data.path);
        } else {
            focusByUserId.delete(data.userId);
        }
    });

    function announceFocus(path: string | null) {
        if (!currentUser) return;
        realtime.whisper('focus', {
            userId: currentUser.id,
            userName: currentUser.name,
            path,
        });
    }

    return { others, focusByPath, announceFocus };
}

export const PRESENCE_KEY: InjectionKey<PresenceHandle> = Symbol(
    'arguesecure.presence',
);
