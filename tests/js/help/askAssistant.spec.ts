/**
 * The "ask a question" control on the help index (W51, PLAN-2.3-ai.md).
 *
 * Three rules, and each one is a decision the plan makes explicitly rather
 * than a detail of this component:
 *
 * - **Nothing is rendered where the assistant is unavailable.** No greyed-out
 *   box, no "coming soon" — and for a guest, not even an explanation, because
 *   the endpoint behind it is authenticated and a control that would 401 is
 *   worse than none.
 * - **The answer is marked as generated, at the point of use**, and survives
 *   as text. `{{ }}`, never `v-html`: W30 deleted this codebase's only
 *   user-authored-HTML renderer, and model output is the last thing that
 *   should reintroduce one.
 * - **A slow answer is announced before it is asked.** ~90s versus a few
 *   seconds, because the documentation has to be read into the model's cache
 *   once. Since 2.6 that happens when the runtime starts (D57), so the slow
 *   one is the answer asked just after a restart. A spinner with no
 *   explanation reads as a hang.
 */
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AskAssistant from '../../../resources/js/Components/Help/AskAssistant.vue';

const post = vi.fn();

beforeEach(() => {
    post.mockReset();
    (window as unknown as { axios: unknown }).axios = { post };
    (globalThis as unknown as { route: unknown }).route = () =>
        '/assistant/help';
});

function control(available = true, unavailableReason: string | null = null) {
    return mount(AskAssistant, {
        props: { available, unavailableReason },
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

async function ask(wrapper: ReturnType<typeof control>, question: string) {
    await wrapper.find('input').setValue(question);
    await wrapper.find('button').trigger('click');
    await flushPromises();
}

describe('the ask-assistant control', () => {
    it('renders nothing at all when the assistant is unavailable and silent', () => {
        // The guest case: `available: false` with no reason. Not a disabled
        // input, not an explanation of a feature they cannot reach.
        const wrapper = control(false, null);

        expect(wrapper.find('[data-testid="ask-assistant"]').exists()).toBe(
            false,
        );
        expect(
            wrapper.find('[data-testid="assistant-unavailable"]').exists(),
        ).toBe(false);
    });

    it('explains itself when there is a reason worth giving', () => {
        // The signed-in-but-runtime-down case: something *is* wrong and
        // somebody should know, so it says so — without offering a control
        // that cannot work.
        const wrapper = control(
            false,
            'The assistant is switched off for this instance.',
        );

        expect(wrapper.find('[data-testid="ask-assistant"]').exists()).toBe(
            false,
        );
        expect(
            wrapper.find('[data-testid="assistant-unavailable"]').text(),
        ).toContain('switched off');
    });

    it('warns that an answer can be slow after a restart, before anyone asks', () => {
        const wrapper = control();
        const warning = wrapper
            .find('[data-testid="first-answer-warning"]')
            .text();

        // 2.6 (D57): the corpus is read in when the runtime starts, so the
        // slow answer is the one asked just after a restart, not every
        // session's first — and the notice must not promise either extreme.
        expect(warning).toContain('a minute or two');
        expect(warning).toContain('restarts');
    });

    it('stops warning once the session has paid that cost', async () => {
        post.mockResolvedValue({ data: { answer: 'A transfer shifts loss.' } });
        const wrapper = control();

        await ask(wrapper, 'What is a transfer?');

        expect(
            wrapper.find('[data-testid="first-answer-warning"]').exists(),
        ).toBe(false);
    });

    it('marks the answer as generated where the answer is', async () => {
        post.mockResolvedValue({ data: { answer: 'A transfer shifts loss.' } });
        const wrapper = control();

        await ask(wrapper, 'What is a transfer?');

        const answer = wrapper.find('[data-testid="assistant-answer"]');
        expect(answer.text()).toContain('Generated answer');
        expect(answer.text()).toContain('A transfer shifts loss.');
    });

    it('renders the answer as text, never as markup', async () => {
        post.mockResolvedValue({
            data: { answer: '<img src=x onerror=alert(1)>' },
        });
        const wrapper = control();

        await ask(wrapper, 'What is a transfer?');

        const answer = wrapper.find('[data-testid="assistant-answer"]');
        // The tag survives as characters and never becomes an element. Model
        // output is untrusted regardless of the prompt: the spike measured
        // injected text reaching the output on 3 of 3 usable models.
        expect(answer.text()).toContain('<img src=x onerror=alert(1)>');
        expect(answer.find('img').exists()).toBe(false);
    });

    it("shows the server's own sentence, because each names a different next step", async () => {
        post.mockRejectedValue({
            response: {
                data: {
                    message:
                        'You already have a question being answered. Wait for it to finish.',
                },
            },
        });
        const wrapper = control();

        await ask(wrapper, 'What is a transfer?');

        expect(
            wrapper.find('[data-testid="assistant-error"]').text(),
        ).toContain('already have a question');
    });

    it('does not ask on a question too short to mean anything', async () => {
        const wrapper = control();

        await ask(wrapper, 'a');

        expect(post).not.toHaveBeenCalled();
    });
});
