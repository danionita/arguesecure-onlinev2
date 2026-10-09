/**
 * Minimal Pusher-protocol client for Laravel Reverb (PLAN.md W16,
 * docs/specs/security.md §8, docs/specs/deployment.md §2.1).
 *
 * Deliberately hand-rolled rather than `laravel-echo`/`pusher-js`: this
 * package's file ownership does not include `package.json` (see the W16
 * session brief — "stop and report" rather than add a dependency outside
 * ownership), and Reverb speaks the same wire protocol those libraries
 * wrap, so a small subscriber is enough for what this app needs: one
 * presence channel per assessment, server-broadcast app events, and
 * outgoing "client events" (Pusher's term for what PLAN.md calls a
 * "whisper" — see useLocks.ts for why those are never used for anything
 * that requires authorization).
 *
 * The client connects through the app's own origin — `/app/{key}` — never
 * directly at REVERB_HOST:REVERB_PORT, because in production nginx proxies
 * that path to the internal-only `reverb` container (deployment.md §2.1);
 * in local dev without the proxy, the REVERB_CLIENT_* overrides point at
 * the standalone `reverb:start` process directly, which is also fine.
 *
 * W27 defect 1: the credentials arrive as a `ReverbConfig` argument, read
 * by the caller from the `reverb` shared Inertia prop
 * (HandleInertiaRequests::reverb()). They used to come from
 * `import.meta.env.VITE_REVERB_APP_KEY`, which Vite substitutes at image
 * **build** time while `docker/entrypoint.sh` generates them at container
 * **start** time — so every deployed bundle carried an empty key and this
 * whole file was dead code in production. Taking the config as an argument
 * rather than reaching for `usePage()` keeps the composable free of an
 * Inertia dependency, which is what lets Vitest mount it with no page.
 */
import { onScopeDispose, reactive, ref, type InjectionKey } from 'vue';
// Relative, not '@/stores/assessmentStore': vitest.config.ts (outside this
// package's ownership) has no '@' alias — see assessmentStore.ts's own
// docblock for the same reasoning applied to its '../calc' import. Matching
// it keeps this file testable by Vitest without needing a change to that
// config file.
import type {
    AssessmentStore,
    AttackDefenceLink,
    CalculationResults,
    NodeRecord,
    RiskAttackLink,
} from '../stores/assessmentStore';

export interface PresenceMember {
    id: number;
    name: string;
}

type EventHandler = (data: unknown) => void;

/**
 * The `reverb` shared Inertia prop. `key` empty means "not configured" —
 * the connection degrades to a no-op, which is what component tests rely
 * on. A null `host`/`port`/`scheme` means "this page's own origin", the
 * only correct answer behind the nginx proxy and the reason one built
 * image works on any hostname, port and scheme.
 */
export interface ReverbConfig {
    key: string;
    host?: string | null;
    port?: number | string | null;
    scheme?: string | null;
}

const PROTOCOL_VERSION = 7;

export function reverbUrl(config: ReverbConfig): string {
    const scheme =
        (config.scheme ??
            (window.location.protocol === 'https:' ? 'https' : 'http')) ===
        'https'
            ? 'wss'
            : 'ws';
    const host = String(config.host ?? window.location.hostname);
    const port = String(config.port ?? window.location.port ?? '');
    const portSuffix =
        port === '' ||
        (scheme === 'wss' && port === '443') ||
        (scheme === 'ws' && port === '80')
            ? ''
            : `:${port}`;

    return `${scheme}://${host}${portSuffix}/app/${config.key}?protocol=${PROTOCOL_VERSION}&client=arguesecure&version=1.0`;
}

interface PusherFrame {
    event: string;
    data?: unknown;
    channel?: string;
}

/**
 * One connection, one presence channel. Assessment/Show.vue creates a
 * single instance and provides it alongside the assessment store and the
 * W15 editor view, for the same reason those are singletons (useEditorView
 * .ts's docblock): the toolbar, the tree and the presence UI all need to
 * observe the same socket rather than each opening their own.
 */
export function useRealtime(
    assessmentUuid: string,
    config: ReverbConfig | null | undefined,
) {
    const connected = ref(false);
    const members = reactive<Map<number, PresenceMember>>(new Map());
    const handlers = new Map<string, Set<EventHandler>>();

    let socket: WebSocket | null = null;
    let socketId: string | null = null;
    let subscribed = false;
    let closedByUs = false;
    let reconnectDelay = 1000;
    let reconnectTimer: ReturnType<typeof window.setTimeout> | null = null;

    const channelName = `presence-assessment.${assessmentUuid}`;

    function dispatch(eventName: string, data: unknown) {
        handlers.get(eventName)?.forEach((handler) => handler(data));
    }

    function parseData(raw: unknown): Record<string, unknown> {
        if (typeof raw === 'string') {
            try {
                return JSON.parse(raw) as Record<string, unknown>;
            } catch {
                return {};
            }
        }
        return (raw as Record<string, unknown>) ?? {};
    }

    async function subscribe() {
        if (!socketId || subscribed) return;

        // POST /broadcasting/auth (registered by Broadcast::routes() in
        // routes/web.php's W16 block). window.axios already carries the
        // session cookie and X-XSRF-TOKEN header for same-origin requests
        // (the store's autosave path relies on the same setup).
        const response = await window.axios.post<{
            auth: string;
            channel_data?: string;
        }>('/broadcasting/auth', {
            socket_id: socketId,
            channel_name: channelName,
        });

        socket?.send(
            JSON.stringify({
                event: 'pusher:subscribe',
                data: {
                    channel: channelName,
                    auth: response.data.auth,
                    channel_data: response.data.channel_data,
                },
            }),
        );
    }

    /**
     * Laravel's `dontBroadcastToCurrentUser()` reads this header to leave the
     * originating connection out of a broadcast (NodeCreated/NodeUpdated).
     * Without it every write echoed back to its author a second later and
     * overwrote what they had typed since — RELEASE-2.5.0.md's item 22b, and
     * the "Details tab goes blank after adding a node" report. It names *this
     * connection*, not the user, so the same person's other tabs still hear
     * their edits.
     */
    function setSocketHeader(id: string | null) {
        const headers = window.axios?.defaults?.headers?.common as
            Record<string, unknown> | undefined;
        if (!headers) return;
        if (id) headers['X-Socket-ID'] = id;
        else delete headers['X-Socket-ID'];
    }

    function handleFrame(frame: PusherFrame) {
        switch (frame.event) {
            case 'pusher:connection_established': {
                const data = parseData(frame.data);
                socketId = String(data.socket_id ?? '');
                setSocketHeader(socketId);
                void subscribe();
                break;
            }
            case 'pusher:ping':
                socket?.send(JSON.stringify({ event: 'pusher:pong' }));
                break;
            case 'pusher_internal:subscription_succeeded': {
                subscribed = true;
                connected.value = true;
                const data = parseData(frame.data);
                const presence = data.presence as
                    | {
                          ids?: (number | string)[];
                          hash?: Record<string, PresenceMember>;
                      }
                    | undefined;
                members.clear();
                if (presence?.hash) {
                    for (const [id, info] of Object.entries(presence.hash)) {
                        members.set(Number(id), {
                            id: Number(id),
                            name: info.name,
                        });
                    }
                }
                break;
            }
            case 'pusher_internal:member_added': {
                const data = parseData(frame.data);
                const info = data.user_info as PresenceMember | undefined;
                const id = Number(data.user_id);
                if (info) members.set(id, { id, name: info.name });
                break;
            }
            case 'pusher_internal:member_removed': {
                const data = parseData(frame.data);
                members.delete(Number(data.user_id));
                break;
            }
            case 'pusher:error':
                // Auth failure (e.g. an unrelated user) lands here — the
                // socket stays open but this channel never subscribes.
                // security.md §8: nothing further is ever received for it.
                break;
            default:
                if (
                    frame.event.startsWith('pusher') ||
                    frame.event.startsWith('client-')
                ) {
                    // Client (whisper) events echoed back to other members
                    // land here too, but this app never relies on them for
                    // anything authoritative — see useLocks.ts.
                    dispatch(frame.event, parseData(frame.data));
                } else {
                    dispatch(frame.event, parseData(frame.data));
                }
        }
    }

    function scheduleReconnect() {
        if (closedByUs || reconnectTimer) return;
        reconnectTimer = window.setTimeout(() => {
            reconnectTimer = null;
            connect();
        }, reconnectDelay);
        reconnectDelay = Math.min(reconnectDelay * 2, 15000);
    }

    function connect() {
        if (closedByUs) return;
        if (!config?.key) return; // no Reverb configured (e.g. component tests) — degrade to no-op

        socket = new WebSocket(reverbUrl(config));

        socket.addEventListener('message', (event) => {
            try {
                handleFrame(JSON.parse(event.data as string) as PusherFrame);
            } catch {
                // ignore malformed frames
            }
        });

        socket.addEventListener('open', () => {
            reconnectDelay = 1000;
        });

        socket.addEventListener('close', () => {
            connected.value = false;
            subscribed = false;
            socketId = null;
            setSocketHeader(null);
            members.clear();
            scheduleReconnect();
        });

        socket.addEventListener('error', () => {
            socket?.close();
        });
    }

    function on(eventName: string, handler: EventHandler): () => void {
        if (!handlers.has(eventName)) handlers.set(eventName, new Set());
        handlers.get(eventName)!.add(handler);
        return () => handlers.get(eventName)?.delete(handler);
    }

    /**
     * Sends a Pusher "client event" (PLAN.md's "whisper"). Reverb relays
     * this directly between subscribed clients — it never touches this
     * app's Laravel process, which is exactly why nothing that requires
     * authorization (a lock refresh, a save) is ever built on it. It is
     * used only for cheap, purely-advisory UI signals such as "which field
     * is Dana focused on right now".
     */
    function whisper(eventName: string, data: unknown) {
        if (!subscribed) return;
        socket?.send(
            JSON.stringify({
                event: `client-${eventName}`,
                channel: channelName,
                data,
            }),
        );
    }

    function disconnect() {
        closedByUs = true;
        setSocketHeader(null);
        if (reconnectTimer) window.clearTimeout(reconnectTimer);
        socket?.close();
        socket = null;
    }

    connect();
    onScopeDispose(disconnect);

    return {
        connected,
        members,
        on,
        whisper,
        disconnect,
        channelName,
    };
}

export type RealtimeConnection = ReturnType<typeof useRealtime>;

/** provide()/inject() key — one connection per assessment, shared the same way EDITOR_VIEW_KEY is (useEditorView.ts). */
export const REALTIME_KEY: InjectionKey<RealtimeConnection> = Symbol(
    'arguesecure.realtime',
);

/**
 * Applies W16's granular broadcast events directly to the assessment
 * store (ui.md §6: "carry the changed data so the client patches its
 * store... do not repeat the legacy pattern of broadcasting an id and
 * re-fetching a rendered HTML fragment"). Kept in this file rather than a
 * separate composable module: this package's file ownership for the
 * frontend is exactly `useRealtime.ts`, `usePresence.ts` and
 * `useLocks.ts` — no fourth composable file — and this function is a
 * consumer of `useRealtime`'s own event stream, not a new piece of state
 * of its own, so it belongs alongside the connection it patches from.
 *
 * Mutates the store's already-reactive `nodes`/`riskAttackLinks`/
 * `attackDefenceLinks`/`results` in place (outside this package's
 * ownership — assessmentStore.ts — but exposed as live references, which
 * is exactly what lets this function update them without a new store
 * method).
 */
export function applyRealtimeUpdatesToStore(
    realtime: RealtimeConnection,
    store: AssessmentStore,
    currentUserId: number | string | null | undefined,
    onRemoteChange?: (notice: RealtimeChangeNotice) => void,
): () => void {
    function isRemote(actorId: number | null): boolean {
        return (
            actorId !== null && String(actorId) !== String(currentUserId ?? '')
        );
    }

    function notify(
        actorId: number | null,
        actorName: string | null,
        summary: string,
    ) {
        if (isRemote(actorId)) {
            onRemoteChange?.({ actorId, actorName, summary });
        }
    }

    const unsubscribers = [
        realtime.on('node.created', (raw) => {
            const { node, actor_id, actor_name } = raw as NodePayload;
            // A node this client already holds was created by this client:
            // the POST response put it there, and anything typed into it
            // since is newer than this payload. Replacing the record — as
            // this handler did until 2.5.1 — swapped the object an open modal
            // was bound to for one with no `tags` and the title as created,
            // which is how the Details tab blanked. The server no longer
            // sends the author their own echo (X-Socket-ID); this is the
            // second half, for any path that still does.
            if (store.nodes[node.uuid]) return;
            store.nodes[node.uuid] = { ...node, tags: node.tags ?? [] };
            store.recomputeLocally();
            notify(actor_id, actor_name, `added '${node.title || 'a node'}'`);
        }),

        realtime.on('node.updated', (raw) => {
            const { node, actor_id, actor_name } = raw as NodePayload;
            const existing = store.nodes[node.uuid];
            if (existing) {
                Object.assign(existing, node);
            } else {
                store.nodes[node.uuid] = { ...node, tags: node.tags ?? [] };
            }
            store.recomputeLocally();
            notify(actor_id, actor_name, `updated '${node.title || 'a node'}'`);
        }),

        realtime.on('node.deleted', (raw) => {
            const { node_uuid, actor_id, actor_name } =
                raw as NodeDeletedPayload;
            const title = store.nodes[node_uuid]?.title;
            delete store.nodes[node_uuid];
            for (let i = store.riskAttackLinks.length - 1; i >= 0; i--) {
                if (store.riskAttackLinks[i].attack_uuid === node_uuid) {
                    store.riskAttackLinks.splice(i, 1);
                }
            }
            for (let i = store.attackDefenceLinks.length - 1; i >= 0; i--) {
                if (
                    store.attackDefenceLinks[i].attack_uuid === node_uuid ||
                    store.attackDefenceLinks[i].defence_uuid === node_uuid
                ) {
                    store.attackDefenceLinks.splice(i, 1);
                }
            }
            store.recomputeLocally();
            notify(actor_id, actor_name, `deleted '${title || 'a node'}'`);
        }),

        realtime.on('link.changed', (raw) => {
            const { edge, action, link, actor_id, actor_name } =
                raw as LinkPayload;

            if (edge === 'risk_attack') {
                const l = link as RiskAttackLink;
                const index = store.riskAttackLinks.findIndex(
                    (existing) =>
                        existing.risk_uuid === l.risk_uuid &&
                        existing.attack_uuid === l.attack_uuid,
                );
                if (action === 'deleted') {
                    if (index !== -1) store.riskAttackLinks.splice(index, 1);
                } else if (index !== -1) {
                    Object.assign(store.riskAttackLinks[index], l);
                } else {
                    store.riskAttackLinks.push(l);
                }
            } else {
                const l = link as AttackDefenceLink;
                const index = store.attackDefenceLinks.findIndex(
                    (existing) =>
                        existing.attack_uuid === l.attack_uuid &&
                        existing.defence_uuid === l.defence_uuid,
                );
                if (action === 'deleted') {
                    if (index !== -1) store.attackDefenceLinks.splice(index, 1);
                } else if (index !== -1) {
                    Object.assign(store.attackDefenceLinks[index], l);
                } else {
                    store.attackDefenceLinks.push(l);
                }
            }

            store.recomputeLocally();
            notify(actor_id, actor_name, 'changed a link');
        }),

        // PLAN.md Phase 6 item 4's fifth named event: "totals recomputed".
        // Replaces store.results.value outright — the server-computed
        // figure always wins over the client's own optimistic recompute
        // (assessmentStore.ts's recomputeLocally() docblock says as much
        // about the PATCH response; this is the same rule applied to a
        // broadcast instead).
        realtime.on('totals.recomputed', (raw) => {
            const { results } = raw as { results: CalculationResults };
            store.results.value = results;
        }),
    ];

    return () => unsubscribers.forEach((off) => off());
}

export interface RealtimeChangeNotice {
    actorId: number | null;
    actorName: string | null;
    summary: string;
}

interface NodePayload {
    node: NodeRecord;
    actor_id: number | null;
    actor_name: string | null;
}

interface NodeDeletedPayload {
    node_uuid: string;
    actor_id: number | null;
    actor_name: string | null;
}

interface LinkPayload {
    edge: 'risk_attack' | 'attack_defence';
    action: 'created' | 'updated' | 'deleted';
    link: RiskAttackLink | AttackDefenceLink;
    actor_id: number | null;
    actor_name: string | null;
}
