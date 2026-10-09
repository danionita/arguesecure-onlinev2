import { expect, type Page } from '@playwright/test';

/**
 * Logging in is an Inertia form post, so the click resolves before the new
 * page is mounted. Every early version of these specs that clicked and then
 * immediately navigated raced the redirect and landed on the login screen
 * again — which surfaces much later as a confusing "field not found" on
 * whatever page the test actually wanted. Waiting for the URL to leave
 * /login is the synchronisation point.
 */
export async function login(
    page: Page,
    email: string,
    password: string,
): Promise<void> {
    await page.goto('/login');
    await page.getByLabel(/^Email/).fill(email);
    await page.getByLabel(/^Password/).fill(password);
    await page.getByRole('button', { name: 'Log in' }).click();

    await expect(page).not.toHaveURL(/\/login$/, { timeout: 15_000 });
}

export async function logout(page: Page): Promise<void> {
    await page.context().clearCookies();
}
