/**
 * The Publish-as-template panel (W45, item 14; reshaped by W141).
 *
 * The thing worth testing here is not that a button exists. It is that
 * nothing is published on one click, and that the sentences telling the user
 * what publishing exposes, and that it cannot be undone from the assessment,
 * are on screen before the click that does it.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import TemplatePanel from '../../../resources/js/Components/Share/TemplatePanel.vue';

function render(props: { busy?: boolean; published?: boolean } = {}) {
    return mount(TemplatePanel, {
        props: { busy: false, published: false, ...props },
        global: {
            mocks: { route: (name: string) => `/${name}` },
            stubs: { Link: { template: '<a><slot /></a>' } },
        },
    });
}

const CONFIRM = '[data-testid="template-confirm-publish"]';

describe('TemplatePanel', () => {
    it('does not publish on the first click', async () => {
        const wrapper = render();

        await wrapper.find('button').trigger('click');

        expect(wrapper.emitted('publish')).toBeUndefined();
        expect(wrapper.find(CONFIRM).exists()).toBe(true);
    });

    it('says what publishing exposes, and that it is a one-way copy, before it happens', async () => {
        const wrapper = render();

        await wrapper.find('button').trigger('click');
        const text = wrapper.find(CONFIRM).text();

        expect(text).toContain('everyone with an account');
        expect(text).toContain('risks, attacks, defences and values');
        expect(text).toContain('editing one never changes the other');
        expect(text).toContain('cannot be unpublished');
        expect(text).toContain('delete it from the Templates page');
    });

    it('publishes on the second, deliberate click', async () => {
        const wrapper = render();

        await wrapper.find('button').trigger('click');
        const buttons = wrapper.find(CONFIRM).findAll('button');
        await buttons[buttons.length - 1].trigger('click');

        expect(wrapper.emitted('publish')).toHaveLength(1);
        expect(wrapper.find(CONFIRM).exists()).toBe(false);
    });

    it('cancels without emitting anything', async () => {
        const wrapper = render();

        await wrapper.find('button').trigger('click');
        await wrapper.find(CONFIRM).findAll('button')[0].trigger('click');

        expect(wrapper.emitted('publish')).toBeUndefined();
        expect(wrapper.find(CONFIRM).exists()).toBe(false);
    });

    it('has no unpublish: after publishing it only points at the Templates page', () => {
        const wrapper = render({ published: true });

        expect(wrapper.text()).toContain('Templates page');
        expect(wrapper.text().toLowerCase()).not.toContain('unpublish ');
        expect(wrapper.findAll('button').map((b) => b.text())).toEqual([
            'Publish another copy',
        ]);
    });

    it('is disabled while a request is in flight', () => {
        const wrapper = render({ busy: true });

        expect(wrapper.find('button').attributes('disabled')).toBeDefined();
    });
});
