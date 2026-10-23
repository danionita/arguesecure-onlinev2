import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import { useLocks } from '../../../resources/js/composables/useLocks';
import type { RealtimeConnection } from '../../../resources/js/composables/useRealtime';

/**
 * W119: field locks are a display aid on the client and a real boundary on the
 * server (LockManager). What the client must get right is the bookkeeping:
 * whom to show as the holder, never letting a whisper claim a field this tab
 * holds, and stopping the 15 s heartbeat the moment it is no longer ours.
 */
const axios = { post: vi.fn(), patch: vi.fn(), delete: vi.fn() };
let whispers: unknown[][];
let whisperHandler: (payload: unknown) => void;
let unsubscribed: boolean;

function setup() {
    const realtime = {
        whisper: (...args: unknown[]) => whispers.push(args),
        on: (_event: string, fn: (payload: unknown) => void) => {
            whisperHandler = fn;
            return () => {
                unsubscribed = true;
            };
        },
    } as unknown as RealtimeConnection;
    const scope = effectScope();
    const locks = scope.run(() => useLocks(realtime, 'a1'))!;
    return { locks, scope };
}

const dana = { user_id: 7, name: 'Dana' };

beforeEach(() => {
    vi.useFakeTimers();
    whispers = [];
    unsubscribed = false;
    Object.values(axios).forEach((fn) => fn.mockReset());
    (window as unknown as { axios: unknown }).axios = axios;
    (globalThis as unknown as { route: unknown }).route = (
        name: string,
        params: unknown[],
    ) => `/${name}/${params.join('/')}`;
});

afterEach(() => vi.useRealTimers());

describe('useLocks', () => {
    it('acquire() marks the field mine, whispers it, and refreshes every 15 s', async () => {
        axios.post.mockResolvedValue({ data: { holder: dana } });
        axios.patch.mockResolvedValue({});
        const { locks } = setup();

        expect(await locks.acquire('n1', 'title')).toBe(true);
        expect(locks.mine.has('n1:title')).toBe(true);
        expect(locks.lockedBy('n1', 'title')).toBeNull();
        expect(whispers[0]).toEqual([
            'lock',
            {
                action: 'acquired',
                nodeUuid: 'n1',
                field: 'title',
                holder: dana,
            },
        ]);

        vi.advanceTimersByTime(14999);
        expect(axios.patch).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1);
        expect(axios.patch).toHaveBeenCalledWith(
            '/assessments.locks.update/a1/n1/title',
        );
    });

    it('a 409 shows the holder and does not mark the field mine', async () => {
        axios.post.mockRejectedValue({
            response: { status: 409, data: { holder: dana } },
        });
        const { locks } = setup();

        expect(await locks.acquire('n1', 'title')).toBe(false);
        expect(locks.lockedBy('n1', 'title')).toEqual(dana);
        expect(locks.mine.has('n1:title')).toBe(false);
        expect(whispers).toEqual([]);
    });

    it('any other failure refuses the lock without inventing a holder', async () => {
        axios.post.mockRejectedValue({ response: { status: 500 } });
        const { locks } = setup();

        expect(await locks.acquire('n1', 'title')).toBe(false);
        expect(locks.lockedBy('n1', 'title')).toBeNull();
    });

    it('a failed heartbeat gives the field up and stops refreshing', async () => {
        axios.post.mockResolvedValue({ data: { holder: dana } });
        axios.patch.mockRejectedValue({ response: { status: 409 } });
        const { locks } = setup();
        await locks.acquire('n1', 'title');

        vi.advanceTimersByTime(15000);
        await vi.advanceTimersByTimeAsync(0);
        expect(locks.mine.has('n1:title')).toBe(false);

        vi.advanceTimersByTime(60000);
        expect(axios.patch).toHaveBeenCalledTimes(1);
    });

    it('release() stops the heartbeat and whispers even if the call fails', async () => {
        axios.post.mockResolvedValue({ data: { holder: dana } });
        axios.delete.mockRejectedValue(new Error('navigating away'));
        axios.patch.mockResolvedValue({});
        const { locks } = setup();
        await locks.acquire('n1', 'title');

        await locks.release('n1', 'title');

        expect(locks.mine.has('n1:title')).toBe(false);
        expect(whispers[1]).toEqual([
            'lock',
            {
                action: 'released',
                nodeUuid: 'n1',
                field: 'title',
                holder: null,
            },
        ]);
        vi.advanceTimersByTime(60000);
        expect(axios.patch).not.toHaveBeenCalled();
    });

    it('whispers update the badge for fields that are not mine, and never override one that is', async () => {
        axios.post.mockResolvedValue({ data: { holder: dana } });
        const { locks } = setup();

        whisperHandler({
            action: 'acquired',
            nodeUuid: 'n2',
            field: 'title',
            holder: dana,
        });
        expect(locks.lockedBy('n2', 'title')).toEqual(dana);

        whisperHandler({
            action: 'released',
            nodeUuid: 'n2',
            field: 'title',
            holder: null,
        });
        expect(locks.lockedBy('n2', 'title')).toBeNull();

        await locks.acquire('n1', 'title');
        whisperHandler({
            action: 'acquired',
            nodeUuid: 'n1',
            field: 'title',
            holder: { user_id: 9, name: 'Eve' },
        });
        expect(locks.mine.has('n1:title')).toBe(true);
        expect(locks.lockedBy('n1', 'title')).toBeNull();

        // The forged claim must not be remembered either: once the field is
        // released it would otherwise surface as a phantom holder.
        axios.delete.mockResolvedValue({});
        await locks.release('n1', 'title');
        expect(locks.lockedBy('n1', 'title')).toBeNull();
    });

    it('disposing the scope stops every heartbeat and the whisper listener', async () => {
        axios.post.mockResolvedValue({ data: { holder: dana } });
        axios.patch.mockResolvedValue({});
        const { locks, scope } = setup();
        await locks.acquire('n1', 'title');

        scope.stop();

        expect(unsubscribed).toBe(true);
        vi.advanceTimersByTime(60000);
        expect(axios.patch).not.toHaveBeenCalled();
    });
});
