import { expect, type Page } from '@playwright/test';

/**
 * Helpers shared by the tree-editor scenarios (PLAN.md's end-to-end list,
 * scenarios 2–9). They exist because those scenarios are one narrative:
 * scenario 2 builds the graph, and 4–9 all read the same one back.
 *
 * Two conventions are encoded here rather than repeated in every spec.
 *
 * **Focus is an index, not an element.** useKeyboardOutline.ts tracks the
 * focused occurrence in its own `focusedPath` ref, not in the DOM — clicking
 * a card does not move it, only the arrow keys do. So every keyboard step
 * below re-establishes focus deterministically: `Escape` clears it to null,
 * after which n presses of ArrowDown land on visible index n-1. Anything
 * that assumed focus persisted across a title commit drifted by one row and
 * then attached a node to the wrong parent, which is invisible until a total
 * comes out wrong three scenarios later.
 *
 * **Saves are asynchronous and unbatched.** Every mutation is its own
 * axios call; Show.vue's header shows "Saving…" then "Saved". Waiting for
 * that indicator is the only synchronisation point the UI actually offers,
 * and skipping it makes the next reload race the write.
 */

/** The title of the assessment scenario 2 creates and the rest of the suite reuses. */
export const assessmentTitle = 'Payment platform';

/**
 * The page's own content, excluding the sidebar (W41).
 *
 * The sidebar lists assessments by title, exactly as the list page does, so
 * from W41 onwards "click the link called X" is ambiguous unless it says
 * where. Every list-page interaction below goes through this; a locator that
 * genuinely means the *navigation* should say so instead, with
 * `page.getByRole('navigation', { name: 'Main' })`.
 */
export function mainContent(page: Page) {
    return page.getByRole('main');
}

/**
 * Opens an assessment by name — the shared one by default — and returns its
 * uuid. Every later scenario needs the uuid for a direct-URL visit (the
 * report, snapshots, a 403 check), and the list is the only place it is
 * discoverable: no route exposes an assessment by title.
 *
 * The `title` parameter exists for scenario 12, which works with a second
 * assessment cloned from a template. It reached it by hand — goto, click,
 * read — and so kept missing the switch to the list view this performs,
 * which is the sort of thing that is invisible until a card the chart does
 * not render is not found.
 */
export async function openAssessment(
    page: Page,
    title: string = assessmentTitle,
): Promise<string> {
    await page.goto('/assessments');
    // Exact: scenario 10 leaves a template-derived copy in the list, whose
    // title starts with the same words.
    //
    // Scoped to `main` since W41: the sidebar lists the same assessments by
    // the same titles, so an unscoped `getByRole('link')` now matches twice
    // and fails Playwright's strict mode. `mainContent()` is the one place
    // that boundary is written down.
    //
    // Two lists since 2.5.1: "My assessments" holds only what the caller
    // owns, and someone else's shared assessment is under "Shared with me".
    const link = () =>
        mainContent(page).getByRole('link', { name: title, exact: true });
    await expect(mainContent(page).getByRole('heading').first()).toBeVisible();
    if ((await link().count()) === 0) {
        await page.goto('/assessments/shared');
    }
    await link().click();
    await expect(page).toHaveURL(/\/assessments\/[0-9a-f-]{36}$/);

    const uuid = new URL(page.url()).pathname.split('/').pop();
    if (uuid === undefined || uuid === '') {
        throw new Error(`Could not read an assessment uuid from ${page.url()}`);
    }

    // W32 made the chart the default view. Every scenario below this helper
    // reads the *list* — its rows, its value chips, its inline title field —
    // so the switch happens here, once, rather than being an unstated
    // precondition each spec would fail on mysteriously. Scenario 15 is the
    // one that asserts what the default actually is, and it does not use
    // this helper.
    //
    // The view switcher is the right thing to wait on before touching it: it
    // is part of the toolbar, which both views render, so it is the earliest
    // view-independent sign that the page has hydrated.
    await expect(page.getByRole('radio', { name: 'List' })).toBeVisible();
    await useListView(page);

    // The tree wait comes *after* the switch, not before: both views render a
    // `<ul role="tree">`, so before it this waited on whichever one the
    // default view happened to show and told the caller nothing about the one
    // they are about to read.
    await expect(page.getByRole('tree')).toBeVisible();

    return uuid;
}

/** Switches the editor to the indented list and waits for it to be showing. */
export async function useListView(page: Page): Promise<void> {
    const list = page.getByRole('radio', { name: 'List' });
    if ((await list.getAttribute('aria-checked')) !== 'true') {
        await list.click();
    }
    await expect(list).toHaveAttribute('aria-checked', 'true');
}

/** Clears keyboard focus, then walks down to the given 0-based visible row. */
export async function focusRow(page: Page, index: number): Promise<void> {
    await page.keyboard.press('Escape');
    for (let i = 0; i <= index; i++) {
        await page.keyboard.press('ArrowDown');
    }
}

/**
 * Adds a child of the given row from the keyboard (2.5.1): Tab opens the node
 * modal on a *draft*, the title field there takes focus, Enter adds it, and
 * the modal switches to the node it created. Nothing exists on the server
 * until that Enter — the reported defect was a node created by Tab itself.
 */
export async function addChildByKeyboard(
    page: Page,
    parentRow: number,
    title: string,
): Promise<void> {
    await focusRow(page, parentRow);
    await page.keyboard.press('Tab');

    const dialog = page.getByRole('dialog');
    await expect(dialog.getByTestId('new-node-form')).toBeVisible();
    const field = dialog.getByTestId('new-node-title').locator('input');
    await expect(field).toBeFocused();

    await field.fill(title);
    await page.keyboard.press('Enter');

    // The modal is now on the created node, not the draft.
    await expect(dialog.getByTestId('new-node-form')).toHaveCount(0);
    await closeNodeModal(page);
    await expect(cardFor(page, title)).toBeVisible();
}

/**
 * Waits for the autosave indicator to settle. It appears as "Saving…" and
 * resolves to "Saved"; asserting on the resolved state is what makes a
 * following reload safe.
 */
export async function expectSaved(page: Page): Promise<void> {
    await expect(
        page.getByRole('status').filter({ hasText: 'Saved' }),
    ).toBeVisible({
        timeout: 15_000,
    });
}

/**
 * Opens a node's modal by clicking its title — the same gesture in the list
 * and the chart since 2.5.1, which removed the list's gear icon.
 */
export async function openNodeModal(
    page: Page,
    title: string,
    tab?: string,
): Promise<void> {
    await titleButtonFor(page, title).click();
    await expect(page.getByRole('dialog')).toBeVisible();

    if (tab !== undefined) {
        await page.getByRole('dialog').getByRole('tab', { name: tab }).click();
    }
}

export async function closeNodeModal(page: Page): Promise<void> {
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);
}

/**
 * Every occurrence of a node, as NodeCard root elements.
 *
 * Deliberately NOT `getByRole('treeitem')`: a treeitem is the `<li>`, and an
 * `<li>` contains the nested `<ul>` of everything below it — so the root's
 * treeitem contains every button in the tree, and filtering treeitems by a
 * descendant button matches every ancestor of that node as well as the node.
 * The first early version of this helper did exactly that and clicked the
 * root's action button while believing it had clicked a leaf's.
 *
 * A NodeCard's own root div holds only that occurrence's row, because
 * TreeNode.vue renders children into a sibling `<ul>` outside it — which is
 * what makes this the correct boundary for "one card".
 */
export function cardsFor(page: Page, title: string) {
    return page.locator('div.group\\/card').filter({
        has: page.getByRole('button', { name: title, exact: true }),
    });
}

/** The first occurrence of a node; `cardsFor` when every occurrence matters. */
export function cardFor(page: Page, title: string) {
    return cardsFor(page, title).first();
}

/**
 * The three assessment totals — Base, Current, Target ALE.
 *
 * W36 moved them off the docked `Toolbar/TotalsPanel.vue` and onto the ToS
 * card, which is the node they describe; the panel is deleted. They are still
 * a polite live region, because a collaborator's edit changes them without
 * this reader touching anything. Every caller that asserted on the panel
 * asserts on the same three figures here, so this is a re-point, not a
 * weakening — the assertions did not change, only where they look.
 */
export function totalsPanel(page: Page) {
    return page.getByTestId('assessment-totals');
}

export interface GraphProps {
    nodes: { uuid: string; type: string; title: string; position: number }[];
    riskAttackLinks: {
        risk_uuid: string;
        attack_uuid: string;
        position: number;
    }[];
    attackDefenceLinks: {
        attack_uuid: string;
        defence_uuid: string;
        position: number;
        strength_fraction: number | null;
    }[];
}

/**
 * The assessment graph exactly as the server sent it, read out of Inertia's
 * own `data-page` attribute.
 *
 * Node uuids are the identity every mutation endpoint takes, and the tree
 * renders none of them — no `id`, no `data-uuid`, nothing. Several scenarios
 * (reordering, moving an edge, the direct-URL 403 checks) need a real uuid
 * to address a real node, and this is the only place the page actually
 * publishes them.
 *
 * Read from `window.history.state.page`, not from `#app[data-page]`: Inertia
 * deletes that attribute during hydration, so it is absent on every page
 * this suite ever looks at — including one loaded directly by URL. The
 * history state is also the version Inertia keeps *current* across
 * client-side visits, so this stays correct after an in-app navigation.
 *
 * These are the server's props, so they reflect the last response, not an
 * edit still in flight; call it after a save has settled or after a reload.
 */
export async function readGraph(page: Page): Promise<GraphProps> {
    return page.evaluate(() => {
        const props = (
            window.history.state as { page?: { props?: unknown } } | null
        )?.page?.props;
        if (props === undefined) {
            throw new Error(
                'No Inertia page state on this document — was the page loaded?',
            );
        }

        return props as GraphProps;
    });
}

/** The uuid of the single node with this exact title. */
export async function uuidOf(page: Page, title: string): Promise<string> {
    const graph = await readGraph(page);
    const node = graph.nodes.find((n) => n.title === title);
    if (node === undefined)
        throw new Error(`No node titled "${title}" in this assessment.`);

    return node.uuid;
}

/**
 * The card's title. Since 2.5.1 a click on it opens the node modal on
 * Details, in the list exactly as in the chart; there is no inline rename.
 */
export function titleButtonFor(page: Page, title: string) {
    return cardFor(page, title).getByRole('button', {
        name: title,
        exact: true,
    });
}

/**
 * Renames a node through its modal's Details tab, and waits for the save.
 * Leaves the modal open, so a caller can go on to another tab; close it with
 * `closeNodeModal`.
 */
export async function renameNode(
    page: Page,
    from: string,
    to: string,
): Promise<void> {
    await openNodeModal(page, from);
    await page
        .getByRole('dialog')
        .getByLabel(/^Title/)
        .fill(to);
    await expectSaved(page);
}
