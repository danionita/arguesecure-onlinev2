import { expect, test, type Page } from '@playwright/test';
import { createHmac } from 'node:crypto';
import { logout } from './support/auth';
import { collaboratorEmail, collaboratorPassword } from './support/env';

/**
 * Scenario 20 (PLAN-2.7-identity.md W116): two-factor authentication with an
 * authenticator app. Enrol, sign out, sign in with a code, sign in with a
 * recovery code.
 *
 * The code the "app" shows is computed here from the key the page displays,
 * by RFC 6238 itself (HMAC-SHA1, 30-second steps, six digits), so the scenario
 * also checks the server against an implementation it shares no code with.
 * The account is the collaborator the earlier scenarios use; it ends with the
 * second factor off again, so a re-run of any of them is unaffected.
 */

function base32Decode(text: string): Buffer {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const char of text.replace(/=+$/, '').toUpperCase()) {
        bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
    }
    const bytes: number[] = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) {
        bytes.push(parseInt(bits.slice(i, i + 8), 2));
    }
    return Buffer.from(bytes);
}

function totp(secret: string, stepOffset = 0): string {
    const step = Math.floor(Date.now() / 1000 / 30) + stepOffset;
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(step));
    const hmac = createHmac('sha1', base32Decode(secret))
        .update(counter)
        .digest();
    const offset = hmac[hmac.length - 1] & 0x0f;
    const value =
        ((hmac[offset] & 0x7f) << 24) |
        (hmac[offset + 1] << 16) |
        (hmac[offset + 2] << 8) |
        hmac[offset + 3];
    return String(value % 1_000_000).padStart(6, '0');
}

async function passwordStep(page: Page): Promise<void> {
    await page.goto('/login');
    await page.getByLabel(/^Email/).fill(collaboratorEmail);
    await page.getByLabel(/^Password/).fill(collaboratorPassword());
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/two-factor-challenge$/);
}

test.describe('two-factor authentication', () => {
    let secret = '';
    let recoveryCodes: string[] = [];

    test('an account enrols from its profile and is shown ten recovery codes', async ({
        page,
    }) => {
        await page.goto('/login');
        await page.getByLabel(/^Email/).fill(collaboratorEmail);
        await page.getByLabel(/^Password/).fill(collaboratorPassword());
        await page.getByRole('button', { name: 'Log in' }).click();
        await expect(page).not.toHaveURL(/\/login$/, { timeout: 15_000 });

        await page.goto('/profile');
        const panel = page.getByRole('region', {
            name: 'Two-factor authentication',
        });
        await panel
            .getByLabel(/^Current password/)
            .fill(collaboratorPassword());
        await panel.getByRole('button', { name: 'Set up' }).click();

        secret = (
            await panel.locator('[data-test="secret"]').innerText()
        ).trim();
        expect(secret).toMatch(/^[A-Z2-7]{32}$/);
        await expect(panel.getByRole('img', { name: /QR code/ })).toBeVisible();

        await panel.getByLabel(/^Six-digit code/).fill(totp(secret));
        await panel.getByRole('button', { name: 'Turn on' }).click();

        const codes = panel
            .locator('[data-test="recovery-codes"]')
            .locator('li');
        await expect(codes).toHaveCount(10);
        recoveryCodes = await codes.allInnerTexts();
        await expect(panel.locator('[data-test="codes-left"]')).toContainText(
            '10 recovery codes left',
        );
    });

    test('a password alone no longer signs in; a code does', async ({
        page,
    }) => {
        // Waits out the 30-second step the enrolment code was spent in.
        test.setTimeout(90_000);
        await logout(page);
        await passwordStep(page);

        // The enrolment code is spent, so the next step's code is the one
        // the app will show in 30 seconds. Waiting for it keeps the scenario
        // honest about single use instead of working around it.
        await page.waitForTimeout(31_000);
        await page.getByLabel(/^Code/).fill(totp(secret));
        await page.getByRole('button', { name: 'Verify' }).click();

        await expect(page).not.toHaveURL(/two-factor-challenge$/, {
            timeout: 15_000,
        });
        await page.goto('/dashboard');
        await expect(page).toHaveURL(/\/dashboard$/);
    });

    test('a recovery code signs in once and is then refused', async ({
        page,
    }) => {
        await logout(page);
        await passwordStep(page);
        await page.getByLabel(/^Code/).fill(recoveryCodes[0]);
        await page.getByRole('button', { name: 'Verify' }).click();
        await expect(page).not.toHaveURL(/two-factor-challenge$/, {
            timeout: 15_000,
        });

        await logout(page);
        await passwordStep(page);
        await page.getByLabel(/^Code/).fill(recoveryCodes[0]);
        await page.getByRole('button', { name: 'Verify' }).click();
        await expect(
            page.getByText('That code was not accepted.'),
        ).toBeVisible();
    });

    test('it can be turned off again, leaving the account as it was', async ({
        page,
    }) => {
        await logout(page);
        await passwordStep(page);
        await page.getByLabel(/^Code/).fill(recoveryCodes[1]);
        await page.getByRole('button', { name: 'Verify' }).click();
        await expect(page).not.toHaveURL(/two-factor-challenge$/, {
            timeout: 15_000,
        });

        await page.goto('/profile');
        const panel = page.getByRole('region', {
            name: 'Two-factor authentication',
        });
        await expect(panel.locator('[data-test="codes-left"]')).toContainText(
            '8 recovery codes left',
        );
        await panel
            .getByRole('button', { name: 'Turn off', exact: true })
            .click();
        await panel
            .getByLabel(/^Current password/)
            .fill(collaboratorPassword());
        await panel.getByLabel(/^Code from your app/).fill(recoveryCodes[2]);
        await panel
            .getByRole('button', { name: 'Turn off two-factor authentication' })
            .click();

        await expect(
            panel.getByRole('button', { name: 'Set up' }),
        ).toBeVisible();
    });
});
