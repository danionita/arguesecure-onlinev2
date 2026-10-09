import { expect, test } from '@playwright/test';
import { login } from './support/auth';
import { adminEmail, adminPassword } from './support/env';
import {
    cardFor,
    cardsFor,
    openAssessment,
    readGraph,
    totalsPanel,
} from './support/tree';

/**
 * Scenario 7 of PLAN.md's end-to-end list: "Delete a node, undo it, confirm
 * it returns with its links intact."
 *
 * The last clause is the whole test. Restoring a node is easy; restoring the
 * edges it sat on is where an undo implementation actually fails, and in a
 * DAG a node can sit on several. Phishing belongs to both risks, so undoing
 * its deletion has to bring back two `risk_attack` rows and its
 * `attack_defence` row to MFA — with the strength that lived on that pivot.
 * An undo that returned the node with one parent, or with its strength reset,
 * would look correct on screen and be wrong in the totals.
 */

const PHISHING = 'Phishing';
const MFA = 'Multi-factor authentication';

test.describe('undo', () => {
    test('deleting a shared node removes every occurrence of it', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        await expect(cardsFor(page, PHISHING)).toHaveCount(2);

        await cardFor(page, PHISHING)
            .getByRole('button', { name: 'Delete' })
            .click();
        // The confirmation says so explicitly, because deleting from one
        // place and losing it from another would otherwise be a surprise.
        await expect(
            page.getByText('This also removes it from every place it appears.'),
        ).toBeVisible();
        await page
            .getByRole('button', { name: 'Delete', exact: true })
            .last()
            .click();

        await expect(cardsFor(page, PHISHING)).toHaveCount(0);
    });

    test('undo returns the node with both of its parents and its strength', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page);

        // Ctrl+Z is bound globally in the editor (ui.md §4) and posts to the
        // per-assessment undo stack — a single linear stack for the whole
        // assessment, not per-user, so a collaborator can undo a mistake
        // they watched someone else make.
        await page.keyboard.press('Control+z');

        // Read the restored edges from the server's own payload rather than
        // counting cards, so this checks the database rather than the render.
        // The reload also serves as the synchronisation point: the undo is a
        // server-side command whose effect (a node undeleted, its edge rows
        // still in place) only exists in the response to the next load.
        await expect(async () => {
            await page.goto(`/assessments/${uuid}`);
            await expect(cardsFor(page, PHISHING)).toHaveCount(2);
        }).toPass({ timeout: 20_000 });

        const graph = await readGraph(page);

        const phishing = graph.nodes.find((n) => n.title === PHISHING);
        expect(phishing, 'the node itself came back').toBeDefined();

        expect(
            graph.riskAttackLinks.filter(
                (l) => l.attack_uuid === phishing!.uuid,
            ),
            'both risk edges came back, not just one',
        ).toHaveLength(2);

        const mfa = graph.nodes.find((n) => n.title === MFA);
        const defenceEdge = graph.attackDefenceLinks.find(
            (l) =>
                l.attack_uuid === phishing!.uuid &&
                l.defence_uuid === mfa!.uuid,
        );
        expect(defenceEdge, 'the defence edge came back').toBeDefined();
        // The value that lived on the pivot, not on either node.
        expect(defenceEdge!.strength_fraction).toBe(0.8);
    });

    test('the totals return to what they were before the delete', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        // The strongest single assertion available that the restore was
        // complete: the totals are a function of every node, every edge and
        // every value at once, so if any part of the undo were partial this
        // figure would differ. Deleting phishing removed 115,000 of the
        // 135,000 inherent total, so a wrong restore is very visible here.
        // Through `totalsPanel()` since W36: the docked panel these figures
        // used to live on is gone, and with it the word "Totals" this
        // filtered on — so the locator matched nothing and the assertion
        // failed on a page showing exactly the right numbers. The helper is
        // the one place that boundary is written down.
        await expect(totalsPanel(page)).toContainText('€135,000');
        await expect(totalsPanel(page)).toContainText('€43,000');
    });
});
