/**
 * The theme control (W41, item 4).
 *
 * `system` is the reason this is three options rather than a switch: it means
 * "follow the OS", it is the default, and a binary toggle pins the theme the
 * first time it is touched with no way back. The tests below are mostly about
 * that third state surviving.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';
import ThemeControl from '../../../resources/js/Components/Nav/ThemeControl.vue';
import {
    THEME_STORAGE_KEY,
    useTheme,
} from '../../../resources/js/Components/UI/useTheme';

beforeEach(() => {
    window.localStorage.clear();
    document.documentElement.classList.remove('light', 'dark');
});

function radios(wrapper: ReturnType<typeof mount>) {
    return wrapper.findAll('[role="radio"]');
}

describe('the docked control', () => {
    it('is a radiogroup, so the three options are heard as one choice', () => {
        const wrapper = mount(ThemeControl);

        expect(
            wrapper.get('[role="radiogroup"]').attributes('aria-label'),
        ).toBe('Theme');
        expect(radios(wrapper).map((r) => r.attributes('aria-label'))).toEqual([
            'Light',
            'Dark',
            'System',
        ]);
    });

    it('starts on System with neither class set, which is what follows the OS', () => {
        const wrapper = mount(ThemeControl);

        expect(
            radios(wrapper)
                .find((r) => r.attributes('aria-checked') === 'true')!
                .attributes('aria-label'),
        ).toBe('System');
        // app.css scopes its prefers-color-scheme block to
        // `:root:not(.light):not(.dark)`, so "no class" *is* the system state.
        expect(document.documentElement.classList.contains('light')).toBe(
            false,
        );
        expect(document.documentElement.classList.contains('dark')).toBe(false);
    });

    it('puts the class on <html> and stores the choice', async () => {
        const wrapper = mount(ThemeControl);
        await radios(wrapper)[1].trigger('click');

        expect(document.documentElement.classList.contains('dark')).toBe(true);
        expect(window.localStorage.getItem(THEME_STORAGE_KEY)).toBe('dark');
    });

    it('takes both classes off again when the user returns to System', async () => {
        const wrapper = mount(ThemeControl);
        await radios(wrapper)[0].trigger('click');
        expect(document.documentElement.classList.contains('light')).toBe(true);

        await radios(wrapper)[2].trigger('click');
        expect(document.documentElement.classList.contains('light')).toBe(
            false,
        );
        expect(document.documentElement.classList.contains('dark')).toBe(false);
        expect(window.localStorage.getItem(THEME_STORAGE_KEY)).toBe('system');
    });

    it('applies a stored choice at setup, before anything mounts', () => {
        window.localStorage.setItem(THEME_STORAGE_KEY, 'dark');

        // Called directly, outside a component, so `onMounted` never runs.
        // Mounting a component would prove nothing: the mount lifecycle fires
        // the onMounted fallback too, and a first version of this test passed
        // happily with the setup-time call deleted. This is the difference
        // between "the class ends up set" and "the class is set before Vue's
        // first paint", which is the whole reason the call moved.
        useTheme();

        expect(document.documentElement.classList.contains('dark')).toBe(true);
    });
});

describe('the rail', () => {
    it('collapses to one button that says which theme is active', () => {
        const wrapper = mount(ThemeControl, { props: { compact: true } });

        expect(radios(wrapper)).toHaveLength(0);
        expect(wrapper.get('button').attributes('aria-label')).toBe(
            'Theme: System. Change theme.',
        );
    });

    it('cycles through all three, so System stays reachable', async () => {
        const wrapper = mount(ThemeControl, { props: { compact: true } });
        const seen: string[] = [];

        for (let i = 0; i < 3; i++) {
            await wrapper.get('button').trigger('click');
            seen.push(window.localStorage.getItem(THEME_STORAGE_KEY)!);
        }

        // From System, the cycle wraps to the first option and runs the same
        // order the docked row shows. A two-state toggle here would strand
        // anyone using the rail: no way back to following the OS.
        expect(seen).toEqual(['light', 'dark', 'system']);
    });
});
