import { expect, test } from '@playwright/test';
import { login } from './support/auth';
import { adminEmail, adminPassword } from './support/env';
import {
    closeNodeModal,
    expectSaved,
    mainContent,
    openAssessment,
    openNodeModal,
    totalsPanel,
} from './support/tree';

/**
 * Scenario 9 of PLAN.md's end-to-end list: "Create a snapshot, change
 * several values, create a second snapshot, and diff them."
 *
 * A snapshot freezes both the graph and the computed results, with the
 * scales inlined, so that recalibrating an assessment later cannot
 * retroactively rewrite a figure that was already reported to a client
 * (PLAN.md Phase 8 item 3). That is the property this scenario is really
 * about: the first snapshot must still show the old numbers after the
 * assessment has moved on.
 */

const BREACH = 'Cardholder data breach';
const BEFORE = 'Before the impact revision';
const AFTER = 'After the impact revision';

/**
 * Saves a version by hand from the History page (2.5.1), which is where
 * versions — formerly "snapshots", on a page of their own — now live.
 */
async function createSnapshot(
    page: import('@playwright/test').Page,
    uuid: string,
    name: string,
) {
    await page.goto(`/assessments/${uuid}/history`);
    await page.getByTestId('save-version').click();
    await page.getByTestId('save-version-form').locator('input').fill(name);
    await page.getByTestId('save-version-submit').click();
    await expect(
        mainContent(page).getByText(name, { exact: false }),
    ).toBeVisible();
}

/** The version card whose name is `name`, on the History page. */
function versionCard(page: import('@playwright/test').Page, name: string) {
    return page.locator('li[data-testid^="version-"]').filter({
        hasText: name,
    });
}

test.describe('snapshots', () => {
    test('a snapshot can be taken of the assessment as it stands', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page);

        await createSnapshot(page, uuid, BEFORE);
    });

    test('the assessment moves on', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        // 200,000 → 500,000 makes the breach pairs 250,000 and 50,000, so
        // inherent becomes 315,000 — far enough from 135,000 that no
        // rounding or formatting coincidence could confuse the two.
        await openNodeModal(page, BREACH, 'Impact');
        await page.getByRole('dialog').getByRole('spinbutton').fill('500000');
        await closeNodeModal(page);
        await expectSaved(page);

        await page.reload();
        await expect(totalsPanel(page)).toContainText('€315,000');
    });

    test('a second snapshot records the new figures', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page);

        await createSnapshot(page, uuid, AFTER);

        // Both, re-read from the server: a snapshot list that replaced
        // rather than appended would still have satisfied createSnapshot's
        // own check. Navigating rather than reloading, because a reload
        // races the Inertia visit that the create just triggered.
        await page.goto(`/assessments/${uuid}/history`);
        await expect(versionCard(page, BEFORE)).toBeVisible();
        await expect(versionCard(page, AFTER)).toBeVisible();
    });

    test('the first snapshot still shows the figures it was taken with', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page);

        await page.goto(`/assessments/${uuid}/history`);
        await versionCard(page, BEFORE)
            .getByRole('link', { name: 'Figures' })
            .click();

        // The whole point of freezing the results alongside the graph: this
        // number must not have followed the assessment. If snapshots
        // recomputed on read, this would now say €315,000 and every figure
        // ever quoted from a snapshot would be retroactively rewritten.
        await expect(page.locator('body')).toContainText('€135,000');
        await expect(page.locator('body')).not.toContainText('€315,000');
    });

    test('diffing the two snapshots shows what moved', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page);

        await page.goto(`/assessments/${uuid}/history`);

        // Each version compares against the one before it (2.5.1); the page
        // routes to the same read-only comparison with both ids.
        await versionCard(page, AFTER)
            .getByRole('link', { name: /^Compare with version/ })
            .click();

        await expect(
            page.getByRole('heading', { name: `${BEFORE} → ${AFTER}` }),
        ).toBeVisible();

        // The movement in the headline figures, which is what a reader of a
        // diff actually wants: both endpoints present on the page.
        await expect(page.locator('body')).toContainText('€135,000');
        await expect(page.locator('body')).toContainText('€315,000');

        // And the changed node named, matched on uuid across the two frozen
        // graphs rather than on title — which is why renaming a node does
        // not read as delete-plus-add.
        await expect(page.locator('body')).toContainText(BREACH);
    });

    test('the assessment is put back for the scenarios that follow', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        await openNodeModal(page, BREACH, 'Impact');
        await page.getByRole('dialog').getByRole('spinbutton').fill('200000');
        await closeNodeModal(page);
        await expectSaved(page);

        await page.reload();
        await expect(totalsPanel(page)).toContainText('€135,000');
    });
});
