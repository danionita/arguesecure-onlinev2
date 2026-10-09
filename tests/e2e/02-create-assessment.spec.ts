import { expect, test } from '@playwright/test';
import { login } from './support/auth';
import { adminEmail, adminPassword } from './support/env';
import { mainContent } from './support/tree';

/**
 * Scenario 2 of PLAN.md's end-to-end list, first half: "User A creates an
 * assessment".
 *
 * This scenario is the reason W26 exists. When these specs were first
 * written the application had no route that could create an assessment â€”
 * every route was `assessments/{assessment}/...` and nothing produced the
 * assessment they presuppose, so a fresh deployment could be installed, set
 * up and logged into, and then did nothing at all. It stayed invisible for
 * 24 work packages because every test built assessments with a factory.
 *
 * So this file is doing two jobs: it covers the scenario, and it is the
 * regression test for "the product is usable from a clean install".
 */
test.describe('creating an assessment', () => {
    test('a new account lands on an empty assessment list, not a dead end', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());

        // W42 moved this. `/dashboard` was the assessment list under a second
        // URL; it is now a dashboard of its own, and the list lives at
        // `/assessments` alone. The claim this test makes â€” a new account
        // lands somewhere with a way forward rather than on Breeze's
        // placeholder, which mentioned no assessments and linked nowhere â€”
        // is unchanged, and scenario 18 covers the dashboard's own version
        // of it.
        await page.goto('/assessments');

        await expect(
            page.getByRole('heading', { name: 'My assessments' }),
        ).toBeVisible();

        // Scoped to the page's own region, because the sidebar carries a
        // control with the same name (2.4 item 19). Both open the same
        // creation dialog, so the shared name is two routes to one behaviour
        // rather than a fork -- but an unscoped locator still cannot choose
        // between them, and this test is about the *list page* offering a way
        // forward rather than about the sidebar.
        await expect(
            mainContent(page).getByRole('button', {
                name: 'New assessment',
            }),
        ).toBeVisible();
    });

    test('the assessment created from the list opens in the tree editor', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/assessments');

        await mainContent(page)
            .getByRole('button', { name: 'New assessment' })
            .click();

        await page.getByLabel(/^Title/).fill('Payment platform');
        await page
            .getByLabel(/^Description/)
            .fill('The cardholder data environment.');
        await page.getByRole('button', { name: 'Create assessment' }).click();

        // Creating redirects straight into the editor for the new
        // assessment, which is the only way to reach one.
        await expect(page).toHaveURL(/\/assessments\/[0-9a-f-]{36}$/);

        // The title lives on the root node (schema.md), so seeing it in the
        // editor proves both rows were written, not just the assessment.
        await expect(page.getByText('Payment platform').first()).toBeVisible();
    });

    test('the new assessment appears in the list afterwards', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/assessments');

        // Scoped since W41: the sidebar lists it under the same title too.
        await expect(
            mainContent(page).getByRole('link', { name: 'Payment platform' }),
        ).toBeVisible();
    });

    test('a title is required', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/assessments');

        await mainContent(page)
            .getByRole('button', { name: 'New assessment' })
            .click();
        await page.getByLabel(/^Description/).fill('No title on purpose.');
        await page.getByRole('button', { name: 'Create assessment' }).click();

        // Still on the list with the dialog open: an untitled assessment
        // renders as "Untitled assessment" everywhere and is unfindable.
        await expect(page).toHaveURL(/\/assessments$/);
    });
});
