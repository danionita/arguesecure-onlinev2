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

let publishedTemplateUuid = '';

test.describe('templates', () => {
    test('an assessment can be published as a template', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page);

        // `saveAsTemplate` — admins only. Since W141 publishing copies the
        // assessment into a separate, ownerless template and leaves the
        // assessment itself alone; the answer carries the copy's uuid.
        const published = await apiRequest(
            page,
            'post',
            `/assessments/${uuid}/template`,
        );
        expect(published.status).toBe(201);
        publishedTemplateUuid = (
            published.body as { template: { uuid: string } }
        ).template.uuid;

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
     * W141: the template is a copy, so the assessment it was published from
     * stays exactly as private and as editable as it was, and there is nothing
     * to unpublish — a template leaves the list when it is deleted.
     */
    test('publishing leaves the assessment alone, and deleting the template takes it off the list', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page, ORIGINAL);

        // The assessment is still an assessment: it is not a template itself,
        // so publishing it again is allowed (it makes a second copy) while
        // publishing the template is not.
        const again = await apiRequest(
            page,
            'post',
            `/assessments/${publishedTemplateUuid}/template`,
        );
        expect(again.status).toBe(404);

        // The route answers a redirect to the Templates page. A plain XHR
        // would re-send DELETE to it (405); Inertia's headers make Laravel
        // answer 303, so the follow-up is a GET, as in the app (the version
        // header keeps that GET from answering 409).
        const removed = await page.evaluate(async (target) => {
            const axios = (window as unknown as { axios: any }).axios;
            try {
                const version = window.history.state?.page?.version ?? '';
                const response = await axios.delete(target, {
                    headers: {
                        'X-Inertia': 'true',
                        'X-Inertia-Version': version,
                    },
                });
                return response.status as number;
            } catch (error: any) {
                return (error?.response?.status ?? 0) as number;
            }
        }, `/templates/${publishedTemplateUuid}`);
        expect(removed).toBeLessThan(400);

        await page.goto('/templates');
        await expect(mainContent(page).getByText(ORIGINAL)).toHaveCount(0);

        // The assessment it was copied from is untouched.
        await page.goto(`/assessments/${uuid}`);
        await expect(page).toHaveURL(new RegExp(`/assessments/${uuid}$`));
    });
});
