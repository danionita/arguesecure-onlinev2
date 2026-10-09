import { expect, test } from '@playwright/test';
import { login } from './support/auth';
import { adminEmail, adminPassword } from './support/env';
import { assessmentTitle, mainContent, openAssessment } from './support/tree';

/**
 * Regression guard for the 502 the end-to-end run found (docker/nginx.conf).
 *
 * The tree editor's response header block is about 4.4 KB — Laravel's Vite
 * integration emits a ~2.7 KB `Link:` preload header for the page
 * component's chunks, plus the CSP and two Set-Cookie headers — and nginx's
 * default `fastcgi_buffer_size` is one 4 KB page. nginx refuses a header
 * block it cannot fit in one buffer, so the application's primary screen
 * answered 502 Bad Gateway in the deployed stack.
 *
 * Nothing before these scenarios could have caught it. The Pest suite never
 * involves nginx, and the sizes sit so near the boundary that the failure
 * appears and disappears with the session cookie's length and with the
 * hashed filenames of a given build — which is exactly why this asserts a
 * status code rather than trusting that the page looked fine once.
 *
 * The distinction that matters is **by URL, not by in-app navigation**. Do
 * not "simplify" this file to click through from the list: that is what
 * every other spec already does, and it exercises the same routes without
 * ever reproducing the bug.
 */
test.describe('full page loads', () => {
    test('the tree editor loads directly by URL, not only by in-app navigation', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page);

        // A fresh navigation to the same URL — a bookmark, a shared link, or
        // simply pressing F5 — rather than an Inertia visit.
        const response = await page.goto(`/assessments/${uuid}`);

        expect(response?.status()).toBe(200);
        await expect(page.getByRole('tree')).toBeVisible();
    });

    test('every other server-rendered screen survives the same treatment', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page);

        // The editor is the largest page, but the margin was ~300 bytes, so
        // any of these could cross the line after an ordinary dependency
        // bump. Checking them together is cheap and turns a future
        // recurrence into a named failure instead of a mystery 502.
        for (const path of [
            '/dashboard',
            '/assessments',
            '/profile',
            '/admin/users',
            '/catalog',
            '/templates',
            '/help',
            `/assessments/${uuid}/report`,
            `/assessments/${uuid}/snapshots`,
            `/assessments/${uuid}/history`,
            '/assessments/shared',
            '/admin/assistant',
            '/admin/defaults',
        ]) {
            const response = await page.goto(path);
            expect(
                response?.status(),
                `${path} should not be a gateway error`,
            ).toBeLessThan(500);
        }
    });

    test('the assessment list still renders its contents after a hard load', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());

        // A 502 renders nginx's own error page, which contains none of the
        // application's markup — so asserting on real content is a second,
        // independent way of catching the same class of failure.
        await page.goto('/assessments');
        await expect(
            mainContent(page).getByRole('link', { name: assessmentTitle }),
        ).toBeVisible();
    });
});
