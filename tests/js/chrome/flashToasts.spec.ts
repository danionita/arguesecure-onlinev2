/**
 * W115: what a controller flashed is shown once, in the shared chrome.
 */
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import {
    flashToasts,
    resetFlashToastsForTests,
    useFlashToasts,
} from '../../../resources/js/Components/Chrome/flashToasts';
import { useToast } from '../../../resources/js/Components/UI/useToast';

const page = { props: {} as Record<string, unknown> };

vi.mock('@inertiajs/vue3', () => ({ usePage: () => page }));

function fire(flash: Record<string, unknown>) {
    document.dispatchEvent(
        new CustomEvent('inertia:flash', { detail: { flash } }),
    );
}

describe('flashToasts()', () => {
    it('turns a success into a success toast with the exact text', () => {
        expect(flashToasts({ success: 'Version saved.' }, {})).toEqual([
            { text: 'Version saved.', tone: 'success', duration: 5000 },
        ]);
    });

    it('keeps a long message up long enough to read', () => {
        const long = 'x'.repeat(400);

        expect(flashToasts({ success: long }, {})[0].duration).toBe(24000);
    });

    it('keeps an error until it is dismissed', () => {
        expect(flashToasts({ error: 'Could not restore.' }, {})).toEqual([
            { text: 'Could not restore.', tone: 'danger', duration: 0 },
        ]);
    });

    it('leaves a message to the page that shows it itself', () => {
        expect(
            flashToasts({ success: 'Saved.' }, { flashSuccess: 'Saved.' }),
        ).toEqual([]);
    });

    it('ignores other keys, blanks and non-strings', () => {
        expect(
            flashToasts(
                { generatedPassword: 'secret', success: ' ', error: 3 },
                {},
            ),
        ).toEqual([]);
        expect(flashToasts(undefined, undefined)).toEqual([]);
    });
});

describe('useFlashToasts()', () => {
    const Layout = defineComponent({
        setup() {
            useFlashToasts();

            return () => h('div');
        },
    });

    beforeEach(() => {
        useToast().toasts.splice(0);
        page.props = {};
    });
    afterEach(resetFlashToastsForTests);

    it('shows one toast per flash event, however often the layout mounts', () => {
        const layouts = [mount(Layout), mount(Layout), mount(Layout)];

        fire({ success: 'Snapshot created.' });

        const { toasts } = useToast();
        expect(toasts).toHaveLength(1);
        expect(toasts[0].text).toBe('Snapshot created.');
        expect(toasts[0].tone).toBe('success');
        layouts.forEach((l) => l.unmount());
    });

    it('shows nothing when no flash event fires', () => {
        const layout = mount(Layout);

        expect(useToast().toasts).toHaveLength(0);
        layout.unmount();
    });

    it('queues nothing while no layout is mounted, so no stale toast surfaces later', () => {
        mount(Layout).unmount();

        fire({ error: 'Could not restore.' });

        expect(useToast().toasts).toHaveLength(0);
    });
});
