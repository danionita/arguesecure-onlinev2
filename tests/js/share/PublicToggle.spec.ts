import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import PublicToggle from '../../../resources/js/Components/Share/PublicToggle.vue';

/**
 * Making an assessment public tells the person, where they decide, that its
 * readers get no live updates and no presence (security.md §8).
 */
describe('PublicToggle', () => {
    it('explains the missing live updates as hover text and as visible copy', () => {
        const wrapper = mount(PublicToggle, {
            props: { modelValue: false, busy: false },
        });

        const note =
            'Readers of a public assessment do not get live updates or see who else is here. They reload the page to see changes.';
        expect(
            wrapper.get('[data-testid="public-toggle"]').attributes('title'),
        ).toBe(note);
        expect(wrapper.get('[data-testid="public-live-note"]').text()).toBe(
            note,
        );
    });
});
