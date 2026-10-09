/**
 * The Assignees tab (W46, item 16; PLAN-2.2.md D13).
 *
 * Two things are worth pinning here, and neither is that a list renders.
 *
 * The first is that this tab never sends `share_as_viewer`. The server has a
 * path that shares an assessment with someone at `viewer` as part of an
 * assignment, and it exists for the Share dialog's benefit, not this
 * dropdown's — a picker that quietly grants read access to a whole assessment
 * is the silent disclosure D13 exists to prevent. That the field is absent
 * from the request is a security assertion, not a formatting one.
 *
 * The second is that a viewer is never asked for the candidate list. That
 * endpoint needs `update`, so fetching it as a viewer is a guaranteed 403 on
 * every node they open.
 */
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AssigneesTab from '../../../resources/js/Components/NodeModal/tabs/AssigneesTab.vue';

vi.stubGlobal(
    'route',
    (name: string, params: unknown) => `/${name}/${JSON.stringify(params)}`,
);

const get = vi.fn();
const post = vi.fn();
const del = vi.fn();

vi.stubGlobal('window', globalThis.window);

function store() {
    return {
        assessment: { uuid: 'assessment-1' },
        abilities: { update: true, manage: false, comment: true },
    } as never;
}

function mountTab() {
    return mount(AssigneesTab, {
        props: { store: store(), uuid: 'node-1' },
        global: { stubs: { teleport: true } },
    });
}

beforeEach(() => {
    get.mockReset();
    post.mockReset();
    del.mockReset();

    (window as unknown as { axios: unknown }).axios = {
        get,
        post,
        delete: del,
    };
});

function assigneesResponse(
    assignees: { id: number; name: string }[],
    canAssign = true,
    canShare = false,
) {
    return { data: { assignees, canAssign, canShare } };
}

describe('AssigneesTab', () => {
    it('lists who is assigned', async () => {
        get.mockResolvedValueOnce(
            assigneesResponse([{ id: 1, name: 'Dana' }]),
        ).mockResolvedValueOnce({ data: { users: [{ id: 1, name: 'Dana' }] } });

        const wrapper = mountTab();
        await flushPromises();

        expect(wrapper.text()).toContain('Dana');
    });

    it('never sends share_as_viewer', async () => {
        get.mockResolvedValueOnce(assigneesResponse([])).mockResolvedValueOnce({
            data: { users: [{ id: 7, name: 'Sam' }] },
        });
        post.mockResolvedValue(assigneesResponse([{ id: 7, name: 'Sam' }]));

        const wrapper = mountTab();
        await flushPromises();

        await wrapper.find('select').setValue('7');
        await wrapper.find('button').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledTimes(1);
        const body = post.mock.calls[0][1];
        expect(body).toEqual({ user_id: 7 });
        expect(body).not.toHaveProperty('share_as_viewer');
    });

    it('does not ask for the candidate list when the user cannot assign', async () => {
        get.mockResolvedValueOnce(
            assigneesResponse([{ id: 1, name: 'Dana' }], false, false),
        );

        const wrapper = mountTab();
        await flushPromises();

        expect(get).toHaveBeenCalledTimes(1);
        expect(wrapper.find('select').exists()).toBe(false);
    });

    it('offers only people who are not already assigned', async () => {
        get.mockResolvedValueOnce(
            assigneesResponse([{ id: 1, name: 'Dana' }]),
        ).mockResolvedValueOnce({
            data: {
                users: [
                    { id: 1, name: 'Dana' },
                    { id: 2, name: 'Sam' },
                ],
            },
        });

        const wrapper = mountTab();
        await flushPromises();

        const labels = wrapper
            .findAll('option')
            .map((option) => option.text())
            .filter((text) => text !== 'Choose a collaborator');

        expect(labels).toEqual(['Sam']);
    });

    it('says where to go when the person is not on the assessment', async () => {
        get.mockResolvedValueOnce(
            assigneesResponse([], true, true),
        ).mockResolvedValueOnce({ data: { users: [] } });

        const wrapper = mountTab();
        await flushPromises();

        expect(wrapper.text()).toContain(
            'Only people who are already on this assessment',
        );
        expect(wrapper.text()).toContain('share it with them first');
    });

    it('tells someone who cannot share whom to ask', async () => {
        get.mockResolvedValueOnce(
            assigneesResponse([], true, false),
        ).mockResolvedValueOnce({ data: { users: [] } });

        const wrapper = mountTab();
        await flushPromises();

        expect(wrapper.text()).toContain('ask a manager or the owner');
    });
});
