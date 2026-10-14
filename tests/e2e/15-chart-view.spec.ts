import { expect, test } from '@playwright/test';
import { login } from './support/auth';
import { adminEmail, adminPassword } from './support/env';
import { assessmentTitle, mainContent, useListView } from './support/tree';

/**
 * Scenario 15 (PLAN-2.1.md W32): the chart view — the assessment as an org
 * chart, four tiers, no vertical scrolling.
 *
 * Deliberately does **not** use `openAssessment()`: that helper switches to
 * the list, because every other scenario reads the list. This one has to see
 * the editor exactly as a user finds it, which is the first thing it
 * asserts — the chart is the default, and a regression that quietly restored
 * the list would change what every user sees first without changing a pixel
 * of either view.
 */

/**
 * The first risk scenario 3 authors. Spelled "Customer data breach" here
 * until the suite was first run end to end, which is a title no scenario
 * ever creates — so every assertion that addressed a card by it waited out
 * its timeout against an assessment that was perfectly correct.
 */
const RISK = 'Cardholder data breach';

/** Opens the shared assessment without the list-view switch the helper does. */
async function openChart(
    page: import('@playwright/test').Page,
): Promise<string> {
    await page.goto('/assessments');
    await mainContent(page)
        .getByRole('link', { name: assessmentTitle, exact: true })
        .click();
    await expect(page).toHaveURL(/\/assessments\/[0-9a-f-]{36}$/);
    await expect(page.getByRole('tree')).toBeVisible();

    return new URL(page.url()).pathname.split('/').pop()!;
}

test.describe('the chart view', () => {
    test('is what the editor opens in', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        await openChart(page);

        await expect(
            page.getByRole('radio', { name: 'Chart' }),
        ).toHaveAttribute('aria-checked', 'true');
        // W37 deleted the tier gutter, so "Defences" is no longer printed down
        // the left edge. The defence tier is still there — it is announced on
        // the cards themselves now.
        await expect(
            page.getByRole('button', { name: /^Defence: / }).first(),
        ).toBeVisible();
    });

    test('every valued card carries a values indicator, in one of two states', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openChart(page);

        const indicators = page.locator('[data-values-set]');
        await expect(indicators.first()).toBeVisible();

        // Both states must actually occur in the seeded assessment, or this
        // asserts only that the "set" branch renders.
        expect(await indicators.count()).toBeGreaterThan(1);
    });

    test('the values indicator opens the modal on the tab that fixes it', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openChart(page);

        await page.locator('[data-values-set]').first().click();

        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();
        await expect(
            dialog.getByRole('tab', { selected: true }),
        ).not.toHaveAccessibleName('Details');

        await page.keyboard.press('Escape');
    });

    test('deleting is in the modal header, not under a hover icon on the card', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openChart(page);

        const card = page
            .getByRole('button', { name: `Risk: ${RISK}` })
            .locator('xpath=ancestor::div[contains(@class,"group/chartcard")]')
            .first();

        await card.hover();
        // The hover panel keeps collapse and add-child only.
        await expect(card.getByRole('button', { name: 'Delete' })).toHaveCount(
            0,
        );

        // 2.5.1: a single click on the title opens the modal, as in the list.
        await card.getByRole('button', { name: `Risk: ${RISK}` }).click();
        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();
        await expect(
            dialog.getByRole('button', { name: 'Delete' }),
        ).toBeVisible();

        await page.keyboard.press('Escape');
    });

    test('fits four tiers without scrolling the page vertically', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await login(page, adminEmail, adminPassword());
        await openChart(page);

        // The structural claim behind the whole view: depth is bounded at
        // four by the schema, so the reader only ever manages one dimension.
        //
        // W37 raised the card to 124px to fit three description lines, which
        // is sized for 1080p — at this 800px viewport the four tiers exceed
        // the space, and the chart's own container takes the scroll (W34).
        // The *page* still must not, which is what this asserts: the toolbar
        // and the switcher stay put while the board moves under them.
        const overflowsVertically = await page.evaluate(
            () =>
                document.documentElement.scrollHeight >
                document.documentElement.clientHeight + 1,
        );
        expect(overflowsVertically).toBe(false);
    });

    /**
     * Reversed by 2.4 item 4 (D26), deliberately.
     *
     * W37 stripped every figure off the chart card, and this test pinned that.
     * The argument held while the alternative was four cue ramps in four
     * visual languages — a reader still had to hover each one to learn
     * anything. D26 is a different proposition: one short figure, in one fixed
     * slot, on every tier, where a checkmark used to say only whether someone
     * had filled the field in.
     *
     * So the assertion is inverted rather than deleted, and it asserts the
     * *abbreviated* form specifically. The card carries `€200k`, not
     * `€200,000.00` — full precision belongs to the list and the report, which
     * is the part of W37's reasoning that survives.
     */
    test('carries the impact as a short figure, not at full precision', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openChart(page);

        const chart = page.getByRole('tree');
        await expect(chart).toContainText(RISK);
        await expect(chart).toContainText('€200k');
        await expect(chart).not.toContainText('200,000');
    });

    test('opens the same node modal the list view opens, on a single click', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await openChart(page);

        await page
            .getByRole('button', { name: `Risk: ${RISK}`, exact: true })
            .first()
            .click();

        await expect(page.getByRole('dialog')).toBeVisible();
        await expect(page.getByRole('dialog')).toContainText(RISK);
        await page.keyboard.press('Escape');
    });

    test('switching to the list and back keeps collapse state and does not reload', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openChart(page);

        // Collapse the risk in the chart, then switch views twice. The two
        // views share one store and one editor view, so this state has to
        // survive; if a switch remounted the page it would not.
        // Both locators are scoped to the risk's own element rather than to
        // the first match on the page. A `<li role="treeitem">` contains the
        // nested list of everything beneath it, so the *root's* treeitem
        // contains the risk's title too — `filter({ hasText: RISK }).first()`
        // returned the root, read its `aria-expanded="true"`, and reported a
        // collapse that had in fact happened. Same trap `support/tree.ts`
        // documents for `cardsFor`; `aria-level` is what separates the tiers.
        const card = page
            .getByRole('button', { name: `Risk: ${RISK}`, exact: true })
            .locator('xpath=ancestor::div[contains(@class,"group/chartcard")]')
            .first();

        await card.hover();
        await card.getByRole('button', { name: 'Collapse' }).click();

        await useListView(page);
        await page.getByRole('radio', { name: 'Chart' }).click();

        await expect(page).toHaveURL(new RegExp(`/assessments/${uuid}$`));
        await expect(
            page
                .locator('li[role="treeitem"][aria-level="2"]')
                .filter({ hasText: RISK })
                .first(),
        ).toHaveAttribute('aria-expanded', 'false');
    });

    /**
     * W105. The template picker and the assistant's suggestions float below
     * the card that opened them, inside the chart's scroll container. That
     * container used to be only as tall as the chart, so on a card with
     * nothing beneath it — a new assessment's root, or any collapsed one —
     * the panel opened into a strip a few pixels high and was cut off.
     * Collapsing the root reproduces that on the shared fixture without
     * creating an assessment later scenarios would then see in their lists.
     */
    test('a panel opened below a card with nothing beneath it is not cut off', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await login(page, adminEmail, adminPassword());
        await openChart(page);

        const root = page
            .locator('li[role="treeitem"][aria-level="1"]')
            .locator('div.group\\/chartcard')
            .first();
        await root.hover();
        await root.getByRole('button', { name: 'Collapse' }).click();
        await expect(
            page.locator('li[role="treeitem"][aria-level="1"]'),
        ).toHaveAttribute('aria-expanded', 'false');

        await root.hover();
        await root.getByTestId('add-from-template').click();

        const picker = page.getByTestId('template-picker');
        await expect(picker).toBeVisible();

        // Visible in the DOM is not visible on screen: the container clips
        // it. What the reader sees is the part of the picker inside the
        // scroll container's box, and all of it must be.
        const scroller = page
            .getByRole('tree')
            .locator(
                'xpath=ancestor::div[contains(@class,"overflow-auto")][1]',
            );
        const pickerBox = (await picker.boundingBox())!;
        const scrollerBox = (await scroller.boundingBox())!;
        expect(pickerBox.height).toBeGreaterThan(100);
        expect(pickerBox.y + pickerBox.height).toBeLessThanOrEqual(
            scrollerBox.y + scrollerBox.height,
        );
    });

    test('remembers the chosen view on the next visit', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openChart(page);

        await useListView(page);
        await page.goto(`/assessments/${uuid}`);

        await expect(page.getByRole('radio', { name: 'List' })).toHaveAttribute(
            'aria-checked',
            'true',
        );

        // Leave the shared fixture as the next scenario expects to find it.
        await page.getByRole('radio', { name: 'Chart' }).click();
    });
});
