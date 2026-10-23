import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Comment } from '../../../resources/js/Components/Comments/types';
import {
    fetchUnresolvedCommentCounts,
    useComments,
} from '../../../resources/js/Components/Comments/useComments';

/**
 * W119: useComments is the only client of CommentController, and every one of
 * its mutations edits a local copy of the thread tree by id. The risk is the
 * edit landing on the wrong comment (a reply patched as if it were a top-level
 * comment, or the reverse), so each case checks which comment changed.
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

beforeEach(() => {
    Object.values(axios).forEach((fn) => fn.mockReset());
    (window as unknown as { axios: unknown }).axios = axios;
    (globalThis as unknown as { route: unknown }).route = (
        name: string,
        params: unknown,
    ) => `/${name}/${[params].flat().join('/')}`;
});

describe('useComments', () => {
    it('load() replaces the list and clears loading', async () => {
        axios.get.mockResolvedValue({ data: { comments: [comment(1)] } });
        const c = useComments('a1', 'n1');
        c.comments.push(comment(99));

        await c.load();

        expect(c.comments.map((x) => x.id)).toEqual([1]);
        expect(c.loading.value).toBe(false);
        expect(c.error.value).toBeNull();
        expect(axios.get).toHaveBeenCalledWith(
            '/assessments.comments.index/a1/n1',
        );
    });

    it('load() reports a failure instead of throwing, and still clears loading', async () => {
        axios.get.mockRejectedValue(new Error('offline'));
        const c = useComments('a1', 'n1');

        await c.load();

        expect(c.error.value).toBe('Could not load comments.');
        expect(c.loading.value).toBe(false);
    });

    it('post() appends a top-level comment, and a reply under its parent only', async () => {
        const c = useComments('a1', 'n1');
        c.comments.push(comment(1), comment(2));

        axios.post.mockResolvedValueOnce({ data: { comment: comment(3) } });
        await c.post('top');
        expect(c.comments.map((x) => x.id)).toEqual([1, 2, 3]);
        expect(axios.post.mock.calls[0][1]).toEqual({
            body: 'top',
            parent_comment_id: null,
        });

        axios.post.mockResolvedValueOnce({ data: { comment: comment(4) } });
        await c.post('reply', 2);
        expect(c.comments.map((x) => x.id)).toEqual([1, 2, 3]);
        expect(c.comments[1].replies.map((x) => x.id)).toEqual([4]);
        expect(c.comments[0].replies).toEqual([]);
        expect(axios.post.mock.calls[1][1]).toEqual({
            body: 'reply',
            parent_comment_id: 2,
        });
    });

    it('update() patches a reply in place without touching its parent', async () => {
        const c = useComments('a1', 'n1');
        c.comments.push(comment(1, { replies: [comment(5)] }));
        axios.patch.mockResolvedValue({
            data: { comment: comment(5, { body: 'edited' }) },
        });

        await c.update(5, 'edited');

        expect(c.comments[0].replies[0].body).toBe('edited');
        expect(c.comments[0].body).toBe('body 1');
    });

    it('destroy() removes a top-level comment or a reply, and only that one', async () => {
        axios.delete.mockResolvedValue({});
        const c = useComments('a1', 'n1');
        c.comments.push(
            comment(1, { replies: [comment(5), comment(6)] }),
            comment(2),
        );

        await c.destroy(5);
        expect(c.comments[0].replies.map((x) => x.id)).toEqual([6]);
        expect(c.comments.map((x) => x.id)).toEqual([1, 2]);

        await c.destroy(1);
        expect(c.comments.map((x) => x.id)).toEqual([2]);
    });

    it('resolve() and unresolve() apply the server copy to the right comment', async () => {
        const c = useComments('a1', 'n1');
        c.comments.push(comment(1), comment(2));
        const stamp = '2026-01-01T00:00:00Z';

        axios.post.mockResolvedValue({
            data: { comment: comment(2, { resolved_at: stamp }) },
        });
        await c.resolve(2);
        expect(c.comments[1].resolved_at).toBe(stamp);
        expect(c.comments[0].resolved_at).toBeNull();

        axios.delete.mockResolvedValue({
            data: { comment: comment(2, { resolved_at: null }) },
        });
        await c.unresolve(2);
        expect(c.comments[1].resolved_at).toBeNull();
    });

    it('fetchUnresolvedCommentCounts() returns the per-node counts', async () => {
        axios.get.mockResolvedValue({ data: { counts: { n1: 2 } } });
        expect(await fetchUnresolvedCommentCounts('a1')).toEqual({ n1: 2 });
        expect(axios.get).toHaveBeenCalledWith(
            '/assessments.comments.unresolved-counts/a1',
        );
    });
});
