import { expect, test } from '@playwright/test';
import { login } from './support/auth';
import {
    adminEmail,
    adminName,
    adminPassword,
    collaboratorEmail,
    collaboratorName,
    collaboratorPassword,
} from './support/env';

/**
 * Scenario 1 of PLAN.md's end-to-end list: "First-run setup creates an
 * admin; the admin creates two users and bulk-imports three more from CSV."
 *
 * This runs first and every later scenario depends on the account it
 * creates, which is why the suite is serial (see playwright.config.ts).
 * It is also the scenario that proves the single most important product
 * decision in the rewrite: there is no seeded admin and no default
 * password anywhere, so a brand-new deployment is reachable only through
 * this screen.
 */
test.describe('first run', () => {
    test('a brand-new instance offers first-run setup instead of a login', async ({
        page,
    }) => {
        await page.goto('/login');

        // A fresh instance has an empty users table, so /login must hand off
        // to /setup rather than presenting a form nobody can satisfy.
        await expect(page).toHaveURL(/\/setup$/);
        // The screen states its own precondition; asserting on that copy
        // rather than a heading because the page deliberately has none.
        await expect(page.getByText('No account exists yet')).toBeVisible();
    });

    test('first-run setup creates the first administrator and signs them in', async ({
        page,
    }) => {
        await page.goto('/setup');

        await page.getByLabel(/^Name/).fill(adminName);
        await page.getByLabel(/^Email/).fill(adminEmail);
        await page.getByLabel(/^Password/).fill(adminPassword());
        await page.getByLabel(/^Confirm password/).fill(adminPassword());

        await page
            .getByRole('button', { name: 'Create administrator' })
            .click();

        // Landing anywhere that is not /setup means the account exists and
        // the session was established.
        await expect(page).not.toHaveURL(/\/setup$/);
    });

    test('the setup screen is closed once an administrator exists', async ({
        page,
    }) => {
        // The single most important property of this screen: it must not
        // remain open to the internet after the first account is created,
        // or it is an unauthenticated admin-creation endpoint. Asserting on
        // the landing URL rather than the status code because the guard is
        // a redirect, and Playwright reports the status of the page it
        // finally lands on — which is a perfectly ordinary 200.
        await page.goto('/setup');

        await expect(page).not.toHaveURL(/\/setup$/);
        await expect(page.getByText('No account exists yet')).toHaveCount(0);
    });

    test('the administrator can create the collaborator account the later scenarios use', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());

        await page.goto('/admin/users/create');

        await page.getByLabel(/^Name/).fill(collaboratorName);
        await page.getByLabel(/^Email/).fill(collaboratorEmail);
        await page.getByLabel(/^Password/).fill(collaboratorPassword());
        await page.getByLabel(/^Confirm password/).fill(collaboratorPassword());

        await page.getByRole('button', { name: 'Create user' }).click();

        // Landing on the user list, with the new address on it, is the
        // evidence the account was actually written — not merely that a
        // form accepted the click.
        await expect(page).toHaveURL(/\/admin\/users$/);
        await expect(page.getByText(collaboratorEmail)).toBeVisible();
    });

    test('the collaborator can log in with the credentials the admin set', async ({
        page,
    }) => {
        await login(page, collaboratorEmail, collaboratorPassword());

        // A non-admin must not reach the admin area — the legacy app gated
        // this on a username string comparison (security.md), which is the
        // defect this rewrite replaced with a real is_admin column.
        //
        // The `admin` middleware refuses with a 403 rather than redirecting,
        // so the URL stays put and the assertion has to be on the response
        // and the rendered page, not on where the browser ended up.
        const response = await page.goto('/admin/users');

        expect(response?.status()).toBe(403);
        await expect(page.getByRole('heading', { name: '403' })).toBeVisible();
    });
});
