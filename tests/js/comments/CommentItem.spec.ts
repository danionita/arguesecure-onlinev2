import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import CommentItem from '../../../resources/js/Components/Comments/CommentItem.vue';
import type { Comment } from '../../../resources/js/Components/Comments/types';

/**
 * W119: CommentItem is where the permission flags the server computes
 * (`can_edit`, `can_delete`) and the two-level threading rule become buttons.
 * The server is authoritative, but a button that appears for someone who may
 * not use it is a broken promise, and a reply that can itself be replied to
 * would build a third level schema.md §3.8 forbids.
 */
function comment(over: Partial<Comment> = {}): Comment {
    return {
        id: 1,
        body: 'Looks risky',
        author: { id: 7, name: 'Dana' },
        created_at: null,
        updated_at: null,
        resolved_at: null,
        resolved_by: null,
        can_edit: false,
        can_delete: false,
        replies: [],
        ...over,
    };
}

function render(
    over: Partial<Comment> = {},
    props: { isReply?: boolean; canComment?: boolean } = {},
) {
    return mount(CommentItem, {
        props: { comment: comment(over), canComment: true, ...props },
    });
}

function textButton(wrapper: ReturnType<typeof render>, text: string) {
    return wrapper.findAll('button').find((b) => b.text() === text);
}

function labelled(wrapper: ReturnType<typeof render>, label: string) {
    return wrapper.find(`button[aria-label="${label}"]`);
}

describe('CommentItem', () => {
    it('shows the author name, and "Deleted user" when the author is gone', () => {
        expect(render().text()).toContain('Dana');
        expect(render({ author: null }).text()).toContain('Deleted user');
    });

    it('escapes the body instead of rendering it as markup', () => {
        // CLAUDE.md W18: a comment body is attacker-controlled text.
        const wrapper = render({ body: '<img src=x onerror=alert(1)>' });

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('p').text()).toBe('<img src=x onerror=alert(1)>');
    });

    it('offers Edit only when the server said can_edit', () => {
        expect(
            labelled(render({ can_edit: false }), 'Edit comment').exists(),
        ).toBe(false);
        expect(
            labelled(render({ can_edit: true }), 'Edit comment').exists(),
        ).toBe(true);
    });

    it('offers Delete only when the server said can_delete, independently of can_edit', () => {
        const editOnly = render({ can_edit: true, can_delete: false });
        expect(labelled(editOnly, 'Delete comment').exists()).toBe(false);

        const deleteOnly = render({ can_edit: false, can_delete: true });
        expect(labelled(deleteOnly, 'Delete comment').exists()).toBe(true);
        expect(labelled(deleteOnly, 'Edit comment').exists()).toBe(false);
    });

    it('emits delete when Delete is clicked', async () => {
        const wrapper = render({ can_delete: true });

        await labelled(wrapper, 'Delete comment').trigger('click');

        expect(wrapper.emitted('delete')).toHaveLength(1);
    });

    it('shows the Resolved badge and Unresolve (not Resolve) for a resolved comment', () => {
        const wrapper = render({ resolved_at: '2026-01-01T00:00:00Z' });

        expect(wrapper.text()).toContain('Resolved');
        expect(textButton(wrapper, 'Unresolve')).toBeDefined();
        expect(textButton(wrapper, 'Resolve')).toBeUndefined();
    });

    it('shows Resolve (not Unresolve) and no badge for an unresolved comment', () => {
        const wrapper = render();

        expect(wrapper.text()).not.toContain('Resolved');
        expect(textButton(wrapper, 'Resolve')).toBeDefined();
        expect(textButton(wrapper, 'Unresolve')).toBeUndefined();
    });

    it('emits resolve and unresolve from the matching buttons', async () => {
        const open = render();
        await textButton(open, 'Resolve')!.trigger('click');
        expect(open.emitted('resolve')).toHaveLength(1);

        const done = render({ resolved_at: '2026-01-01T00:00:00Z' });
        await textButton(done, 'Unresolve')!.trigger('click');
        expect(done.emitted('unresolve')).toHaveLength(1);
    });

    it('a reply has no Reply, Resolve or Unresolve button (threads are two levels)', () => {
        const wrapper = render(
            { can_edit: true, can_delete: true },
            { isReply: true },
        );

        expect(textButton(wrapper, 'Reply')).toBeUndefined();
        expect(textButton(wrapper, 'Resolve')).toBeUndefined();
        expect(textButton(wrapper, 'Unresolve')).toBeUndefined();
        // It still carries its own edit/delete.
        expect(labelled(wrapper, 'Edit comment').exists()).toBe(true);
        expect(wrapper.classes()).toContain('ml-8');
    });

    it('a user who may not comment gets no Reply, Resolve or Unresolve', () => {
        const wrapper = render({}, { canComment: false });
        expect(textButton(wrapper, 'Reply')).toBeUndefined();
        expect(textButton(wrapper, 'Resolve')).toBeUndefined();

        const resolved = render(
            { resolved_at: '2026-01-01T00:00:00Z' },
            { canComment: false },
        );
        expect(textButton(resolved, 'Unresolve')).toBeUndefined();
    });

    it('emits the trimmed reply body and closes the reply box', async () => {
        const wrapper = render();

        await textButton(wrapper, 'Reply')!.trigger('click');
        await wrapper.find('textarea').setValue('  agreed  ');
        await textButton(wrapper, 'Post reply')!.trigger('click');

        expect(wrapper.emitted('reply')).toEqual([['agreed']]);
        expect(wrapper.find('textarea').exists()).toBe(false);
    });

    it('does not emit a reply that is only whitespace', async () => {
        const wrapper = render();

        await textButton(wrapper, 'Reply')!.trigger('click');
        await wrapper.find('textarea').setValue('   ');
        await textButton(wrapper, 'Post reply')!.trigger('click');

        expect(wrapper.emitted('reply')).toBeUndefined();
        expect(wrapper.find('textarea').exists()).toBe(true);
    });

    it('cancelling a reply emits nothing and hides the box', async () => {
        const wrapper = render();

        await textButton(wrapper, 'Reply')!.trigger('click');
        await textButton(wrapper, 'Cancel')!.trigger('click');

        expect(wrapper.emitted('reply')).toBeUndefined();
        expect(wrapper.find('textarea').exists()).toBe(false);
    });

    it('edits in place: starts from the current body and emits the trimmed edit', async () => {
        const wrapper = render({ can_edit: true });

        await labelled(wrapper, 'Edit comment').trigger('click');
        const area = wrapper.find('textarea');
        expect((area.element as HTMLTextAreaElement).value).toBe('Looks risky');
        // The edit button hides while editing so there is one editor at a time.
        expect(labelled(wrapper, 'Edit comment').exists()).toBe(false);

        await area.setValue('  Changed  ');
        await textButton(wrapper, 'Save')!.trigger('click');

        expect(wrapper.emitted('edit')).toEqual([['Changed']]);
        expect(wrapper.find('textarea').exists()).toBe(false);
    });

    it('does not emit an edit that empties the body', async () => {
        const wrapper = render({ can_edit: true });

        await labelled(wrapper, 'Edit comment').trigger('click');
        await wrapper.find('textarea').setValue('  ');
        await textButton(wrapper, 'Save')!.trigger('click');

        expect(wrapper.emitted('edit')).toBeUndefined();
    });

    it('cancelling an edit keeps the original body', async () => {
        const wrapper = render({ can_edit: true });

        await labelled(wrapper, 'Edit comment').trigger('click');
        await wrapper.find('textarea').setValue('Nope');
        await textButton(wrapper, 'Cancel')!.trigger('click');

        expect(wrapper.emitted('edit')).toBeUndefined();
        expect(wrapper.find('p').text()).toBe('Looks risky');
    });
});
