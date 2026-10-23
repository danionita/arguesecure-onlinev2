/**
 * Print once on arrival (W138, D110).
 *
 * The Print menu's *Chart*, *List* and *Report* items print a view the user
 * is not on by visiting it with a one-shot `?print=<token>`. The target page
 * calls this on mount: it prints after the first paint, then never again for
 * that token. The token is unique per click, so choosing Print twice in a row
 * works, while an Inertia back-navigation onto the same URL finds its token
 * already spent and does not reopen the dialog (the same guard the report's
 * download mode keeps).
 */
import { onMounted } from 'vue';

export const PRINT_QUERY_KEY = 'print';

/** A fresh token for one click of the menu. */
export function newPrintToken(): string {
    return String(Date.now());
}

/** `url` with the one-shot print parameter added. */
export function withPrintToken(url: string, token = newPrintToken()): string {
    const [path, query = ''] = url.split('?');
    const params = new URLSearchParams(query);
    params.set(PRINT_QUERY_KEY, token);

    return `${path}?${params.toString()}`;
}

/**
 * Prints if the current URL carries an unspent token. `before` runs just ahead
 * of the dialog (the report shows its "Save as PDF" hint there).
 */
export function usePrintOnArrival(before?: () => void): void {
    onMounted(() => {
        if (typeof window === 'undefined') return;

        const token = new URLSearchParams(window.location.search).get(
            PRINT_QUERY_KEY,
        );
        if (!token) return;

        const key = `arguesecure.print:${window.location.pathname}:${token}`;
        try {
            if (window.sessionStorage.getItem(key)) return;
            window.sessionStorage.setItem(key, '1');
        } catch {
            // Storage can throw (private mode). Printing without the guard
            // beats not printing; a back-navigation may reprompt.
        }

        before?.();
        window.requestAnimationFrame(() =>
            window.setTimeout(() => window.print(), 0),
        );
    });
}
