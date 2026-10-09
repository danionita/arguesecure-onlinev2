import { expect, test } from '@playwright/test';
import { login } from './support/auth';
import { adminEmail, adminPassword } from './support/env';
import {
    closeNodeModal,
    expectSaved,
    openAssessment,
    openNodeModal,
    totalsPanel,
} from './support/tree';

/**
 * Scenario 4 of PLAN.md's end-to-end list: "Both users' totals update within
 * a second of a value change, and match a hand calculation."
 *
 * The hand calculation, from the graph scenario 2 built (see
 * 03-keyboard-authoring.spec.ts for the full statement of it):
 *
 *   pairs        ALE = ARO × impact
 *   breach  × phishing   0.5 × 200,000 = 100,000
 *   outage  × phishing   0.5 ×  30,000 =  15,000
 *   breach  × SQLi       0.1 × 200,000 =  20,000
 *                            inherent  = 135,000
 *
 *   current  — MFA implemented, 80% on phishing, so phishing pairs × 0.2
 *              20,000 + 3,000 + 20,000                =  43,000
 *   target   — MFA + WAF (todo), 50% on SQL injection
 *              20,000 + 3,000 + 10,000                =  33,000
 *
 * These are the numbers the engine must produce, computed here by hand
 * rather than read off the engine's own fixtures — a mirror of the
 * implementation would agree with a wrong implementation. calculation-engine
 * .md is normative and both the PHP engine and its TypeScript mirror are
 * unit-tested against golden vectors; what this scenario adds is that the
 * numbers survive the whole round trip through the database, the graph
 * loader, Inertia and the browser, which no unit test covers.
 *
 * The "within a second" half is asserted as: the panel shows the new figure
 * without a reload, because W12 recomputes locally through the TypeScript
 * engine the instant an edit is made and reconciles against the server's
 * answer when it lands.
 */

const BREACH = 'Cardholder data breach';

test.describe('live totals', () => {
    test('the totals panel matches the hand calculation', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        const panel = totalsPanel(page);

        // Money.vue formats with Intl in en-GB with no fraction digits, so
        // these are the exact strings a user reads.
        await expect(panel).toContainText('€135,000');
        await expect(panel).toContainText('€43,000');
        await expect(panel).toContainText('€33,000');

        // inherent → current is a 68% reduction (135,000 → 43,000).
        await expect(panel).toContainText('68%');
    });

    test('the root card carries the same three figures as the panel', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        // ui.md §5 has the docked panel and the root row reading the same
        // `store.results`, precisely so the two can never disagree. A user
        // who saw two different totals on one screen would rightly stop
        // trusting the tool.
        const rootCard = page.locator('div.group\\/card').first();
        await expect(rootCard).toContainText('€135,000');
        await expect(rootCard).toContainText('€43,000');
        await expect(rootCard).toContainText('€33,000');
    });

    test('changing an impact moves the totals, and the server agrees', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        await expect(totalsPanel(page)).toContainText('€135,000');

        // Doubling the breach impact to 400,000 doubles both pairs it
        // participates in: inherent becomes 200,000 + 15,000 + 40,000 =
        // 255,000, current becomes 40,000 + 3,000 + 40,000 = 83,000.
        await openNodeModal(page, BREACH, 'Impact');
        await page.getByRole('dialog').getByRole('spinbutton').fill('400000');
        await closeNodeModal(page);
        await expectSaved(page);

        // The authoritative PHP result, re-read from the server. This is the
        // assertion that would catch the two engines disagreeing, and the
        // one that proves the write landed rather than merely being drawn.
        await page.reload();
        await expect(totalsPanel(page)).toContainText('€255,000');
        await expect(totalsPanel(page)).toContainText('€83,000');

        // Restore, so the scenarios after this one still see the numbers
        // their own comments state.
        await openNodeModal(page, BREACH, 'Impact');
        await page.getByRole('dialog').getByRole('spinbutton').fill('200000');
        await closeNodeModal(page);
        await expectSaved(page);
        await page.reload();
        await expect(totalsPanel(page)).toContainText('€135,000');
    });

    test('a missing value excludes its pair rather than counting as zero', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        // calculation-engine.md, and CLAUDE.md's third easy-to-get-wrong
        // item: a missing impact or likelihood removes the pair from the
        // total entirely. Coercing it to zero would silently understate
        // risk, which is the dangerous direction — so clearing the breach
        // impact must REMOVE its two pairs (100,000 + 20,000 inherent),
        // leaving only the outage pair at 0.5 × 30,000 = 15,000.
        await openNodeModal(page, BREACH, 'Impact');
        await page.getByRole('dialog').getByRole('spinbutton').fill('');
        await closeNodeModal(page);
        await expectSaved(page);
        await page.reload();

        const panel = totalsPanel(page);
        await expect(panel).toContainText('€15,000');
        await expect(panel).not.toContainText('€135,000');

        // The panel also marks the total as a lower bound once anything is
        // missing, rather than presenting a partial figure as complete.
        await expect(panel).toContainText('≥');

        await openNodeModal(page, BREACH, 'Impact');
        await page.getByRole('dialog').getByRole('spinbutton').fill('200000');
        await closeNodeModal(page);
        await expectSaved(page);
        await page.reload();
        await expect(totalsPanel(page)).toContainText('€135,000');
    });
});

/**
 * The "within a second" half of scenario 4, asserted with the modal still
 * open — no close, no save, no reload. This is the only test in the suite
 * that distinguishes the optimistic client-side recompute from the
 * authoritative server figure, because it is the only one that looks before
 * the server has been asked.
 *
 * Until W27 it asserted the opposite, as a pinned defect.
 * `assessmentStore.recomputeLocally()` assembled its graph with
 * `scaleLevels: {}` and `scales: {}` on the reasoning that qualitative
 * values could not be resolved client-side — but AssessmentController was
 * already sending both and Show.vue simply did not declare them as props.
 * With no calibration, RiskCalculator throws "Cannot band-map a value
 * against an empty scale" on the first risk that has an impact, because the
 * band code it derives for display is computed whether or not the value was
 * entered qualitatively. A bare `catch {}` swallowed that on every
 * keystroke, so the panel kept the last server figure until a reload: the
 * numbers were right, just never immediate, and nothing anywhere reported
 * it.
 */
test.describe('the totals move before the server is asked', () => {
    test('an impact change moves the panel while the modal is still open', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        const panel = totalsPanel(page);
        await expect(panel).toContainText('€135,000');

        await openNodeModal(page, BREACH, 'Impact');
        await page.getByRole('dialog').getByRole('spinbutton').fill('400000');

        // Same arithmetic as the test above: doubling the breach impact
        // gives inherent 200,000 + 15,000 + 40,000 = 255,000 and current
        // 40,000 + 3,000 + 40,000 = 83,000. Read here from the client's own
        // engine, with the dialog still on screen.
        await expect(panel).toContainText('€255,000');
        await expect(panel).toContainText('€83,000');

        await closeNodeModal(page);
        await expectSaved(page);

        // And the server agrees with what was already on screen — which is
        // the property that makes the optimistic figure safe to show.
        await page.reload();
        await expect(totalsPanel(page)).toContainText('€255,000');

        await openNodeModal(page, BREACH, 'Impact');
        await page.getByRole('dialog').getByRole('spinbutton').fill('200000');
        await closeNodeModal(page);
        await expectSaved(page);
        await page.reload();
        await expect(totalsPanel(page)).toContainText('€135,000');
    });
});
