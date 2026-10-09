/**
 * The Save-as-template panel (W45, item 14).
 *
 * The thing worth testing here is not that a button exists. It is that
 * nothing is published on one click, and that the sentence telling the user
 * what publishing exposes is on screen before the click that does it. That
 * copy is the security content of this package as far as the person using it
 * is concerned.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import TemplatePanel from '../../../resources/js/Components/Share/TemplatePanel.vue';
import type { TemplateStatus } from '../../../resources/js/Components/Share/types';

function render(status: Partial<TemplateStatus> = {}, busy = false) {
    return mount(TemplatePanel, {
        props: {
            busy,
            status: {
                is_template: false,
                is_public: false,
                canSaveAsTemplate: true,
                ...status,
            },
        },
    });
}

const CONFIRM = '[data-testid="template-confirm-publish"]';
const UNCONFIRM = '[data-testid="template-confirm-unpublish"]';

describe('TemplatePanel', () => {
    it('does not publish on the first click', async () => {
        const wrapper = render();

        await wrapper.find('button').trigger('click');

        expect(wrapper.emitted('publish')).toBeUndefined();
        expect(wrapper.find(CONFIRM).exists()).toBe(true);
    });

    it('says what publishing exposes before it happens', async () => {
        const wrapper = render();

        await wrapper.find('button').trigger('click');
        const text = wrapper.find(CONFIRM).text();

        expect(text).toContain('everyone with an account');
        expect(text).toContain('risks, attacks, defences and values');
        // 2.5.1: publishing no longer makes the assessment itself public.
        expect(text).toContain('does not make this assessment itself public');
        expect(text).toContain('unpublish it at any time');
    });

    it('publishes on the second, deliberate click', async () => {
        const wrapper = render();

        await wrapper.find('button').trigger('click');
        const buttons = wrapper.find(CONFIRM).findAll('button');
        await buttons[buttons.length - 1].trigger('click');

        expect(wrapper.emitted('publish')).toHaveLength(1);
    });

    it('cancels without emitting anything', async () => {
        const wrapper = render();

        await wrapper.find('button').trigger('click');
        await wrapper.find(CONFIRM).findAll('button')[0].trigger('click');

        expect(wrapper.emitted('publish')).toBeUndefined();
        expect(wrapper.find(CONFIRM).exists()).toBe(false);
    });

    it('asks before unpublishing, and says existing copies are unaffected', async () => {
        const wrapper = render({ is_template: true });

        await wrapper.find('button').trigger('click');

        expect(wrapper.find(UNCONFIRM).text()).toContain('not affected');
        expect(wrapper.emitted('unpublish')).toBeUndefined();
    });

    it('offers no control to someone who may not publish', () => {
        const wrapper = render({ canSaveAsTemplate: false });

        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('tells a non-admin why there is no control (2.5.1: admins only)', () => {
        const wrapper = render({ canSaveAsTemplate: false });

        expect(wrapper.text()).toContain('Only an administrator');
    });

    it('retires the prompt once the state has actually changed', async () => {
        const wrapper = render();

        await wrapper.find('button').trigger('click');
        expect(wrapper.find(CONFIRM).exists()).toBe(true);

        await wrapper.setProps({
            status: {
                is_template: true,
                is_public: false,
                canSaveAsTemplate: true,
            },
        });

        expect(wrapper.find(CONFIRM).exists()).toBe(false);
    });
});
