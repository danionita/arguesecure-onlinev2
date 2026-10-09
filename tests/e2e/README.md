# End-to-end scenarios (W25)

These drive a real browser against a real running stack. They do not boot
one, and they are **not** part of `php artisan test` or `npm run test:unit`.

## Why a separate stack

Scenario 1 is first-run setup, which needs an **empty users table**. Running
it against a working instance would mean wiping that instance's data, so the
run uses a second, throwaway stack on its own port and its own data
directory. Both can run at once.

## Running them

The suite is **order-dependent by design**: scenario 1 creates the
administrator every later scenario logs in as, which is why
`playwright.config.ts` sets `fullyParallel: false` and a single worker. The
twelve scenarios in `docs/PLAN.md` read as one narrative and are executed as
one.

Because the admin is created *by the run*, the database must start empty
every time. A second run against a populated database fails at login: the
passwords are generated fresh per run and never stored.

```bash
# 1. A throwaway env for the isolated stack. .env.* is gitignored;
#    never commit this file.
#
#    Note: a .env must ALSO exist. Compose appends env_file lists when it
#    merges an overlay rather than replacing them, so each service ends up
#    reading .env and then .env.e2e — the second wins on every key, but the
#    first must still be present or `up` aborts before pulling an image.
#    You will not notice this locally; CI creates both.
cp .env.example .env.e2e
#    then set, at minimum:
#      APP_PORT=8100
#      DATA_PATH=./data-e2e
#      APP_URL=http://localhost:8100
#      DB_PASSWORD / DB_ROOT_PASSWORD / REVERB_APP_ID / REVERB_APP_KEY /
#      REVERB_APP_SECRET  — generate each, e.g. openssl rand -hex 16

# 2. Start clean. Note the file list: docker-compose.e2e.yml points the app
#    containers at .env.e2e (--env-file alone does not), and
#    docker-compose.override.yml is deliberately excluded because it
#    publishes 3306/6379 and would collide with a running local stack.
docker compose -p arguesecure-e2e --env-file .env.e2e \
  -f docker-compose.yml -f docker-compose.e2e.yml down
rm -rf ./data-e2e
docker compose -p arguesecure-e2e --env-file .env.e2e \
  -f docker-compose.yml -f docker-compose.e2e.yml up -d --build

# 3. Credentials come from the environment, never from a file (CLAUDE.md).
export E2E_ADMIN_PASSWORD="$(openssl rand -base64 24)"
export E2E_COLLABORATOR_PASSWORD="$(openssl rand -base64 24)"

# 4. Run — after the build has finished, not alongside it.
npx playwright test
```

**Let the stack settle, and do not run this while the machine is busy.** The
order dependence has a sharp edge: scenario 3 builds the graph scenarios 4–12
read back, so one node that fails to be added there surfaces as ten or more
failures spread across five spec files. That has happened twice (W67, W68) and
both times the real count was one or zero. (Since 2.5.1 adding a node opens a
draft in the node modal and creates nothing until Enter, so the old race — a
node created empty and removed again when its name lost to a keypress — is
gone, but the cascade shape is the same for any early failure.)

So: a double-digit failure count is a reason to re-run before investigating.
Find the earliest failure, not the loudest one.

Scenario 12 creates a third account and needs `E2E_OUTSIDER_PASSWORD`; it
falls back to `E2E_COLLABORATOR_PASSWORD` if that is not exported.

Point the suite somewhere else with `E2E_BASE_URL`.

**Reset the whole stack, not just the database.** `migrate:fresh` alone is
not enough: sessions, the cache and the calculation cache all live in Redis,
and a database that restarts its ids from 1 collides with keys the previous
run left behind. Take the stack down with `-v`, delete `./data-e2e`, and
bring it back up.

## Conventions worth knowing before editing these

- **Labels carry a required marker.** `Components/UI/Input.vue` renders
  `<label>{{ label }} <span>*</span></label>`, so `getByLabel('Name', {
  exact: true })` never matches. Anchor instead: `getByLabel(/^Name/)`.
- **Log in through `support/auth.ts`.** An Inertia form post resolves before
  the next page mounts, so clicking and immediately navigating races the
  redirect — and the failure surfaces much later as a missing field on a
  page the test never reached.
- **Guards are redirects, not error statuses.** Asserting
  `response.status()` is usually wrong; assert the URL the browser lands on.
  The exceptions are the deliberate refusals — `admin`, and `view` on an
  assessment you have no share for — which really are 403s.
- **A `treeitem` contains its whole subtree.** `<li role="treeitem">` wraps
  the nested `<ul>` of everything below it, so filtering treeitems by a
  descendant button matches every ancestor too — the root's treeitem
  contains every button on the page. Address a card through
  `support/tree.ts`'s `cardFor`/`cardsFor`, which scope to the NodeCard root
  element instead.
- **Keyboard focus is an index, not an element.** `useKeyboardOutline.ts`
  tracks the focused occurrence in its own ref; clicking a card does not move
  it. Re-establish it with `Escape` and count ArrowDowns — `focusRow()` does
  this — rather than assuming it survived the last edit.
- **Node uuids are not in the DOM.** Nothing renders an id. Read them from
  `window.history.state.page.props` via `readGraph()`; Inertia deletes the
  `#app[data-page]` attribute during hydration, so that is not an option.
- **Only node-field saves show "Saved".** Edge mutations (linking, strength,
  reduction target) go through `useLinkMutations`, which never touches the
  store's `saveState`, so `expectSaved()` after one waits forever. Assert the
  rendered effect instead.
- **A one-field PATCH to a node is a 422.** `UpdateNodeRequest` validates the
  whole record including the `updated_at` it uses for conflict detection.
  Edit through the UI, or send what the store sends.
- **`page.goto` on a download aborts.** The CSV and JSON exports send
  `Content-Disposition: attachment`; use `page.request.get()`, which carries
  the same cookies.
- **Some specs are pinned defects.** A `test.describe('DEFECT: ...')` block
  asserts the behaviour that exists *today*, with the diagnosis and the fix
  in its docblock. Fixing the underlying bug turns it red on purpose. Read
  the docblock and replace the assertions; do not delete the block silently.
