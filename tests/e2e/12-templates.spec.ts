import { expect, test } from '@playwright/test';
import { apiRequest } from './support/api';
import { login } from './support/auth';
import { adminEmail, adminPassword } from './support/env';
import {
    closeNodeModal,
    mainContent,
    openAssessment,
    readGraph,
    renameNode,
    totalsPanel,
    useListView,
} from './support/tree';

/**
 * Scenario 10 of PLAN.md's end-to-end list: "Create a new assessment from
 * the ISO 27001 template and confirm the catalogue defaults resolved against
 * that assessment's scales."
 *
 * ## Why not the ISO 27001 template
 *
 * The shipped templates are seeder fixtures
 * (`database/seeders/templates/*.json`, W22), and `TemplateAssessmentSeeder`
 * requires an owning `User` — every row it writes sets `owner_user_id`,
 * `created_by` and `updated_by`. A deployment's entrypoint runs
 * `migrate --force` and no seeders, and it cannot run this one either,
 * because at first boot there are no users at all: the first account is
 * created through the setup screen, by a person, after the container is
 * already serving. So a fresh install has an empty template library, and
 * `DatabaseSeeder` cannot be used to fill it because it creates a test user,
 * which would break the empty-users-table precondition first-run setup
 * depends on. Reported with W25 rather than worked around here.
 *
 * This scenario therefore exercises the same mechanism through the route a
 * user actually has: mark an existing assessment as a template, then create
 * a new assessment from it. That is `AssessmentCloner` — the service
 * CLAUDE.md singles out as having been broken twice by silently-dropped
 * identity columns, both times while the whole suite stayed green — so the
 * assertions below deliberately read every part of the copy back rather than
 * checking that a redirect happened.
 */

const ORIGINAL = 'Payment platform';
const COPY = 'Payment platform (from template)';
const BREACH = 'Cardholder data breach';
const PHISHING = 'Phishing';
const MFA = 'Multi-factor authentication';

test.describe('templates', () => {
    test('an assessment can be marked as a template', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page);

        // `saveAsTemplate` — admins only since 2.5.1, because every account
        // on the instance can start from a template. The admin is acting
        // here, so this passes.
        //
        // The status is not asserted: the route answers an XHR with JSON and
        // an ordinary form post with a redirect, and what comes back here
        // depends on the headers apiRequest happens to send. The effect is
        // the assertion that matters, and it is the one a user would check.
        await apiRequest(page, 'patch', `/assessments/${uuid}/template`, {
            is_template: true,
        });

        await page.goto('/templates');
        // Scoped since W41: the sidebar lists the same assessment under the
        // same name, so an unscoped match resolves to two elements.
        await expect(mainContent(page).getByText(ORIGINAL)).toBeVisible();
    });

    test('a new assessment can be created from it', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/templates');

        // Scoped to the row, not `.first()`. W27 made first-run setup seed
        // the bundled ISO templates, so this list is no longer "the one
        // template the previous test just marked" — picking the first row
        // would silently copy an ISO fixture and every assertion below
        // would then be describing the wrong assessment.
        // `.last()` of the divs holding both the title and the button is the
        // innermost one, which is TemplateCard's own root.
        const row = page
            .locator('div')
            .filter({ has: page.getByText(ORIGINAL, { exact: true }) })
            .filter({
                has: page.getByRole('button', { name: /use this template/i }),
            })
            .last();
        await row.getByRole('button', { name: /use this template/i }).click();

        // Using a template lands in the editor for the *new* assessment.
        await expect(page).toHaveURL(/\/assessments\/[0-9a-f-]{36}$/);

        // In the chart, since W32 made it the default — and this test does
        // not go through `openAssessment()`, which is where every other
        // scenario's switch to the list happens. The rename below edits the
        // *list* card's inline title, so without this it waited for a button
        // that view does not render, the copy kept the original's name, and
        // every later scenario that opens "Payment platform" by name was
        // choosing between two identical rows.
        await useListView(page);
        await expect(page.getByRole('tree')).toBeVisible();

        // Rename the copy's root immediately. A template copy carries the
        // original's title, so from here on the assessment list holds two
        // rows reading "Payment platform" — and every later scenario that
        // opens "the assessment" by name would be picking between them at
        // random. Renaming is also the first thing a real user does.
        await renameNode(page, ORIGINAL, COPY);
        await closeNodeModal(page);

        await page.reload();
        await expect(
            page.getByRole('button', { name: COPY, exact: true }),
        ).toBeVisible();
    });

    test('the copy carries the whole graph, including edge-level values', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/assessments');

        // Two assessments now; the copy is the renamed one.
        await expect(
            mainContent(page).getByRole('link', {
                name: ORIGINAL,
                exact: true,
            }),
        ).toBeVisible();
        await mainContent(page)
            .getByRole('link', { name: COPY, exact: true })
            .click();
        await expect(page).toHaveURL(/\/assessments\/[0-9a-f-]{36}$/);

        const graph = await readGraph(page);

        // Every node type came across.
        for (const title of [BREACH, PHISHING, MFA]) {
            expect(
                graph.nodes.some((n) => n.title === title),
                `${title} was copied`,
            ).toBe(true);
        }

        // The DAG shape survived: phishing still has two parents, which a
        // cloner that walked a tree would have flattened into two nodes.
        const phishing = graph.nodes.find((n) => n.title === PHISHING)!;
        expect(
            graph.riskAttackLinks.filter(
                (l) => l.attack_uuid === phishing.uuid,
            ),
        ).toHaveLength(2);

        // And the value that lives on the pivot rather than on either node —
        // exactly the kind of column an identity-column mistake drops
        // silently, leaving a copy that looks complete and computes wrongly.
        const mfa = graph.nodes.find((n) => n.title === MFA)!;
        const edge = graph.attackDefenceLinks.find(
            (l) =>
                l.attack_uuid === phishing.uuid && l.defence_uuid === mfa.uuid,
        );
        expect(edge?.strength_fraction).toBe(0.8);
    });

    test('the copy computes the same totals as the original', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/assessments');
        await mainContent(page)
            .getByRole('link', { name: COPY, exact: true })
            .click();

        // Into the list: the three totals live on the ToS card, which is a
        // list-view card. The chart deliberately carries no currency at all
        // (W32, scenario 15).
        await useListView(page);

        // The strongest single check that the copy is complete: totals are a
        // function of every node, every edge, every value and the scales the
        // defaults resolved against, so matching the original to the euro
        // means all of it came across.
        await expect(totalsPanel(page)).toContainText('€135,000');
        await expect(totalsPanel(page)).toContainText('€43,000');
        await expect(totalsPanel(page)).toContainText('€33,000');
    });

    test('the copy is independent — editing it does not touch the original', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/assessments');
        await mainContent(page)
            .getByRole('link', { name: COPY, exact: true })
            .click();
        // The rename below edits the list card's inline title.
        await useListView(page);

        // "Inserting is a copy: later edits do not propagate into
        // assessments already signed off" (PLAN.md Phase 8 item 4). The
        // inverse matters just as much and is easier to get wrong — a clone
        // that reused rows rather than duplicating them would show this edit
        // in both places.
        //
        // Renamed through the editor rather than by a bare PATCH:
        // UpdateNodeRequest validates the node's whole payload, including the
        // `updated_at` it uses for conflict detection, so a one-field patch
        // is a 422. The store sends the complete record for that reason.
        await renameNode(page, BREACH, 'Renamed only in the copy');
        await closeNodeModal(page);

        await openAssessment(page, ORIGINAL);
        await expect(
            page.getByRole('button', { name: BREACH, exact: true }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Renamed only in the copy' }),
        ).toHaveCount(0);
    });

    /*
     * 2.5.1: publishing a template no longer sets `is_public`. The template
     * list is open to every account and starting from a template copies it,
     * so the source assessment itself stays exactly as private as it was —
     * W45 coupled the two flags only because the list used to be limited to
     * what the viewer could already read. Scenarios 13 and 14 go on to assert
     * that a stranger is locked out of this assessment, which is now true
     * with or without this step; unpublishing is still what a real user does
     * when they are finished sharing, and both halves are asserted here.
     */
    test('publishing leaves the assessment private, and unpublishing takes it off the list', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page, ORIGINAL);

        const published = await apiRequest(
            page,
            'get',
            `/assessments/${uuid}/template`,
        );
        expect(published.status).toBe(200);
        expect(published.body).toMatchObject({
            is_template: true,
            is_public: false,
        });

        const unpublished = await apiRequest(
            page,
            'patch',
            `/assessments/${uuid}/template`,
            { is_template: false },
        );
        expect(unpublished.status).toBeLessThan(400);

        const after = await apiRequest(
            page,
            'get',
            `/assessments/${uuid}/template`,
        );
        expect(after.body).toMatchObject({
            is_template: false,
            is_public: false,
        });

        await page.goto('/templates');
        await expect(mainContent(page).getByText(ORIGINAL)).toHaveCount(0);
    });
});
