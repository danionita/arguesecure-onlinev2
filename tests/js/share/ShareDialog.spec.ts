import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import ShareDialog from '../../../resources/js/Components/Share/ShareDialog.vue';
import type {
    ShareCollaborator,
    SharePayload,
} from '../../../resources/js/Components/Share/types';
import { useToast } from '../../../resources/js/Components/UI/useToast';

/**
 * W119: ShareDialog is the only caller of the sharing endpoints. What matters
 * is that each child event becomes the right request with the right payload
 * (a role change that PATCHed the wrong collaborator, or a "transfer" that
 * posted the wrong user, is an access-control mistake), that the dialog
 * re-syncs from the server afterwards instead of trusting its own guess, and
 * that the server's refusal reaches the user. Authorization itself is
 * server-side (tests/Feature/Sharing); `canTransfer` only hides a control.
 */
const axios = {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
};

const collaborator: ShareCollaborator = {
    id: 11,
    user: { id: 5, name: 'Eve', email: 'eve@example.test' },
    role: 'viewer',
    invited_by: null,
    invited_at: null,
    accepted_at: null,
};

function sharePayload(over: Partial<SharePayload> = {}): SharePayload {
    return {
        owner: { id: 1, name: 'Owner', email: 'owner@example.test' },
        collaborators: [collaborator],
        is_public: false,
        share_link: null,
        share_token_created_at: null,
        ...over,
    };
}

// Stubs expose only what the dialog binds, so a test drives the dialog the
// way the real child would, by emitting.
const Modal = defineComponent({
    name: 'Modal',
    props: ['show', 'title'],
    emits: ['close'],
    setup(props, { slots }) {
        return () =>
            props.show ? h('div', { class: 'modal' }, slots.default?.()) : null;
    },
});
const stub = (name: string) =>
    defineComponent({
        name,
        props: [
            'owner',
            'collaborators',
            'busy',
            'modelValue',
            'shareLink',
            'status',
            'assessmentUuid',
        ],
        setup: () => () => h('div', { class: name }),
    });
const stubs = {
    Modal,
    CollaboratorList: stub('CollaboratorList'),
    AddCollaboratorForm: stub('AddCollaboratorForm'),
    PublicToggle: stub('PublicToggle'),
    TemplatePanel: stub('TemplatePanel'),
    ShareLinkPanel: stub('ShareLinkPanel'),
    TransferOwnershipPanel: stub('TransferOwnershipPanel'),
};

function respondToLoads(share: SharePayload = sharePayload()) {
    axios.get.mockImplementation(() => Promise.resolve({ data: share }));
}

async function render(
    props: {
        show?: boolean;
        canTransfer?: boolean;
        canPublishTemplate?: boolean;
    } = {},
) {
    const wrapper = mount(ShareDialog, {
        props: { show: true, assessmentUuid: 'a1', ...props },
        global: { stubs },
    });
    await flushPromises();
    return wrapper;
}

function toasts() {
    return useToast().toasts.map((t) => [t.tone, t.text]);
}

beforeEach(() => {
    Object.values(axios).forEach((fn) => fn.mockReset());
    (window as unknown as { axios: unknown }).axios = axios;
    (globalThis as unknown as { route: unknown }).route = (
        name: string,
        params: unknown,
    ) => `/${name}/${[params].flat().join('/')}`;
    useToast().toasts.splice(0);
    respondToLoads();
});

describe('ShareDialog loading', () => {
    it('loads the share state on open', async () => {
        await render();

        expect(axios.get).toHaveBeenCalledTimes(1);
        expect(axios.get).toHaveBeenCalledWith('/share.index/a1');
    });

    it('requests nothing while closed, and loads when it is opened', async () => {
        const wrapper = await render({ show: false });
        expect(axios.get).not.toHaveBeenCalled();

        await wrapper.setProps({ show: true });
        await flushPromises();

        expect(axios.get).toHaveBeenCalledTimes(1);
    });

    it('passes the loaded owner, collaborators and flags to its panels', async () => {
        respondToLoads(
            sharePayload({ is_public: true, share_link: 'https://x.test/s/t' }),
        );
        const wrapper = await render();

        const list = wrapper.findComponent({ name: 'CollaboratorList' });
        expect(list.props()).toMatchObject({
            owner: { id: 1, name: 'Owner', email: 'owner@example.test' },
            collaborators: [collaborator],
            busy: false,
        });
        expect(
            wrapper.findComponent({ name: 'PublicToggle' }).props('modelValue'),
        ).toBe(true);
        expect(
            wrapper
                .findComponent({ name: 'ShareLinkPanel' })
                .props('shareLink'),
        ).toBe('https://x.test/s/t');
    });

    it('closes with an error toast when sharing settings cannot be loaded', async () => {
        axios.get.mockRejectedValue(new Error('403'));
        const wrapper = await render();

        expect(toasts()).toEqual([
            ['danger', 'Could not load sharing settings.'],
        ]);
        expect(wrapper.emitted('close')).toHaveLength(1);
        expect(wrapper.find('.CollaboratorList').exists()).toBe(false);
    });

    it('re-emits the modal close', async () => {
        const wrapper = await render();

        wrapper.findComponent(Modal).vm.$emit('close');

        expect(wrapper.emitted('close')).toHaveLength(1);
    });
});

describe('ShareDialog ownership transfer control', () => {
    it('is hidden unless the caller may transfer', async () => {
        const wrapper = await render();

        expect(wrapper.find('.TransferOwnershipPanel').exists()).toBe(false);
    });

    it('is shown to someone who may transfer', async () => {
        const wrapper = await render({ canTransfer: true });

        expect(wrapper.find('.TransferOwnershipPanel').exists()).toBe(true);
    });
});

describe('ShareDialog template control (W141)', () => {
    it('is shown to an admin who may publish', async () => {
        const wrapper = await render({ canPublishTemplate: true });

        expect(wrapper.find('.TemplatePanel').exists()).toBe(true);
    });

    it('is hidden from everyone else', async () => {
        const wrapper = await render();

        expect(wrapper.find('.TemplatePanel').exists()).toBe(false);
    });
});

describe('ShareDialog mutations', () => {
    async function drive(
        component: string,
        event: string,
        args: unknown[],
        props: { canTransfer?: boolean; canPublishTemplate?: boolean } = {},
    ) {
        const wrapper = await render(props);
        axios.get.mockClear();
        wrapper.findComponent({ name: component }).vm.$emit(event, ...args);
        await flushPromises();
        return wrapper;
    }

    it.each([
        [
            'adding a collaborator posts the user id and role',
            'AddCollaboratorForm',
            'add',
            [5, 'editor'],
            'post',
            '/share.collaborators.store/a1',
            { user_id: 5, role: 'editor' },
        ],
        [
            'changing a role patches that collaborator only',
            'CollaboratorList',
            'update-role',
            [collaborator, 'manager'],
            'patch',
            '/share.collaborators.update/a1/11',
            { role: 'manager' },
        ],
        [
            'revoking deletes that collaborator',
            'CollaboratorList',
            'revoke',
            [collaborator],
            'delete',
            '/share.collaborators.destroy/a1/11',
            undefined,
        ],
        [
            'toggling public patches is_public with the new value',
            'PublicToggle',
            'update:modelValue',
            [true],
            'patch',
            '/share.public.update/a1',
            { is_public: true },
        ],
        [
            'generating a link posts to the token route',
            'ShareLinkPanel',
            'generate',
            [],
            'post',
            '/share.token.store/a1',
            undefined,
        ],
        [
            'revoking the link deletes the token',
            'ShareLinkPanel',
            'revoke',
            [],
            'delete',
            '/share.token.destroy/a1',
            undefined,
        ],
        [
            'publishing posts to the publish route, with no body',
            'TemplatePanel',
            'publish',
            [],
            'post',
            '/assessments.template.publish/a1',
            undefined,
        ],
        [
            'transferring posts the chosen user id',
            'TransferOwnershipPanel',
            'transfer',
            [5],
            'post',
            '/share.transfer/a1',
            { user_id: 5 },
        ],
    ] as const)(
        '%s',
        async (_name, component, event, args, verb, expectedUrl, body) => {
            await drive(component, event, [...args], {
                canTransfer: true,
                canPublishTemplate: true,
            });

            const calls = axios[verb].mock.calls;
            expect(calls).toHaveLength(1);
            expect(calls[0][0]).toBe(expectedUrl);
            expect(calls[0][1]).toEqual(body);
            // The dialog re-reads the server rather than trusting itself.
            expect(axios.get).toHaveBeenCalledTimes(1);
        },
    );

    it('says what happened after publishing, and that the assessment is unchanged', async () => {
        await drive('TemplatePanel', 'publish', [], {
            canPublishTemplate: true,
        });

        expect(toasts()).toEqual([
            [
                'success',
                'Published as a template. This assessment is unchanged.',
            ],
        ]);
    });

    it('confirms an ownership transfer only after the server accepted it', async () => {
        await drive('TransferOwnershipPanel', 'transfer', [5], {
            canTransfer: true,
        });

        expect(toasts()).toEqual([['success', 'Ownership transferred.']]);
    });

    it('shows the server message when a change is refused, and does not re-sync or claim success', async () => {
        axios.patch.mockRejectedValue({
            response: { data: { message: 'You cannot demote the owner.' } },
        });

        await drive('CollaboratorList', 'update-role', [
            collaborator,
            'viewer',
        ]);

        expect(toasts()).toEqual([['danger', 'You cannot demote the owner.']]);
        expect(axios.get).not.toHaveBeenCalled();
    });

    it('falls back to a generic message when the failure carries none', async () => {
        axios.post.mockRejectedValue(new Error('network'));

        await drive('TransferOwnershipPanel', 'transfer', [5], {
            canTransfer: true,
        });

        expect(toasts()).toEqual([
            ['danger', 'That change could not be saved.'],
        ]);
    });

    it('does not announce a publish that failed', async () => {
        axios.post.mockRejectedValue(new Error('403'));

        await drive('TemplatePanel', 'publish', [], {
            canPublishTemplate: true,
        });

        expect(toasts().map(([tone]) => tone)).toEqual(['danger']);
    });

    it('keeps its panels busy while a mutation is in flight', async () => {
        const wrapper = await render();
        let finish: (v: unknown) => void = () => {};
        axios.patch.mockReturnValue(new Promise((r) => (finish = r)));

        wrapper
            .findComponent({ name: 'PublicToggle' })
            .vm.$emit('update:modelValue', true);
        await flushPromises();
        expect(
            wrapper.findComponent({ name: 'PublicToggle' }).props('busy'),
        ).toBe(true);

        finish({});
        await flushPromises();
        expect(
            wrapper.findComponent({ name: 'PublicToggle' }).props('busy'),
        ).toBe(false);
    });
});
