/**
 * Progress and cancellation for a generation happening somewhere else (W58).
 *
 * The properties worth pinning here are the ones a later tidy-up would remove
 * without noticing what they were for:
 *
 * - **The bar never reads 100% while work is still running.** A full bar next
 *   to an unfinished job is the single most annoying thing a progress
 *   indicator can do, and the denominator is a *budget*, not a prediction.
 * - **Polling reschedules itself after each reply**, rather than running on an
 *   interval. The machine being polled is by construction busy doing
 *   inference; stacking requests on it is the last thing to do.
 * - **A failed poll is not a failed generation.** The work is happening on the
 *   server whether or not this GET succeeded.
 * - **Polling stops when the component goes away**, or the node modal leaks a
 *   timer per open.
 */
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import GenerationProgress from '../../../resources/js/Components/Assistant/GenerationProgress.vue';
import { useGenerationProgress } from '../../../resources/js/composables/useGenerationProgress';

const get = vi.fn();
const del = vi.fn();

beforeEach(() => {
    get.mockReset();
    del.mockReset();
    (window as unknown as { axios: unknown }).axios = { get, delete: del };
});

function progressPanel(props: Record<string, unknown> = {}) {
    return mount(GenerationProgress, {
        props: {
            elapsedLabel: '12s',
            percent: 40,
            tokens: 200,
            expected: 512,
            ...props,
        },
        global: {
            stubs: {
                Button: {
                    template:
                        '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
                    props: ['disabled', 'loading', 'variant', 'size'],
                    emits: ['click'],
                },
            },
        },
    });
}

/** Mounts a throwaway component so the composable gets a real lifecycle. */
function harness(options: { progressUrl: string; cancelUrl: string }) {
    let api: ReturnType<typeof useGenerationProgress> | null = null;

    const wrapper = mount({
        template: '<div />',
        setup() {
            api = useGenerationProgress(options);

            return {};
        },
    });

    return {
        wrapper,
        api: api as unknown as ReturnType<typeof useGenerationProgress>,
    };
}

describe('the progress panel', () => {
    it('leads with elapsed time, because that is the honest number', () => {
        const panel = progressPanel();

        expect(panel.find('[data-testid="generation-elapsed"]').text()).toBe(
            '12s',
        );
        expect(panel.text()).toContain('200 of up to 512');
    });

    it('says a queued job is queued rather than showing it as working', () => {
        // "Queued but no worker has picked it up" and "generating" look
        // identical from a spinner, and the difference is the whole question
        // when somebody is deciding whether to press the button again.
        const panel = progressPanel({ waiting: true });

        expect(panel.find('[data-testid="generation-waiting"]').exists()).toBe(
            true,
        );
    });

    it('offers a stop, and says so while it is stopping', async () => {
        const panel = progressPanel();

        await panel.find('[data-testid="generation-cancel"]').trigger('click');

        expect(panel.emitted('cancel')).toHaveLength(1);

        await panel.setProps({ cancelling: true });
        expect(
            panel.find('[data-testid="generation-cancel"]').text(),
        ).toContain('Stopping');
    });
});

describe('watching a generation', () => {
    it('never fills the bar while the generation is still running', async () => {
        get.mockResolvedValue({
            data: {
                running: true,
                queued: false,
                // Past its budget: the runtime counts frames, and the budget is
                // an upper bound rather than a promise.
                tokens: 700,
                expected_tokens: 512,
                elapsed_seconds: 61,
            },
        });

        const { api, wrapper } = harness({
            progressUrl: '/assistant/generation',
            cancelUrl: '/assistant/generation',
        });

        api.start();
        await flushPromises();

        expect(api.percent.value).toBe(99);
        expect(api.elapsedLabel.value).toBe('1m 1s');

        api.stop();
        wrapper.unmount();
    });

    it('keeps the last known figures when a poll fails', async () => {
        get.mockResolvedValueOnce({
            data: {
                running: true,
                queued: false,
                tokens: 100,
                expected_tokens: 512,
                elapsed_seconds: 10,
            },
        });

        const { api, wrapper } = harness({
            progressUrl: '/assistant/generation',
            cancelUrl: '/assistant/generation',
        });

        api.start();
        await flushPromises();

        get.mockRejectedValueOnce(new Error('network'));
        await flushPromises();

        // A dropped request says nothing about the generation, which is
        // happening on the server either way. Showing zero — or an error —
        // would invite the user to give up on work that is still going.
        expect(api.tokens.value).toBe(100);

        api.stop();
        wrapper.unmount();
    });

    it('probes once without starting a loop', async () => {
        get.mockResolvedValue({
            data: {
                running: false,
                queued: false,
                tokens: 0,
                expected_tokens: 0,
                elapsed_seconds: 0,
            },
        });

        const { api, wrapper } = harness({
            progressUrl: '/assistant/generation',
            cancelUrl: '/assistant/generation',
        });

        expect(await api.probe()).toBe(false);
        expect(get).toHaveBeenCalledTimes(1);

        wrapper.unmount();
    });

    it('stops polling when the component goes away', async () => {
        vi.useFakeTimers();

        get.mockResolvedValue({
            data: {
                running: true,
                queued: false,
                tokens: 1,
                expected_tokens: 512,
                elapsed_seconds: 1,
            },
        });

        const { api, wrapper } = harness({
            progressUrl: '/assistant/generation',
            cancelUrl: '/assistant/generation',
        });

        api.start();
        await flushPromises();

        const calls = get.mock.calls.length;

        // The node card mounts and unmounts constantly as the tree renders; a
        // timer that outlived it would be a leak per open.
        wrapper.unmount();

        await vi.advanceTimersByTimeAsync(10_000);
        await flushPromises();

        expect(get.mock.calls.length).toBe(calls);

        vi.useRealTimers();
    });

    it('sends the cancel to the server rather than only stopping the watching', async () => {
        get.mockResolvedValue({
            data: {
                running: true,
                queued: false,
                tokens: 1,
                expected_tokens: 512,
                elapsed_seconds: 1,
            },
        });
        del.mockResolvedValue({});

        const { api, wrapper } = harness({
            progressUrl: '/assistant/generation',
            cancelUrl: '/assistant/generation/cancel',
        });

        await api.cancel();

        // Closing the browser tab does not stop a generation: llama.cpp keeps
        // generating and the single-flight lock stays held. The DELETE is what
        // actually frees the CPU.
        expect(del).toHaveBeenCalledWith('/assistant/generation/cancel');
        expect(api.cancelling.value).toBe(true);

        wrapper.unmount();
    });
});
