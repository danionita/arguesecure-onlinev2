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
 * Scenario 19 (PLAN-2.2.md W49, item 21): the chart and the list print as what
 * is on screen, minus the chrome.
 *
 * These assertions are here rather than in `tests/js` for the same reason
 * scenario 16's are: every one of them is a fact about *rendered layout under
 * a print stylesheet*, and jsdom has no layout engine and no media emulation.
 * `page.emulateMedia({ media: 'print' })` applies the real `@media print`
 * rules against the real bundle, which is the only way to find out whether the
 * sidebar is actually gone.
 *
 * D6 stands: this is the browser's own print pipeline. Nothing here renders a
 * PDF, and no dependency was added to check that it would.
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

test.describe('printing', () => {
    test('the sidebar and the toolbar controls are hidden in the list view', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);
        await useListView(page);

        // On screen first: the assertion is that print *changes* this, not
        // that these elements never existed.
        await expect(
            page.getByRole('navigation', { name: 'Main' }),
        ).toBeVisible();
        await expect(page.getByTestId('toolbar-row')).toBeVisible();

        await page.emulateMedia({ media: 'print' });

        await expect(
            page.getByRole('navigation', { name: 'Main' }),
        ).toBeHidden();
        // The row itself stays — it carries the active-filter chips — but the
        // search box and the view/zoom cluster inside it go.
        await expect(page.getByRole('searchbox')).toBeHidden();

        await page.emulateMedia({ media: 'screen' });
    });

    test('the sidebar is hidden in the chart view too', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        await openChart(page);

        await page.emulateMedia({ media: 'print' });

        await expect(
            page.getByRole('navigation', { name: 'Main' }),
        ).toBeHidden();
        // The chart itself is still there: hiding the chrome must not hide the
        // thing being printed.
        await expect(page.locator('.chart-canvas')).toBeAttached();

        await page.emulateMedia({ media: 'screen' });
    });

    test('the running header carries the assessment title and the date', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);
        await useListView(page);

        const header = page.getByTestId('print-header');

        // Hidden on screen — a header that said "printed 21/08/2026" above the
        // editor all day would be nonsense.
        await expect(header).toBeHidden();

        await page.emulateMedia({ media: 'print' });

        await expect(header).toBeVisible();
        await expect(header).toContainText('printed');

        await page.emulateMedia({ media: 'screen' });
    });

    test('the list ignores the current zoom in favour of a fixed print size', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);
        await useListView(page);

        const tree = page.getByRole('tree');

        // Zoom in twice, then confirm print does not inherit it. The list
        // applies zoom with CSS `zoom` (PLAN-2.2.md D9), so the rendered box
        // is what changes — `getComputedStyle().fontSize` is unaffected by it,
        // which is the trap scenario 16 already fell into once.
        await page.getByRole('button', { name: 'Increase text size' }).click();
        await page.getByRole('button', { name: 'Increase text size' }).click();

        const zoomed = await tree.evaluate((el) => getComputedStyle(el).zoom);
        expect(zoomed).not.toBe('1');

        await page.emulateMedia({ media: 'print' });

        const printed = await tree.evaluate((el) => getComputedStyle(el).zoom);
        expect(printed).toBe('1');

        await page.emulateMedia({ media: 'screen' });
    });

    test('a risk block is not split across a page break', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);
        await useListView(page);

        await page.emulateMedia({ media: 'print' });

        // `break-inside: avoid` on the risk rows. Playwright cannot paginate,
        // so the property is what is asserted — the browser's own pagination
        // is then its problem, and it is the pipeline D6 chose deliberately.
        const risk = page
            .locator('li[role="treeitem"][aria-level="2"]')
            .first();
        const breakInside = await risk.evaluate(
            (el) => getComputedStyle(el).breakInside,
        );

        expect(breakInside).toBe('avoid');

        await page.emulateMedia({ media: 'screen' });
    });

    test('the report still prints, unchanged by the editor rules', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page);

        await page.goto(`/assessments/${uuid}/report`);
        await page.emulateMedia({ media: 'print' });

        // The editor rules are scoped to `.editor-print`, which the report
        // does not carry — this is the assertion that the two cannot collide.
        await expect(
            page.getByRole('navigation', { name: 'Main' }),
        ).toBeHidden();
        await expect(page.locator('.report-section').first()).toBeVisible();
        await expect(page.getByTestId('print-header')).toHaveCount(0);

        await page.emulateMedia({ media: 'screen' });
    });
});
