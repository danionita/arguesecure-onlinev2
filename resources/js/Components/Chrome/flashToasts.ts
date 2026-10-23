/**
 * What a controller flashed reaches the person, once (W115).
 *
 * `HandleInertiaRequests::handle()` turns the session's `success` and `error`
 * into Inertia flash data, which arrives with exactly one response and is kept
 * out of browser history. Inertia announces it with one `inertia:flash` event
 * per response that carries it — on the first page load too — so listening to
 * that event, rather than watching a prop, is what makes "once" true: Back,
 * a partial reload and a component re-mount all leave it silent.
 *
 * Shown as a toast because it is the one surface every authenticated page
 * already has (`ToastContainer` in `AuthenticatedLayout`), and its live region
 * announces it politely (ui.md §11). Before this, "Version saved.", the restore
 * summary, a restore that could not run and "Saved as a template." were
 * written and discarded.
 */
import { useToast, type ToastMessage } from '@/Components/UI/useToast';
import { usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';

export interface FlashToast {
    text: string;
    tone: ToastMessage['tone'];
    /** Milliseconds; 0 stays until dismissed. */
    duration: number;
}

/**
 * The default toast is gone in five seconds; a restore summary runs to four
 * sentences. Roughly reading speed, never less than the default.
 */
const MS_PER_CHARACTER = 60;
const MIN_DURATION = 5000;

function text(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

/**
 * The toasts one response's flash data calls for. Pure, so the decisions are
 * testable without a router.
 *
 * Two rules beyond "show it":
 *
 * - **An error stays until dismissed.** It says something did not happen —
 *   a restore refused, and why — and a message that leaves on a timer can be
 *   missed by someone who looked away.
 * - **A page that shows the message itself keeps it.** Settings and the admin
 *   Defaults tab (W87) pass `flashSuccess` as a prop and render it beside the
 *   scale card it is about. Toasting it as well would say it twice, once in
 *   the wrong place.
 */
export function flashToasts(
    flash: Record<string, unknown> | null | undefined,
    pageProps: Record<string, unknown> | null | undefined,
): FlashToast[] {
    const toasts: FlashToast[] = [];

    const success = text(flash?.success);
    if (success !== null && success !== pageProps?.flashSuccess) {
        toasts.push({
            text: success,
            tone: 'success',
            duration: Math.max(MIN_DURATION, success.length * MS_PER_CHARACTER),
        });
    }

    const error = text(flash?.error);
    if (error !== null) {
        toasts.push({ text: error, tone: 'danger', duration: 0 });
    }

    return toasts;
}

let stopListening: (() => void) | null = null;
let mountedLayouts = 0;

/**
 * Called from `AuthenticatedLayout`'s setup. Registers one listener for the
 * life of the app, however many times the layout mounts: the layout re-mounts
 * on most visits, and a listener per mount is a toast per mount.
 *
 * On `document` rather than through `router.on()`, which is a wrapper around
 * the same `addEventListener` — and which every component test that mounts
 * the layout with a mocked `@inertiajs/vue3` would have to grow a `router`
 * for.
 */
export function useFlashToasts(): void {
    onMounted(() => mountedLayouts++);
    onBeforeUnmount(() => (mountedLayouts = Math.max(0, mountedLayouts - 1)));

    if (stopListening !== null || typeof document === 'undefined') {
        return;
    }

    const page = usePage();
    const { push } = useToast();

    const onFlash = (event: Event) => {
        // No layout, no ToastContainer: a toast queued now would surface,
        // undismissed, on some later unrelated page.
        if (mountedLayouts === 0) {
            return;
        }

        const flash = (
            event as CustomEvent<{ flash?: Record<string, unknown> }>
        ).detail?.flash;

        for (const toast of flashToasts(
            flash,
            page.props as Record<string, unknown>,
        )) {
            push(toast.text, toast.tone, toast.duration);
        }
    };

    document.addEventListener('inertia:flash', onFlash);
    stopListening = () =>
        document.removeEventListener('inertia:flash', onFlash);
}

/** Tests only: remove the listener so each test can register its own. */
export function resetFlashToastsForTests(): void {
    stopListening?.();
    stopListening = null;
    mountedLayouts = 0;
}
