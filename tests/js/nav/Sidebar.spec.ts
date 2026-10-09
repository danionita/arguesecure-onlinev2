/**
 * The sidebar's contract with a keyboard and a screen reader (W41).
 *
 * Accessibility is the substance of this package, not a footnote, so most of
 * what is asserted here is names and roles rather than pixels: a rail of
 * unlabelled glyphs is unusable, and a disclosure without `aria-expanded`
 * announces nothing at all.
 *
 * The one non-accessibility assertion — the admin item — is a pair, and the
 * pair is the point: hiding a link is courtesy, `EnsureUserIsAdmin` is the
 * guard, and the middleware test next door asserts the other half so the two
 * are never mistaken for each other.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Sidebar from '../../../resources/js/Components/Nav/Sidebar.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        name: 'Link',
        props: ['href', 'method', 'as'],
        template: '<a :href="href"><slot /></a>',
    },
    usePage: () => ({ props: {}, url: '/dashboard' }),
}));

/**
 * `route()` reaches templates as a Vue *global property* — that is what
 * `ZiggyVue` installs in app.ts — not as a bare global. Setting
 * `globalThis.route` alone leaves the compiled template resolving
 * `_ctx.route`, which is undefined, and every test in this file fails with
 * the same unhelpful TypeError.
 */
const route = (name: string, param?: string | Record<string, unknown>) => {
    if (param === undefined) return `/${name}`;

    // Ziggy renders a plain value as a path segment and an object as a query
    // string. The stub did the first only, so `route('x', { new: 1 })` came
    // back as `/x/[object Object]` — which no assertion about a query
    // parameter can see. Modelling both shapes keeps the stub honest about
    // the one difference the callers actually rely on.
    if (typeof param === 'object') {
        return `/${name}?${new URLSearchParams(
            Object.entries(param).map(([k, v]) => [k, String(v)]),
        ).toString()}`;
    }

    return `/${name}/${param}`;
};

beforeEach(() => {
    window.localStorage.clear();
    (globalThis as unknown as { route: typeof route }).route = route;
});

function render(over: Record<string, unknown> = {}) {
    return mount(Sidebar, {
        global: { config: { globalProperties: { route } } },
        props: {
            myAssessments: [{ uuid: 'a1', title: 'Web shop' }],
            sharedWithMe: [
                { uuid: 'a2', title: 'Payments', ownerName: 'Dana' },
            ],
            isAdmin: false,
            notificationCount: 0,
            railed: false,
            panelVisible: true,
            isOverlay: false,
            currentUrl: '/dashboard',
            ...over,
        },
    });
}

describe('landmarks and names', () => {
    it('is a labelled navigation landmark', () => {
        const nav = render().get('nav');

        expect(nav.attributes('aria-label')).toBe('Main');
    });

    it('marks the current destination without relying on colour', () => {
        const current = render()
            .findAll('a')
            .filter((a) => a.attributes('aria-current') === 'page');

        expect(current).toHaveLength(1);
        expect(current[0].text()).toContain('Dashboard');
    });

    it('names who shared each assessment, since the list is about other people’s work', () => {
        expect(render().text()).toContain('Payments — Dana');
    });
});

describe('the icon rail', () => {
    it('keeps every label as an accessible name when the text is gone', () => {
        const wrapper = render({ railed: true, panelVisible: false });

        const named = wrapper
            .findAll('a, button')
            .map((el) => el.attributes('aria-label'))
            .filter(Boolean);

        // Not an exhaustive list — the claim is that nothing in the rail is
        // an unnamed glyph, which is what a spot-check of the destinations
        // that only exist as icons here establishes.
        expect(named).toEqual(
            expect.arrayContaining([
                'Dashboard',
                'My assessments',
                'Shared with me',
                'Templates',
                'Notifications',
                'Profile',
                'Log out',
            ]),
        );
    });

    it('has no unnamed interactive element at all', () => {
        const wrapper = render({ railed: true, panelVisible: false });

        const unnamed = wrapper.findAll('a, button').filter((el) => {
            const label = el.attributes('aria-label');
            const text = el.text().trim();

            return !label && text === '';
        });

        expect(unnamed).toHaveLength(0);
    });

    it('offers a real button to expand, carrying its state', () => {
        const wrapper = render({ railed: true, panelVisible: false });
        const toggle = wrapper
            .findAll('button')
            .find((b) => b.attributes('aria-label') === 'Open navigation');

        expect(toggle).toBeDefined();
        expect(toggle!.attributes('aria-expanded')).toBe('false');
    });

    it('emits toggle rather than deciding its own width', async () => {
        const wrapper = render({ railed: true, panelVisible: false });
        await wrapper
            .findAll('button')
            .find((b) => b.attributes('aria-label') === 'Open navigation')!
            .trigger('click');

        expect(wrapper.emitted('toggle')).toHaveLength(1);
    });
});

describe('groups are disclosures', () => {
    it('wires aria-expanded and aria-controls to the list it controls', async () => {
        const wrapper = render();
        const toggle = wrapper
            .findAll('button')
            .find((b) =>
                b.attributes('aria-label')?.endsWith('Shared with me'),
            )!;

        expect(toggle.attributes('aria-expanded')).toBe('false');

        // Matched as an attribute, not as a `#id` selector: `useId()` emits
        // values containing `:`, and jsdom has no `CSS.escape` to make them
        // safe in one.
        const controlled = toggle.attributes('aria-controls')!;
        expect(wrapper.find(`[id="${controlled}"]`).exists()).toBe(true);

        await toggle.trigger('click');
        expect(toggle.attributes('aria-expanded')).toBe('true');
    });

    it('separates navigating to the full list from expanding it', () => {
        // Two controls on one row, on purpose: the sidebar shows at most ten
        // of anything, and a row that both navigates and expands is how a
        // user ends up somewhere they did not ask to go.
        const wrapper = render();
        const groupLink = wrapper
            .findAll('a')
            .find((a) => a.text().includes('My assessments'))!;

        expect(groupLink.attributes('href')).toBe('/assessments.index');
    });
});

describe('the admin item', () => {
    it('is absent for a non-admin', () => {
        expect(render().text()).not.toContain('Admin');
    });

    it('is present for an admin', () => {
        expect(render({ isAdmin: true }).text()).toContain('Admin');
    });
});

describe('notifications', () => {
    it('shows the count when there is room for it', () => {
        expect(render({ notificationCount: 3 }).text()).toContain('3');
    });

    it('puts the count in the name when there is not', () => {
        const wrapper = render({
            notificationCount: 3,
            railed: true,
            panelVisible: false,
        });
        // A `<button>` since W47: this item opens the feed rather than going
        // anywhere. Everything else about it — the accessible name, the rail
        // dot — comes from the same NavItem as the destinations around it,
        // which is why that change is invisible to the assertion below.
        const item = wrapper
            .findAll('button')
            .find((el) =>
                el.attributes('aria-label')?.startsWith('Notifications'),
            );

        // A bare dot conveys nothing to a screen reader; the number has to
        // survive the loss of the label.
        expect(item!.attributes('aria-label')).toBe('Notifications, 3 unread');
    });
});

describe('the overlay', () => {
    it('has a backdrop that dismisses it', async () => {
        const wrapper = render({
            railed: true,
            panelVisible: true,
            isOverlay: true,
        });

        // Addressed by testid, not by `[aria-hidden]`: every icon in the
        // sidebar is aria-hidden too, and the looser selector matched the
        // first `<svg>` instead.
        await wrapper.get('[data-testid="nav-backdrop"]').trigger('click');

        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('has no backdrop when docked, where there is nothing to dismiss', () => {
        expect(render().find('[data-testid="nav-backdrop"]').exists()).toBe(
            false,
        );
    });
});

describe('the toggle has one location (W73 item 18)', () => {
    /**
     * The row index of the toggle among the sidebar's own direct children,
     * which is what "same row in both states" actually means. Asserted as a
     * position rather than as "a toggle exists somewhere", because a toggle
     * existing somewhere is exactly what was true before and is the defect.
     */
    const toggleRow = (wrapper: ReturnType<typeof render>) => {
        const rows = Array.from(wrapper.get('nav').element.children);

        return rows.findIndex((row) =>
            row.querySelector('[data-testid="nav-toggle"]'),
        );
    };

    it('sits in the same row whether the panel is open or collapsed', () => {
        // The button that changes the width must not move when the width
        // changes: the click that collapses the panel and the click that
        // expands it again have to be in the same place, and this is the
        // control in the sidebar that gets used most.
        expect(toggleRow(render({ railed: true, panelVisible: false }))).toBe(
            toggleRow(render()),
        );
    });

    it('is in its own row, not sharing the logo’s', () => {
        // W41's reasoning survives W73 intact — two 16px targets side by side
        // in a 48px column is a coin toss — so the fix was to move the
        // expanded control down into the dedicated row, never to merge the
        // collapsed one upward into the logo row.
        for (const state of [{}, { railed: true, panelVisible: false }]) {
            const wrapper = render(state);
            const row = wrapper.get('[data-testid="nav-toggle"]').element
                .parentElement!;

            expect(row.querySelector('a[href="/dashboard"]')).toBeNull();
        }
    });

    it('is the only toggle on the page, at either width', () => {
        for (const state of [{}, { railed: true, panelVisible: false }]) {
            expect(
                render(state).findAll('[data-testid="nav-toggle"]'),
            ).toHaveLength(1);
        }
    });

    it('carries its state and its name at both widths', () => {
        const expanded = render().get('[data-testid="nav-toggle"]');
        expect(expanded.attributes('aria-expanded')).toBe('true');
        // Labelled by visible text when there is room for it, exactly as
        // NavItem is, so the row cannot drift from the destinations under it.
        expect(expanded.text()).toBe('Collapse navigation');

        const railed = render({ railed: true, panelVisible: false }).get(
            '[data-testid="nav-toggle"]',
        );
        expect(railed.attributes('aria-expanded')).toBe('false');
        expect(railed.attributes('aria-label')).toBe('Open navigation');
    });

    it('emits toggle from that one control', async () => {
        const wrapper = render();
        await wrapper.get('[data-testid="nav-toggle"]').trigger('click');

        expect(wrapper.emitted('toggle')).toHaveLength(1);
    });
});

describe('New assessment creates one (W73 item 19)', () => {
    const createControl = (wrapper: ReturnType<typeof render>) =>
        wrapper
            .findAll('a, button')
            .find(
                (el) =>
                    (el.attributes('aria-label') ?? el.text()) ===
                    'New assessment',
            );

    it('opens the creation dialog rather than dropping the user on a list', () => {
        // It used to `href` at `assessments.index` with no query: a control
        // named for an action that navigated to a list, where the user then
        // had to find the real create affordance themselves.
        //
        // W73's first answer was a POST to `assessments.new` that created an
        // untitled assessment outright. That was removed at 2.4 integration:
        // the list page already carries a button reading "New assessment"
        // which asks for a title, so the page ended up with two identically
        // named controls doing different things -- ambiguous on screen and
        // indistinguishable to a screen reader. `?new=1` opens that same
        // dialog, so both controls now mean one thing and there is one write
        // path to `assessments.store`.
        //
        // Asserting the query parameter, not merely that the href points at
        // the index: without `new` this is the pre-W73 control again, and a
        // test that only checked the path would not notice.
        const control = createControl(render())!;
        const href = control.attributes('href') ?? '';

        expect(href).toContain('assessments.index');
        expect(href).toContain('new');
        expect(control.attributes('method')).toBeUndefined();
    });

    it('is inside the My assessments group, below the list it adds to', () => {
        const wrapper = render();

        // The group's disclosure list, addressed through the `aria-controls`
        // its own header button names — the same indirection the disclosure
        // test above uses, and for the same `useId()` reason.
        const listId = wrapper
            .findAll('button')
            .find((b) =>
                b.attributes('aria-label')?.endsWith('My assessments'),
            )!
            .attributes('aria-controls')!;
        const items = wrapper.get(`[id="${listId}"]`).findAll('li');

        expect(items.at(-1)!.text()).toBe('New assessment');
        // Below the list, not above it: it is what you do about the
        // emptiness, and it reads wrong before the thing it adds to.
        expect(items[0].text()).toContain('Web shop');
    });

    it('is still offered in the rail, where the group has no list to sit under', () => {
        // A NavGroup collapses to a bare link when compact and renders no
        // children at all, so the create control returns to the top level
        // rather than disappearing with the titles.
        const control = createControl(
            render({ railed: true, panelVisible: false }),
        );

        expect(control).toBeDefined();
        expect(control!.attributes('aria-label')).toBe('New assessment');
    });

    it('exists exactly once at either width', () => {
        // The two renderings are mutually exclusive — `compact` is precisely
        // the state in which the group renders no children — so this is one
        // control in one place, not a duplicate.
        for (const state of [{}, { railed: true, panelVisible: false }]) {
            const wrapper = render(state);
            const all = wrapper
                .findAll('a, button')
                .filter(
                    (el) =>
                        (el.attributes('aria-label') ?? el.text()) ===
                        'New assessment',
                );

            expect(all).toHaveLength(1);
        }
    });
});

describe('Shared with me points at its own page (W73 item 20, D33)', () => {
    it('sends the group header to the shared page, not the combined list', () => {
        // `/assessments` mixes owned and shared rows and does not
        // distinguish them, so the header was a promise its destination
        // could not keep.
        const header = render()
            .findAll('a')
            .find((a) => a.text().includes('Shared with me'))!;

        expect(header.attributes('href')).toBe('/assessments.shared');
    });

    it('still sends each row into its own assessment', () => {
        const row = render()
            .findAll('a')
            .find((a) => a.text().includes('Payments'))!;

        expect(row.attributes('href')).toBe('/assessments.show/a2');
    });
});

/**
 * W82 item 41: History and Assessment settings left for `AssessmentActions`
 * in the same commit that removed the `currentAssessment` prop this file
 * used to gate them on. Asserted by the *specific routes*, not by the item
 * count falling — a count assertion is a presence assertion inverted, and it
 * is exactly as blind to "something else was added back" as the thing it
 * replaces. The route names below are the two this component would have
 * pointed at, had it still known how (see git history for
 * `assessments.history.index` / `assessments.settings.edit`).
 */
describe('History and Assessment settings are gone (D41)', () => {
    it('renders no link to either route, at any width', () => {
        for (const state of [{}, { railed: true, panelVisible: false }]) {
            const hrefs = render(state)
                .findAll('a')
                .map((a) => a.attributes('href') ?? '');

            expect(
                hrefs.some((href) =>
                    href.includes('assessments.history.index'),
                ),
            ).toBe(false);
            expect(
                hrefs.some((href) =>
                    href.includes('assessments.settings.edit'),
                ),
            ).toBe(false);
        }
    });

    it('no longer accepts a currentAssessment prop to gate them with', () => {
        // Not merely unused — gone. A prop nothing reads is exactly the kind
        // of dead control CLAUDE.md's `ReportOptions::$figures` write-up
        // warns about, and re-adding it without a reader would be the same
        // mistake with an extra step.
        const wrapper = render({
            currentAssessment: { uuid: 'a9', canManage: true },
        } as never);

        expect(wrapper.text()).not.toContain('Assessment settings');
        expect(
            wrapper
                .findAll('a')
                .some((a) => (a.attributes('href') ?? '').includes('a9')),
        ).toBe(false);
    });
});

/** W82 item 18: reachable only by typing the URL since W51. */
describe('Help', () => {
    it('sits immediately above Notifications, in the bottom section', () => {
        // The bottom section (theme, admin, profile, log out) is the nav's
        // last direct child; Help and Notifications are both direct children
        // of it, in DOM order.
        const bottom = Array.from(render().get('nav').element.children).at(-1)!;
        const rows = Array.from(bottom.children);

        const helpRow = rows.findIndex(
            (row) => row.textContent?.trim() === 'Help',
        );
        const notificationsRow = rows.findIndex(
            (row) =>
                row.textContent?.trim().startsWith('Notifications') ?? false,
        );

        expect(helpRow).toBeGreaterThanOrEqual(0);
        expect(notificationsRow).toBe(helpRow + 1);
    });

    it('links to help.index', () => {
        const link = render()
            .findAll('a')
            .find((a) => a.text() === 'Help')!;

        expect(link.attributes('href')).toBe('/help.index');
    });
});

/**
 * W82 item 17: the rail's visible tooltip. `NavItem.vue` owns the mechanism
 * — it wraps itself in the shared `Tooltip` component, and only when
 * `compact` — so this exercises it through a real `Sidebar` render rather
 * than restating the mechanism as a second, disagreeing description of it.
 */
describe('the rail shows a visible tooltip, not only an accessible name', () => {
    it('reveals the label on hover of a compact item', async () => {
        const wrapper = render({ railed: true, panelVisible: false });
        // `mouseenter` does not bubble, so the trigger has to land on
        // `Tooltip`'s own root — the `<span>` its listener is bound to —
        // not on the link inside it.
        const trigger = wrapper
            .findAll('span')
            .find((s) => s.find('[aria-label="Dashboard"]').exists())!;

        expect(document.querySelector('[role="tooltip"]')).toBeNull();

        await trigger.trigger('mouseenter');

        // Beside the rail, not under the icon (2.5.1) — teleported to body.
        const tooltip = document.querySelector('[role="tooltip"]');
        expect(tooltip?.textContent?.trim()).toBe('Dashboard');
        expect(tooltip?.getAttribute('data-placement')).toBe('right');
        await trigger.trigger('mouseleave');
        wrapper.unmount();
    });

    it('shows no tooltip at all in the expanded panel, where the label is already text', async () => {
        const wrapper = render({ panelVisible: true, railed: false });
        const dashboard = wrapper
            .findAll('a')
            .find((a) => a.text().includes('Dashboard'))!;

        // Not wrapped in `Tooltip` at all in this mode — the mechanism
        // itself is absent, not merely quiet.
        expect(dashboard.element.parentElement?.tagName).not.toBe('SPAN');

        await dashboard.trigger('mouseenter');

        expect(wrapper.find('[role="tooltip"]').exists()).toBe(false);
    });
});
