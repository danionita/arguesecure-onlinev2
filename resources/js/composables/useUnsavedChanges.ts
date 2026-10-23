import { router } from '@inertiajs/vue3';
import { type MaybeRefOrGetter, onBeforeUnmount, ref, toValue } from 'vue';

/**
 * Unsaved-changes guard (W142, D117).
 *
 * A page or card passes a getter for "has edits that are not saved". While it
 * is true, a user-started GET visit asks first in the app's own dialog
 * (`UnsavedChangesDialog`, mounted once in the layout) and closing or
 * reloading the tab raises the browser's prompt, whose text we cannot set.
 * Form submits are not GET visits, so Save itself is never interrupted.
 */

/** The question on screen, if any. The dialog resolves it. */
const request = ref<((leave: boolean) => void) | null>(null);

/** Each guard's getter and the optional scope it asked to be counted in. */
const dirtyGetters = new Map<() => boolean, string | undefined>();

/** True while a confirmed visit is being replayed past the guard. */
let replaying = false;

export function pendingLeaveRequest() {
    return request;
}

export function answerLeaveRequest(leave: boolean): void {
    const resolve = request.value;
    request.value = null;
    resolve?.(leave);
}

/** With a scope, only guards registered in that scope count. */
export function hasUnsavedChanges(scope?: string): boolean {
    for (const [isDirty, own] of dirtyGetters) {
        if (scope !== undefined && own !== scope) continue;
        if (isDirty()) return true;
    }
    return false;
}

/** Resolves true when the user chooses to discard. */
export function confirmLeave(): Promise<boolean> {
    // A second question while one is open means "stay": the first stays up.
    if (request.value) return Promise.resolve(false);
    return new Promise((resolve) => {
        request.value = resolve;
    });
}

/**
 * One listener pair serves every guard on the page: with several cards each
 * guarding, a visit must raise one question, not one per card.
 */
let installed: (() => void) | null = null;

function install(): () => void {
    const stopBefore = router.on('before', (event) => {
        const visit = event.detail.visit;
        if (replaying || !hasUnsavedChanges() || visit.method !== 'get') return;
        // Background fetches and same-page visits (filters, partial
        // reloads) lose nothing and must not raise a question.
        if (visit.prefetch || visit.async) return;
        if (visit.url.pathname === window.location.pathname) return;
        void confirmLeave().then((leave) => {
            if (!leave) return;
            replaying = true;
            try {
                // The original options ride along: replace, preserveScroll,
                // callbacks and the rest were the caller's, not ours.
                router.visit(visit.url.href, visit as never);
            } finally {
                replaying = false;
            }
        });
        return false;
    });

    function onBeforeUnload(event: BeforeUnloadEvent) {
        if (!hasUnsavedChanges()) return;
        event.preventDefault();
        event.returnValue = '';
    }
    window.addEventListener('beforeunload', onBeforeUnload);

    return () => {
        stopBefore();
        window.removeEventListener('beforeunload', onBeforeUnload);
    };
}

export function useUnsavedChanges(
    dirty: MaybeRefOrGetter<boolean>,
    scope?: string,
): void {
    const isDirty = () => toValue(dirty);
    dirtyGetters.set(isDirty, scope);
    if (dirtyGetters.size === 1) installed = install();

    onBeforeUnmount(() => {
        dirtyGetters.delete(isDirty);
        if (dirtyGetters.size === 0) {
            installed?.();
            installed = null;
        }
    });
}
