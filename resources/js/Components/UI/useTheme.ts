/**
 * Applies the explicit light/dark toggle required by docs/specs/ui.md §2, on
 * top of the `prefers-color-scheme` default handled purely in CSS (see
 * resources/css/app.css). This composable only knows the client-side
 * mechanics: reading/writing localStorage, and toggling the `.dark` /
 * `.light` class on `<html>` that the custom-variant in app.css keys off.
 *
 * ## Three states, not two (W41)
 *
 * `system` is a real choice and the default, not the absence of one: it means
 * "follow the OS", and it is the only value that keeps up when someone's
 * machine switches at sunset. `light` and `dark` pin it. The CSS already
 * encodes exactly this — the media query is scoped `:root:not(.light):not(.dark)`
 * — so `system` is expressed by removing both classes rather than by adding a
 * third.
 *
 * `toggle()` predates the three-way control and is kept for the keyboard
 * shortcut: it flips between light and dark, deliberately never landing on
 * `system`, because a shortcut whose result depends on the time of day is not
 * a shortcut.
 *
 * ## Applied during setup, not on mount (W41)
 *
 * It used to run in `onMounted`, which is after Vue's first paint — so a user
 * who had chosen dark against a light OS saw a white flash on every
 * navigation. Reading storage in the composable body moves it before the
 * first render of anything Vue owns. The remaining gap, between the browser
 * painting the empty document and this module executing, is closed by a
 * nonce'd inline script in `resources/views/app.blade.php`; the two share the
 * storage key through `THEME_STORAGE_KEY` below and must stay in agreement.
 */
import { onMounted, ref, watch } from 'vue';

export type ThemePreference = 'light' | 'dark' | 'system';

/** Also read by the pre-paint script in resources/views/app.blade.php. */
export const THEME_STORAGE_KEY = 'arguesecure.theme';

export function isThemePreference(value: unknown): value is ThemePreference {
    return value === 'light' || value === 'dark' || value === 'system';
}

function applyClass(preference: ThemePreference) {
    if (typeof document === 'undefined') return;

    const root = document.documentElement;
    root.classList.remove('light', 'dark');
    if (preference !== 'system') {
        root.classList.add(preference);
    }
}

function readStored(): ThemePreference {
    if (typeof window === 'undefined') return 'system';

    const stored = window.localStorage.getItem(THEME_STORAGE_KEY);

    return isThemePreference(stored) ? stored : 'system';
}

export function useTheme() {
    const preference = ref<ThemePreference>(readStored());

    applyClass(preference.value);

    // Still re-applied on mount: a second component instance mounting later
    // must not be able to leave the class off if something else stripped it,
    // and this costs one classList write.
    onMounted(() => applyClass(preference.value));

    watch(preference, (value) => {
        applyClass(value);
        window.localStorage.setItem(THEME_STORAGE_KEY, value);
    });

    function setTheme(value: ThemePreference) {
        preference.value = value;
    }

    /** Light <-> dark only; see the header on why this never selects `system`. */
    function toggle() {
        preference.value = preference.value === 'dark' ? 'light' : 'dark';
    }

    return { preference, setTheme, toggle };
}
