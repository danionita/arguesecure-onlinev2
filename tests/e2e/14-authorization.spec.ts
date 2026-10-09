import { expect, test } from '@playwright/test';
import { apiRequest } from './support/api';
import { login } from './support/auth';
import { adminEmail, adminPassword } from './support/env';
import { openAssessment, readGraph, totalsPanel } from './support/tree';

/**
 * Scenario 12 of PLAN.md's end-to-end list: "As User C with no share,
 * attempt to open the assessment, its individual nodes, and its report by
 * direct URL — all must 403. Re-run against the node-render and node-update
 * endpoints specifically, since those were unprotected in v1."
 *
 * This is the scenario that closes defect #4 in docs/specs/security.md. In
 * the legacy application the `show` actions on risks, attacks and defences
 * had **no authorization at all**, and neither did the AJAX node-render
 * endpoint — a straightforward read of another organisation's private risk
 * assessment by anyone who could guess a URL. CLAUDE.md's response is
 * absolute: "every route touching an assessment goes through
 * AssessmentPolicy. No exceptions."
 *
 * So this tests the routes, not the buttons, and it tests them as a real
 * authenticated user who simply has no relationship to this assessment —
 * the case a UI-level check would never catch, because User C never sees the
 * UI at all.
 *
 * User C is created here rather than in scenario 1 because their defining
 * property is that they have no share, and the cleanest way to guarantee
 * that is to create them after every share in the run already exists.
 */

const OUTSIDER_EMAIL = 'e2e-outsider@example.test';
const OUTSIDER_NAME = 'E2E Outsider';

/** A password for an account that exists only inside this run, never written to a file. */
function outsiderPassword(): string {
    const value =
        process.env.E2E_OUTSIDER_PASSWORD ??
        process.env.E2E_COLLABORATOR_PASSWORD;

    if (value === undefined || value === '') {
        throw new Error(
            'E2E_OUTSIDER_PASSWORD (or E2E_COLLABORATOR_PASSWORD) must be set — see tests/e2e/support/env.ts.',
        );
    }

    return value;
}

test.describe('a user with no share', () => {
    let assessmentUuid = '';
    let nodeUuid = '';

    test('the admin creates User C, who is given nothing', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        assessmentUuid = await openAssessment(page);
        nodeUuid = (await readGraph(page)).nodes.find(
            (n) => n.type === 'attack',
        )!.uuid;

        await page.goto('/admin/users/create');
        await page.getByLabel(/^Name/).fill(OUTSIDER_NAME);
        await page.getByLabel(/^Email/).fill(OUTSIDER_EMAIL);
        await page.getByLabel(/^Password/).fill(outsiderPassword());
        await page.getByLabel(/^Confirm password/).fill(outsiderPassword());
        await page.getByRole('button', { name: 'Create user' }).click();

        await expect(page).toHaveURL(/\/admin\/users$/);
        await expect(page.getByText(OUTSIDER_EMAIL)).toBeVisible();
    });

    test('the assessment itself is 403 by direct URL', async ({ page }) => {
        await login(page, OUTSIDER_EMAIL, outsiderPassword());

        // Not a redirect to the list, not an empty tree: a refusal. Anything
        // that renders is a read of private data.
        const response = await page.goto(`/assessments/${assessmentUuid}`);
        expect(response?.status()).toBe(403);
        await expect(page.getByRole('tree')).toHaveCount(0);
        await expect(page.getByText('Payment platform')).toHaveCount(0);
    });

    test('the report is 403 by direct URL', async ({ page }) => {
        await login(page, OUTSIDER_EMAIL, outsiderPassword());

        // The report is exactly as sensitive as the tree — more so, since it
        // summarises every figure on one page — and it is deliberately
        // outside the `auth` group so a public assessment or a share link
        // can reach it. That makes the policy the only thing standing here.
        const response = await page.goto(
            `/assessments/${assessmentUuid}/report`,
        );
        expect(response?.status()).toBe(403);
    });

    test('the node-update endpoint is refused', async ({ page }) => {
        await login(page, OUTSIDER_EMAIL, outsiderPassword());
        await page.goto('/dashboard');

        // Named explicitly in the scenario, because this is the shape of the
        // v1 hole: a mutation endpoint reached directly, with a real session,
        // by someone who was never shown the page it belongs to.
        const updated = await apiRequest(
            page,
            'patch',
            `/assessments/${assessmentUuid}/nodes/${nodeUuid}`,
            {
                title: 'Written by a user with no share',
            },
        );
        expect(updated.status).toBe(403);

        const created = await apiRequest(
            page,
            'post',
            `/assessments/${assessmentUuid}/nodes`,
            {
                type: 'risk',
                title: 'Should never exist',
            },
        );
        expect(created.status).toBe(403);

        const deleted = await apiRequest(
            page,
            'delete',
            `/assessments/${assessmentUuid}/nodes/${nodeUuid}`,
        );
        expect(deleted.status).toBe(403);
    });

    test('every other assessment-scoped route is refused too', async ({
        page,
    }) => {
        await login(page, OUTSIDER_EMAIL, outsiderPassword());
        await page.goto('/dashboard');

        // "No exceptions" is a claim about the whole surface, so it is
        // checked across the whole surface rather than on the two or three
        // routes that happen to be famous. Each of these reads or writes
        // some part of the same private assessment.
        const reads = [
            `/assessments/${assessmentUuid}/snapshots`,
            `/assessments/${assessmentUuid}/report/defences.csv`,
            `/assessments/${assessmentUuid}/report/export.json`,
            `/assessments/${assessmentUuid}/nodes/${nodeUuid}/comments`,
            `/assessments/${assessmentUuid}/nodes/${nodeUuid}/revisions`,
            `/assessments/${assessmentUuid}/nodes/${nodeUuid}/references`,
            `/assessments/${assessmentUuid}/undo`,
        ];

        for (const url of reads) {
            const result = await apiRequest(page, 'get', url);
            expect(result.status, `${url} must not be readable`).toBe(403);
        }

        const writes: [Parameters<typeof apiRequest>[1], string, unknown][] = [
            ['post', `/assessments/${assessmentUuid}/undo`, {}],
            [
                'post',
                `/assessments/${assessmentUuid}/snapshots`,
                { name: 'Should never exist' },
            ],
            [
                'post',
                `/assessments/${assessmentUuid}/history/versions`,
                { name: 'Should never exist either' },
            ],
            [
                'post',
                `/assessments/${assessmentUuid}/reorder`,
                { scope: 'nodes', order: [nodeUuid] },
            ],
            [
                'post',
                `/assessments/${assessmentUuid}/nodes/${nodeUuid}/comments`,
                { body: 'Should never exist' },
            ],
        ];

        for (const [method, url, body] of writes) {
            const result = await apiRequest(page, method, url, body);
            expect(result.status, `${url} must not be writable`).toBe(403);
        }
    });

    test('the assessment is untouched after all of that', async ({ page }) => {
        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        // The status codes above say the requests were refused; this says
        // nothing slipped through anyway. A 403 with a side effect would
        // pass every assertion in this file except this one.
        const graph = await readGraph(page);
        expect(
            graph.nodes.some(
                (n) => n.title === 'Written by a user with no share',
            ),
        ).toBe(false);
        expect(graph.nodes.some((n) => n.title === 'Should never exist')).toBe(
            false,
        );
        // Through `totalsPanel()` since W36: the docked panel is gone, and
        // with it the word "Totals" this filtered on.
        await expect(totalsPanel(page)).toContainText('€135,000');
    });

    test('an assessment listing shows User C nothing that is not theirs', async ({
        page,
    }) => {
        await login(page, OUTSIDER_EMAIL, outsiderPassword());
        await page.goto('/assessments');

        // The index scopes its own query rather than filtering after the
        // fact, so this checks that the scoping is real: a listing that
        // leaked titles would disclose plenty on its own.
        //
        // Deliberately *not* scoped to `main` like the other list assertions
        // W41 rewrote. The sidebar lists assessments too, from a different
        // query in HandleInertiaRequests, and "User C sees nothing that is
        // not theirs" has to hold in both places. Narrowing this to the page
        // body would leave the sidebar's scoping untested on every route in
        // the application.
        await expect(
            page.getByRole('link', { name: /Payment platform/ }),
        ).toHaveCount(0);
    });
});
