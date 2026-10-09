/**
 * `AssessmentActions` mounted where it actually lives (W82, D40):
 * `Pages/Assessment/Show.vue`'s title row, both view modes.
 *
 * `AssessmentActions.spec.ts` (tests/js/toolbar/) proves the component's own
 * contract in isolation — five actions, in order, gated on `abilities`. That
 * is necessary but not sufficient: a component that is correct in isolation
 * and never mounted is exactly what shipped through this package's first
 * commit, and the brief's own W36 citation is precisely "an affordance
 * cannot leave one surface in one commit and arrive on another in the
 * next." This file is the "arrived" half — it mounts the real page, with
 * its real `store.abilities`, and asserts the bar renders from there, that
 * the old bare Share button is gone (not doubled up alongside the new bar),
 * and that the ability gating still holds at this mount point specifically,
 * not only inside the component's own spec.
 *
 * Every composable `Show.vue` wires up that talks to a socket
 * (`useRealtime`) degrades to a no-op with no Reverb key configured — see
 * that composable's own docblock: "config.key ... no Reverb configured
 * (e.g. component tests) — degrade to no-op." That is what makes mounting
 * the whole page here possible without a real WebSocket, a real Echo
 * connection or a running queue worker.
 */
import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import type { Abilities } from '../../../resources/js/stores/assessmentStore';
import { makeFixtureInit } from '../composables/support/fixture';

const route = (name: string, param?: string | Record<string, unknown>) => {
    if (param === undefined) return `/${name}`;
    if (typeof param === 'object') {
        return `/${name}?${new URLSearchParams(
            Object.entries(param).map(([k, v]) => [k, String(v)]),
        ).toString()}`;
    }
    return `/${name}/${param}`;
};

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div><slot /></div>' },
    Link: {
        name: 'Link',
        props: ['href', 'method', 'as'],
        template: '<a :href="href"><slot /></a>',
    },
    usePage: () => ({
        props: {
            auth: { user: { id: 1, name: 'Dana' } },
            // Empty key: useRealtime's own documented no-op path.
            reverb: { key: '', host: null, port: null, scheme: null },
        },
        url: '/assessments/assessment-uuid',
    }),
    router: {
        get: vi.fn(),
        post: vi.fn(),
        reload: vi.fn(),
        visit: vi.fn(),
    },
}));

// eslint-disable-next-line @typescript-eslint/no-explicit-any
let Show: any;

beforeAll(async () => {
    (globalThis as unknown as { route: typeof route }).route = route;
    (globalThis as unknown as { matchMedia: unknown }).matchMedia = () => ({
        matches: false,
        addEventListener: () => {},
        removeEventListener: () => {},
    });
    // window.axios exists in the real app (bootstrap.ts); nothing this
    // suite triggers calls it, but createAssessmentStore's autosave wiring
    // reads it defensively at construction in some paths, so a harmless
    // stand-in keeps a mount from throwing on an undefined global.
    (window as unknown as { axios: unknown }).axios = {
        post: vi.fn().mockResolvedValue({ data: {} }),
        patch: vi.fn().mockResolvedValue({ data: {} }),
        get: vi.fn().mockResolvedValue({ data: {} }),
    };

    // Imported once, here, after the mocks above are registered — Show.vue's
    // own module-scope imports (Inertia, the store) must see the mocked
    // '@inertiajs/vue3', which vi.mock's hoisting guarantees for the static
    // imports above but not for a dynamic one, so this keeps the two in the
    // order that actually matters: mocks registered, then the page module
    // evaluated. Cached rather than re-imported per test: a fresh import per
    // mount was paying Vite's transform cost for the whole page graph
    // (chart, tree, node modal, share dialog and everything under them)
    // four times over.
    //
    // The explicit 60s timeout below is the other half of the same fix:
    // even cached, that first transform is slow enough to blow past
    // Vitest's 5s default hook timeout when the whole suite's ~70 files are
    // transforming under contention in one `vitest run` — this file passed
    // every time alone and failed intermittently only inside the full run.
    // 20s still wasn't always enough under heavy contention; 60s is a
    // generous ceiling rather than a tuned minimum, on the theory that a
    // slow pass beats a flaky one.
    ({ default: Show } =
        await import('../../../resources/js/Pages/Assessment/Show.vue'));
}, 60000);

function mountShow(abilities: Partial<Abilities> = {}) {
    const init = makeFixtureInit();

    return mount(Show, {
        props: {
            ...init,
            scaleLevels: [],
            scales: [],
            assistantAvailable: false,
            abilities: { ...init.abilities, ...abilities },
        },
        global: {
            config: { globalProperties: { route } },
            stubs: {
                // Real: ChromeTitleRow, AssessmentActions, ExportMenu,
                // Toolbar and its children — everything this file asserts
                // on. Stubbed: everything else that would need its own
                // heavy fixture (the canvas, the modal, the drag dialog,
                // the share dialog's own axios calls) and is not this
                // file's concern. `AuthenticatedLayout` gets a *template*
                // stub rather than the bare `true` auto-stub: Vue Test
                // Utils' auto-stubs do not render slot content by default,
                // and `#header` — the slot ChromeTitleRow and
                // AssessmentActions arrive through — has to render for this
                // file to assert on anything.
                AuthenticatedLayout: {
                    template: '<div><slot name="header" /><slot /></div>',
                },
                ChartView: true,
                TreeNode: true,
                NodeModal: true,
                ReparentDialog: true,
                ShortcutsOverlay: true,
                ShareDialog: true,
                PresenceAvatars: true,
                teleport: true,
            },
        },
    });
}

describe('the action bar is mounted, not merely built (W82, D40/D41)', () => {
    it('renders AssessmentActions in the title row, for a manager, in both view modes', async () => {
        const wrapper = mountShow({ manage: true });
        const assertBarComplete = () => {
            const bar = wrapper.find('[data-testid="assessment-actions"]');
            expect(bar.exists()).toBe(true);
            expect(bar.text()).toContain('Settings');
            expect(bar.text()).toContain('History');
            expect(bar.text()).toContain('Share');
            expect(bar.text()).toContain('Export');
            expect(bar.text()).toContain('Print');
        };

        assertBarComplete(); // chart mode, the default

        // The bar does not depend on which view is active — reached the
        // same way a reader would, through the toolbar's own switcher,
        // rather than by poking internal state.
        await wrapper
            .findAll('[role="radio"], button')
            .find((el) => el.text() === 'List')
            ?.trigger('click');
        await wrapper.vm.$nextTick();

        assertBarComplete(); // list mode
    });

    it('carries no second, duplicated Share control now that the bar has one', () => {
        const wrapper = mountShow({ manage: true });

        // Exactly one control named "Share" — the one inside
        // `AssessmentActions` — not that one plus the bare Button the title
        // row used to render directly.
        const shareControls = wrapper
            .findAll('button, a')
            .filter((el) => el.text() === 'Share');

        expect(shareControls).toHaveLength(1);
    });

    it('opens the page-level ShareDialog when the bar emits share', async () => {
        const wrapper = mountShow({ manage: true });

        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'Share')!
            .trigger('click');

        const dialog = wrapper.findComponent({ name: 'ShareDialog' });
        expect(dialog.exists()).toBe(true);
        expect(dialog.props('show')).toBe(true);
    });

    /**
     * The mutation-tested guard, at this mount point specifically — not a
     * restatement of `AssessmentActions.spec.ts`'s own gating test, which
     * proves the component is correct in isolation and says nothing about
     * whether `Show.vue` actually passes it real abilities. Neutering
     * `store.abilities` at the mount (see the W82 report) turns this red;
     * restored afterwards.
     */
    it('hides Settings and Share from a viewer, here, with the page-level store.abilities', () => {
        const wrapper = mountShow({ manage: false });
        const bar = wrapper.get('[data-testid="assessment-actions"]');

        expect(bar.text()).not.toContain('Settings');
        expect(bar.text()).not.toContain('Share');
        expect(bar.text()).toContain('History');
        expect(bar.text()).toContain('Export');
        expect(bar.text()).toContain('Print');
    });
});
