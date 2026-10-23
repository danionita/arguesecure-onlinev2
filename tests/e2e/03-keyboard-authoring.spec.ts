import { expect, test } from '@playwright/test';
import { login } from './support/auth';
import { adminEmail, adminPassword } from './support/env';
import {
    addChildByKeyboard,
    cardFor,
    cardsFor,
    closeNodeModal,
    expectSaved,
    focusRow,
    openAssessment,
    openNodeModal,
    readGraph,
} from './support/tree';

/**
 * Scenario 2 of PLAN.md's end-to-end list, second half: "User A ... adds two
 * risks, three attacks (one linked to both risks), and four defences with
 * mixed statuses and reduction targets — driven entirely from the keyboard,
 * checking that adding a node focuses the name field and never opens a
 * form."
 *
 * That last clause is the whole point of the scenario, and it is a
 * regression test against the legacy editor specifically: v1's flow was
 * click a wrench, reveal buttons, then navigate to a separate full page to
 * name the node (CLAUDE.md: "the main thing to eliminate"). So this asserts
 * the negative — no dialog opens, no navigation happens — as hard as it
 * asserts the positive.
 *
 * It is also the fixture the rest of the suite runs on. Scenarios 4–9 all
 * read this same graph back, so the shape and the numbers here are chosen to
 * make a hand calculation short enough to state in a comment:
 *
 *   Cardholder data breach   impact 200,000/occurrence
 *   Service outage           impact  30,000/occurrence
 *   Phishing                 0.5/yr, under BOTH risks
 *   SQL injection            0.1/yr, under the breach risk only
 *   Multi-factor auth        implemented, 80% against phishing
 *   Web application firewall todo,        50% against SQL injection
 *
 *   inherent = 0.5·200,000 + 0.5·30,000 + 0.1·200,000 = 135,000
 *   current  = MFA only:  20,000 + 3,000 + 20,000     =  43,000
 *   target   = MFA + WAF: 20,000 + 3,000 + 10,000     =  33,000
 */

const BREACH = 'Cardholder data breach';
const OUTAGE = 'Service outage';
const PHISHING = 'Phishing';
const SQLI = 'SQL injection';
const MFA = 'Multi-factor authentication';
const WAF = 'Web application firewall';

test.describe('keyboard-driven authoring', () => {
    test('the whole graph is built without ever leaving the keyboard', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        // Row indices are the flattened visible order, which grows as nodes
        // are added — see support/tree.ts on why focus is re-established
        // from Escape every time rather than assumed to persist.
        //
        // root(0)
        await addChildByKeyboard(page, 0, BREACH); // root(0) R1(1)
        await addChildByKeyboard(page, 0, OUTAGE); // root(0) R1(1) R2(2)
        await addChildByKeyboard(page, 1, PHISHING); // ... R1(1) P(2) R2(3)
        await addChildByKeyboard(page, 1, SQLI); // R1(1) P(2) S(3) R2(4)
        await addChildByKeyboard(page, 2, MFA); // R1(1) P(2) MFA(3) S(4) R2(5)
        await addChildByKeyboard(page, 4, WAF); // ... S(4) WAF(5) R2(6)

        for (const title of [BREACH, OUTAGE, PHISHING, SQLI, MFA, WAF]) {
            await expect(cardFor(page, title)).toBeVisible();
        }
    });

    /**
     * 2.5.1. This scenario used to assert that Tab created a node inline, with
     * no dialog. That creation-before-naming is the reported defect: the node
     * was broadcast to everyone untitled, and the author's own echo blanked
     * the form. Tab now opens a *draft* in the node modal, and abandoning it
     * leaves nothing behind — which is the property this test pins.
     */
    test('Tab opens a draft; abandoning it creates nothing and stays on the page', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page);
        const before = (await readGraph(page)).nodes.length;

        let created = 0;
        page.on('request', (request) => {
            if (
                request.method() === 'POST' &&
                /\/nodes$/.test(new URL(request.url()).pathname)
            ) {
                created++;
            }
        });

        await focusRow(page, 0);
        await page.keyboard.press('Tab');

        const dialog = page.getByRole('dialog');
        await expect(dialog.getByTestId('new-node-form')).toBeVisible();
        await expect(
            dialog.getByTestId('new-node-title').locator('input'),
        ).toBeFocused();

        await page.keyboard.press('Escape');
        await expect(dialog).toHaveCount(0);
        await expect(page).toHaveURL(new RegExp(`/assessments/${uuid}$`));

        expect(created).toBe(0);
        await page.reload();
        expect((await readGraph(page)).nodes.length).toBe(before);
        await expect(
            page.locator('div.group\\/card').filter({
                has: page.getByRole('button', {
                    name: 'Untitled risk',
                    exact: true,
                }),
            }),
        ).toHaveCount(0);
    });

    test('the shared attack is linked under the second risk too', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        // A DAG edge, not a second node: the same Phishing node gains a
        // second parent (schema.md — "an attack may belong to several
        // risks"). There is no keyboard affordance for creating an edge
        // between two existing nodes.
        //
        // W39 removed the Links tab from attacks and moved both halves of the
        // risk_attack edge — "Appears under", and the control that adds
        // another — onto Details, beside the title. The other way to do this
        // is now a drag onto the second risk, answering "Link it here as
        // well" (W38); that gesture is covered by its own unit tests.
        // 2.4 item 12/D31 moved "Appears under" — the parent list, the detach
        // action and this link control — off the Details tab and onto the
        // parent counter's popover on the card. The counter only renders once
        // a node appears in more than one place, so for an attack under a
        // single risk there is no longer a keyboard path to a second link:
        // the drag gesture W38 added is the only one, which is a deliberate
        // decision, not an oversight.
        //
        // The step stays here rather than moving to scenario 8, because
        // scenario 7's duplicate highlighting needs Phishing to appear under
        // two risks and gets that from this scenario. Dropping a node on a
        // card under a different parent opens ReparentDialog, which asks the
        // question the gesture cannot: move, or link as well.
        await cardFor(page, PHISHING).dragTo(cardFor(page, OUTAGE));

        const reparent = page.getByRole('dialog');
        await expect(reparent).toContainText('Move or link?');
        await reparent
            .getByRole('button', { name: 'Link it here as well' })
            .click();

        // Deliberately not `expectSaved` here. Edge mutations go through
        // useLinkMutations, which never touches the store's `saveState`, so
        // the header indicator says nothing about them — waiting for "Saved"
        // after a link change waits forever. Assert the effect instead: the
        // attack now draws twice, which is the DAG this scenario exists to
        // build.
        await expect(cardsFor(page, PHISHING)).toHaveCount(2);

        // Two occurrences of one node is exactly what makes this a DAG, and
        // is what scenario 5's duplicate highlighting then depends on.
        await expect(
            page.getByRole('button', { name: PHISHING, exact: true }),
        ).toHaveCount(2);
    });

    test('values and mixed defence statuses are entered through the modal', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);
        const dialog = page.getByRole('dialog');

        // Impact is per-occurrence loss, never annual (CLAUDE.md's first
        // easy-to-get-wrong item) — the field label says so, and asserting
        // on that label is what stops a future rename from quietly turning
        // this into an annual figure.
        await openNodeModal(page, BREACH, 'Impact');
        await expect(
            dialog.getByText('Loss each time this occurs'),
        ).toBeVisible();
        await dialog.getByRole('spinbutton').fill('200000');
        await closeNodeModal(page);
        await expectSaved(page);

        await openNodeModal(page, OUTAGE, 'Impact');
        await dialog.getByRole('spinbutton').fill('30000');
        await closeNodeModal(page);
        await expectSaved(page);

        // Likelihood is a rate per year and may exceed 1 — the second
        // easy-to-get-wrong item.
        await openNodeModal(page, PHISHING, 'Likelihood');
        await expect(dialog.getByText('How often per year')).toBeVisible();
        await dialog.getByRole('spinbutton').fill('0.5');
        await closeNodeModal(page);
        await expectSaved(page);

        await openNodeModal(page, SQLI, 'Likelihood');
        await dialog.getByRole('spinbutton').fill('0.1');
        await closeNodeModal(page);
        await expectSaved(page);

        // Mixed statuses: implemented counts towards `current`, todo only
        // towards `target` (PLAN.md's three baselines).
        //
        // Status is on Details, not Cost, since 2.4 item 9: a tab called Cost
        // that opened on a status control was answering a different question
        // from the one its label asks. Capex, opex and amortisation stayed;
        // everything that was not a cost moved to Details.
        await openNodeModal(page, MFA, 'Details');
        await dialog.getByRole('radio', { name: 'Implemented' }).click();
        await closeNodeModal(page);
        await expectSaved(page);

        await openNodeModal(page, WAF, 'Details');
        await dialog.getByRole('radio', { name: 'Todo' }).click();
        await closeNodeModal(page);
        await expectSaved(page);
    });

    test('per-link strength is set on the edge, not on the defence', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);
        const dialog = page.getByRole('dialog');

        // schema.md §3.6: strength lives on `attack_defence`, because one
        // control may be 80% effective against one attack and 20% against
        // another. So it is set per edge, from the defence's Strength tab —
        // the same LinksTab component and the same LinkStrengthEditor W31
        // made reachable from both ends of the edge, renamed by W39 to say
        // what it edits and now reachable only from this end.
        //
        // The strength input is addressed by role within its own edge's
        // list item rather than by label: LinksTab renders "Strength
        // fraction" as a bare <label> with no `for`, so getByLabel never
        // matches it. Asserting the label text separately keeps the meaning
        // of the field pinned even though the locator cannot use it.
        // As above, edge edits produce no "Saved" indicator; the rendered
        // percentage next to the input is the acknowledgement the UI does
        // give, and the following test reloads to prove it was persisted.
        await openNodeModal(page, MFA, 'Strength');
        const phishingEdge = dialog
            .getByRole('listitem')
            .filter({ hasText: PHISHING });
        await expect(phishingEdge.getByText('Strength fraction')).toBeVisible();
        await phishingEdge.getByRole('spinbutton').fill('0.8');
        await expect(phishingEdge.getByText('80%')).toBeVisible();
        await closeNodeModal(page);

        await openNodeModal(page, WAF, 'Strength');
        const sqliEdge = dialog.getByRole('listitem').filter({ hasText: SQLI });
        await sqliEdge.getByRole('spinbutton').fill('0.5');
        await expect(sqliEdge.getByText('50%')).toBeVisible();
        await closeNodeModal(page);
    });

    test('everything entered survives a reload', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        // The autosave indicator says a write was accepted; only a reload
        // proves it was persisted, which is the difference the legacy app's
        // silently-dropped mass assignments used to hide (CLAUDE.md).
        for (const title of [BREACH, OUTAGE, PHISHING, SQLI, MFA, WAF]) {
            await expect(cardFor(page, title)).toBeVisible();
        }
        await expect(
            page.getByRole('button', { name: PHISHING, exact: true }),
        ).toHaveCount(2);

        // Strength lives on the attack_defence pivot, and a pivot write is
        // the exact shape of failure CLAUDE.md warns about most loudly — an
        // identity column dropped silently at the model layer, leaving the
        // UI looking correct until something reads the row back. Reading it
        // back after a reload is the only assertion that would have caught
        // that, so it is made explicitly rather than inferred from a total.
        await openNodeModal(page, MFA, 'Strength');
        await expect(
            page
                .getByRole('dialog')
                .getByRole('listitem')
                .filter({ hasText: PHISHING })
                .getByText('80%'),
        ).toBeVisible();
        await closeNodeModal(page);
    });

    /**
     * W88 item 21, in a real browser against a real server — the one place the
     * defect was ever visible. The Details title commits on every keystroke,
     * and until W88 two keystrokes closer together than one round trip made
     * the second request carry an `updated_at` the first had already
     * superseded. The server answered 409 and the editor accused the typist of
     * being a second editor.
     *
     * `pressSequentially` with no delay is the reproduction: it types as fast
     * as the browser will accept input, which is faster than any round trip.
     * `tests/js/stores/nodeWriteOverlap.spec.ts` pins the same thing
     * deterministically against a fake server; this exists because a timing
     * defect that only appeared over a real wire deserves one assertion over a
     * real wire.
     *
     * The title is retyped **to the value it already had**, so this leaves the
     * fixture every later scenario reads back exactly as it found it.
     */
    test('typing quickly does not accuse the typist of being a second editor', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);
        const dialog = page.getByRole('dialog');

        await openNodeModal(page, MFA, 'Details');
        const title = dialog.getByLabel('Title');
        await title.click();
        await page.keyboard.press('ControlOrMeta+a');
        await title.pressSequentially(MFA);

        // The order here is the assertion. `toHaveCount(0)` succeeds on its
        // first poll, so asserting the banner's absence straight after typing
        // would pass on a regressed build too — the 409 has not come back yet.
        // Waiting for the writes to settle first is what makes the absence
        // mean something: every keystroke's round trip has completed by then,
        // so a conflict, if it were going to happen, has already happened.
        await expectSaved(page);
        await expect(page.getByText('while you were editing.')).toHaveCount(0);
        await expect(title).toHaveValue(MFA);

        await closeNodeModal(page);
        await expect(cardFor(page, MFA)).toBeVisible();
    });
});
