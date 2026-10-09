/**
 * `Tooltip` grew a second slot in W69 (PLAN-2.4.md item 11), and the whole
 * risk of that change is regression: it is one of the most-used components in
 * this codebase, and every existing caller passes a `text` prop and nothing
 * else. So the first group here asserts the **old** behaviour is untouched,
 * and only then does the second group assert the new slot.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import Tooltip from '../../../resources/js/Components/UI/Tooltip.vue';

function mountTooltip(options: Parameters<typeof mount>[1] = {}) {
    return mount(Tooltip, {
        props: { text: 'Impact set — open to change it' },
        slots: { default: '<button>trigger</button>' },
        ...options,
    });
}

async function show(wrapper: ReturnType<typeof mountTooltip>) {
    await wrapper.trigger('mouseenter');

    return wrapper.find('[role="tooltip"]');
}

describe('the text prop, which every existing caller uses', () => {
    it('stays hidden until hover, then renders the string', async () => {
        const wrapper = mountTooltip();

        expect(wrapper.find('[role="tooltip"]').exists()).toBe(false);
        expect((await show(wrapper)).text()).toBe(
            'Impact set — open to change it',
        );
    });

    it('shows on focus too, so it is reachable by keyboard (ui.md §11)', async () => {
        const wrapper = mountTooltip();
        await wrapper.trigger('focusin');

        expect(wrapper.find('[role="tooltip"]').exists()).toBe(true);

        await wrapper.trigger('focusout');
        expect(wrapper.find('[role="tooltip"]').exists()).toBe(false);
    });

    it('keeps rendering the trigger, which is the default slot', () => {
        expect(mountTooltip().find('button').text()).toBe('trigger');
    });

    it('honours the placement prop either way', async () => {
        const top = await show(mountTooltip());
        expect(top.classes()).toContain('bottom-full');

        const bottom = await show(
            mountTooltip({
                props: { text: 'x', placement: 'bottom' },
                slots: { default: '<button>trigger</button>' },
            }),
        );
        expect(bottom.classes()).toContain('top-full');
    });
});

describe('the content slot (W69)', () => {
    it('renders block children, which a text prop cannot do', async () => {
        const wrapper = mountTooltip({
            props: { text: 'ignored' },
            slots: {
                default: '<button>trigger</button>',
                content:
                    '<span class="block">Fraud</span><span class="block">Data loss</span>',
            },
        });

        const bubble = await show(wrapper);
        expect(bubble.findAll('span.block').map((s) => s.text())).toEqual([
            'Fraud',
            'Data loss',
        ]);
    });

    /**
     * The additive half of the change: a caller that supplies no `#content`
     * must get exactly what it got before. If the slot ever stops falling back
     * to `text`, every tooltip in the app goes blank and this is the test that
     * says so.
     */
    it('falls back to the text prop when no content is supplied', async () => {
        expect((await show(mountTooltip())).text()).toBe(
            'Impact set — open to change it',
        );
    });

    it('lets a caller drop the text prop entirely', async () => {
        const wrapper = mount(Tooltip, {
            slots: {
                default: '<button>trigger</button>',
                content: '<span>Appears in 3 places</span>',
            },
        });

        expect((await show(wrapper)).text()).toBe('Appears in 3 places');
    });
});
