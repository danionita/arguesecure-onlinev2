/**
 * The notification panel (W47, item 15).
 *
 * What is worth pinning: the feed is fetched on **open**, never on mount. This
 * component lives in the authenticated layout, so a fetch on mount would pull
 * the feed on every page in the application for a list most visits never open
 * — and the badge already comes from a shared prop, so nothing on screen is
 * waiting for it.
 *
 * Then: the panel marks read on open (that is what "the badge counts unread"
 * means in practice), and it does not send that request when there was nothing
 * unread to mark.
 */
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import NotificationPanel from '../../../resources/js/Components/Nav/NotificationPanel.vue';

vi.stubGlobal(
    'route',
    (name: string, params?: unknown) =>
        `/${name}${params === undefined ? '' : `/${String(params)}`}`,
);

const get = vi.fn();
const patch = vi.fn();
const visit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: {
        visit: (...args: unknown[]) => visit(...args),
    },
}));

function row(overrides: Record<string, unknown> = {}) {
    return {
        id: 'n1',
        kind: 'mention',
        read: false,
        created_at: '2026-08-22T10:00:00.000Z',
        assessment_uuid: 'a-1',
        assessment_title: 'Payment platform',
        node_uuid: 'n-1',
        node_title: 'Cardholder data breach',
        author: 'Sam Author',
        excerpt: 'Please review @Dana',
        ...overrides,
    };
}

function mountPanel(show = false) {
    // `Modal` teleports to body, which `wrapper.text()` cannot see. Stubbing
    // the teleport renders it inline instead — the panel's own markup is
    // unchanged either way, and the teleport is W10's concern, not this
    // component's.
    return mount(NotificationPanel, {
        props: { show },
        global: { stubs: { teleport: true } },
    });
}

beforeEach(() => {
    get.mockReset();
    patch.mockReset();
    visit.mockReset();

    (window as unknown as { axios: unknown }).axios = { get, patch };
});

describe('NotificationPanel', () => {
    it('fetches nothing until it is opened', async () => {
        mountPanel(false);
        await flushPromises();

        expect(get).not.toHaveBeenCalled();
    });

    it('loads the feed and marks it read on open', async () => {
        get.mockResolvedValue({
            data: { notifications: [row()], unread: 1 },
        });
        patch.mockResolvedValue({ data: { unread: 0 } });

        const wrapper = mountPanel(false);
        await wrapper.setProps({ show: true });
        await flushPromises();

        expect(get).toHaveBeenCalledTimes(1);
        expect(patch).toHaveBeenCalledTimes(1);
        expect(wrapper.emitted('read')).toHaveLength(1);
    });

    it('does not mark read when nothing was unread', async () => {
        get.mockResolvedValue({
            data: { notifications: [row({ read: true })], unread: 0 },
        });

        const wrapper = mountPanel(false);
        await wrapper.setProps({ show: true });
        await flushPromises();

        expect(patch).not.toHaveBeenCalled();
        expect(wrapper.emitted('read')).toBeUndefined();
    });

    it('says who mentioned you, and on what', async () => {
        get.mockResolvedValue({
            data: { notifications: [row()], unread: 0 },
        });

        const wrapper = mountPanel(false);
        await wrapper.setProps({ show: true });
        await flushPromises();

        expect(wrapper.text()).toContain(
            'Sam Author mentioned you on Cardholder data breach',
        );
        expect(wrapper.text()).toContain('Payment platform');
    });

    it('renders an assignment differently from a mention', async () => {
        get.mockResolvedValue({
            data: {
                notifications: [
                    row({
                        kind: 'assignment',
                        assigned_by: 'Priya Owner',
                        author: undefined,
                        excerpt: undefined,
                    }),
                ],
                unread: 0,
            },
        });

        const wrapper = mountPanel(false);
        await wrapper.setProps({ show: true });
        await flushPromises();

        expect(wrapper.text()).toContain(
            'Priya Owner assigned you Cardholder data breach',
        );
    });

    /*
     * A node deleted since the notification was sent carries a null uuid.
     * Rendering it as a link would send the reader to a 404; rendering the row
     * without one at least tells them what happened.
     */
    it('renders a row with no node as text rather than a broken link', async () => {
        get.mockResolvedValue({
            data: {
                notifications: [row({ node_uuid: null })],
                unread: 0,
            },
        });

        const wrapper = mountPanel(false);
        await wrapper.setProps({ show: true });
        await flushPromises();

        expect(wrapper.find('li button').exists()).toBe(false);
        expect(wrapper.text()).toContain('Cardholder data breach');
    });

    it('navigates to the node’s deep link', async () => {
        get.mockResolvedValue({
            data: { notifications: [row()], unread: 0 },
        });

        const wrapper = mountPanel(false);
        await wrapper.setProps({ show: true });
        await flushPromises();

        await wrapper.find('li button').trigger('click');

        expect(visit).toHaveBeenCalledWith(
            expect.stringContaining('?node=n-1'),
        );
        expect(wrapper.emitted('close')).toHaveLength(1);
    });
});
