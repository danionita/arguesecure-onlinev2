import { computed, onBeforeUnmount, ref } from 'vue';

/**
 * Watching a generation that is running somewhere else (W58).
 *
 * ## Why any of this exists
 *
 * CPU inference is slow enough to change what the interface owes the user: a
 * help answer's first run measured 94 seconds, a suggestion 28, an
 * executive-summary draft 249 — on a machine faster than most deployment
 * hosts. A spinner that says nothing for four minutes is indistinguishable
 * from a hang, and what a person does about an apparent hang is reload or
 * click again, which costs the shared CPU another four minutes.
 *
 * So this polls a progress endpoint and exposes three things a spinner cannot:
 * how long it has been going, roughly how far through it is, and a way to stop
 * it.
 *
 * ## Two shapes, one composable
 *
 * Jobs A and B run inside the caller's own request (`/assistant/generation`);
 * job C runs on a queue and belongs to the assessment. The endpoints differ,
 * the polling does not, so the URLs are arguments.
 *
 * ## The bar is an upper bound, not a prediction
 *
 * `expected_tokens` is the configured generation budget. Most generations stop
 * short of it — a model that has finished its answer stops — so the bar
 * usually jumps to done from somewhere below full. That is why the label reads
 * "up to", and why the elapsed time sits beside it: elapsed is the honest
 * number, the bar is the reassuring one.
 */
export interface GenerationProgress {
    running: boolean;
    queued: boolean;
    tokens: number;
    expected_tokens: number;
    elapsed_seconds: number;
}

/** Every 2s. Fast enough to feel live, slow enough that a four-minute job is 120 requests, not 1,200. */
const POLL_MS = 2000;

export function useGenerationProgress(options: {
    progressUrl: string;
    cancelUrl: string;
}) {
    const tokens = ref(0);
    const expected = ref(0);
    const elapsed = ref(0);
    const queued = ref(false);
    const running = ref(false);
    const cancelling = ref(false);

    let timer: ReturnType<typeof setTimeout> | null = null;
    /** Whether this watcher is meant to be watching. Separate from `timer`, which is only ever the *next* tick. */
    let watching = false;

    const percent = computed(() => {
        if (expected.value <= 0) return 0;

        // Capped at 99 while it is still running: a bar that reads 100% next
        // to a job that has not finished is the single most annoying thing a
        // progress indicator can do.
        return Math.min(99, Math.round((tokens.value / expected.value) * 100));
    });

    /** "2m 14s" — minutes only once there are any, because "0m 3s" reads as broken. */
    const elapsedLabel = computed(() => {
        const seconds = elapsed.value % 60;
        const minutes = Math.floor(elapsed.value / 60);

        return minutes > 0 ? `${minutes}m ${seconds}s` : `${seconds}s`;
    });

    async function poll() {
        if (!watching) return;

        try {
            const { data } = await window.axios.get<GenerationProgress>(
                options.progressUrl,
            );

            running.value = data.running;
            queued.value = data.queued ?? false;
            tokens.value = data.tokens ?? 0;
            expected.value = data.expected_tokens ?? 0;
            elapsed.value = data.elapsed_seconds ?? 0;
        } catch {
            // A failed poll is not a failed generation, and it must never be
            // shown as one: the generation is happening on the server whether
            // or not this GET succeeded. Keep the last known figures and try
            // again on the next tick.
        }

        // Rescheduled after the response rather than on an interval, so a slow
        // reply cannot stack requests on a machine that is, by construction,
        // already busy doing inference.
        if (watching) timer = setTimeout(poll, POLL_MS);
    }

    /**
     * One look, no loop: is something running right now?
     *
     * For a page that loads while a generation it did not start is already in
     * flight — a refresh during a four-minute draft, or a collaborator opening
     * the report — where beginning to poll unconditionally would leave every
     * idle page making a request every two seconds forever.
     */
    async function probe(): Promise<boolean> {
        try {
            const { data } = await window.axios.get<GenerationProgress>(
                options.progressUrl,
            );

            return data.running || data.queued;
        } catch {
            return false;
        }
    }

    function start() {
        stop();
        tokens.value = 0;
        elapsed.value = 0;
        cancelling.value = false;
        watching = true;
        // Asked immediately rather than after the first interval: a queued
        // draft is queued from the moment the button is pressed, and two
        // seconds of showing nothing is exactly the gap this exists to close.
        void poll();
    }

    function stop() {
        watching = false;
        if (timer !== null) clearTimeout(timer);
        timer = null;
        running.value = false;
        queued.value = false;
    }

    async function cancel() {
        cancelling.value = true;

        try {
            await window.axios.delete(options.cancelUrl);
        } catch {
            // Same reasoning as a failed poll. The user pressed Stop; telling
            // them the Stop failed invites them to press it again, which does
            // nothing useful. The generation's own timeout is the backstop.
        }
    }

    // Polling that outlives the component would keep a timer alive for a page
    // the user has left, and on a page that mounts and unmounts often — the
    // node modal — that is a leak per open.
    onBeforeUnmount(stop);

    return {
        tokens,
        expected,
        elapsed,
        elapsedLabel,
        percent,
        queued,
        running,
        cancelling,
        probe,
        start,
        stop,
        cancel,
    };
}
