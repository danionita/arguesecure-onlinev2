/**
 * The product mark above every guest page (login, first-run setup, 2FA).
 * It is a link home, large enough to read as a logo, and carries the name.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import GuestLayout from '../../../resources/js/Layouts/GuestLayout.vue';

const page = { props: {} as Record<string, unknown> };

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    usePage: () => page,
}));

describe('GuestLayout', () => {
    it('shows the ArgueSecure logo linking home, above the slot', () => {
        const wrapper = mount(GuestLayout, {
            slots: { default: '<p>body</p>' },
        });
        const logo = wrapper.get('[data-test="guest-logo"]');

        expect(logo.attributes('href')).toBe('/');
        expect(logo.text()).toBe('ArgueSecure');
        expect(logo.find('svg').attributes('width')).toBe('56');
        expect(wrapper.text()).toContain('body');
    });

    it('shows the deployment notice above the logo when one is set', () => {
        page.props = { notice: 'Public demo' };
        const wrapper = mount(GuestLayout);
        expect(wrapper.get('[role="status"]').text()).toBe('Public demo');
        page.props = {};
        expect(mount(GuestLayout).find('[role="status"]').exists()).toBe(false);
    });
});
