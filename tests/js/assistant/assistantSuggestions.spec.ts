/**
 * The assistant suggestions panel, and the accept step that is the guardrail
 * (W52, PLAN-2.3-ai.md D24).
 *
 * The 2.3 spike measured that text injected into a node description reaches
 * the model's output on **3 of 3** usable models, and that no prompt wording
 * prevents it — an injection that merely adds to the output never forces the
 * model to choose between the injection and its task. `title` is a field the
 * accept path legitimately reads, so nothing downstream can tell an injected
 * suggestion from a genuine one.
 *
 * That makes the person reading this list the control. These assertions pin
 * the properties that keep it one: nothing is accepted in bulk, nothing is
 * pre-selected, each item is added on its own, and the panel says where the
 * text came from.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import AssistantSuggestions from '../../../resources/js/Components/Assistant/AssistantSuggestions.vue';

const SUGGESTIONS = [
    {
        title: 'Credential stuffing',
        description: 'Reused passwords against the admin console.',
    },
    {
        title: 'SQL injection on checkout',
        description: 'Unsanitised order lookup.',
    },
];

function panel(overrides: Record<string, unknown> = {}) {
    return mount(AssistantSuggestions, {
        props: {
            suggestions: SUGGESTIONS,
            childType: 'attack',
            accepted: [],
            busy: false,
            ...overrides,
        },
        global: {
            stubs: {
                Button: {
                    template:
                        '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
                    props: ['disabled', 'loading', 'variant'],
                },
            },
        },
    });
}

describe('the assistant suggestions panel', () => {
    it('says the text was generated, and that nothing is added yet', () => {
        const text = panel().text();

        // The reader must never have to work out which of the things on their
        // screen a model wrote.
        expect(text).toContain('local model');
        expect(text).toContain('nothing is added until you do');
    });

    it('offers one add button per suggestion and no bulk accept', () => {
        const wrapper = panel();

        expect(
            wrapper.findAll('[data-testid="accept-suggestion"]'),
        ).toHaveLength(SUGGESTIONS.length);
        // Deliberately absent. "Accept all" would skip the only step that can
        // catch an injected title, which is a person reading it.
        expect(wrapper.text()).not.toContain('Add all');
    });

    it('emits the suggestion and its index when one is added', async () => {
        const wrapper = panel();

        await wrapper
            .findAll('[data-testid="accept-suggestion"]')[1]
            .trigger('click');

        expect(wrapper.emitted('accept')?.[0]).toEqual([SUGGESTIONS[1], 1]);
    });

    it('will not offer the same suggestion twice once it is added', () => {
        const wrapper = panel({ accepted: [0] });

        expect(
            wrapper.findAll('[data-testid="suggestion-added"]'),
        ).toHaveLength(1);
        expect(
            wrapper.findAll('[data-testid="accept-suggestion"]'),
        ).toHaveLength(1);
    });

    it('renders titles and descriptions as text, never as markup', () => {
        const wrapper = panel({
            suggestions: [
                {
                    title: '<img src=x onerror=alert(1)>',
                    description: '<script>alert(2)</script>',
                },
            ],
        });

        // Model output is untrusted regardless of the prompt.
        expect(wrapper.text()).toContain('<img src=x onerror=alert(1)>');
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('script').exists()).toBe(false);
    });

    it('says so plainly when the model returned nothing usable', () => {
        const wrapper = panel({ suggestions: [] });

        // Better than an empty panel: the parser discards anything that is not
        // a title and a description, so "nothing usable" is a real outcome and
        // silence would read as a bug.
        expect(wrapper.find('[data-testid="no-suggestions"]').text()).toContain(
            'did not return anything usable',
        );
    });

    it('can be dismissed, because it was asked for and should go away', () => {
        const wrapper = panel();

        wrapper.find('[data-testid="dismiss-suggestions"]').trigger('click');

        expect(wrapper.emitted('dismiss')).toHaveLength(1);
    });
});
