# End-to-end scenarios

These drive a real browser against a real running stack. They do not boot
one, and they are **not** part of `php artisan test` or `npm run test:unit`.

## Why a separate stack

Scenario 1 is first-run setup, which needs an **empty users table**. Running
it against a working instance would mean wiping that instance's data, so the
run uses a second, throwaway stack on its own port and its own data
directory. Both can run at once.

## Running them

One command does everything: it writes a throwaway `.env.e2e` with freshly
generated secrets, boots the isolated stack, waits for `/up`, runs the
suite with per-run passwords, prints the container logs if anything fails,
and tears the stack down again, pass or fail (containers, volumes,
`./data-e2e`, `.env.e2e`). It needs Docker, bash (Git Bash on Windows is
fine) and Playwright's browser (`npx playwright install chromium`). CI runs
the same file.

```bash
tools/dev/checks/checks.sh e2e            # or tools/dev/checks/e2e.sh
tools/dev/checks/checks.sh e2e --grep "scenario 3"   # extra arguments go to Playwright
```

### From a session that can be stopped

A run takes half an hour, and the shell or coding session waiting on it can be
stopped meanwhile, taking the run with it. Start it detached and read it back
from files:

```bash
tools/dev/checks/e2e.sh --detach [args]   # starts the run in the background; returns once it is running
tools/dev/checks/e2e.sh --wait            # prints a progress line every 30 s for up to 8 minutes
tools/dev/checks/e2e.sh --status          # state, counts and the last log lines
```

`--wait` exits 0 when the run passed, 1 when it failed, 2 when it is still
running (call it again), 3 when it was killed or aborted and 4 when no run
exists. The log is `run.log` in `$TMPDIR/arguesecure-e2e-state`. Only one run
can exist per machine, from any checkout: the stack has a fixed compose project
and port, so a second run would remove the first one's containers. A second
start is refused and names the run in progress. A run that stops touching its
heartbeat file for 90 s counts as killed, and the next start removes its
containers, volumes, `data-e2e` and `.env.e2e` before it begins. Whether a
detached run outlives the process that started it depends on how that process
is ended; if yours does not, run Playwright in a container instead.

Playwright runs on the host when `npx` is found. Without Node on the host it
runs in the official Playwright container instead (no Node or browser needed;
the first run installs the dependencies into a Docker volume). The container
shares the app container's network, so the suite dials `http://localhost` as a
host browser would. `E2E_PLAYWRIGHT=host` or `container` forces one, and
`E2E_PLAYWRIGHT_IMAGE` overrides the image.

`E2E_PORT` (default 8100) moves the stack. Allow 25-30 minutes for a full
run. Do not run it while the machine is busy.

The suite is **order-dependent by design**: scenario 1 creates the
administrator every later scenario logs in as, which is why
`playwright.config.ts` sets `fullyParallel: false` and a single worker. The
twelve original scenarios read as one narrative and are executed as one.
Because the admin is created *by the run*, the database must start empty
every time and the passwords are never stored; the script guarantees both.

### By hand

Only to debug the stack itself. Credentials come from the environment,
never from a file.

```bash
cp .env.example .env.e2e   # then set APP_PORT=8100, DATA_PATH=./data-e2e,
                           # APP_URL=http://localhost:8100 and generate
                           # DB_PASSWORD, DB_ROOT_PASSWORD, REVERB_APP_ID,
                           # REVERB_APP_KEY, REVERB_APP_SECRET
[ -f .env ] || cp .env.e2e .env   # compose appends env_file lists, so .env must exist
docker compose -p arguesecure-e2e --env-file .env.e2e \
  -f docker-compose.yml -f docker-compose.e2e.yml up -d --build
export E2E_ADMIN_PASSWORD="$(openssl rand -base64 24)"
export E2E_COLLABORATOR_PASSWORD="$(openssl rand -base64 24)"
npx playwright test
```

`docker-compose.override.yml` is deliberately left out of that command: it
publishes 3306/6379 and would collide with a running local stack. Reset the
whole stack, not just the database: take it down with `-v` and delete
`./data-e2e`, because sessions and caches live in Redis.

**Let the stack settle, and do not run this while the machine is busy.** The
order dependence has a sharp edge: scenario 3 builds the graph scenarios 4–12
read back, so one node that fails to be added there surfaces as ten or more
failures spread across five spec files. That has happened twice and
both times the real count was one or zero. (Since 2.5.1 adding a node opens a
draft in the node modal and creates nothing until Enter, so the old race — a
node created empty and removed again when its name lost to a keypress — is
gone, but the cascade shape is the same for any early failure.)

So: a double-digit failure count is a reason to re-run before investigating.
Find the earliest failure, not the loudest one.

Scenario 12 creates a third account and needs `E2E_OUTSIDER_PASSWORD`; it
falls back to `E2E_COLLABORATOR_PASSWORD` if that is not exported.

Running `npx playwright test` by hand against another instance works with `E2E_BASE_URL`; the script always sets it to its own stack.

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
