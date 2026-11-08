import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DeploymentNotice from '../../../resources/js/Components/Chrome/DeploymentNotice.vue';

const page = { props: {} as Record<string, unknown> };
vi.mock('@inertiajs/vue3', () => ({ usePage: () => page }));

beforeEach(() => {
    page.props = {};
});

describe('DeploymentNotice', () => {
    it('shows the text in a status element', () => {
        page.props = { notice: 'Public demo' };
        const wrapper = mount(DeploymentNotice);
        const el = wrapper.get('[role="status"]');
        expect(el.text()).toBe('Public demo');
        expect(el.classes()).toContain('no-print');
    });

    it('shows markup literally, with no child elements', () => {
        page.props = { notice: '<b>hi</b>' };
        const el = mount(DeploymentNotice).get('[role="status"]');
        expect(el.text()).toBe('<b>hi</b>');
        expect(el.element.children.length).toBe(0);
    });

    it.each([null, '', undefined])('renders nothing for %s', (value) => {
        page.props = { notice: value };
        expect(mount(DeploymentNotice).find('[role="status"]').exists()).toBe(
            false,
        );
    });
});
