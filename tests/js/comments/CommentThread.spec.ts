import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CommentItem from '../../../resources/js/Components/Comments/CommentItem.vue';
import CommentThread from '../../../resources/js/Components/Comments/CommentThread.vue';
import type { Comment } from '../../../resources/js/Components/Comments/types';
import { useToast } from '../../../resources/js/Components/UI/useToast';

/**
 * W119: CommentThread wires CommentItem events to the right comment id and
 * request. The risk is a reply's edit/delete being sent for its parent (or
 * the reverse), a failed request vanishing silently, and a viewer who may not
 * comment being shown a posting form.
 */
function comment(id: number, over: Partial<Comment> = {}): Comment {
    return {
        id,
        body: `body ${id}`,
        author: { id: 1, name: 'Dana' },
        created_at: null,
        updated_at: null,
        resolved_at: null,
        resolved_by: null,
        can_edit: true,
        can_delete: true,
        replies: [],
        ...over,
    };
}

const axios = {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
};

const url = (name: string, params: unknown) =>
    `/${name}/${[params].flat().join('/')}`;

beforeEach(() => {
    Object.values(axios).forEach((fn) => fn.mockReset());
    (window as unknown as { axios: unknown }).axios = axios;
    (globalThis as unknown as { route: unknown }).route = url;
    useToast().toasts.splice(0);
});

async function render(comments: Comment[], canComment = true) {
    axios.get.mockResolvedValue({ data: { comments } });
    const wrapper = mount(CommentThread, {
        props: { assessmentUuid: 'a1', nodeUuid: 'n1', canComment },
    });
    await flushPromises();
    return wrapper;
}

function toastTexts(): string[] {
    return useToast().toasts.map((t) => t.text);
}

describe('CommentThread', () => {
    it('loads the thread for the given node on mount', async () => {
        await render([]);

        expect(axios.get).toHaveBeenCalledExactlyOnceWith(
            '/assessments.comments.index/a1/n1',
        );
    });

    it('says so when there are no comments', async () => {
        const wrapper = await render([]);

        expect(wrapper.text()).toContain('No comments yet');
        expect(wrapper.findAllComponents(CommentItem)).toHaveLength(0);
    });

    it('shows the load error instead of an empty state', async () => {
        axios.get.mockRejectedValue(new Error('boom'));
        const wrapper = mount(CommentThread, {
            props: { assessmentUuid: 'a1', nodeUuid: 'n1', canComment: true },
        });
        await flushPromises();

        expect(wrapper.text()).toContain('Could not load comments.');
        expect(wrapper.text()).not.toContain('No comments yet');
    });

    it('renders replies as replies, under their parent, and only they are marked isReply', async () => {
        const wrapper = await render([
            comment(1, { replies: [comment(2), comment(3)] }),
            comment(4),
        ]);

        const items = wrapper.findAllComponents(CommentItem);
        expect(items.map((i) => i.props('comment').id)).toEqual([1, 2, 3, 4]);
        expect(items.map((i) => i.props('isReply'))).toEqual([
            false,
            true,
            true,
            false,
        ]);
    });

    it('gives replies no reply handler: a reply cannot be replied to', async () => {
        const wrapper = await render([comment(1, { replies: [comment(2)] })]);

        const reply = wrapper.findAllComponents(CommentItem)[1];
        expect(reply.text()).not.toContain('Reply');
    });

    it('hides the new-comment form from someone who may not comment', async () => {
        const wrapper = await render([comment(1)], false);

        expect(wrapper.find('form').exists()).toBe(false);
    });

    it('keeps Post disabled until there is non-blank text', async () => {
        const wrapper = await render([]);
        const post = wrapper.find('button[type="submit"]');

        expect(post.attributes('disabled')).toBeDefined();
        await wrapper.find('form textarea').setValue('   ');
        expect(post.attributes('disabled')).toBeDefined();
        await wrapper.find('form textarea').setValue('hello');
        expect(post.attributes('disabled')).toBeUndefined();
    });

    it('posts the trimmed body as a top-level comment and clears the box', async () => {
        const wrapper = await render([]);
        axios.post.mockResolvedValue({ data: { comment: comment(9) } });

        await wrapper.find('form textarea').setValue('  hello  ');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledExactlyOnceWith(
            '/assessments.comments.store/a1/n1',
            { body: 'hello', parent_comment_id: null },
        );
        expect(
            (wrapper.find('form textarea').element as HTMLTextAreaElement)
                .value,
        ).toBe('');
    });

    it('does not send a blank comment', async () => {
        const wrapper = await render([]);

        await wrapper.find('form textarea').setValue('   ');
        await wrapper.find('form').trigger('submit');

        expect(axios.post).not.toHaveBeenCalled();
    });

    it('keeps the typed text and toasts when posting fails', async () => {
        const wrapper = await render([]);
        axios.post.mockRejectedValue(new Error('500'));

        await wrapper.find('form textarea').setValue('keep me');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(toastTexts()).toEqual(['Could not post the comment.']);
        expect(
            (wrapper.find('form textarea').element as HTMLTextAreaElement)
                .value,
        ).toBe('keep me');
    });

    it('sends a reply with its parent comment id', async () => {
        const wrapper = await render([comment(5)]);
        axios.post.mockResolvedValue({ data: { comment: comment(6) } });

        wrapper.findComponent(CommentItem).vm.$emit('reply', 'agreed');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledExactlyOnceWith(
            '/assessments.comments.store/a1/n1',
            { body: 'agreed', parent_comment_id: 5 },
        );
    });

    it('edits and deletes a reply by the reply id, not its parent id', async () => {
        const wrapper = await render([comment(5, { replies: [comment(6)] })]);
        axios.patch.mockResolvedValue({ data: { comment: comment(6) } });
        axios.delete.mockResolvedValue({});

        const reply = wrapper.findAllComponents(CommentItem)[1];
        reply.vm.$emit('edit', 'new text');
        reply.vm.$emit('delete');
        await flushPromises();

        expect(axios.patch).toHaveBeenCalledExactlyOnceWith(
            '/assessments.comments.update/a1/6',
            { body: 'new text' },
        );
        expect(axios.delete).toHaveBeenCalledExactlyOnceWith(
            '/assessments.comments.destroy/a1/6',
        );
    });

    it('resolves and unresolves the top-level comment it was emitted from', async () => {
        const wrapper = await render([comment(5), comment(8)]);
        axios.post.mockResolvedValue({ data: { comment: comment(8) } });
        axios.delete.mockResolvedValue({ data: { comment: comment(8) } });

        wrapper.findAllComponents(CommentItem)[1].vm.$emit('resolve');
        await flushPromises();
        expect(axios.post.mock.calls[0][0]).toBe(
            '/assessments.comments.resolve/a1/8',
        );

        wrapper.findAllComponents(CommentItem)[1].vm.$emit('unresolve');
        await flushPromises();
        expect(axios.delete.mock.calls[0][0]).toBe(
            '/assessments.comments.resolve/a1/8',
        );
    });

    it.each([
        ['reply', ['x'], 'post', 'Could not post the reply.'],
        ['edit', ['x'], 'patch', 'Could not save the edit.'],
        ['delete', [], 'delete', 'Could not delete the comment.'],
        ['resolve', [], 'post', 'Could not resolve the thread.'],
        ['unresolve', [], 'delete', 'Could not unresolve the thread.'],
    ] as const)(
        'a failed %s tells the user instead of failing silently',
        async (event, args, verb, message) => {
            const wrapper = await render([comment(5)]);
            axios[verb].mockRejectedValue(new Error('500'));

            wrapper.findComponent(CommentItem).vm.$emit(event, ...args);
            await flushPromises();

            expect(toastTexts()).toEqual([message]);
        },
    );

    /**
     * DEFECT: the nodeUuid watcher reloads, but useComments(props.assessmentUuid,
     * props.nodeUuid) captured the first node's uuid at setup, so the reload
     * fetches (and later posts to) the ORIGINAL node. Today the modal remounts
     * per node, which hides it. Fix: pass getters/refs into useComments, or key
     * the component on nodeUuid; then flip this to expect '.../a1/n2'.
     */
    it('DEFECT: a changed nodeUuid reloads the thread of the original node', async () => {
        const wrapper = await render([]);

        await wrapper.setProps({ nodeUuid: 'n2' });
        await flushPromises();

        expect(axios.get).toHaveBeenCalledTimes(2);
        expect(axios.get).toHaveBeenLastCalledWith(
            '/assessments.comments.index/a1/n1',
        );
    });
});
