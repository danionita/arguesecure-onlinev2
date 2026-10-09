import { expect, test, type Page } from '@playwright/test';
import { login } from './support/auth';
import { adminEmail, adminPassword } from './support/env';
import {
    assessmentTitle,
    mainContent,
    openAssessment,
    useListView,
} from './support/tree';

/**
 * Scenario 16 (PLAN-2.2.md W34): the top bar, the width it leaves behind, and
 * the two things zoom now means.
 *
 * These assertions are here rather than in `tests/js` because every one of
 * them is a geometric fact and jsdom has no layout engine — `offsetTop` is 0
 * for everything, `clientWidth` is 0, and a bar that wrapped onto three rows
 * would look identical to one that did not. The unit spec guards the CSS
 * classes that permit wrapping; this guards the result.
 */

/** Opens the assessment without the helper's switch to the list view. */
async function openChart(page: Page): Promise<string> {
    await page.goto('/assessments');
    await mainContent(page)
        .getByRole('link', { name: assessmentTitle, exact: true })
        .click();
    await expect(page).toHaveURL(/\/assessments\/[0-9a-f-]{36}$/);
    await expect(page.getByRole('tree')).toBeVisible();

    return new URL(page.url()).pathname.split('/').pop()!;
}

test.describe('the top bar', () => {
    test('holds every control on one row at 1280px', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        const row = page.getByTestId('toolbar-row');
        await expect(row).toBeVisible();

        // Every direct child shares a vertical centre. A wrapped row is the
        // failure this catches, and it is invisible any other way: the bar
        // still renders, still works, and simply costs the chart view a
        // description line.
        //
        // Centres rather than top edges: the row is `items-center`, so
        // controls of different heights — the search box is taller than the
        // filter button — legitimately start at different offsets while
        // sitting on one row. Comparing tops reported three rows on a bar
        // that has exactly one, which is a test that fails whenever the
        // design is right.
        const centres = await row.evaluate((el) =>
            Array.from(el.children).map((child) => {
                const box = child.getBoundingClientRect();

                return Math.round(box.top + box.height / 2);
            }),
        );

        expect(centres.length).toBeGreaterThan(1);
        expect(new Set(centres).size).toBe(1);
    });

    test('carries no baseline selector', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        await expect(page.getByRole('radio', { name: 'Inherent' })).toHaveCount(
            0,
        );
        await expect(page.getByRole('radio', { name: 'Target' })).toHaveCount(
            0,
        );
    });

    test('names the filter "Missing values", and it reaches an unset strength', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        await page.getByRole('button', { name: /Filters/ }).click();

        const toggle = page.getByLabel('Missing values', { exact: true });
        await expect(toggle).toBeVisible();
        await toggle.click();

        // The chip on the bar is what tells a user a filter is on without
        // reopening the panel — ui.md §5's "shows what is active".
        await expect(page.getByLabel('Active filters')).toContainText(
            'Missing values',
        );
    });
});

/**
 * W82 (D40, D41, D42): the action bar this whole file is named after was
 * built and tested in isolation first, and mounted into `Show.vue` in a
 * follow-up fix round the same day — the ownership rule that produced that
 * split is `docs/PLAN-2.5/task-W82-brief.md`'s own citation of W36: an
 * affordance may not leave one surface in one commit and arrive on another
 * in the next. These are the geometric/real-browser claims `tests/js`
 * cannot make: that the five actions are actually on screen together, that
 * History and Settings are reachable *only* from the bar now that they have
 * left the sidebar, and that a real print renders the canvas without the
 * chrome around it.
 */
test.describe('the action bar (W82, D40/D41/D42)', () => {
    test('renders Settings, History, Share, Export and Print together, in order', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        const bar = page.getByTestId('assessment-actions');
        await expect(bar).toBeVisible();

        const labels = ['Settings', 'History', 'Share', 'Export', 'Print'];
        const names = await bar
            .locator('button, a')
            .evaluateAll((els) =>
                els.map((el) => el.textContent?.trim() ?? ''),
            );

        // Present, and in D40's order — not merely "somewhere in the row".
        const positions = labels.map((label) =>
            names.findIndex((name) => name === label),
        );
        expect(positions.every((i) => i >= 0)).toBe(true);
        expect(positions).toEqual([...positions].sort((a, b) => a - b));
    });

    test('History and Settings are reachable from the bar, and not from the sidebar', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        const sidebar = page.getByRole('navigation', { name: 'Main' });
        await expect(
            sidebar.getByRole('link', { name: 'History' }),
        ).toHaveCount(0);
        await expect(
            sidebar.getByRole('link', { name: 'Assessment settings' }),
        ).toHaveCount(0);

        const bar = page.getByTestId('assessment-actions');
        await expect(bar.getByRole('link', { name: 'History' })).toBeVisible();
        await expect(bar.getByRole('link', { name: 'Settings' })).toBeVisible();

        await bar.getByRole('link', { name: 'History' }).click();
        await expect(page).toHaveURL(/\/history$/);
    });

    test('printing the editor hides the bar and the sidebar, and shows the canvas', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        await page.emulateMedia({ media: 'print' });

        // The specified rule (`.no-print { display: none !important }`),
        // not merely that a class name appears somewhere — this is exactly
        // the trap the W82 brief names, so the assertion is on what the
        // browser actually computes under the real stylesheet.
        await expect(page.getByTestId('assessment-actions')).toBeHidden();
        await expect(
            page.getByRole('navigation', { name: 'Main' }),
        ).toBeHidden();
        await expect(page.getByRole('tree')).toBeVisible();

        await page.emulateMedia({ media: 'screen' });
    });
});

test.describe('the editor width', () => {
    test('uses the whole window rather than a centred 1280px column', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 1920, height: 1080 });
        await login(page, adminEmail, adminPassword());
        await openChart(page);

        const barWidth = await page
            .getByTestId('toolbar-row')
            .evaluate((el) => el.getBoundingClientRect().width);

        // `max-w-7xl` capped this at 1280 and letterboxed the chart on
        // exactly the screens the chart view exists for. Allow for the
        // page padding, not for 640px of dead margin.
        expect(barWidth).toBeGreaterThan(1800);
    });
});

test.describe('zoom', () => {
    test('in the list, changes text size and not the list width', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);
        await useListView(page);

        const tree = page.getByRole('tree');
        // The height of a real line of text on a card, not the container's
        // computed font-size. The list zooms with the CSS `zoom` property
        // (D9), which gives the element its own coordinate space rather than
        // rewriting any descendant's font-size — so `getComputedStyle` reads
        // the same 14px before and after while the text on screen has
        // plainly grown, and asserting on it can only ever fail.
        const measure = () =>
            tree.evaluate((el) => ({
                width: Math.round(el.getBoundingClientRect().width),
                lineHeight:
                    el.querySelector('button')?.getBoundingClientRect()
                        .height ?? 0,
            }));

        const before = await measure();

        await page.getByRole('button', { name: 'Increase text size' }).click();
        await page.getByRole('button', { name: 'Increase text size' }).click();

        const after = await measure();

        // The reported bug this test was written for: the list grew past the
        // viewport when zoomed in. That is still the property, and it is the
        // one a reader actually feels — the page must never scroll sideways.
        //
        // What changed in 2.4 (item 8) is the *width claim*. The list is now
        // capped at a reading measure rather than filling its container, and
        // the cap sits on the element carrying CSS `zoom`, so the measure
        // grows with the type deliberately: zooming in is a request for
        // bigger text, and a cap that fought it would shrink the line length
        // in characters, which is the opposite of what the cap is for. So
        // "the width does not change" is no longer true and is not asserted.
        const overflowsHorizontally = await page.evaluate(
            () =>
                document.documentElement.scrollWidth >
                document.documentElement.clientWidth + 1,
        );
        expect(overflowsHorizontally).toBe(false);

        // The text has to actually be bigger, or this is a no-op that happens
        // to satisfy the overflow check.
        expect(before.lineHeight).toBeGreaterThan(0);
        expect(after.lineHeight).toBeGreaterThan(before.lineHeight);
    });

    /**
     * Rewritten for 2.4 item 8, which reverses the claim this test made.
     *
     * It used to assert the list filled its container to the right edge —
     * written against a bug where a CSS transform shrank the list away from
     * it. The list is now capped at a reading measure (`max-w-4xl`), so space
     * to the right of it is *intended*: a full-bleed row at 1280px and wider
     * is a line length nobody reads comfortably.
     *
     * The original bug is still guarded, though, and that distinction is the
     * whole point of this rewrite: a transform-shrunk list gets narrower as
     * you zoom *out*, while a capped list does not move at all until the
     * container is narrower than the cap. So the assertion is that zooming out
     * leaves the rendered width alone rather than pulling it off the cap.
     */
    test('in the list, zooming out holds the reading measure rather than shrinking away from it', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);
        await useListView(page);

        const tree = page.getByRole('tree');

        // Measured in the list's *own* coordinate space, by dividing the
        // rendered width by the CSS `zoom` in effect. That is the number the
        // cap is written in and the number a reading measure is actually
        // about: a line length in characters, which is what stays constant
        // when someone zooms. The rendered width necessarily scales with zoom
        // — that is what `zoom` does, and asserting it did not would be
        // asserting the feature away.
        const read = () =>
            tree.evaluate((el) => {
                const zoom = Number(getComputedStyle(el).zoom) || 1;

                return {
                    zoom,
                    measure: Math.round(
                        el.getBoundingClientRect().width / zoom,
                    ),
                    container: Math.round(
                        el.parentElement!.getBoundingClientRect().width,
                    ),
                };
            });

        const before = await read();

        // The cap only means anything where there is room to exceed it, which
        // is what this test's viewport is set up for.
        expect(before.measure).toBeLessThan(before.container);

        await page.getByRole('button', { name: 'Decrease text size' }).click();
        await page.getByRole('button', { name: 'Decrease text size' }).click();

        const after = await read();

        // Zoom actually changed, or the comparison below is vacuous.
        expect(after.zoom).toBeLessThan(before.zoom);

        // The measure is unchanged: the same line length, in smaller type.
        // The original bug — a transform that shrank the list away from its
        // own measure — would show up here as a smaller number, because a
        // transform scales the box without changing the coordinate space the
        // cap is expressed in.
        //
        // A 1px tolerance, not exact equality (Integration 2.5): `measure` is
        // `Math.round(rect.width / zoom)`, and W84 raised the list's baseline
        // zoom to 1.35 — a divisor that was not in play when this assertion
        // was written and that now produces sub-pixel rounding differences
        // between the two reads. The thing this test exists to catch is the
        // measure *shrinking away* under zoom, which reads as tens of pixels,
        // not one — a tolerance this tight still fails on that and stops
        // failing on rounding noise.
        expect(Math.abs(after.measure - before.measure)).toBeLessThanOrEqual(
            1,
        );
    });

    test('in the chart, scales the picture and lets the reader scroll down to it', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await login(page, adminEmail, adminPassword());
        await openChart(page);

        await expect(
            page.getByRole('group', { name: 'Zoom chart' }),
        ).toBeVisible();

        for (let i = 0; i < 5; i++) {
            await page
                .getByRole('button', { name: 'Increase chart size' })
                .click();
        }

        // W32 pinned `overflow-y-hidden` here, which cropped the defence tier
        // with no way to reach it once zoomed. PLAN-2.2.md D9 reverses that
        // for the zoomed case only.
        const scrollable = await page.getByRole('tree').evaluate((el) => {
            const box = el.closest('.overflow-auto') as HTMLElement | null;
            if (!box) return null;
            return {
                overflowY: getComputedStyle(box).overflowY,
                canScroll: box.scrollHeight > box.clientHeight + 1,
            };
        });

        expect(scrollable).not.toBeNull();
        expect(scrollable!.overflowY).toBe('auto');
        expect(scrollable!.canScroll).toBe(true);
    });
});

/**
 * W84/D43: zoom is persisted per view. Scenario 16 already exercises the
 * list's and the chart's zoom controls separately (the `zoom` describe block
 * above); what is new here is that changing one must leave the other
 * exactly where it was — before D43 there was one shared number, so setting
 * the chart's zoom moved the list's control too.
 */
test.describe('zoom is independent per view (W84/D43)', () => {
    test('zooming the chart does not move the list, and vice versa', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await login(page, adminEmail, adminPassword());
        await openChart(page);

        // Chart starts at 100%.
        await expect(
            page.getByRole('group', { name: 'Zoom chart' }),
        ).toContainText('100%');

        await page.getByRole('button', { name: 'Increase chart size' }).click();
        await page.getByRole('button', { name: 'Increase chart size' }).click();
        await expect(
            page.getByRole('group', { name: 'Zoom chart' }),
        ).toContainText('120%');

        // Switching to the list must show the list's own, untouched 100% —
        // not the chart's 120% carried over.
        await useListView(page);
        await expect(
            page.getByRole('group', { name: 'Zoom text size' }),
        ).toContainText('100%');

        await page.getByRole('button', { name: 'Decrease text size' }).click();
        await expect(
            page.getByRole('group', { name: 'Zoom text size' }),
        ).toContainText('90%');

        // And back on the chart, its 120% is exactly as left — the list's
        // change did not touch it.
        await page.getByRole('radio', { name: 'Chart' }).click();
        await expect(
            page.getByRole('group', { name: 'Zoom chart' }),
        ).toContainText('120%');

        // Leave both views reset for whichever scenario runs next.
        // The reset control's accessible name is its text ("100%"), not its
        // title tooltip, so it is reached as the middle button of the group
        // rather than by a name that does not exist.
        await page
            .getByRole('group', { name: 'Zoom chart' })
            .getByRole('button')
            .nth(1)
            .click();
        await useListView(page);
        await page
            .getByRole('group', { name: 'Zoom text size' })
            .getByRole('button')
            .nth(1)
            .click();
        await page.getByRole('radio', { name: 'Chart' }).click();
    });

    test("surviving a reload: each view keeps its own zoom, not the other view's", async ({
        page,
    }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await login(page, adminEmail, adminPassword());
        const uuid = await openChart(page);

        await page.getByRole('button', { name: 'Increase chart size' }).click();
        await useListView(page);
        await page.getByRole('button', { name: 'Decrease text size' }).click();
        await page.getByRole('button', { name: 'Decrease text size' }).click();

        await page.goto(`/assessments/${uuid}`);
        await useListView(page);
        await expect(
            page.getByRole('group', { name: 'Zoom text size' }),
        ).toContainText('80%');

        await page.getByRole('radio', { name: 'Chart' }).click();
        await expect(
            page.getByRole('group', { name: 'Zoom chart' }),
        ).toContainText('110%');

        // Leave both views reset for whichever scenario runs next.
        // The reset control's accessible name is its text ("100%"), not its
        // title tooltip, so it is reached as the middle button of the group
        // rather than by a name that does not exist.
        await page
            .getByRole('group', { name: 'Zoom chart' })
            .getByRole('button')
            .nth(1)
            .click();
        await useListView(page);
        await page
            .getByRole('group', { name: 'Zoom text size' })
            .getByRole('button')
            .nth(1)
            .click();
        await page.getByRole('radio', { name: 'Chart' }).click();
    });
});

/**
 * W84, items 11/12: the list always centres in its (capped) viewport; the
 * chart centres only when it is narrower than the page and left-aligns when
 * it is wider, so a chart wider than the window never starts with its root
 * off-screen. Both are asserted at two viewport widths — one where the
 * painted content is narrower than the container and one where it is wider
 * — because "centred" and "left-aligned" are relative claims and a single
 * width can only ever demonstrate one of them.
 *
 * The chart's own width is controlled with the zoom buttons rather than
 * relying on the fixture assessment happening to be a particular size at a
 * particular viewport: zooming out several steps guarantees the painted
 * chart is well inside any reasonable viewport, and zooming in several steps
 * from a narrow viewport guarantees the reverse, regardless of how many
 * risks the shared assessment currently has.
 */
test.describe('centring (W84, items 11/12)', () => {
    /** Left/right gap, in the *container's* coordinate space, for `el`. */
    async function gaps(el: import('@playwright/test').Locator) {
        return el.evaluate((node) => {
            const container = node.parentElement!;
            const nodeBox = node.getBoundingClientRect();
            const containerBox = container.getBoundingClientRect();

            return {
                left: Math.round(nodeBox.left - containerBox.left),
                right: Math.round(containerBox.right - nodeBox.right),
            };
        });
    }

    /**
     * The chart's sizer — the element ChartView.vue puts `mx-auto` on —
     * scoped as an ancestor of the tree rather than by a bare page-wide
     * `.mx-auto` locator. A bare locator is safe only as long as no other
     * `.mx-auto` element is on the page at the same time (it happens not to
     * be, today: the list's own `mx-auto` is a sibling view, never mounted
     * alongside the chart, and Modal.vue's is behind `v-if="show"`), and it
     * would turn into a strict-mode violation the moment that stopped being
     * true. The exact-token xpath match (padded with spaces) avoids a class
     * name that merely contains "mx-auto" as a substring matching too.
     */
    function chartSizer(page: import('@playwright/test').Page) {
        return page
            .getByRole('tree')
            .locator(
                'xpath=ancestor::div[contains(concat(" ", normalize-space(@class), " "), " mx-auto ")]',
            )
            .first();
    }

    test('the chart centres when it is narrower than the page', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 1900, height: 900 });
        await login(page, adminEmail, adminPassword());
        await openChart(page);

        // Zoom out well below fit-to-width, so the painted chart is
        // comfortably narrower than a 1900px viewport regardless of the
        // fixture's current size.
        for (let i = 0; i < 4; i++) {
            await page
                .getByRole('button', { name: 'Decrease chart size' })
                .click();
        }

        const sizer = chartSizer(page);
        await expect(sizer).toBeVisible();
        const { left, right } = await gaps(sizer);

        // Both gaps positive (it is not flush against either edge) and
        // roughly equal — `margin: auto` on both sides is exact in a
        // non-scrolled container, but rounding and scrollbar width earn a
        // few pixels of tolerance.
        expect(left).toBeGreaterThan(0);
        expect(Math.abs(left - right)).toBeLessThanOrEqual(4);

        // The reset control's accessible name is its text ("100%"), not its
        // title tooltip, so it is reached as the middle button of the group
        // rather than by a name that does not exist.
        await page
            .getByRole('group', { name: 'Zoom chart' })
            .getByRole('button')
            .nth(1)
            .click();
    });

    test('the chart left-aligns when it is wider than the page, so the root stays reachable', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 900, height: 800 });
        await login(page, adminEmail, adminPassword());
        await openChart(page);

        // Zoom in well past fit-to-width, so the painted chart is
        // comfortably wider than a 900px viewport.
        for (let i = 0; i < 6; i++) {
            await page
                .getByRole('button', { name: 'Increase chart size' })
                .click();
        }

        const sizer = chartSizer(page);
        await expect(sizer).toBeVisible();
        const { left } = await gaps(sizer);

        // Left-aligned: CSS collapses the auto margin to 0 once the content
        // exceeds the container, so the root (top-left corner of the sizer)
        // starts at the scroll origin rather than being pushed off-screen to
        // the left by a centred but overflowing box.
        expect(left).toBe(0);

        // The reset control's accessible name is its text ("100%"), not its
        // title tooltip, so it is reached as the middle button of the group
        // rather than by a name that does not exist.
        await page
            .getByRole('group', { name: 'Zoom chart' })
            .getByRole('button')
            .nth(1)
            .click();
    });

    test('the list centres in its viewport once the container is wider than the reading-measure cap', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 1600, height: 900 });
        await login(page, adminEmail, adminPassword());
        await openAssessment(page); // openAssessment already switches to the list

        const list = page.getByRole('tree');
        await expect(list).toBeVisible();
        const { left, right } = await gaps(list);

        expect(left).toBeGreaterThan(0);
        expect(Math.abs(left - right)).toBeLessThanOrEqual(4);
    });

    test('the list sits flush left once the viewport is narrower than the cap, rather than clipping on both sides', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 700, height: 800 });
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        const list = page.getByRole('tree');
        await expect(list).toBeVisible();
        const { left } = await gaps(list);

        expect(left).toBe(0);
    });
});
