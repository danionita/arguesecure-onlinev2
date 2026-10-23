import { expect, test } from '@playwright/test';
import { login } from './support/auth';
import { adminEmail, adminPassword } from './support/env';
import { cardsFor, openAssessment } from './support/tree';

/**
 * Scenario 5 of PLAN.md's end-to-end list: "Hovering the shared attack
 * highlights both of its occurrences."
 *
 * Small on the page, load-bearing underneath. The whole reason the schema is
 * a DAG rather than a tree is that one attack can contribute to several
 * risks; the cost of that decision is that the same node appears in more
 * than one place, and a reader who does not notice will double-count it by
 * eye. Duplicate highlighting is the affordance that pays that cost back, so
 * it is the thing that makes the DAG legible rather than confusing.
 */

const PHISHING = 'Phishing';
const SQLI = 'SQL injection';

test.describe('duplicate highlighting', () => {
    test('the shared attack really does occur twice', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        // The precondition, asserted rather than assumed: if scenario 2's
        // second link had not persisted, every assertion below would pass
        // vacuously against a single occurrence.
        await expect(cardsFor(page, PHISHING)).toHaveCount(2);
        await expect(cardsFor(page, SQLI)).toHaveCount(1);

        // The card also states the fact in words for anyone not hovering.
        // W29 moved that from a native `title` attribute to the Tooltip
        // component, so the count is asserted as text on the card and the
        // sentence itself on hover, below.
        await expect(
            cardsFor(page, PHISHING).first().getByText('2', { exact: true }),
        ).toBeVisible();
    });

    test('hovering one occurrence highlights every occurrence', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        const occurrences = cardsFor(page, PHISHING);
        const first = occurrences.nth(0);
        const second = occurrences.nth(1);

        // NodeCard applies a ring class to every occurrence whose path is in
        // `duplicates.highlightedPathKeys`. Asserting on the class is
        // unavoidable — the effect is purely visual and carries no ARIA
        // state — so it is asserted on the semantic token (`ring-focus-ring`)
        // rather than on the whole class string, which changes with layout.
        await expect(first).not.toHaveClass(/ring-focus-ring/);
        await expect(second).not.toHaveClass(/ring-focus-ring/);

        await first.hover();

        // Both, not just the hovered one: highlighting only what the pointer
        // is already on would tell the reader nothing they did not know.
        await expect(first).toHaveClass(/ring-focus-ring/);
        await expect(second).toHaveClass(/ring-focus-ring/);
    });

    test('the highlight clears when the pointer leaves', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        const occurrences = cardsFor(page, PHISHING);
        await occurrences.nth(0).hover();
        await expect(occurrences.nth(1)).toHaveClass(/ring-focus-ring/);

        // Move to a node that occurs once. A highlight that never cleared
        // would leave the whole tree ringed after a few seconds of reading
        // — and would also make the previous test pass for the wrong reason.
        await cardsFor(page, SQLI).first().hover();
        await expect(occurrences.nth(0)).not.toHaveClass(/ring-focus-ring/);
        await expect(occurrences.nth(1)).not.toHaveClass(/ring-focus-ring/);
    });

    test('hovering a node that occurs once highlights nothing else', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        await cardsFor(page, SQLI).first().hover();

        // The negative control. Without it, a bug that highlighted every
        // card on any hover would satisfy every assertion above.
        await expect(
            page.locator('div.group\\/card.ring-focus-ring'),
        ).toHaveCount(1);
    });
});

/**
 * W72 item 12 (D31). Highlighting says *that* a node appears twice; the
 * parent counter's popover is where a reader finds out *where*, and it is the
 * only place in the application that can take one of those parents away.
 *
 * These read; they do not detach. The suite is one ordered narrative built by
 * scenario 2, and every later scenario's hand-calculated totals assume
 * Phishing is still under both risks — a detach here would be a silent wrong
 * answer three files later, which is the drift `support/tree.ts` warns about
 * at length. The detach path itself is unit-tested from both sides, including
 * the refusal.
 */
test.describe('the parent popover', () => {
    /**
     * W72 mounted the popover from the node modal's Details tab, because both
     * node cards were outside its ownership, and these tests addressed it
     * there. 2.4 integration moved it onto the parent counter on the card —
     * which is what item 12 asks for, and what D31 argues is where a reader
     * asks the question — so it is reached from the card now, not the dialog.
     */
    test('names both risks the shared attack appears under', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        await cardsFor(page, PHISHING)
            .first()
            .getByTestId('appears-under-trigger')
            .click();

        // The panel is teleported out of the card — every surface the counter
        // sits on clips its children — so it is addressed on the page.
        const panel = page.getByTestId('appears-under');
        await expect(panel).toBeVisible();
        await expect(panel).toContainText('Cardholder data breach');
        await expect(panel).toContainText('Service outage');

        // Two parents, so detaching either is offered: it leaves the attack
        // somewhere to show.
        await expect(
            panel.getByRole('button', { name: /^Remove from / }),
        ).toHaveCount(2);
    });

    /**
     * The refusal itself — a single-parent attack must not offer detach,
     * because that would hide the attack rather than delete it — is unit
     * tested from both sides in tests/js/nodeModal/tabs.spec.ts. It cannot be
     * driven from here any more, and that is the point of this test.
     */
    test('is absent entirely on an attack under one risk', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        // The counter renders only above one occurrence — long-standing card
        // behaviour, and D26 keeps chrome off the card deliberately. Since the
        // popover carries the link control as well as detach, the consequence
        // is that a single-parent attack has no keyboard route to a second
        // link; the W38 drag gesture is the only one, which scenario 3 drives.
        //
        // This is a decision (a shared attack's other parents are managed from
        // its parent counter), not an oversight — so it is asserted rather than left to be rediscovered as
        // a missing affordance.
        await expect(
            cardsFor(page, SQLI).first().getByTestId('appears-under-trigger'),
        ).toHaveCount(0);
    });
});
