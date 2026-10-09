import { expect, test, type Page } from '@playwright/test';
import { apiRequest } from './support/api';
import { login } from './support/auth';
import { adminEmail, adminPassword } from './support/env';
import { cardFor, openAssessment, readGraph, uuidOf } from './support/tree';

/**
 * Scenario 6 of PLAN.md's end-to-end list: "Drag a defence to a different
 * attack; reorder risks; reload and confirm the order persisted."
 *
 * Until W27 this spec could not perform the gesture at all:
 * `resources/js/composables/useDragDrop.ts` was written, unit-tested and
 * imported by nothing, no element carried `draggable`, and no other
 * affordance for reordering or re-parenting existed anywhere in the UI. It
 * therefore drove `POST .../reorder` and `POST .../move` directly and
 * pinned the gap with a `[draggable="true"]` count of zero. W27 wired the
 * composable into `Components/Tree/**`, so the gestures below are real and
 * the assertions are the ones this file always made.
 *
 * ## Where the drag actually lands
 *
 * The drag source and the drop target are both the NodeCard root element —
 * one card is one *occurrence*, which is the unit a drag moves (ui.md
 * §3.1). Deliberately not the enclosing `<li role="treeitem">`, which wraps
 * the whole subtree beneath it: a drop on a leaf would register on every
 * ancestor as well. `cardFor()` in support/tree.ts addresses exactly that
 * element.
 *
 * A drop means "put the dragged occurrence where this one is". Same parent
 * is a reorder; a different parent of the same scope is a re-parent. So the
 * target of a defence move is another *defence* under the destination
 * attack, not the attack itself — the payload the target carries is what
 * names the destination parent.
 */

const BREACH = 'Cardholder data breach';
const OUTAGE = 'Service outage';
const PHISHING = 'Phishing';
const SQLI = 'SQL injection';
const MFA = 'Multi-factor authentication';
const WAF = 'Web application firewall';

/** Titles of the top-level risks, in the order the tree renders them. */
async function riskOrder(page: Page): Promise<string[]> {
    const graph = await readGraph(page);

    return graph.nodes
        .filter((n) => n.type === 'risk')
        .sort((a, b) => a.position - b.position)
        .map((n) => n.title);
}

/**
 * Drags one card onto another and waits for the write it triggers.
 *
 * The wait is the point. `onDrop` posts and only then patches the store, so
 * a reload issued straight after the gesture races the request and fails as
 * "the order did not persist" — the same class of failure the "Saved"
 * indicator guards against for node edits. Edge mutations never touch the
 * store's `saveState`, so there is no indicator to wait on here; the
 * response is the only synchronisation point the UI offers.
 */
async function dragCardOnto(
    page: Page,
    sourceTitle: string,
    targetTitle: string,
    endpoint: 'reorder' | 'move',
): Promise<void> {
    const settled = page.waitForResponse(
        (r) =>
            r.url().endsWith(`/${endpoint}`) && r.request().method() === 'POST',
    );

    await cardFor(page, sourceTitle).dragTo(cardFor(page, targetTitle));

    // W38: a drop onto a *different* parent is ambiguous in a DAG — the node
    // can move there or belong to both — so it now asks, and nothing is sent
    // until the question is answered. Reordering among siblings is not
    // ambiguous and still posts straight away, which is why only this arm
    // needs the extra click and why the reorder cases above never noticed.
    if (endpoint === 'move') {
        await page.getByRole('button', { name: 'Move it here' }).click();
    }

    const response = await settled;

    expect(response.status(), `${endpoint} should have been accepted`).toBe(
        200,
    );
}

test.describe('reordering and re-parenting', () => {
    test('the tree offers a drag affordance on every node but the root', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        // Six draggable cards: two risks, two attacks — one of which
        // (Phishing) is shared and therefore renders twice — and two
        // defences, one of which (MFA) hangs off both Phishing occurrences.
        // The root is neither draggable nor a drop target: it has no
        // siblings to reorder among and no other parent to move to.
        await expect(page.locator('[draggable="true"]').first()).toBeVisible();
        await expect(cardFor(page, BREACH)).toHaveAttribute(
            'draggable',
            'true',
        );
        await expect(cardFor(page, WAF)).toHaveAttribute('draggable', 'true');

        const root = page.locator('div.group\\/card').first();
        await expect(root).not.toHaveAttribute('draggable', 'true');
    });

    test('risks can be resequenced by dragging, and the new order survives a reload', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        expect(await riskOrder(page)).toEqual([BREACH, OUTAGE]);

        // Risk-level ordering lives on the node rows themselves (`scope:
        // nodes`); the two edge scopes below live on their pivots.
        await dragCardOnto(page, OUTAGE, BREACH, 'reorder');

        await page.reload();
        expect(await riskOrder(page)).toEqual([OUTAGE, BREACH]);

        // Back again, so later scenarios read the tree in the order their
        // own comments assume.
        await dragCardOnto(page, BREACH, OUTAGE, 'reorder');
        await page.reload();
        expect(await riskOrder(page)).toEqual([BREACH, OUTAGE]);
    });

    test('a defence dragged to another attack carries its strength with it', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page);

        const phishing = await uuidOf(page, PHISHING);
        const sqli = await uuidOf(page, SQLI);
        const waf = await uuidOf(page, WAF);

        const before = await readGraph(page);
        const wafEdgeBefore = before.attackDefenceLinks.find(
            (l) => l.defence_uuid === waf,
        );
        expect(wafEdgeBefore?.attack_uuid).toBe(sqli);
        expect(wafEdgeBefore?.strength_fraction).toBe(0.5);

        // Onto MFA, which is the defence sitting under Phishing — dropping
        // on it means "put WAF where MFA is", and MFA's parent is the
        // destination.
        await dragCardOnto(page, WAF, MFA, 'move');

        await page.reload();
        const after = await readGraph(page);
        const wafEdgeAfter = after.attackDefenceLinks.find(
            (l) => l.defence_uuid === waf,
        );

        expect(wafEdgeAfter?.attack_uuid).toBe(phishing);
        // Strength lives on the pivot (schema.md §3.6), so a move is a
        // delete-and-recreate of that row — and the value it carried has to
        // survive it. Losing this silently would change every total on the
        // page with nothing on screen to explain why.
        expect(wafEdgeAfter?.strength_fraction).toBe(0.5);

        // Only one edge, not two: a move must not leave the old one behind.
        expect(
            after.attackDefenceLinks.filter((l) => l.defence_uuid === waf),
        ).toHaveLength(1);

        // The restore goes over HTTP rather than by dragging, and has to:
        // the move just emptied SQL injection of defences, so there is no
        // longer a card under it to drop onto. Re-parenting onto an empty
        // parent is a real gap in the gesture, noted in
        // docs/RELEASE-2.0.0.md; it is not what this test is asserting.
        await apiRequest(page, 'post', `/assessments/${uuid}/move`, {
            edge: 'attack_defence',
            from_parent_uuid: phishing,
            to_parent_uuid: sqli,
            child_uuid: waf,
        });
        await page.reload();
        const restored = await readGraph(page);
        expect(
            restored.attackDefenceLinks.find((l) => l.defence_uuid === waf)
                ?.attack_uuid,
        ).toBe(sqli);
    });

    test('dragging one occurrence of a shared node leaves the other alone', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page);

        // Phishing sits under both risks. Ordering lives on the edge, so
        // resequencing it under one risk must not touch the other — this is
        // the property a `parent_id` column could not have expressed, and
        // the reason the schema has none. `cardFor` returns the first
        // occurrence, which is the one under the breach risk.
        const breach = await uuidOf(page, BREACH);
        const phishing = await uuidOf(page, PHISHING);
        const sqli = await uuidOf(page, SQLI);

        await dragCardOnto(page, SQLI, PHISHING, 'reorder');

        await page.reload();
        const graph = await readGraph(page);

        const underBreach = graph.riskAttackLinks
            .filter((l) => l.risk_uuid === breach)
            .sort((a, b) => a.position - b.position)
            .map((l) => l.attack_uuid);
        expect(underBreach).toEqual([sqli, phishing]);

        // Phishing still appears under the outage risk, untouched.
        expect(
            graph.riskAttackLinks.filter((l) => l.attack_uuid === phishing),
        ).toHaveLength(2);

        await apiRequest(page, 'post', `/assessments/${uuid}/reorder`, {
            scope: 'risk_attack',
            parent_uuid: breach,
            order: [phishing, sqli],
        });
    });
});
