# ArgueSecure 2.0 — Rewrite, Modernization & Extension Plan

> This is the master plan. Detailed, implementable specifications live in [`docs/specs/`](specs/):
> [schema](specs/schema.md) · [calculation-engine](specs/calculation-engine.md) · [ui](specs/ui.md) · [security](specs/security.md) · [deployment](specs/deployment.md)
>
> **This plan is complete through W28 (released as 2.0.1).** Work after that lives in
> [`PLAN-2.1.md`](PLAN-2.1.md) — packages W29–W33: node-card legibility, `notes`
> removal, per-link strength from the defence side, the org-chart view, and reports.
> Then [`PLAN-2.2.md`](PLAN-2.2.md) — packages W34–W49: the three views given three
> jobs, the sidebar shell, labels, assignees, notifications, settings and history.
> Then [`PLAN-2.3-ai.md`](PLAN-2.3-ai.md) — packages W50–W54: the local-model
> assistant, its runtime and its off switch. It began as a sketch; the spike in
> [`spikes/2.3-inference.md`](spikes/2.3-inference.md) measured the five open
> questions it could not answer and turned it into a plan. **The assistant is
> off by default and absent on a deployment that has not configured one** — no
> other feature depends on it, and every one of them works without it.
>
> **All four plans are now complete**, plus two self-scoped packages that no
> plan contained: **W55** deleted the Breeze scaffold W10 had replaced and left
> in place, and **W56** closed three of the five history gaps W48 found and
> pinned. What all of W29–W56 shipped, and everything still open, is in
> [`RELEASE-2.3.0.md`](RELEASE-2.3.0.md) — read that before proposing new work.

## Context

ArgueSecure is a distributed risk assessment platform built ~8 years ago during the owner's PhD and used since for consultancy engagements and teaching. It lets people collaboratively build structured risk assessments shaped as a tree: **Target of Assessment → Risks → Attacks → Defences/Transfers**, where a Defence is a component or architectural decision that reduces a risk and a Transfer shifts the risk or loss to another party (a contract clause, a disclaimer, an insurer).

The original developer is long out of contact and every layer of the stack is years past end-of-life: Laravel 5.2, PHP 7.1, Node 8, socket.io 1.3.7, jQuery 1.11, Bootstrap 3, Debian Buster. Several dependencies are not merely outdated but abandoned (`laravelcollective/html`, `laravel-elixir`, `lord/laroute`, `dompdf 0.6`, `swiftmailer`) — they need replacing, not upgrading. There is effectively no test coverage (2 real tests, both bypassing middleware) and no CI.

There are also live security defects: `.env` containing a real `APP_KEY` is committed to git, which on Laravel 5.2 is remote code execution (CVE-2018-15133); the websocket relay broadcasts every assessment to any anonymous client with privacy filtered only in browser JavaScript; several read endpoints have no authorization at all; and the admin role is a string comparison against the username `"admin"` with a documented default password.

Against that, the application itself is small: about 5,700 lines of first-party code, 4 entities, 25 routes, and 604 lines of jQuery. The domain knowledge worth preserving fits on one page. **The decision is therefore a clean rewrite in this repository, treating the existing code as an executable specification.** The old code stays in git history; nothing is carried forward except the schema concepts, the node-type metadata, and the visual design of the tree.

The rewrite also delivers a substantial feature expansion — quantification, sharing, reporting, templates, and a modernized editing experience — specified in full below.

---

## Decisions already made (do not re-litigate)

| Area | Decision |
|---|---|
| Strategy | Clean rewrite, same repository. Old code preserved in git history only. |
| Backend | Laravel 12, PHP 8.3, MariaDB 11, Redis 7 |
| Frontend | Inertia 2 + Vue 3 (`<script setup>`, Composition API) + TypeScript + Vite + Tailwind CSS 4 |
| Real-time | Laravel Reverb with private/presence channels, plus field-level pessimistic locking via Redis TTLs |
| Data | Fresh database. No migration of legacy assessments. |
| Schema | Single `nodes` table (single-table inheritance on `type`) with two typed pivot tables for the DAG edges |
| Defence math | Independent multiplicative composition: `residual = original × Π(1 − sᵢ)` |
| Reduction target | Per-defence choice of `likelihood` / `impact` / blank. **Blank is the default** and means "reduces the combined loss". |
| Risk-level defences | **Not supported.** Defences attach to attacks only. |
| Transfers | Purely multiplicative, like defences. **No deductible or cap.** |
| Qualitative values | Low/medium/high map to configurable numeric bands per assessment; everything computes numerically underneath |
| Result display | **Currency figures only.** No severity band scale. Reduction percentages and proportional bars provide the at-a-glance read. |
| Baselines | Three: **inherent** (no defences), **current** (implemented only), **target** (implemented + todo). Shelved never counts. |
| Defence cost | Separate one-off and annual amounts, one-off annualized over a configurable period (default 3 years) |
| Accounts | Admin-created (individually or bulk CSV). No self-registration, no SMTP dependency. |
| Sharing | Pick specific existing users and assign a role, plus a public flag and a revocable read-only link token |
| Reports | HTML report page with a Download PDF button driven by a tuned print stylesheet (see note in Phase 7) |
| External refs | Stored and validated identifiers rendered as hyperlinks. No outbound API calls. |
| Licensing | Free and open-source dependencies only. No paid libraries or services. |

---

## Architecture

```
┌──────────────────────────────────────────────────────────────┐
│  docker compose                                              │
│                                                              │
│  app        nginx + php-fpm 8.3   (Laravel 12 + Inertia)     │
│  reverb     php artisan reverb:start        (websockets)     │
│  queue      php artisan queue:work          (recalc jobs)    │
│  scheduler  php artisan schedule:work       (cleanup)        │
│  db         mariadb:11                                       │
│  redis      redis:7-alpine    (cache + queue + locks)        │
└──────────────────────────────────────────────────────────────┘
```

Redis replaces memcached entirely — one fewer service, and it is needed anyway for locks and the queue. The standalone Node websocket server is deleted; Reverb is a PHP process using the same image, so there is no second language in the deployment.

Requests are server-routed by Laravel and rendered by Vue through Inertia — no separate API to version, no client-side router, no duplicated authorization. State that must be instantaneous (the calculation engine) is mirrored in TypeScript on the client and reconciled against the authoritative PHP result.

---

## Phase 0 — Repository hygiene and secret remediation

Do this first and independently; it is small and it is the only urgent security work. Full detail in [`docs/specs/security.md`](specs/security.md#2-phase-0--legacy-secret-remediation-w4).

1. Tag the current state as `v1-final` so the running system stays recoverable.
2. Add `.env`, `.env-deploy`, and `*.local` to `.gitignore`. Delete `.env` and `.env-deploy` from tracking.
3. ~~Purge both files from git history with `git filter-repo`.~~ **Declined 2026-08-04 — history holds two dead `APP_KEY`s and placeholder values only, and the repository is private. See [`docs/specs/security.md`](specs/security.md#21-the-history-purge-declined-with-reasoning). Settled; do not reopen.**
4. Treat both committed `APP_KEY` values as burned. The rewrite generates a new one; never reuse either.
5. Remove the hardcoded `admin` / `XDARSEC` bootstrap from `migrate.sh` and the README. Phase 3 replaces it with a first-run setup flow.
6. Delete `public/vendor/` and `public/js/plugins/` (114,000 lines of an unused purchased Bootstrap 3 theme) as part of the Phase 1 teardown.

---

## Phase 1 — Foundation

**Goal: an empty but complete, deployable Laravel 12 + Inertia + Vue application with CI, running under `docker compose up`.** Full detail in [`docs/specs/deployment.md`](specs/deployment.md).

1. Scaffold Laravel 12 with Inertia + Vue 3 + TypeScript, Vite, Tailwind 4, Pest for testing. Remove any registration routes the starter kit ships.
2. Delete the legacy application: `app/` (old), `resources/views/`, `resources/assets/`, `public/vendor/`, `public/js/`, `server/`, `gulpfile.js`, `phpspec.yml`, `Dockerfile.websocket`.
3. **Dockerfile** — multi-stage: a Node stage running `npm ci && npm run build`, then a `php:8.3-fpm-alpine` stage with `pdo_mysql`, `redis`, `opcache`, `intl`, `bcmath`, `zip`, Composer install with `--no-dev --optimize-autoloader`, and the built assets copied in. Run as a non-root user. Use nginx rather than Apache.
4. **docker-compose.yml** — the six services above. Preserve the existing Unraid-friendly patterns from the current `docker-compose.yml`: `restart: unless-stopped`, `env_file: .env`, a named bridge network, and bind mounts — but make the host path a variable (`${DATA_PATH:-./data}`) so it deploys anywhere unchanged. Add healthchecks on `db` and `redis` and use `depends_on: condition: service_healthy`.
5. Keep the reverse-proxy trust configuration from the recent commits. This is real, recently-earned knowledge: the instance runs behind SWAG, and without it Laravel generates `http://` URLs. Configure `TrustProxies` for RFC1918 ranges with `X-Forwarded-Proto` trusted and `X-Forwarded-Host` **not** trusted.
6. `.env.example` documenting every variable, with no real values. Entrypoint script runs `php artisan migrate --force`, `config:cache`, `route:cache`, `view:cache`, and `storage:link`.
7. **CI** — a GitHub Actions workflow running Pest, PHPStan (level 6+), Laravel Pint, `vue-tsc --noEmit`, ESLint, and Vitest on every push.

---

## Phase 2 — Domain model

**Goal: the full schema, models, and policies, with no UI.** The authoritative specification is [`docs/specs/schema.md`](specs/schema.md); the summary below exists only for orientation.

### The core shape

A single `nodes` table with `type ∈ {root, risk, attack, defence}`, plus two typed pivot tables carrying the edges. This is a **DAG, not a tree** — attacks may belong to several risks and defences to several attacks — so a parent column would be wrong. A single node table is chosen because every cross-cutting concern (comments, revisions, tags, references, locks, ordering, search) then points at a real foreign key with real cascade behaviour; MySQL and MariaDB cannot put a foreign key on a polymorphic pair, which is exactly the class of orphan-row bug the legacy lock-sweep suffered from. Four queries load an entire assessment, always.

Type-specific columns are nullable and guarded by named `CHECK` constraints asserting that columns belonging to other types are `NULL`. PHP-side single-table-inheritance subclasses (`RootNode`, `Risk`, `Attack`, `Defence`), each with a booted global scope on `type`, mean application code never sees a wrong accessor.

### Tables

| Table | Purpose |
|---|---|
| `users` | + `is_admin` boolean, replacing the `$user->name != "admin"` check |
| `assessments` | owner, currency, `amortization_years` (default 3), `is_public`, `share_token`, `is_template`, `calc_version`, soft deletes, the three scale FKs |
| `nodes` | STI core; identity, text, `position`, per-type value columns, `created_by`/`updated_by`, soft deletes, `uuid` |
| `risk_attack` | edge + `position` |
| `attack_defence` | edge + `position` + `strength_mode` / `strength_fraction` / `strength_level_id` + `reduction_target` + `rationale` |
| `scales`, `scale_levels` | three kinds — impact, likelihood, strength — system defaults plus per-assessment overrides |
| `assessment_user` | sharing: role ∈ viewer / commenter / editor / manager |
| `comments` | threaded two levels deep, resolvable |
| `node_revisions` | immutable per-field change log grouped by operation |
| `assessment_operations` | the undo stack |
| `tags`, `node_tag` | assessment-scoped (a deliberate change from the legacy global tags, which made one user's rename hit everyone) |
| `node_references` | CVE and friends |
| `snapshots` | frozen graph + frozen results |
| `catalog_entries`, `catalog_entry_links` | reusable library items |

Locking has **no table** — it lives entirely in Redis (Phase 6).

### Key column details

**`nodes`** — risk rows carry `impact_mode`, `impact_amount` (loss **per occurrence**), `impact_level_id`. Attack rows carry `likelihood_mode`, `likelihood_aro` (occurrences **per year**, may exceed 1), `likelihood_level_id`. Defence rows carry `is_transfer`, `status ∈ {implemented, todo, shelved}`, `capex_amount`, `opex_annual_amount`. A generated column enforces exactly one root per assessment. `FULLTEXT(title, description)` backs search.

**`attack_defence`** — strength lives here, not on the defence, because the same control may be 80% effective against one attack and 20% against another. `reduction_target` is nullable; **null is the default and means the reduction applies to the combined loss**. On link creation, transfers default to `impact` and defences to `null`; both are user-overridable.

**`scale_levels`** — `ordinal`, `code`, `label`, `color`, `lower_bound`, `upper_bound` (exclusive, null on the top band), `representative` (the number used in arithmetic). Validate on save that bands are contiguous with no gaps or overlaps, start at 0, and that `lower ≤ representative < upper` — that last invariant is what makes the qualitative → numeric → qualitative round-trip lossless. Ship sensible system defaults; every assessment may override.

Note that `Assessment` has **no title column** — the title and description live on the root node, and listings join `nodes` on `type = 'root'`. One join is cheaper than a class of staleness bugs.

### Authorization

A single `AssessmentPolicy` is the source of truth, with abilities `view`, `comment`, `update`, `manage`, `delete`, resolved from: owner, `assessment_user.role`, `is_public`, a valid share token in the session, or `user.is_admin`. `NodePolicy` and `CommentPolicy` are thin delegates to the owning assessment's policy.

**This directly fixes two legacy defects.** First, the old `RiskPolicy`/`AttackPolicy`/`DefencePolicy` all returned `$node->tree->public || owner` for `update` and `destroy`, meaning any logged-in user could edit or delete the contents of any public assessment. Second, `show` actions on risks, attacks and defences had no authorization at all, and neither did the AJAX node-render endpoint — a straightforward read of private data. Every route in the rewrite goes through the policy; add a Pest test asserting that.

---

## Phase 3 — Accounts, admin, profile, sharing

1. **Login only.** No public registration. Rate-limited, with a generic failure message.
2. **First-run setup** — if no users exist, the app serves a one-time setup screen creating the first admin. This replaces the hardcoded `admin` / `XDARSEC` account entirely. Never seed credentials.
3. **Admin area** (`/admin`, gated on `is_admin`) — a real Vue interface, not the legacy string-compare pages:
   - Users table with search, sort, pagination: name, email, admin flag, assessment count, last login, created date.
   - Create, edit, deactivate, delete a user; set or reset a password; toggle admin.
   - Bulk create from a pasted or uploaded CSV, with a **preview-and-confirm step** showing exactly which rows will be created and why any are rejected. The legacy importer silently swallowed bad rows.
   - Optionally export the created accounts as a printable credential sheet for workshops — this is the one genuinely useful thing the legacy PDF did. Render it as an HTML print view, not via a PDF library.
   - Instance settings: default currency, default scale calibration, whether public assessments are permitted, session lifetime, registration lockdown.
4. **Profile page** (`/profile`) — display name, email, change password (requiring the current one), a strong password policy, session/device list with revoke, and delete-my-account with a confirmation that names what will be deleted.
5. **Sharing** — a share dialog on each assessment: search existing users, add with a role, change or revoke a role, transfer ownership. Plus the public flag and a **revocable read-only link token** for people with no account. Show the current collaborator list with roles and who invited whom.

---

## Phase 4 — Calculation engine

**Goal: a pure, well-tested engine, before any UI depends on it.** Build this ahead of the editor; the UI is much easier to design once the numbers are real. The authoritative specification, with every formula, edge case and the golden vectors, is [`docs/specs/calculation-engine.md`](specs/calculation-engine.md).

### The formula in brief

```
factor(a, S) = Π over live links (a,d) where d ∈ S of (1 − s(d,a))
ALE(r, a, S) = ARO(a) × Imp(r) × factor(a, S)
Total(S)     = Σ over all valued pairs (r,a) of ALE(r,a,S)
```

Computed for three defence sets `S`: **inherent** (empty), **current** (`status = implemented`), **target** (`status ∈ {implemented, todo}`). Shelved defences never participate.

The **(risk, attack) pair is the unit of loss**. Summing per-pair expected losses to a total is unconditionally valid because expectation is linear — no independence assumption is needed for the totals. Both slicings partition the same pair set, so `Σ over risks = Σ over attacks = Total`, which is what makes drill-down reconcile.

### A worked example to encode as the first golden vector

Two risks (R1 breach €200,000; R2 outage qualitative *moderate* → €30,000), three attacks (A1 phishing 0.5/yr, shared by both risks; A2 SQLi qualitative *unlikely* → 0.06/yr on R1; A3 DDoS 2.0/yr on R2), four defences (D1 MFA implemented, likelihood, 0.80 on A1, €4,000/yr; D2 cyber insurance implemented transfer, impact, 0.50 on A1 and 0.30 on A3, €12,000/yr; D3 WAF todo, likelihood, 0.65 on A2 and 0.50 on A3, €10,000/yr; D4 VPN hardening shelved, combined, 0.40 on A1).

Expected: **inherent €187,000**, **current €65,500**, **target €36,700**. Risk rows reconcile as R1 €22,000 + R2 €43,500 = €65,500, and attack rows as €11,500 + €12,000 + €42,000 = €65,500. `MC(D1) = €46,000`, `MC(D2) = €29,500`, opportunity of D3 = €28,800. Log-share attribution gives D1 €72,343.40 and D2 €49,156.60, summing exactly to the €121,500 inherent-to-current reduction, while the removal deltas sum to only €75,500 — the non-additivity, demonstrated.

---

## Phase 5 — The tree editor

**Goal: replace the legacy editor wholesale. This is the screen the tool lives or dies by.** Full detail in [`docs/specs/ui.md`](specs/ui.md).

The legacy flow — click a wrench to reveal buttons, then navigate to a full separate page to edit a node — is the main thing to eliminate.

- **Node card** — compact and dense, with a summary line carrying the numbers as coloured chips, action buttons always visible, and indicators for notes, comments, references, shared-across-parents, locked, and missing values.
- **Editing** — modal editor, never a page navigation. Adding a node inserts it immediately with an empty title and focuses that field. Keyboard-first outlining. Drag and drop to reorder and re-parent. Autosave with an explicit indicator and 409 conflict handling. A single linear per-assessment undo stack for structural changes.
- **Navigation and canvas** — rebuilt top navigation, zoom, both scroll axes, search/filter/focus mode, collapse/expand, duplicate highlighting on hover, and a live totals panel.

---

## Phase 6 — Real-time collaboration

1. **Reverb** with a `presence-assessment.{id}` channel per assessment, authorized server-side through `AssessmentPolicy`. This closes the legacy hole where every socket received every broadcast for every assessment and privacy was filtered in browser JavaScript.
2. **Presence** — avatars of who is viewing, and a live cursor or focus indicator showing which node each person is on.
3. **Field-level locking in Redis, no table and no polling.** Key `lock:{assessment}:{node_uuid}:{field}`, acquired with `SET NX PX 45000`, refreshed by a client whisper every 15 s using a compare-and-extend Lua script, released on blur/save/navigate with compare-and-delete, and on presence-leave. **The TTL is the sweep** — this deletes the entire legacy `HeartbeatController`, the `Heartbeat` middleware's full-table lock sweep, and the `locked`/`lock_time` columns. A blocked field shows "Locked by Dana" inline rather than a modal overlay.
4. **Broadcast granular events** — node created/updated/deleted/moved, link changed, comment added, totals recomputed — carrying the changed data, so the client patches its store. The legacy design broadcast only an id and then re-fetched a rendered HTML fragment per change; do not repeat that.
5. Toast notifications for others' changes, throttled and grouped so a bulk edit does not produce forty toasts.

---

## Phase 7 — Reports

A styled, self-contained report at `/assessments/{id}/report`, print-optimised, with charts as inline SVG so they survive printing.

**Contents:**
1. Cover — assessment title, target of assessment, owner, date, scale calibration used, completeness statement.
2. Executive summary — inherent, current and target annual loss, the reduction each buys, and the total annual cost of controls.
3. Risk register — every risk with impact, contributing attacks, current and target ALE, and the reduction.
4. **Defences ranked by importance** — implemented controls by marginal contribution, with cost, CBR, ROSI and net benefit, in the three cost buckets.
5. **Defences ranked by opportunity** — todo controls by what implementing them would buy, which is the roadmap.
6. Transfers section — what is transferred, to whom, and the retained impact.
7. Full assessment tree, indented, with values.
8. Appendices — CVE and other references, comments if requested, methodology and formulas, and the model-quality warnings from Phase 4.

**Options before generating:** baseline (current / target / both), include or exclude shelved defences, include comments, include the methodology appendix, qualitative-only or full figures.

**On PDF.** The button triggers the browser's print pipeline against a dedicated `@media print` stylesheet with proper page breaks, repeated table headers, and expanded links. On every current browser that produces a real PDF via "Save as PDF", and it always matches what is on screen. This is deliberate: it keeps a headless-Chromium container (roughly doubling image size and memory) out of a resource-efficient deployment, and it keeps the legacy `dompdf 0.6` — the single most dangerous dependency in the old repo, with known remote-file-inclusion and RCE issues — permanently gone. **If a true one-click server-generated file is wanted later**, add a Gotenberg sidecar as an optional compose profile; it is free, self-hosted, and reuses the same print stylesheet, so nothing built here is wasted.

Also provide CSV export of the ranked defence list, and JSON export/import of a whole assessment — the latter doubles as backup and as a way to hand an assessment to someone on another instance.

---

## Phase 8 — Extended features

1. **Comments** — threaded two levels, on any node, resolvable, with the `commenter` role able to comment but not edit. Unresolved-comment count on the node card.
2. **Per-node history** — the revision list in the node modal, showing who changed which field from what to what, with a "revert this field" action recorded as a normal edit.
3. **Snapshots** — freeze a named version storing both the graph and the computed results, with the scales inlined so later recalibration cannot retroactively rewrite history. Diff two snapshots by matching nodes on `uuid`, showing added, removed and changed nodes and edges, plus the movement in each headline figure.
4. **Templates and catalogue** — mark an assessment as a template and create new assessments from it; a searchable library of standard risks, attacks and defences, insertable by drag or command palette. Catalogue entries can be *bundles* (a "Phishing" entry shipping with MFA, awareness training and mail filtering pre-linked at default strengths). Store level **codes** rather than ids in catalogue defaults so an entry inserts correctly into any assessment regardless of its calibration. Note that inserting is a copy: later edits to a catalogue entry do not propagate into assessments already signed off, which is the correct behaviour.
5. **External references** — CVE primarily, validated against `CVE-\d{4}-\d{4,}` and linked to NVD. The table also accepts CWE, CAPEC, MITRE ATT&CK technique ids and plain URLs at no extra cost, since the validation-and-link mechanism is identical; surface CVE prominently in the UI and the rest under a "more" option.
6. **Read-only share links** — a revocable token granting view access with no account, clearly badged as read-only.

---

## Phase 9 — Content and documentation

1. **Sample assessments**, seeded and cloneable:
   - A small fully-populated demo — a web shop or similar — with every field filled so a new user can see what a complete assessment looks like.
   - Realistic templates for common system archetypes: a public-facing web application, a cloud SaaS deployment, an internal network, an IoT or embedded product.
   - **ISO/IEC 27001 and 27002** — risks and controls aligned to the Annex A control set.
   - **ISO/IEC 42001 and the EU AI Act** — AI-management-system risks: model, data, deployment and governance, with obligations mapped as defences.

   Store these as seeder-loaded JSON fixtures under `database/seeders/templates/` so they are editable without code changes. Note that ISO standard *text* is copyrighted — reference control identifiers and write original descriptions; do not reproduce standard text.
2. **New Help page**, replacing the hand-edited legacy Blade file, covering the concepts (risk, attack, defence, transfer), the quantification model with the formulas and their assumptions stated plainly, the keyboard map, collaboration and locking, sharing and roles, and how to read a report. Author it as markdown files rendered by the app so updating help is not a code deploy.
3. **README** rewritten around `docker compose up`, the actual current deployment. The legacy README still documents Vagrant, Homestead and a downloadable VM image, and publishes the default admin password.
4. `CONTRIBUTING.md`, an explicit `LICENSE` file (MIT, as the README claims but never provided), and a short architecture note.

---

## Phase 10 — Hardening and release

1. **Tests** — Pest feature tests for every route's authorization (the legacy app had zero); unit tests for the calculation engine driven by the golden vectors; Vitest for the TypeScript engine mirror against the same vectors; a handful of Playwright end-to-end tests covering login, create assessment, add nodes, enter values, share, and generate a report.
2. **OWASP pass** — see [`docs/specs/security.md`](specs/security.md).
3. **Resource efficiency** — opcache with JIT, eager loading everywhere (the legacy tree render was an N+1 by construction), the four-query graph load, Redis caching of results, gzip/brotli, and a Vite bundle budget. Target under 512 MB total across all containers at idle.
4. **Backups** — a documented `mysqldump` recipe and a scheduled command exporting all assessments as JSON to `storage/backups`.
5. Tag `v2.0.0`, write release notes, and document the cutover.

---

## Backlog (deliberately deferred, not discarded)

Server-side PDF via a Gotenberg profile; DOCX export; live CVE enrichment from the NVD API; risk-level defences (an insurance-style control attaching to a whole risk rather than to each attack — declined for now, but note that `RiskCalculator` should compute a `riskLevelFactor(r, S)` that is currently always `1.0`, so this becomes a migration plus a UI affordance rather than an engine rewrite); deductible and cap on transfers; a risk-appetite threshold line on reports; Monte Carlo distributions instead of point estimates; a portfolio view across assessments; multi-currency; SSO/OIDC; a public API with tokens; email notifications; an assessment-diff against the template it came from; comments on links as well as nodes.

---

## Verification

**Per phase**, before moving on:

- `docker compose up --build` from a clean checkout with only `.env.example` copied to `.env` reaches a working login screen. Re-verify at the end of every phase — deployability is a non-functional requirement, not a final step.
- CI green: Pest, PHPStan, Pint, `vue-tsc`, ESLint, Vitest.

**Phase 4 specifically** — the engine is the piece most likely to be subtly wrong. The full verification checklist is in [`docs/specs/calculation-engine.md`](specs/calculation-engine.md#15-verification-checklist).

**End to end**, before release:

1. First-run setup creates an admin; the admin creates two users and bulk-imports three more from CSV.
2. User A creates an assessment, adds two risks, three attacks (one linked to both risks), and four defences with mixed statuses and reduction targets — driven entirely from the keyboard, checking that adding a node focuses the name field and never opens a form.
3. User A shares it with User B as an editor. User B opens it in a second browser: presence avatars appear, B's edits appear live in A's window, and a field A is editing shows as locked for B.
4. Both users' totals update within a second of a value change, and match a hand calculation.
5. Hovering the shared attack highlights both of its occurrences.
6. Drag a defence to a different attack; reorder risks; reload and confirm the order persisted.
7. Delete a node, undo it, confirm it returns with its links intact.
8. Generate the report; confirm current and target states differ correctly, the ranked defence list is ordered by marginal contribution, cost buckets are separated, and Save as PDF produces a clean, correctly paginated document.
9. Create a snapshot, change several values, create a second snapshot, and diff them.
10. Create a new assessment from the ISO 27001 template and confirm the catalogue defaults resolved against that assessment's scales.
11. Open the read-only share link in a private window with no session: content is visible, every edit affordance is absent, and the API rejects a hand-crafted mutation.
12. As User C with no share, attempt to open the assessment, its individual nodes, and its report by direct URL — all must 403. Re-run against the node-render and node-update endpoints specifically, since those were unprotected in v1.

---

# Execution guide — running this with Sonnet agents

## Ground rules every agent must follow

These are restated in [`CLAUDE.md`](../CLAUDE.md) so every agent and sub-agent inherits them automatically.

1. **The specification lives in the repo** — `docs/PLAN.md` and `docs/specs/`. Read those, not any plan file outside the repository.
2. **One work package per session.** Do not start the next package in the same session; context degrades and blast radius grows.
3. **A package is not done until CI is green.** Run the checks locally before declaring completion.
4. **Commit at the end of each package** on a branch named `v2/<package-id>`, with the package id in the message. Never commit to `master`.
5. **Stay inside your declared file ownership.** If a package needs to change a file another package owns, stop and report rather than editing it.
6. **Never write a secret, key, password, or seeded credential into any file**, including seeders, tests, fixtures, and documentation.
7. **Write the test with the code, not after.**

## The task graph

Packages on the same line have no shared file ownership and can run **in parallel**. Everything else is sequential on its dependencies.

```
W1  bootstrap  ──▶ W2  scaffold ──▶ W3  docker ──┐
                                                 ├──▶ W5 schema ──▶ W6 models+policies ──┐
                            W4  hygiene ─────────┘                                       │
                                                                                         ▼
              ┌──────────────────────────────────────────────────────────────────────────┤
              ▼                          ▼                        ▼                      ▼
        W7 engine-php            W8 auth+admin           W9 sharing            W10 design-system
              │                          │                        │                      │
              ├──▶ W11 engine-ts ────────┴────────────────────────┴──────────────────────┤
              │                                                                          ▼
              └──────────────────────────────────────────────────▶ W12 tree-editor-core ─┤
                                                                          │              │
                              ┌───────────────────────────────────────────┤              │
                              ▼                    ▼                      ▼              │
                        W13 node-modal      W14 keyboard+dnd       W15 canvas+search      │
                              └────────────────────┴──────────────────────┘              │
                                                   ▼                                     │
                                             W16 realtime ◀────────────────────────────── ┘
                                                   │
              ┌────────────────┬───────────────────┼──────────────────┬─────────────────┐
              ▼                ▼                   ▼                  ▼                 ▼
        W17 reports     W18 comments+history  W19 snapshots    W20 templates      W21 references
              └────────────────┴───────────────────┴──────────────────┴─────────────────┘
                                                   ▼
                              W22 sample content ──▶ W23 help+docs ──▶ W24 hardening ──▶ W25 e2e+release
```

## Work packages

Each is one session. **Owns** lists the paths the package may write.

| ID | Package | Depends on | Owns | Notes |
|---|---|---|---|---|
| **W1** | Bootstrap the spec | — | `CLAUDE.md`, `docs/**` | ✅ **Done.** This document and the spec set. |
| **W2** | Scaffold | W1 | everything except `docs/**` | Delete the legacy app; scaffold Laravel 12 + Inertia + Vue + TS + Tailwind + Pest. Ends with a working login screen and green CI. Large, destructive, single session, no sub-agents. |
| **W3** | Docker + CI | W2 | `Dockerfile`, `docker-compose.yml`, `docker/**`, `.github/workflows/**`, `.env.example` | Must end with a clean-checkout `docker compose up --build` reaching the login screen. Spec: `docs/specs/deployment.md`. |
| **W4** | Repo hygiene | — | `.gitignore`, `migrate.sh`, `startup.sh` | ✅ Done (with W2). History purge declined — see `security.md` §2.1. `migrate.sh` and `startup.sh` deleted as dead v1 scripts. |
| **W5** | Schema | W3 | `database/migrations/**`, `database/factories/**` | ✅ Done. 18 migrations verified against a real MariaDB 11.4. Band-invariant validation deferred to `ScaleValidator` (needs cross-row comparison, not expressible as a single-row CHECK). |
| **W6** | Models + policies | W5 | `app/Models/**`, `app/Policies/**`, `app/Domain/Graph/**`, `app/Domain/Structure/**` | ✅ Done. STI wiring, `AssessmentPolicy`, graph loader, link/reorder/clone services, 79 tests. `AssessmentCloner` has no dedicated test — **W20 (templates/catalogue) must add one**, it is the service that package leans on hardest. |
| **W7** | Calculation engine (PHP) | W6 | `app/Domain/Calculation/**`, `tests/Unit/Calculation/**`, `tests/fixtures/calculation-vectors.json` | **The highest-risk package.** Spec: `docs/specs/calculation-engine.md`. Built engine + independent oracle in isolation, then reconciled — the isolation caught four defects in the spec's own worked example. **Two carried-forward gaps:** (a) `uncalibrated_scales` cannot be emitted until `AssessmentGraph` carries an `is_system` flag — see `calculation-engine.md` §12; (b) `CalculationCache` (Redis) deferred to W16, since the engine must stay pure. |
| **W8** | Auth, admin, profile | W6 | `app/Http/Controllers/Auth/**`, `Admin/**`, `resources/js/Pages/{Auth,Admin,Profile}/**` | Phase 3 items 1–4. **Must add the `is_active` middleware** — see `security.md` §4; W6 correctly left it out of the policies. |
| **W9** | Sharing | W6 | `app/Http/Controllers/ShareController.php`, `app/Domain/Sharing/**`, `resources/js/Components/Share/**` | Phase 3 item 5. |
| **W10** | Design system | W3 | `resources/css/**`, `resources/js/Components/UI/**`, `resources/js/Layouts/**` | Tailwind tokens, the rebuilt nav bar, buttons, chips, modals, icons, the node-type constant. Everything visual downstream consumes this. |
| **W11** | Calculation engine (TS) | W7 | `resources/js/calc/**`, `tests/js/calc/**` | Mirror of W7, validated against the same golden vectors to 1e-9. |
| **W12** | Tree editor core | W7, W10 | `resources/js/Pages/Assessment/**`, `resources/js/Components/Tree/**`, `resources/js/stores/**` | Node card, recursive DAG rendering, the store, autosave with 409 handling. |
| **W13** | Node modal | W12 | `resources/js/Components/NodeModal/**` | Tabbed editor: details, values, links, references. |
| **W14** | Keyboard + drag-drop + undo | W12 | `resources/js/composables/useKeyboard*.ts`, `useDragDrop.ts`, `app/Domain/History/**` | |
| **W15** | Canvas, search, focus, duplicates | W12 | `resources/js/composables/useCanvas.ts`, `useSearch.ts`, `Components/Toolbar/**` | Zoom, pan, both scroll axes, search/filter, focus mode, duplicate highlighting. |
| **W16** | Real-time | W12 | `app/Events/**`, `app/Domain/Locking/**`, `routes/channels.php`, `resources/js/composables/useRealtime.ts` | Reverb, presence, Redis locks. **Also owns `Calculation\CalculationCache`**, deferred from W7 because it needs Redis I/O and `RiskCalculator` must stay pure — see `calculation-engine.md` §11.4. |
| **W17** | Reports | W7, W10 | `app/Http/Controllers/ReportController.php`, `resources/js/Pages/Report/**`, `resources/css/print.css` | |
| **W18** | Comments + history UI | W13 | `app/Http/Controllers/CommentController.php`, `resources/js/Components/{Comments,History}/**` | |
| **W19** | Snapshots | W7 | `app/Domain/Snapshots/**`, `resources/js/Pages/Snapshots/**` | |
| **W20** | Templates + catalogue | W6 | `app/Domain/Catalog/**`, `resources/js/Components/Catalog/**` | |
| **W21** | External references | W13 | `app/Domain/References/**`, `config/arguesecure.php` | Small; can be folded into W13. |
| **W22** | Sample content | W20 | `database/seeders/**`, `database/seeders/templates/*.json` | **Good candidate for one sub-agent per template.** Reference control identifiers only — do not reproduce copyrighted standard text. |
| **W23** | Help + docs | W22 | `resources/markdown/help/**`, `README.md`, `LICENSE`, `CONTRIBUTING.md` | |
| **W24** | Hardening | W16, W17 | `config/**`, `app/Http/Middleware/**`, `tests/Feature/Security/**` | The OWASP pass and the performance pass. Spec: `docs/specs/security.md`. |
| **W25** | E2E + release | W24 | `tests/e2e/**`, `playwright.config.ts`, `docker-compose.e2e.yml`, release notes | ✅ **Done.** All twelve scenarios automated; 69 Playwright tests. Read `tests/e2e/README.md` first — the run needs its own stack and refuses to start without a password in the environment. The scenarios found four defects that had made shipped features non-functional (a 502 on the tree editor behind nginx, entered values never counted, a reorder endpoint that answered 200 and did nothing, undo that only rendered an error overlay) and four more that are pinned as `DEFECT:` blocks in `tests/e2e/**` — chief among them that real-time collaboration never connects in a deployed stack. See [`docs/RELEASE-2.0.0.md`](RELEASE-2.0.0.md). |
| **W27** | Close the release defects | W25 | `resources/js/composables/{useRealtime,useDragDrop,useKeyboardOutline}.ts`, `resources/js/Components/{Tree,Share}/**`, `resources/js/Pages/Assessment/Show.vue`, `resources/js/stores/assessmentStore.ts`, `app/Http/Middleware/HandleInertiaRequests.php`, `app/Http/Controllers/{AssessmentController,Auth/SetupController}.php`, `routes/web.php` (share group), `docker/nginx.conf`, `.env.example`, `config/arguesecure.php`, `database/seeders/SampleContentSeeder.php`, `tests/e2e/**` | ✅ **Done.** Closed the four pinned `DEFECT:` blocks W25 left in `tests/e2e/**`, plus the two smaller limitations. **Deliberately crosses package ownership** — the four defects each sat on a seam between packages, which is why W25 could not fix them: (1) real-time never connected in any deployment, because Vite bakes `VITE_REVERB_APP_KEY` at image build time while the entrypoint generates it at container start — the key now arrives as a shared Inertia prop, and `docker/nginx.conf` was separately proxying `/app` to `127.0.0.1` when Reverb is its own container; (2) the optimistic recompute threw "Cannot band-map a value against an empty scale" into a bare `catch {}` on every keystroke, because `Show.vue` never declared the `scaleLevels`/`scales` props the controller had always been sending; (3) `useDragDrop.ts` was imported by nothing, so no reordering affordance existed at all; (4) `ShareDialog.vue` was mounted by no page and the share routes bound by primary key while every payload exposes only `uuid`. Also: an abandoned untitled node now removes itself, and templates are seeded when the first admin is created. |
| **W26** | Assessment lifecycle | W6 | `app/Domain/Structure/AssessmentCreator.php`, `app/Http/Controllers/AssessmentLifecycleController.php`, `resources/js/Pages/Assessment/Index.vue` | **Added after the fact, and the reason it is worth reading this row.** Listing, creating and deleting assessments was assigned to no package: W8 owned auth, W9 sharing, W12 the editor that *renders* an assessment. Nothing created one, so a fresh deployment reached first-run setup and stopped — invisible for 24 packages because every test builds assessments with a factory and every route takes a uuid directly. Found by writing e2e scenario 2, which begins "User A creates an assessment". |
| **W28** | Close the pinned security defects; e2e in CI | W27 | `app/Http/Requests/Auth/LoginRequest.php`, `app/Http/Middleware/SecurityHeaders.php`, `bootstrap/app.php`, `routes/{web,auth}.php`, `.github/workflows/ci.yml`, `tests/Feature/Security/PinnedDefectsTest.php` | ✅ **Done, released as 2.0.1.** Closed the five defects W24 pinned rather than fixed, each `DEFECT:` block rewritten in place as a `FIXED:` regression guard: login throttling gained a second limiter keyed on IP alone (the pair key made password spraying free — every new email was a fresh bucket); `laravelVersion`/`phpVersion` removed from the guest landing page; `throttle:120,1` on node and link mutations with `throttle:300,1` as the authenticated default, plus `throttle:5,1` on the three routes §9 rates separately (`PUT /password`, `POST /confirm-password`, `PATCH /profile` — the last two found by mutation testing, not by the original pin); and `SecurityHeaders` moved to global middleware plus a `withExceptions()->respond()` hook, so a 404 for an unmatched URL and `/up` carry the header set too. Added the fifth CI job running all twelve Playwright scenarios against a throwaway stack — they had found eight defects and ran only when someone remembered to. **The lesson worth carrying:** the throttle pin asserted that no route had *a* `throttle` middleware, so once fixed it could not detect losing the tighter 120/min limit — `throttle:300,1` also matches a prefix test. Assert the specified value, and mutation-test the guard. |

## When to fan out sub-agents

Sub-agents share one working directory, so **fan out only when the sub-tasks own disjoint files**, and say so in the prompt. Good candidates:

- **W5** — one agent per table group (core nodes and edges / scales / collaboration / history), since each writes its own migration files.
- **W7** — one agent writes the engine, a second independently writes the golden vectors from the spec and the worked example, without seeing the implementation. Then reconcile. This is the single most valuable fan-out in the plan: an independently-derived oracle is what catches a subtly wrong engine.
- **W8** — admin area, profile page, and auth flow are three disjoint page trees.
- **W17** — one agent per report section, each owning its own Vue component.
- **W22** — one agent per template JSON.
- **W24** — one agent on the OWASP checklist, one on the performance pass.

Do **not** fan out W2, W6, W12, or W16 — each has a single coherent design that fragments badly across agents.

For read-only work — "find every place X is used", "check whether the spec covers Y" — use the `Explore` agent freely at any point; it cannot conflict with anything.

## Session prompt template

> Read `CLAUDE.md` and `docs/PLAN.md`, plus the spec file named in the package row. Implement work package **W5 — Schema**. Own only the paths listed for W5. Follow the ground rules. Commit to branch `v2/w5-schema` when CI is green.

Where the table suggests a fan-out, add: *"Fan out sub-agents for X, Y and Z — they own disjoint files."*
