/**
 * Module-level toast queue. Real-time (W16) is responsible for the
 * throttling/grouping behaviour in docs/specs/ui.md §6 ("Dana made 6
 * changes", not six toasts) — this composable only owns the mechanics of
 * showing, auto-dismissing and manually dismissing a toast.
 */
import { reactive } from 'vue';

export interface ToastMessage {
    id: number;
    tone: 'info' | 'success' | 'danger';
    text: string;
}

let nextId = 1;
const toasts = reactive<ToastMessage[]>([]);

function dismiss(id: number) {
    const index = toasts.findIndex((t) => t.id === id);
    if (index !== -1) toasts.splice(index, 1);
}

function push(
    text: string,
    tone: ToastMessage['tone'] = 'info',
    duration = 5000,
) {
    const id = nextId++;
    toasts.push({ id, tone, text });
    if (duration > 0) {
        window.setTimeout(() => dismiss(id), duration);
    }
    return id;
}

export function useToast() {
    return { toasts, push, dismiss };
}
