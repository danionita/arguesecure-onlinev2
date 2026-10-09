import { expect, test } from '@playwright/test';
import { apiRequest } from './support/api';
import { login } from './support/auth';
import { adminEmail, adminPassword } from './support/env';
import {
    assessmentTitle,
    openAssessment,
    readGraph,
    titleButtonFor,
    useListView,
} from './support/tree';

/**
 * Scenario 11 of PLAN.md's end-to-end list: "Open the read-only share link
 * in a private window with no session: content is visible, every edit
 * affordance is absent, and the API rejects a hand-crafted mutation."
 *
 * This is a security assertion, not a smoke test, and the three clauses have
 * to be checked separately because they fail separately. Hiding the buttons
 * is presentation; rejecting the request is authorization; and a system that
 * does the first without the second is the exact defect this rewrite exists
 * to remove. `is_public` in v1 meant world-*writable* — the old
 * RiskPolicy/AttackPolicy/DefencePolicy all returned
 * `$node->tree->public || owner` for `update` and `destroy`, so any logged-in
 * user could edit or delete the contents of any public assessment
 * (security.md, PLAN.md Phase 2). In 2.0, `is_public` and a share token
 * grant `view` and nothing else.
 *
 * Every test here runs in a context with no session at all — not a
 * logged-out page, a browser that has never authenticated — because the
 * token holder is someone with no account.
 */

const PHISHING = 'Phishing';

/** An account with no share of any kind, created by this scenario for its own use. */
const linkHolderEmail = 'e2e-link-holder@example.test';

function linkHolderPassword(): string {
    const value =
        process.env.E2E_LINK_HOLDER_PASSWORD ??
        process.env.E2E_COLLABORATOR_PASSWORD;

    if (value === undefined || value === '') {
        throw new Error(
            'E2E_LINK_HOLDER_PASSWORD (or E2E_COLLABORATOR_PASSWORD) must be set — see support/env.ts.',
        );
    }

    return value;
}

/** Mints a share link as the owner and returns its `/s/{token}` URL. */
async function mintShareLink(
    browser: import('@playwright/test').Browser,
): Promise<{
    shareUrl: string;
    assessmentUuid: string;
    nodeUuid: string;
}> {
    const context = await browser.newContext();

    try {
        const page = await context.newPage();
        await login(page, adminEmail, adminPassword());
        const assessmentUuid = await openAssessment(page);
        const graph = await readGraph(page);
        const nodeUuid = graph.nodes.find((n) => n.title === PHISHING)!.uuid;

        const created = await apiRequest(
            page,
            'post',
            `/assessments/${assessmentUuid}/share/token`,
        );
        expect(created.status).toBeLessThan(300);

        const shareUrl = (created.body as { share_link: string }).share_link;
        expect(
            shareUrl,
            'the owner should get a revocable read-only link back',
        ).toContain('/s/');

        return { shareUrl, assessmentUuid, nodeUuid };
    } finally {
        await context.close();
    }
}

test.describe('the read-only share link', () => {
    test('an account exists that holds nothing but the link', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        await page.goto('/admin/users/create');
        await page.getByLabel(/^Name/).fill('E2E Link Holder');
        await page.getByLabel(/^Email/).fill(linkHolderEmail);
        await page.getByLabel(/^Password/).fill(linkHolderPassword());
        await page.getByLabel(/^Confirm password/).fill(linkHolderPassword());
        await page.getByRole('button', { name: 'Create user' }).click();

        await expect(page).toHaveURL(/\/admin\/users$/);
        await expect(page.getByText(linkHolderEmail)).toBeVisible();
    });

    test('an anonymous holder of the link can read the assessment', async ({
        browser,
    }) => {
        const { shareUrl, assessmentUuid } = await mintShareLink(browser);
        const context = await browser.newContext();

        try {
            const page = await context.newPage();

            // Presenting the token establishes a session flag scoped to this
            // assessment and redirects to a clean URL, so the token itself
            // never lands in history, a Referer or an access log after this
            // one request (security.md §3.2).
            await page.goto(shareUrl);
            await expect(page).not.toHaveURL(/\/s\//);

            await page.goto(`/assessments/${assessmentUuid}`);
            await expect(page.getByRole('tree')).toBeVisible();

            // Into the list, because that is where a card's button is named
            // by its title alone; the chart names them "Attack: Phishing".
            // The switcher is a view control, not an edit affordance, so a
            // read-only holder has it — which is itself worth knowing.
            await useListView(page);
            await expect(
                page
                    .getByRole('button', { name: PHISHING, exact: true })
                    .first(),
            ).toBeVisible();
        } finally {
            await context.close();
        }
    });

    test('every edit affordance is absent', async ({ browser }) => {
        const { shareUrl, assessmentUuid } = await mintShareLink(browser);
        const context = await browser.newContext();

        try {
            const page = await context.newPage();
            await page.goto(shareUrl);
            await page.goto(`/assessments/${assessmentUuid}`);
            await expect(page.getByRole('tree')).toBeVisible();
            // The affordances below are list-view ones.
            await useListView(page);

            // NodeCard renders these only when `abilities.update` is true, so
            // their absence is evidence the server told the client it may not
            // edit — not merely that CSS hid them.
            for (const action of ['Add child', 'Delete']) {
                await expect(
                    page.getByRole('button', { name: action }),
                    `${action} must not be offered to a read-only holder`,
                ).toHaveCount(0);
            }

            // 2.5.1: the title opens the node modal — for a read-only holder
            // too, because reading is the point of the link — and every field
            // in it is disabled, since `abilities.update` is false.
            const title = titleButtonFor(page, assessmentTitle);
            await title.click();
            const dialog = page.getByRole('dialog');
            await expect(dialog).toBeVisible();
            await expect(dialog.getByLabel(/^Title/)).toBeDisabled();
            await expect(
                dialog.getByRole('button', { name: 'Delete' }),
            ).toHaveCount(0);
        } finally {
            await context.close();
        }
    });

    test('a hand-crafted mutation is rejected by the server', async ({
        browser,
    }) => {
        const { shareUrl, assessmentUuid, nodeUuid } =
            await mintShareLink(browser);
        const context = await browser.newContext();

        try {
            const page = await context.newPage();
            await page.goto(shareUrl);
            await page.goto(`/assessments/${assessmentUuid}`);
            await expect(page.getByRole('tree')).toBeVisible();

            // Issued from the page's own origin with its own cookies and a
            // valid CSRF token — everything the application's own code sends.
            // Only the authorization decision stands between this and a
            // write, which is the entire claim being tested.
            const edit = await apiRequest(
                page,
                'patch',
                `/assessments/${assessmentUuid}/nodes/${nodeUuid}`,
                {
                    title: 'Written by someone holding only a read-only link',
                },
            );
            expect([401, 403]).toContain(edit.status);

            const create = await apiRequest(
                page,
                'post',
                `/assessments/${assessmentUuid}/nodes`,
                {
                    type: 'risk',
                    title: 'Also should not exist',
                },
            );
            expect([401, 403]).toContain(create.status);

            const remove = await apiRequest(
                page,
                'delete',
                `/assessments/${assessmentUuid}/nodes/${nodeUuid}`,
            );
            expect([401, 403]).toContain(remove.status);

            // And nothing was written — a rejection that still had a side
            // effect would satisfy the status assertions above.
            await page.reload();
            await useListView(page);
            await expect(
                page
                    .getByRole('button', { name: PHISHING, exact: true })
                    .first(),
            ).toBeVisible();
            await expect(
                page.getByRole('button', {
                    name: 'Written by someone holding only a read-only link',
                }),
            ).toHaveCount(0);
        } finally {
            await context.close();
        }
    });

    test('a logged-in user holding only the link is refused by the policy, not the middleware', async ({
        browser,
    }) => {
        const { shareUrl, assessmentUuid, nodeUuid } =
            await mintShareLink(browser);
        const context = await browser.newContext();

        try {
            const page = await context.newPage();

            // This test exists because the one above it does not prove what
            // it looks like it proves. An anonymous holder is rejected by the
            // `auth` middleware before AssessmentPolicy is ever consulted, so
            // that test passes even with the policy's `update` stubbed to
            // return true — confirmed by breaking it deliberately.
            //
            // A *logged-in* user presenting the token clears `auth` and then
            // has exactly one thing standing between them and a write: the
            // policy, which grants a share token `view` and nothing else.
            // That is also the precise shape of the v1 defect — `is_public`
            // there granted update and destroy as well — so this is the case
            // worth pinning.
            // A brand-new account with no share of its own. It cannot be the
            // collaborator from scenario 3 — they are a real editor, so a
            // successful write would be correct for them and would prove
            // nothing here.
            await login(page, linkHolderEmail, linkHolderPassword());
            await page.goto(shareUrl);
            await page.goto(`/assessments/${assessmentUuid}`);
            await expect(page.getByRole('tree')).toBeVisible();

            const edit = await apiRequest(
                page,
                'patch',
                `/assessments/${assessmentUuid}/nodes/${nodeUuid}`,
                {
                    title: 'Written by a token holder who is merely logged in',
                },
            );
            expect(edit.status).toBe(403);

            const created = await apiRequest(
                page,
                'post',
                `/assessments/${assessmentUuid}/nodes`,
                {
                    type: 'risk',
                    title: 'Should not exist either',
                },
            );
            expect(created.status).toBe(403);
        } finally {
            await context.close();
        }
    });

    test('revoking the link locks the holder out again', async ({
        browser,
    }) => {
        const { shareUrl, assessmentUuid } = await mintShareLink(browser);
        const holder = await browser.newContext();
        const owner = await browser.newContext();

        try {
            const holderPage = await holder.newPage();
            await holderPage.goto(shareUrl);
            await holderPage.goto(`/assessments/${assessmentUuid}`);
            await expect(holderPage.getByRole('tree')).toBeVisible();

            const ownerPage = await owner.newPage();
            await login(ownerPage, adminEmail, adminPassword());
            const ownerUuid = await openAssessment(ownerPage);
            const revoked = await apiRequest(
                ownerPage,
                'delete',
                `/assessments/${ownerUuid}/share/token`,
            );
            expect(revoked.status).toBeLessThan(300);

            // "Revocable" has to mean the already-established session stops
            // working, not just that the URL stops resolving — otherwise
            // revoking would only affect people who had not used the link
            // yet, which is precisely the wrong half.
            const afterRevoke = await holderPage.goto(
                `/assessments/${assessmentUuid}`,
            );
            expect(afterRevoke?.status()).toBe(403);
        } finally {
            await holder.close();
            await owner.close();
        }
    });
});
