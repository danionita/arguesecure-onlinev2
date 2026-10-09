/**
 * The label field on the Details tab (W43, item 18).
 *
 * The behaviour worth pinning is the part that is easy to get subtly wrong:
 * Enter has two meanings depending on whether the user has arrowed into the
 * suggestion list, and getting that backwards means someone typing "PCI"
 * silently gets whichever label happened to be first.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import TagEditor from '../../../resources/js/Components/Tags/TagEditor.vue';

function render(over: Partial<InstanceType<typeof TagEditor>['$props']> = {}) {
    return mount(TagEditor, {
        props: {
            names: [],
            available: ['PCI DSS', 'Payments', 'Vendor'],
            canEdit: true,
            ...over,
        },
    });
}

describe('adding a label', () => {
    it('commits what was typed when nothing is highlighted', async () => {
        const wrapper = render();

        await wrapper.get('input').setValue('Brand new');
        await wrapper.get('input').trigger('keydown', { key: 'Enter' });

        // Not the first suggestion. Someone who typed a name and never looked
        // at the list expects the name they typed.
        expect(wrapper.emitted('add')?.[0]).toEqual(['Brand new']);
    });

    it('commits the highlighted suggestion once the user has arrowed to one', async () => {
        const wrapper = render();

        await wrapper.get('input').setValue('p');
        await wrapper.get('input').trigger('keydown', { key: 'ArrowDown' });
        await wrapper.get('input').trigger('keydown', { key: 'Enter' });

        expect(wrapper.emitted('add')?.[0]).toEqual(['PCI DSS']);
    });

    it('offers only labels this node does not already carry', async () => {
        const wrapper = render({ names: ['PCI DSS'] });

        await wrapper.get('input').trigger('focus');

        const options = wrapper.findAll('[role="option"]').map((o) => o.text());
        expect(options).toEqual(['Payments', 'Vendor']);
    });

    it('emits nothing for an empty or whitespace-only entry', async () => {
        const wrapper = render();

        await wrapper.get('input').setValue('   ');
        await wrapper.get('input').trigger('keydown', { key: 'Enter' });

        expect(wrapper.emitted('add')).toBeUndefined();
    });

    it('clears the box after committing, so the next label can be typed straight away', async () => {
        const wrapper = render();

        await wrapper.get('input').setValue('First');
        await wrapper.get('input').trigger('keydown', { key: 'Enter' });

        expect(wrapper.get('input').element.value).toBe('');
    });
});

describe('removing a label', () => {
    it('removes the one whose chip was clicked', async () => {
        const wrapper = render({ names: ['PCI DSS', 'Payments'] });

        await wrapper
            .get('[aria-label="Remove label Payments"]')
            .trigger('click');

        expect(wrapper.emitted('remove')?.[0]).toEqual(['Payments']);
    });

    it('removes the last one on Backspace in an empty box', async () => {
        const wrapper = render({ names: ['PCI DSS', 'Payments'] });

        await wrapper.get('input').trigger('keydown', { key: 'Backspace' });

        expect(wrapper.emitted('remove')?.[0]).toEqual(['Payments']);
    });

    it('leaves the labels alone when Backspace is deleting typed text', async () => {
        const wrapper = render({ names: ['PCI DSS'] });

        await wrapper.get('input').setValue('half-typ');
        await wrapper.get('input').trigger('keydown', { key: 'Backspace' });

        // Otherwise correcting a typo would silently take a label off.
        expect(wrapper.emitted('remove')).toBeUndefined();
    });
});

describe('a reader who cannot edit', () => {
    it('sees the labels and no way to change them', () => {
        const wrapper = render({ names: ['PCI DSS'], canEdit: false });

        expect(wrapper.text()).toContain('PCI DSS');
        expect(wrapper.find('input').exists()).toBe(false);
        expect(wrapper.find('[aria-label^="Remove label"]').exists()).toBe(
            false,
        );
    });

    it('says so rather than showing an empty gap when there are none', () => {
        expect(render({ names: [], canEdit: false }).text()).toContain(
            'No labels',
        );
    });
});
