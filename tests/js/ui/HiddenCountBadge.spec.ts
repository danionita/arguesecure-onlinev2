/**
 * W130 follow-up: the hidden-children count is styled like the card's
 * collapse button (IconButton `subtle`): muted, same height, no ground at
 * rest. A grey pill looked foreign on the button bar. jsdom computes no CSS,
 * so the classes are asserted by name; each pair below is a value the design
 * specifies, not "some class is present".
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import HiddenCountBadge from '../../../resources/js/Components/UI/HiddenCountBadge.vue';
import IconButton from '../../../resources/js/Components/UI/IconButton.vue';

describe('HiddenCountBadge matches the card button bar', () => {
    const badge = mount(HiddenCountBadge, { props: { count: 3 } });
    const classes = badge.get('button').classes();

    it('has no ground or border at rest, and shows one only on hover', () => {
        expect(classes).not.toContain('bg-surface-2');
        expect(classes).not.toContain('border');
        expect(classes).toContain('hover:bg-surface-2');
    });

    it('uses the collapse button text colours and height', () => {
        const chevron = mount(IconButton, {
            props: { icon: 'chevron-down', label: 'Collapse' },
        })
            .get('button')
            .classes();
        for (const c of [
            'text-text-muted',
            'hover:text-text-primary',
            'h-6',
            'rounded',
        ]) {
            expect(chevron).toContain(c);
            expect(classes).toContain(c);
        }
    });

    it('is a rounded rectangle, not a pill', () => {
        expect(classes).not.toContain('rounded-full');
    });

    it('shows the number and emits toggle on click', async () => {
        expect(badge.text()).toBe('3');
        await badge.get('button').trigger('click');
        expect(badge.emitted('toggle')).toHaveLength(1);
    });
});
