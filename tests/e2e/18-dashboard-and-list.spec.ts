import { expect, test, type Page } from '@playwright/test';
import { login } from './support/auth';
import { adminEmail, adminPassword } from './support/env';
import { assessmentTitle, mainContent } from './support/tree';

/**
 * Scenario 18 (PLAN-2.2.md W42): the dashboard, the list filter, and
 * Duplicate.
 *
 * The filter is here rather than only in `tests/js` for one reason: its whole
 * claim is that it makes **no request**. A unit test can assert that the
 * router mock was not called; only a browser can assert that nothing went over
 * the wire, which is what the request listener below does.
 *
 * Duplicate is here because it is the only W42 action that produces a second
 * assessment, and the thing worth checking end to end is that the copy shows
 * up in the list under a name that tells it apart from its source.
 *
 * W73 reshapes the dashboard into two sections of tabs (D32) and adds two
 * destinations — "New assessment", which now opens the creation dialog rather
 * than dropping the user on a list, and the shared page
 * (D33). Three claims here need a real browser rather than jsdom: that
 * `tabindex="-1"` genuinely keeps a tab out of the focus order, that the
 * create button produces an assessment the sidebar and the list then both
 * show, and that `/assessments/shared` is not swallowed by the unconstrained
 * `assessments/{assessment:uuid}` route registered beside it — a routing
 * order that only a real request can prove.
 */

const COPY_SUFFIX = ' (copy)';

test.describe('the dashboard', () => {
    test('is its own page, not the assessment list again', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/dashboard');

        await expect(
            page.getByRole('heading', { name: 'Dashboard', level: 1 }),
        ).toBeVisible();

        // Two sections since W73 (D32), not four stacked lists: the two that
        // answer "what needs me" and the two that answer "where do I go"
        // were half a screen apart, and neither pair fit above the fold.
        for (const name of ['Notifications', 'Assessments']) {
            await expect(page.getByRole('heading', { name })).toBeVisible();
        }

        // The four lists survive as tabs, and every one of them is reachable
        // whether or not it has anything in it.
        for (const name of [
            'Assigned to me',
            'Mentions',
            'Mine',
            'Shared with me',
        ]) {
            await expect(page.getByRole('tab', { name })).toBeVisible();
        }
    });

    test('the tabs switch panels, and the first of each is open on arrival', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/dashboard');

        // Work first: "Assigned to me" is the tab you land on, not the
        // inventory. A dashboard that opens with a list of things you own has
        // told you nothing you did not know.
        await expect(
            page.getByRole('tab', { name: 'Assigned to me' }),
        ).toHaveAttribute('aria-selected', 'true');
        await expect(page.getByRole('tab', { name: 'Mine' })).toHaveAttribute(
            'aria-selected',
            'true',
        );

        await page.getByRole('tab', { name: 'Mentions' }).click();
        await expect(
            page.getByRole('tab', { name: 'Mentions' }),
        ).toHaveAttribute('aria-selected', 'true');
        await expect(
            page.getByRole('tab', { name: 'Assigned to me' }),
        ).toHaveAttribute('aria-selected', 'false');
    });

    test('the tablist is walkable with the arrow keys', async ({ page }) => {
        // Roving tabindex: Tab reaches the tablist once and the arrows move
        // within it. Only a real browser applies `tabindex="-1"` to focus
        // order, which is why this claim is here and not in tests/js.
        await login(page, adminEmail, adminPassword());
        await page.goto('/dashboard');

        await page.getByRole('tab', { name: 'Assigned to me' }).focus();
        await page.keyboard.press('ArrowRight');

        await expect(page.locator(':focus')).toHaveAttribute('role', 'tab');
        await expect(
            page.getByRole('tab', { name: 'Mentions' }),
        ).toHaveAttribute('aria-selected', 'true');
    });

    /**
     * The four empty states survive D32 as tab-panel empty states — the point
     * of that decision was that the two questions ("what needs me", "where do
     * I go") should be one screen apart rather than half a screen, not that
     * the explanations should go.
     *
     * So the Mentions copy is no longer on screen at rest: it lives in the
     * Mentions panel, which is hidden until that tab is selected. Selecting it
     * first is the change; the claim — an empty section explains itself rather
     * than leaving a gap — is unchanged, and is now asserted for both tabs in
     * the Notifications section rather than only the one that used to be
     * visible on load.
     */
    test('explains the empty sections instead of leaving gaps', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/dashboard');

        // Assigned to me is the selected tab on arrival.
        await expect(
            page.getByText('Assigned to you', { exact: false }).first(),
        ).toBeVisible();

        await page.getByRole('tab', { name: 'Mentions' }).click();

        await expect(
            page.getByText('Comments that mention you', { exact: false }),
        ).toBeVisible();
    });

    test('links an assessment straight into the editor', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/dashboard');

        await mainContent(page)
            .getByRole('link', { name: assessmentTitle, exact: true })
            .first()
            .click();

        await expect(page).toHaveURL(/\/assessments\/[0-9a-f-]{36}$/);
    });
});

test.describe('the list filter', () => {
    test('narrows the list without going to the server', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/assessments');

        const rows = mainContent(page).locator('[data-assessment]');
        const before = await rows.count();
        expect(before).toBeGreaterThan(0);

        // Any request for *data* from here is a failure of the claim. Static
        // assets are excluded deliberately and not as a loophole: Vite
        // prefetches the page chunks behind the links on this list, which is
        // the router warming its own cache and happens whether or not anyone
        // types. What must not happen is the list being re-fetched — a
        // document navigation or an Inertia XHR — because the whole list is
        // already here and filtering it on the server would be slower and no
        // more correct.
        const requests: string[] = [];
        page.on('request', (request) => {
            if (['document', 'xhr', 'fetch'].includes(request.resourceType())) {
                requests.push(request.url());
            }
        });

        await page
            .getByRole('searchbox', { name: 'Filter assessments' })
            .fill('zzzz-no-such-assessment');

        await expect(page.getByText('No assessments match that')).toBeVisible();
        expect(requests).toEqual([]);

        // And clearing it puts everything back, still without a request.
        await page
            .getByRole('searchbox', { name: 'Filter assessments' })
            .fill('');
        await expect(rows).toHaveCount(before);
        expect(requests).toEqual([]);
    });

    test('matches on a substring of the title', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/assessments');

        await page
            .getByRole('searchbox', { name: 'Filter assessments' })
            .fill(assessmentTitle.slice(0, 6).toLowerCase());

        await expect(
            mainContent(page).getByRole('link', {
                name: assessmentTitle,
                exact: true,
            }),
        ).toBeVisible();
    });
});

test.describe('duplicate', () => {
    test('produces a second assessment, named so it can be told apart', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/assessments');

        const row = mainContent(page)
            .locator('[data-assessment]')
            .filter({
                has: page.getByRole('link', {
                    name: assessmentTitle,
                    exact: true,
                }),
            })
            .first();

        await row.getByRole('button', { name: 'Duplicate' }).click();

        // Back on the list, with the copy visible. `AssessmentCloner` copies
        // the root title verbatim, so without the rename this row and its
        // source would be indistinguishable here and in the sidebar.
        await expect(page).toHaveURL(/\/assessments$/);
        await expect(
            mainContent(page).getByRole('link', {
                name: assessmentTitle + COPY_SUFFIX,
                exact: true,
            }),
        ).toBeVisible();
    });

    test('the copy is independent of its source', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/assessments');

        await mainContent(page)
            .getByRole('link', {
                name: assessmentTitle + COPY_SUFFIX,
                exact: true,
            })
            .click();
        await expect(page).toHaveURL(/\/assessments\/[0-9a-f-]{36}$/);
        const copyUuid = new URL(page.url()).pathname.split('/').pop();

        await page.goto('/assessments');
        await mainContent(page)
            .getByRole('link', { name: assessmentTitle, exact: true })
            .click();
        const sourceUuid = new URL(page.url()).pathname.split('/').pop();

        // Different identities, not two names for the same rows — the
        // property AssessmentCloner exists to provide and has broken twice.
        expect(copyUuid).not.toBe(sourceUuid);
    });
});

test.describe('New assessment opens the creation dialog (W73 item 19)', () => {
    /**
     * Placed last in this file on purpose. It leaves a real, extra assessment
     * behind, and the suite is order-dependent — so it runs after everything
     * in scenario 18 that counts rows or filters them.
     *
     * W73 first made this control POST an untitled assessment outright. That
     * was removed at 2.4 integration: the list page already carries a button
     * with this exact name that asks for a title, so the page ended up with
     * two identically named controls doing two different things — which this
     * suite hit as an unresolvable locator, and a screen-reader user would hit
     * as two indistinguishable announcements. Both now open the one dialog.
     */
    const sidebar = (page: Page) =>
        page.getByRole('navigation', { name: 'Main' });

    test('opens the titled creation dialog rather than a bare list', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/dashboard');

        // Inside the My assessments group, below the list it adds to. Until
        // W73 this was an `href` at `assessments.index` with no query: a
        // control named for an action that navigated to a list instead, where
        // the user then had to find the real create affordance themselves.
        await sidebar(page)
            .getByRole('link', { name: 'New assessment' })
            .click();

        await expect(page).toHaveURL(/\/assessments\?new=1$/);

        // The dialog is open on arrival — that is the whole difference from
        // the pre-W73 control, and asserting the URL alone would not see it.
        await expect(
            page.getByRole('button', { name: 'Create assessment' }),
        ).toBeVisible();
    });

    test('creates the assessment and shows it in the sidebar and the list', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/dashboard');

        await sidebar(page)
            .getByRole('link', { name: 'New assessment' })
            .click();
        await page.getByLabel(/^Title/).fill('Made from the sidebar');
        await page.getByRole('button', { name: 'Create assessment' }).click();

        await expect(page).toHaveURL(/\/assessments\/[0-9a-f-]{36}$/);

        await page.goto('/assessments');

        // The control lives inside the group it adds to, so the row it
        // creates has to appear there — otherwise the button looks inert.
        await expect(
            sidebar(page)
                .getByRole('link', { name: 'Made from the sidebar' })
                .first(),
        ).toBeVisible();
        await expect(
            mainContent(page)
                .getByRole('link', {
                    name: 'Made from the sidebar',
                    exact: true,
                })
                .first(),
        ).toBeVisible();
    });
});

test.describe('the shared page (W73 item 20, D33)', () => {
    test('is reachable from the sidebar group header', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/dashboard');

        // The header used to point at `/assessments`, a list that mixes owned
        // and shared rows and does not distinguish them — a promise its
        // destination could not keep.
        await page
            .getByRole('navigation', { name: 'Main' })
            .getByRole('link', { name: 'Shared with me', exact: true })
            .click();

        await expect(page).toHaveURL(/\/assessments\/shared$/);
        await expect(
            page.getByRole('heading', {
                name: 'Assessments shared with me',
                level: 1,
            }),
        ).toBeVisible();

        // 2.5.1: the page is a list, not an assessment, so the navigation
        // stays expanded. The layout's "inside an assessment" match used to
        // take any segment after /assessments/, which included "shared".
        await expect(page.getByTestId('nav-toggle')).toHaveAttribute(
            'aria-expanded',
            'true',
        );
    });

    test('the literal path is not swallowed by the uuid route beside it', async ({
        page,
    }) => {
        // `assessments.show` binds `assessments/{assessment:uuid}` with no
        // pattern constraint, so this page only exists because its route is
        // registered before it. A 404 here means someone tidied the block
        // down to the foot of routes/web.php.
        await login(page, adminEmail, adminPassword());
        const response = await page.goto('/assessments/shared');

        expect(response?.status()).toBe(200);
    });

    test('says nobody has shared anything, rather than that you have none', async ({
        page,
    }) => {
        // The admin owns everything in this run and has been invited to
        // nothing, so this is the empty case — and the empty case is the
        // reason the page exists rather than a filter on the combined list.
        await login(page, adminEmail, adminPassword());
        await page.goto('/assessments/shared');

        await expect(page.getByText('Nothing shared with you')).toBeVisible();
        await expect(page.getByText('Create one to start mapping')).toHaveCount(
            0,
        );
    });
});
