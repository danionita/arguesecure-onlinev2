import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { useRealtime } from '../../../resources/js/composables/useRealtime';

/**
 * 2.5.1: the connection announces its socket id on every request as
 * `X-Socket-ID`, which is what lets NodeCreated/NodeUpdated leave the author's
 * own connection out of the broadcast (`dontBroadcastToCurrentUser()`). The
 * header has to go again when the connection does — a stale id would exclude
 * nobody, but one left behind after navigation is a lie about which socket
 * this page is.
 */
class FakeSocket {
    static last: FakeSocket | null = null;
    listeners = new Map<string, ((event: unknown) => void)[]>();
    sent: string[] = [];

    constructor() {
        FakeSocket.last = this;
    }

    addEventListener(name: string, fn: (event: unknown) => void) {
        this.listeners.set(name, [...(this.listeners.get(name) ?? []), fn]);
    }

    send(data: string) {
        this.sent.push(data);
    }

    close() {
        this.emit('close', {});
    }

    emit(name: string, event: unknown) {
        this.listeners.get(name)?.forEach((fn) => fn(event));
    }
}

let headers: Record<string, unknown>;

beforeEach(() => {
    headers = {};
    (window as unknown as { axios: unknown }).axios = {
        defaults: { headers: { common: headers } },
        post: vi.fn().mockResolvedValue({ data: { auth: 'sig' } }),
    };
    vi.stubGlobal('WebSocket', FakeSocket);
});

afterEach(() => {
    vi.unstubAllGlobals();
});

function connect() {
    let realtime!: ReturnType<typeof useRealtime>;
    const wrapper = mount(
        defineComponent({
            setup() {
                realtime = useRealtime('assessment-uuid', { key: 'app-key' });
                return () => h('div');
            },
        }),
    );

    return { wrapper, realtime };
}

describe('X-Socket-ID', () => {
    it('is set from the socket id the server assigns', () => {
        connect();

        FakeSocket.last!.emit('message', {
            data: JSON.stringify({
                event: 'pusher:connection_established',
                data: JSON.stringify({ socket_id: '123.456' }),
            }),
        });

        expect(headers['X-Socket-ID']).toBe('123.456');
    });

    it('is removed when the page lets go of the connection', () => {
        const { wrapper } = connect();
        FakeSocket.last!.emit('message', {
            data: JSON.stringify({
                event: 'pusher:connection_established',
                data: JSON.stringify({ socket_id: '123.456' }),
            }),
        });

        wrapper.unmount();

        expect(headers['X-Socket-ID']).toBeUndefined();
    });
});
