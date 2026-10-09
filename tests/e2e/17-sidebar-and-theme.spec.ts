import { expect, test, type Page } from '@playwright/test';
import { login } from './support/auth';
import { adminEmail, adminPassword } from './support/env';
import { assessmentTitle, mainContent } from './support/tree';

/**
 * Scenario 17 (PLAN-2.2.md W41): the sidebar and the theme.
 *
 * These live here rather than in `tests/js` because every claim W41 makes is
 * either geometric or spans a real navigation, and jsdom has neither a layout
 * engine nor a router. The unit specs guard the *mechanism* — that the
 * reserved gutter is computed from `railed` alone, that the theme class is
 * applied during setup — and this guards the result: that the chart is
 * genuinely the same number of pixels wide before and after the nav expands,
 * and that a chosen theme genuinely survives a full page load.
 */

const nav = (page: Page) => page.getByRole('navigation', { name: 'Main' });

async function openChart(page: Page): Promise<void> {
    await page.goto('/assessments');
    await mainContent(page)
        .getByRole('link', { name: assessmentTitle, exact: true })
        .click();
    await expect(page).toHaveURL(/\/assessments\/[0-9a-f-]{36}$/);
}

test.describe('the sidebar', () => {
    test('every destination is reachable, and named', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/dashboard');

        for (const name of [
            'Dashboard',
            'My assessments',
            'Templates',
            'Profile',
        ]) {
            await expect(
                nav(page).getByRole('link', { name, exact: true }),
            ).toBeVisible();
        }
    });

    test('a skip link precedes it and lands on the content', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/dashboard');

        // First tab stop on the page, before any destination — the whole
        // point being that a keyboard user does not walk the entire nav on
        // every page before reaching what they came for.
        await page.keyboard.press('Tab');
        const focused = page.locator(':focus');
        await expect(focused).toHaveText('Skip to content');

        await focused.press('Enter');
        await expect(page).toHaveURL(/#main-content$/);
    });

    test('the whole nav is walkable by keyboard, in the order it reads', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/dashboard');

        // Walk until the last nav item is reached rather than a fixed number
        // of presses. The nav lists the account's own assessments under two
        // of its groups (W41), so the number of stops before Profile grows
        // with the fixture — a fixed 12 was enough when this was written and
        // stopped being enough as soon as earlier scenarios had created a
        // second assessment.
        const seen: string[] = [];
        for (let i = 0; i < 40; i++) {
            await page.keyboard.press('Tab');
            const label = await page
                .locator(':focus')
                .evaluate(
                    (el) =>
                        el.getAttribute('aria-label') ??
                        el.textContent?.trim() ??
                        '',
                );
            seen.push(label);
            if (label.includes('Profile')) break;
        }

        // Not an exact sequence — the number of assessments in the fixture
        // varies with which scenarios have run. What must hold is that the
        // account controls at the bottom come after the destinations at the
        // top, i.e. that DOM order matches reading order.
        const dashboard = seen.findIndex((l) => l.includes('Dashboard'));
        const profile = seen.findIndex((l) => l.includes('Profile'));
        expect(dashboard).toBeGreaterThanOrEqual(0);
        expect(profile).toBeGreaterThan(dashboard);
    });

    test('it is a rail inside an assessment and docked outside one', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/dashboard');
        const docked = await nav(page).evaluate((el) => el.clientWidth);

        await openChart(page);
        const railed = await nav(page).evaluate((el) => el.clientWidth);

        expect(railed).toBeLessThan(docked);
        expect(railed).toBeLessThanOrEqual(48);
    });

    test('expanding the rail does not change the chart’s width', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openChart(page);

        const canvas = mainContent(page)
            .locator('main, [class*="overflow"]')
            .first();
        const before = await canvas.evaluate((el) => el.clientWidth);

        await nav(page)
            .getByRole('button', { name: 'Open navigation' })
            .click();
        await expect(
            nav(page).getByRole('link', { name: 'Dashboard', exact: true }),
        ).toBeVisible();

        const after = await canvas.evaluate((el) => el.clientWidth);

        // The one assertion D16 exists for. The chart lays out to the width
        // it is given, and someone is usually pointing at it — so the panel
        // floats over the content rather than pushing it.
        expect(after).toBe(before);
    });

    test('leaving the assessment docks it again', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        await openChart(page);
        // `exact`: the brand link at the top is labelled "ArgueSecure —
        // dashboard" and points at the same place, so a substring match
        // resolves to two links.
        await nav(page)
            .getByRole('link', { name: 'Dashboard', exact: true })
            .click();
        await expect(page).toHaveURL(/\/dashboard$/);

        await expect(
            nav(page).getByRole('link', { name: 'Dashboard', exact: true }),
        ).toBeVisible();
        expect(
            await nav(page).evaluate((el) => el.clientWidth),
        ).toBeGreaterThan(48);
    });
});

test.describe('the theme', () => {
    test('sets the class on <html> and survives a full reload', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/dashboard');

        await nav(page).getByRole('radio', { name: 'Dark' }).click();
        await expect(page.locator('html')).toHaveClass(/dark/);

        // A reload, not an in-app visit: the pre-paint script in
        // app.blade.php is the only thing that can apply the class before the
        // browser paints, and an Inertia visit would never exercise it.
        await page.reload();
        await expect(page.locator('html')).toHaveClass(/dark/);
    });

    test('returns to following the OS', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/dashboard');

        await nav(page).getByRole('radio', { name: 'Dark' }).click();
        await nav(page).getByRole('radio', { name: 'System' }).click();

        // "No class" *is* the system state: app.css scopes its
        // prefers-color-scheme block to `:root:not(.light):not(.dark)`.
        await expect(page.locator('html')).not.toHaveClass(/dark/);
        await expect(page.locator('html')).not.toHaveClass(/light/);
    });
});

test.describe('the admin item', () => {
    test('is shown to an admin, who can also reach the page', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/dashboard');

        await nav(page).getByRole('link', { name: 'Admin' }).click();
        await expect(page).toHaveURL(/\/admin\/users$/);
    });
});

test.describe('the toggle has one location (W73 item 18)', () => {
    /**
     * The unit spec asserts the toggle's *row index* among the nav's
     * children, which is the mechanism. This asserts the consequence, and it
     * is the half jsdom cannot make: that the control is in the same place on
     * screen before and after the thing it controls changes.
     *
     * W41 split it in two — `ml-auto` in the logo row when expanded, a
     * dedicated full-width row when railed — for a reason that was sound
     * (two 16px targets in a 48px column is a coin toss) and had a
     * consequence that was not: the button that changes the width moved when
     * the width changed, so the click that expands the panel and the click
     * that collapses it again were never in the same place.
     */
    const toggle = (page: Page) =>
        nav(page).locator('[data-testid="nav-toggle"]');

    test('does not move vertically when the panel opens over the rail', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openChart(page);

        const before = await toggle(page).boundingBox();
        await toggle(page).click();

        // The panel is open: the same control, now labelled by visible text.
        await expect(
            nav(page).getByRole('link', { name: 'Dashboard', exact: true }),
        ).toBeVisible();

        const after = await toggle(page).boundingBox();

        // The row is the same row, so the vertical centre is unchanged. The
        // horizontal extent is not asserted: the panel is 240px and the rail
        // is 48px, and a full-width control in each is correct.
        expect(Math.round(before!.y + before!.height / 2)).toBe(
            Math.round(after!.y + after!.height / 2),
        );
    });

    test('is one control, not two in different places', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        await openChart(page);

        await expect(toggle(page)).toHaveCount(1);
        await toggle(page).click();
        await expect(
            nav(page).getByRole('link', { name: 'Dashboard', exact: true }),
        ).toBeVisible();
        await expect(toggle(page)).toHaveCount(1);
    });

    test('is below the product mark, never beside it', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/dashboard');

        const mark = await nav(page)
            .getByRole('link', { name: 'ArgueSecure', exact: true })
            .boundingBox();
        const control = await toggle(page).boundingBox();

        // Its own row, kept rather than merged upward — W41's reasoning for
        // the dedicated row survives W73 intact.
        expect(control!.y).toBeGreaterThanOrEqual(mark!.y + mark!.height);
    });
});
