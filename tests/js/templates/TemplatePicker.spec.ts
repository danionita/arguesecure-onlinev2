/**
 * The template picker (2.5.1): step one chooses a template, step two chooses
 * among its nodes of the child type, filtered as you type because a template
 * can hold 150 controls. The picker owns no state beyond the filter — which
 * template is chosen, what loaded and what each item would do all come in as
 * props, from `useTemplateChildren.ts` — so these tests drive it by props and
 * read what it emits.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import TemplatePicker from '../../../resources/js/Components/Node/TemplatePicker.vue';
import type {
    TemplateItemState,
    TemplateNodeItem,
} from '../../../resources/js/Components/Node/useTemplateChildren';

const TEMPLATES = [
    {
        uuid: 'tpl1',
        title: 'ISO/IEC 27001',
        counts: { risk: 3, attack: 4, defence: 93 },
    },
    {
        uuid: 'tpl2',
        title: 'Risks only',
        counts: { risk: 2, attack: 0, defence: 0 },
    },
];

const ITEMS: TemplateNodeItem[] = [
    {
        uuid: 'n1',
        title: 'Ransomware',
        description: 'Files encrypted for a ransom.',
        is_transfer: false,
        parents: ['Data loss', 'Outage'],
    },
    {
        uuid: 'n2',
        title: 'Phishing',
        description: 'Credentials harvested by email.',
        is_transfer: false,
        parents: [],
    },
    {
        uuid: 'n3',
        title: 'Supplier compromise',
        description: null,
        is_transfer: false,
        parents: [],
    },
];

function picker(props: Record<string, unknown> = {}) {
    return mount(TemplatePicker, {
        props: {
            mode: 'multi',
            childType: 'attack',
            templates: TEMPLATES,
            templateUuid: null,
            items: null,
            loading: false,
            error: null,
            ...props,
        },
    });
}

describe('step one: which template', () => {
    it('lists every template with its count of this child type, and emits the one chosen', async () => {
        const wrapper = picker();
        const options = wrapper.findAll('[data-testid="template-option"]');

        expect(options).toHaveLength(2);
        expect(options[0].text()).toContain('ISO/IEC 27001');
        expect(options[0].text()).toContain('4 attacks');

        await options[0].trigger('click');
        expect(wrapper.emitted('choose')).toEqual([['tpl1']]);
    });

    it('disables a template with none of this type', () => {
        const options = picker().findAll('[data-testid="template-option"]');

        expect(options[1].attributes('disabled')).toBeDefined();
        expect(options[1].text()).toContain('0 attacks');
    });

    it('says when there are no templates', () => {
        expect(picker({ templates: [] }).text()).toContain(
            'There are no templates yet.',
        );
    });

    it('shows the loading state instead of a list', () => {
        const wrapper = picker({ loading: true, templates: null });

        expect(wrapper.text()).toContain('Loading');
        expect(wrapper.find('[data-testid="template-option"]').exists()).toBe(
            false,
        );
    });

    it('shows an error as text', () => {
        const wrapper = picker({ error: 'The templates could not be loaded.' });

        expect(
            wrapper.find('[data-testid="template-picker-error"]').text(),
        ).toBe('The templates could not be loaded.');
    });
});

describe('step two: which of its nodes', () => {
    function chosen(props: Record<string, unknown> = {}) {
        return picker({ templateUuid: 'tpl1', items: ITEMS, ...props });
    }

    it('shows each node with its description and where the template puts it', () => {
        const items = chosen().findAll('[data-testid="template-item"]');

        expect(items).toHaveLength(3);
        expect(items[0].text()).toContain('Ransomware');
        expect(items[0].text()).toContain('Files encrypted for a ransom.');
        expect(items[0].text()).toContain(
            'In the template under Data loss, Outage',
        );
        expect(items[1].text()).not.toContain('In the template under');
    });

    it('filters on title and description, ignoring case', async () => {
        const wrapper = chosen();
        const filter = wrapper.find('[data-testid="template-picker-filter"]');

        await filter.setValue('PHISH');
        expect(wrapper.findAll('[data-testid="template-item"]')).toHaveLength(
            1,
        );

        await filter.setValue('ransom');
        const matches = wrapper.findAll('[data-testid="template-item"]');
        expect(matches).toHaveLength(1);
        expect(matches[0].text()).toContain('Ransomware');

        await filter.setValue('nothing like this');
        expect(wrapper.text()).toContain('Nothing matches.');
    });

    it('clears the filter when another template is chosen', async () => {
        const wrapper = chosen();
        await wrapper
            .find('[data-testid="template-picker-filter"]')
            .setValue('phish');

        await wrapper.setProps({ templateUuid: null, items: null });
        await wrapper.setProps({ templateUuid: 'tpl1', items: ITEMS });

        expect(wrapper.findAll('[data-testid="template-item"]')).toHaveLength(
            3,
        );
    });

    it('goes back to the template list', async () => {
        const wrapper = chosen();

        await wrapper
            .find('[data-testid="template-picker-back"]')
            .trigger('click');

        expect(wrapper.emitted('choose')).toEqual([[null]]);
    });

    it('renders template text as text, never as markup', () => {
        const wrapper = chosen({
            items: [
                {
                    ...ITEMS[0],
                    title: '<img src=x onerror=alert(1)>',
                    description: '<b>bold</b>',
                },
            ],
        });

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('b').exists()).toBe(false);
        expect(wrapper.text()).toContain('<img src=x onerror=alert(1)>');
    });
});

describe('multi mode: one action per item, as many as wanted', () => {
    const STATES: Record<string, TemplateItemState> = {
        n1: 'add',
        n2: 'link',
        n3: 'here',
    };

    function multi(props: Record<string, unknown> = {}) {
        return picker({
            templateUuid: 'tpl1',
            items: ITEMS,
            stateOf: (item: TemplateNodeItem) => STATES[item.uuid],
            busy: null,
            ...props,
        });
    }

    it('labels each action by what it will do, and shows "Already here" as text', () => {
        const wrapper = multi();
        const actions = wrapper.findAll('[data-testid="template-item-take"]');

        expect(actions.map((a) => a.text())).toEqual(['Add', 'Link']);
        expect(wrapper.find('[data-testid="template-item-done"]').text()).toBe(
            'Already here',
        );
    });

    it('emits the item taken, and stays open for the next one', async () => {
        const wrapper = multi();

        await wrapper
            .findAll('[data-testid="template-item-take"]')[0]
            .trigger('click');

        expect(wrapper.emitted('take')).toEqual([[ITEMS[0]]]);
        expect(wrapper.emitted('dismiss')).toBeUndefined();
    });

    it('disables every action while one is being written', () => {
        const actions = multi({ busy: 'n1' }).findAll(
            '[data-testid="template-item-take"]',
        );

        expect(
            actions.every((a) => a.attributes('disabled') !== undefined),
        ).toBe(true);
    });

    it('is closed with Done', async () => {
        const wrapper = multi();
        const done = wrapper.find('[data-testid="template-picker-dismiss"]');

        expect(done.text()).toBe('Done');
        await done.trigger('click');
        expect(wrapper.emitted('dismiss')).toHaveLength(1);
    });
});

describe('single mode: the new-node form picks exactly one', () => {
    it('offers Use on every item regardless of state, and Cancel to close', async () => {
        const wrapper = picker({
            mode: 'single',
            templateUuid: 'tpl1',
            items: ITEMS,
        });

        const uses = wrapper.findAll('[data-testid="template-item-use"]');
        expect(uses).toHaveLength(3);
        expect(
            wrapper.find('[data-testid="template-item-take"]').exists(),
        ).toBe(false);
        expect(
            wrapper.find('[data-testid="template-picker-dismiss"]').text(),
        ).toBe('Cancel');

        await uses[1].trigger('click');
        expect(wrapper.emitted('take')).toEqual([[ITEMS[1]]]);
    });
});
