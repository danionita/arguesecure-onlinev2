# ArgueSecure 2.0.0 — release notes and cutover

ArgueSecure 2.0 is a clean rewrite. It shares a repository, a domain and a
name with 1.x and nothing else: not a line of the old application survives,
and no 1.x data is migrated. The 1.x code remains in this repository's git
history and is tagged `v1-final`.

---

## Why the rewrite happened

Every layer of 1.x was years past end of life — Laravel 5.2, PHP 7.1, Node 8,
socket.io 1.3.7, jQuery 1.11, Bootstrap 3, Debian Buster — and several
dependencies were abandoned rather than merely outdated (`laravelcollective/
html`, `laravel-elixir`, `lord/laroute`, `dompdf 0.6`, `swiftmailer`). There
were two real tests, both bypassing middleware, and no CI.

There were also four live security defects, all of which 2.0 removes:

| 1.x defect | 2.0 |
|---|---|
| `.env` with a live `APP_KEY` committed — on Laravel 5.2 that is remote code execution (CVE-2018-15133) | Both historical keys treated as burned. Keys are generated on first container start and never committed. |
| The websocket relay broadcast every assessment to every anonymous client, with privacy filtered in browser JavaScript | Reverb presence channels authorized server-side through `AssessmentPolicy`. |
| `show` actions on risks, attacks and defences had no authorization at all, and neither did the AJAX node-render endpoint | Every assessment-scoped route goes through `AssessmentPolicy`. Asserted end to end — see scenario 12 below. |
| Admin was a string comparison against the username `"admin"`, with a documented default password | A real `is_admin` column, and no seeded credential anywhere. The first account is created through a one-time first-run setup screen. |

`is_public` also meant world-**writable** in 1.x: the old policies returned
`$node->tree->public || owner` for `update` and `destroy`, so any logged-in
user could edit or delete the contents of any public assessment. In 2.0
`is_public` grants read and nothing else.

---

## What is new

**Quantification.** Assessments carry real numbers. Impact is loss *per
occurrence*; likelihood is a rate *per year* and may exceed 1. The unit of
loss is the (risk, attack) pair, and three baselines are computed —
**inherent** (no defences), **current** (implemented only) and **target**
(implemented plus todo). Shelved defences never count. A missing impact or
likelihood excludes its pair from the total rather than being read as zero,
because reading it as zero would understate risk. Results render as currency
with reduction percentages, never as a low/medium/high band.

**A real editor.** The 1.x flow — click a wrench, reveal buttons, navigate to
a separate page to rename a node — is gone. Nodes are created inline and the
title field takes focus immediately; a tabbed modal covers details, values,
links, references, comments and history without ever leaving the tree.

**A DAG, not a tree.** An attack can belong to several risks and a defence to
several attacks. Strength lives on the `attack_defence` edge, because one
control may be 80% effective against one attack and 20% against another, and
ordering lives on the edge too, so the same attack can sit in different
positions under different risks. Hovering a shared node highlights every
place it appears.

**Reports** at `/assessments/{id}/report`, print-optimised, with defences
ranked by marginal contribution and by opportunity, plus CSV and JSON export.
PDFs come from the browser's own print pipeline against a tuned print
stylesheet — deliberately, to keep a headless-Chromium sidecar out of the
deployment and `dompdf` permanently gone.

**Snapshots** freeze the graph *and* the computed results with the scales
inlined, so recalibrating later cannot retroactively rewrite a figure already
reported to a client. Two snapshots can be diffed, matched on node uuid.

**Sharing** by named collaborator with a role, plus a public flag and a
revocable read-only link for people with no account.

**Templates and a catalogue**, comments, per-node revision history with
per-field revert, external references (CVE and friends), a single linear undo
stack, and admin-created accounts with CSV bulk import.

---

## What the release scenarios found, and what W27 closed

The twelve end-to-end scenarios found eight defects. Four were fixed during
the release pass, each of which had made a shipped feature non-functional:
the tree editor returned 502 behind nginx on any full page load; typed impact
and likelihood values were stored but never counted, so a fully populated
assessment reported €0; `POST .../reorder` answered 200 and reordered
nothing; and Ctrl+Z performed no undo and covered the screen with an error
overlay.

The other four were pinned as tests asserting the broken behaviour, with the
diagnosis in each docblock, and **all four are now closed by W27** — along
with the two smaller limitations below them:

1. **Real-time collaboration did not connect in any Docker deployment.**
   Presence avatars, live updates and visible field locks were all inert, so
   two people editing the same assessment overwrote each other with no
   warning. Two independent causes. The Reverb app key was substituted into
   the browser bundle by Vite at image **build** time while the entrypoint
   generates it at container **start** time, so the shipped bundle had no key
   and never opened a WebSocket; the key now arrives as a shared Inertia prop
   and the client dials its own origin, which keeps one image deployable
   anywhere. Separately, nginx proxied `/app` to `127.0.0.1:8080` when Reverb
   is its own container — that upstream is now the `reverb` service, resolved
   at request time so the two containers may start in either order.

2. **Totals updated on reload, not live.** `recomputeLocally()` assembled its
   graph with an empty scale calibration, so the engine threw "Cannot
   band-map a value against an empty scale" on the first risk with an impact
   — into a bare `catch {}` that swallowed it silently on every keystroke.
   The controller had always been sending `scaleLevels` and `scales`; the
   editor page simply never declared them as props. The catch is now narrowed
   to reporting the failure rather than hiding it.

3. **Drag and drop was not wired up.** `useDragDrop.ts` was written,
   unit-tested and imported by nothing, so no affordance for reordering or
   re-parenting existed at all. It is now bound to the node card, which is
   both the drag source and the drop target.

4. **The share dialog was not reachable.** `Components/Share/ShareDialog.vue`
   was complete but no page mounted it, and the share routes bound by numeric
   `id` while every client-facing payload exposes only `uuid`, so a browser
   could not address them. The routes are now uuid-bound like every other
   assessment route, and the dialog opens from the editor's header.

5. **A fresh deployment had no templates.** The bundled fixtures need an
   owning user, which does not exist at first boot. First-run setup now seeds
   the templates and the two ISO catalogue libraries the moment the first
   administrator is created. The demo web shop is content rather than
   reference material, so it still requires a deliberate `db:seed`.

6. **An abandoned untitled node persisted.** Pressing Tab and then Enter
   without typing left an "Untitled risk" behind, because the commit path
   returned early when the draft equalled the current (empty) title and so
   never reached `removeIfEmpty`. It now removes itself.

### Still open

- **Re-parenting onto an empty parent has no gesture.** A drop means "put the
  dragged occurrence where this one is", so moving a defence to an attack
  that currently has none offers nothing to drop onto. The `move` endpoint
  handles it; the UI has no way to ask for it.
- **Shift+Tab does nothing in the outline.** Deliberately: a node's level
  here *is* its type, and nothing may change type, so there is no promotion
  to perform. Re-parenting within a level is a drag.
- The older pinned defects in `tests/Feature/**` remain open and are found
  with `grep -rn "DEFECT:" tests/`: login throttling keyed per email+IP
  rather than per IP, unthrottled node/link/comment mutations, the guest
  landing page shipping exact framework versions, 404s carrying no security
  headers, and `CatalogInserter` issuing a query per descendant.

---

## Deploying 2.0.0

```bash
git clone <this repository>
cd arguesecure-online
cp .env.example .env
docker compose up --build -d
```

Set at minimum `APP_URL`, `DB_PASSWORD`, `DB_ROOT_PASSWORD` and `DATA_PATH` in
`.env`. `APP_KEY` and the three `REVERB_*` values are generated on first start
and persisted to `storage/app/config/app.key` — **back that file up.** Losing
it invalidates every session and every encrypted cookie.

Then open the application in a browser. It will offer a **first-run setup
screen** rather than a login, because the `users` table is empty. Create the
first administrator there. There is no default account and no seeded password
anywhere in this repository, by design.

`docs/specs/deployment.md` covers the reverse-proxy configuration (the
instance this was built for runs behind SWAG), the healthchecks, and backups.

---

## Cutover from 1.x

**There is no data migration, and none is planned.** 1.x assessments stay in
the 1.x database. The schema, the authorization model and the calculation
model are all different, and 1.x had no quantification to migrate: an imported
assessment would arrive with every value missing and every total zero, which
is worse than an honest fresh start.

Recommended sequence:

1. Confirm `v1-final` points at the last 1.x commit, and take a `mysqldump` of
   the 1.x database. Keep both. This is the only copy of any 1.x assessment.
2. Stand 2.0 up **alongside** 1.x on a different port and data directory. The
   compose project name and `DATA_PATH` are both variables, so the two never
   share a volume.
3. Complete first-run setup, create the accounts, and re-enter any assessment
   that is still in use. Re-entering is also the moment to add the impact and
   likelihood figures 1.x could not hold.
4. Cut the reverse proxy over to the 2.0 instance.
5. Leave 1.x stopped but intact for as long as anyone might ask for an old
   assessment. Do not delete its data directory on the day of the cutover.

### Two things to do at cutover, in this order

**Rotate anything that ever touched 1.x.** Both `APP_KEY` values in the
repository's history are burned and must never be reused; 2.0 generates its
own. Every 1.x account password should be considered public — 1.x documented
its default admin password in the README — so accounts are recreated in 2.0
with fresh credentials rather than carried over.

**Untrack `.env` and `.env-deploy` on `master`.** They are in `.gitignore` and
carry no live secret, but they should not be tracked. This is an ordinary
`git rm --cached` commit.

Rewriting history to purge them was **declined on 2026-08-04** and that
decision is settled — the history holds two dead `APP_KEY`s and placeholder
values only, the repository is private, and a force-push across a repository
with this many branches costs more than it buys. The reasoning is recorded in
`docs/specs/security.md` §2.1. Do not reopen it.

---

## Verification behind this release

All six checks pass, and every one was run rather than assumed:

| Check | Result |
|---|---|
| `vendor/bin/pint --test` | passed |
| `vendor/bin/phpstan analyse` (level 6) | no errors |
| `php artisan test` | 587 passed |
| `npm run lint` | passed |
| `npm run type-check` | passed |
| `npm run test:unit` | 169 passed |
| `npx playwright test` | 70 passed |

The Playwright suite is the twelve release scenarios in `docs/PLAN.md`'s
Verification section, run in order as one narrative against a real browser and
a real stack. It is not part of `php artisan test`; see `tests/e2e/README.md`
for how to run it.
