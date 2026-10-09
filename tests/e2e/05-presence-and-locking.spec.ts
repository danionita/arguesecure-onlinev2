import { expect, test, type Page } from '@playwright/test';
import { apiRequest } from './support/api';
import { login } from './support/auth';
import {
    adminEmail,
    adminName,
    adminPassword,
    collaboratorEmail,
    collaboratorName,
    collaboratorPassword,
} from './support/env';
import {
    cardFor,
    closeNodeModal,
    expectSaved,
    openAssessment,
    openNodeModal,
    renameNode,
} from './support/tree';

/**
 * Scenario 3 of PLAN.md's end-to-end list: "User A shares it with User B as
 * an editor. User B opens it in a second browser: presence avatars appear,
 * B's edits appear live in A's window, and a field A is editing shows as
 * locked for B."
 *
 * Two describe blocks: the share itself, and then the three real-time
 * properties across two live browsers. Until W27 the second block asserted
 * that none of them worked, pinned as a defect — see its own docblock.
 *
 * The share is still driven over HTTP rather than through the dialog W27
 * mounted. That is deliberate: this block's job is to establish the
 * collaborator every later scenario depends on, and doing it over HTTP keeps
 * it independent of the dialog's markup. Scenario 13 exercises the dialog.
 */

const PHISHING = 'Phishing';

async function openAsCollaborator(page: Page): Promise<void> {
    await login(page, collaboratorEmail, collaboratorPassword());
    await openAssessment(page);
}

/** Both sides of a two-browser scenario, each with its own session. */
async function twoBrowsers(
    browser: import('@playwright/test').Browser,
    body: (owner: Page, editor: Page) => Promise<void>,
): Promise<void> {
    const ownerContext = await browser.newContext();
    const editorContext = await browser.newContext();

    try {
        const owner = await ownerContext.newPage();
        const editor = await editorContext.newPage();

        await login(owner, adminEmail, adminPassword());
        await openAssessment(owner);
        await openAsCollaborator(editor);

        await body(owner, editor);
    } finally {
        await ownerContext.close();
        await editorContext.close();
    }
}

test.describe('sharing with a second user', () => {
    test('the owner shares the assessment with the collaborator as an editor', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page);

        // Found through the app's own user search, so the test never has to
        // know a user id either. `query` is the parameter name
        // SearchUsersRequest validates — `q` returns 422.
        const search = await apiRequest(
            page,
            'get',
            `/assessments/${uuid}/share/users?query=${encodeURIComponent(collaboratorEmail)}`,
        );
        expect(search.status).toBe(200);

        const users = (
            search.body as { users: { id: number; email: string }[] }
        ).users;
        const collaborator = users.find((u) => u.email === collaboratorEmail);
        expect(
            collaborator,
            'the collaborator account from scenario 1 should be findable',
        ).toBeDefined();

        const shared = await apiRequest(
            page,
            'post',
            `/assessments/${uuid}/share/collaborators`,
            { user_id: collaborator!.id, role: 'editor' },
        );
        expect(shared.status).toBe(201);
    });

    test('the collaborator can now open an assessment they do not own', async ({
        page,
    }) => {
        await openAsCollaborator(page);

        // Before the share this was a 403 — scenario 12 asserts exactly that
        // for a third user who never received one.
        await expect(cardFor(page, PHISHING)).toBeVisible();

        // An editor sees the edit affordances; a viewer would not. This is
        // the positive control for scenario 11's "every edit affordance is
        // absent" on the read-only link.
        await expect(
            cardFor(page, PHISHING).getByRole('button', { name: 'Delete' }),
        ).toBeVisible();
    });

    test("one user's saved change is visible to the other after a reload", async ({
        browser,
    }) => {
        await twoBrowsers(browser, async (owner, editor) => {
            const renamed = 'Phishing (credential harvesting)';

            await renameNode(editor, PHISHING, renamed);
            await closeNodeModal(editor);
            // Wait for the write to be acknowledged before reloading the
            // other window; without this the reload races the PATCH and the
            // failure looks like "the change did not propagate".
            await expectSaved(editor);

            // `finally` for the same reason as the live-patch test below:
            // this renames shared fixture data every later scenario reads,
            // so the restore must not be reachable only on the success path.
            try {
                // Deliberately a reload rather than the live patch the next
                // describe block asserts. This is the durable half: it proves
                // the collaborator's write reached the *database* under their
                // own session, which a broadcast alone would not.
                await owner.reload();
                await expect(
                    owner
                        .getByRole('button', { name: renamed, exact: true })
                        .first(),
                ).toBeVisible();
            } finally {
                await renameNode(editor, renamed, PHISHING);
                await closeNodeModal(editor);
            }

            await owner.reload();
            await expect(
                owner
                    .getByRole('button', { name: PHISHING, exact: true })
                    .first(),
            ).toBeVisible();
        });
    });
});

/**
 * The real-time half of scenario 3, against two live browsers.
 *
 * Until W27 these three tests asserted the exact opposite, pinned as a
 * defect: real-time collaboration was inert in every Docker deployment.
 * `useRealtime.ts` read `import.meta.env.VITE_REVERB_APP_KEY`, which Vite
 * substitutes when the image is **built**, while `docker/entrypoint.sh`
 * generates the Reverb credentials when the container first **starts** — so
 * the shipped bundle carried no key, `connect()` returned immediately, and
 * no WebSocket was ever opened. Separately, nginx proxied `/app` to
 * `127.0.0.1:8080`, which is inside the app container; Reverb is its own
 * compose service. Everything W16 built was present, correct and
 * unreachable, and two people editing one assessment overwrote each other
 * with no avatar, no lock badge and no toast.
 *
 * Nothing but a browser against a built image could see it. The Pest suite
 * sets `BROADCAST_CONNECTION=null`, Vitest mounts components with no Reverb
 * configured — which is what the `if (!key) return` degradation was written
 * to tolerate — and `npm run dev` reads `.env` at build time, so a developer
 * machine worked perfectly. That is why these are e2e tests and not unit
 * ones.
 *
 * They are deliberately generous with time. A socket, a `/broadcasting/auth`
 * round trip and a presence-subscription frame all have to complete before
 * anything is observable, and a run on a cold container is not fast.
 */
test.describe('real-time collaboration', () => {
    test('the editor opens a WebSocket to Reverb through the app’s own origin', async ({
        page,
    }) => {
        const sockets: string[] = [];
        page.on('websocket', (ws) => sockets.push(ws.url()));

        await login(page, adminEmail, adminPassword());
        await openAssessment(page);

        await expect.poll(() => sockets, { timeout: 15_000 }).not.toEqual([]);

        // Through `/app/{key}` on the page's own host, never at
        // REVERB_HOST:REVERB_PORT — that is what lets one built image run
        // behind any hostname, port and scheme (deployment.md §2.1).
        const origin = new URL(page.url());
        expect(sockets[0]).toContain(`//${origin.host}/app/`);
    });

    test('each user sees the other by name in the presence bar', async ({
        browser,
    }) => {
        await twoBrowsers(browser, async (owner, editor) => {
            // Avatar.vue renders the member's name as its `title`, so this
            // is the presence channel's membership as the user reads it.
            await expect(owner.getByTitle(collaboratorName)).toBeVisible({
                timeout: 15_000,
            });
            await expect(editor.getByTitle(adminName)).toBeVisible({
                timeout: 15_000,
            });
        });
    });

    test("one user's edit appears in the other's window with no reload", async ({
        browser,
    }) => {
        await twoBrowsers(browser, async (owner, editor) => {
            const renamed = 'Phishing (live)';

            await renameNode(editor, PHISHING, renamed);
            await closeNodeModal(editor);

            // The restore is in a `finally` because this test mutates shared
            // fixture data that every later scenario depends on. When the
            // assertion below failed in CI, the restore never ran, "Phishing"
            // stayed renamed for the rest of the suite, and three real
            // failures became sixteen — scenarios 7, 8, 9, 12 and 13 all
            // reported a missing node rather than the problem they were
            // written to catch. A failing test must fail alone.
            try {
                // No `owner.reload()`. The broadcast carries the changed node
                // and applyRealtimeUpdatesToStore patches the store with it —
                // ui.md §6's "do not repeat the legacy id-then-refetch
                // design".
                await expect(
                    owner
                        .getByRole('button', { name: renamed, exact: true })
                        .first(),
                ).toBeVisible({ timeout: 15_000 });
            } finally {
                // Through the editor's own window, which holds the rename
                // regardless of whether the owner's ever saw it.
                await renameNode(editor, renamed, PHISHING);
                await closeNodeModal(editor);
            }

            // Only meaningful on the success path: that the *owner* sees the
            // restore live is the same claim as above, made twice.
            await expect(
                owner
                    .getByRole('button', { name: PHISHING, exact: true })
                    .first(),
            ).toBeVisible({ timeout: 15_000 });
        });
    });

    /**
     * 2.5.1: one editor per node. The node modal holds a whole-node lock while
     * it is open; everyone else sees the holder's name on the card, and a
     * modal opened meanwhile is read-only with the holder named. Closing
     * releases it, and the second person's modal becomes editable without
     * being reopened.
     */
    test('a node one user has open is read-only to the other, and released on close', async ({
        browser,
    }) => {
        await twoBrowsers(browser, async (owner, editor) => {
            // Wait for both sides to be on the channel first: a lock is
            // announced over it, so a browser that has not subscribed yet
            // would miss the announcement and fail for the wrong reason.
            await expect(editor.getByTitle(adminName)).toBeVisible({
                timeout: 15_000,
            });

            await openNodeModal(owner, PHISHING);

            // ui.md §6: the holder is named on the card — never an overlay
            // that blocks reading.
            const card = cardFor(editor, PHISHING);
            await expect(card.getByTestId('node-locked')).toContainText(
                adminName,
                { timeout: 15_000 },
            );

            // Opening it anyway: readable, not writable, and says why.
            await openNodeModal(editor, PHISHING);
            const dialog = editor.getByRole('dialog');
            await expect(dialog.getByTestId('node-modal-locked')).toContainText(
                adminName,
            );
            await expect(dialog.getByLabel(/^Title/)).toBeDisabled();

            // The holder closes; the reader's modal becomes editable in place.
            await closeNodeModal(owner);
            await expect(dialog.getByTestId('node-modal-locked')).toHaveCount(
                0,
                { timeout: 20_000 },
            );
            await expect(dialog.getByLabel(/^Title/)).toBeEnabled();
            await closeNodeModal(editor);
            await expect(card.getByTestId('node-locked')).toHaveCount(0, {
                timeout: 15_000,
            });
        });
    });
});
