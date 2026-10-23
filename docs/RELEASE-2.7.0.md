# ArgueSecure 2.7.0 — sign-in, scales, the trash and a security pass

> **Draft, written before the cut.** Follows [`RELEASE-2.6.3.md`](RELEASE-2.6.3.md).
> Plan: [`PLAN-2.7.md`](PLAN-2.7.md) and its companions
> ([identity](PLAN-2.7-identity.md), [scales](PLAN-2.7-scales.md),
> [logs](PLAN-2.7-logs.md), [dependencies](PLAN-2.7-dependencies.md),
> [collapsed indicator](PLAN-2.7-collapsed-indicator.md),
> [counter bar](PLAN-2.7-counter-bar.md),
> [authentication](PLAN-2.7-authentication.md),
> [filter categories](PLAN-2.7-filter-categories.md),
> [print choice](PLAN-2.7-print-choice.md),
> [report warnings](PLAN-2.7-report-warnings.md),
> [admin templates](PLAN-2.7-admin-templates.md)). Packages W104–W153 (those the plan
> marks as built), decisions D70–D109. The release is a minor, not a
> patch, because it adds eight migrations and several features. Tagged on the
> `master` commit that carries it; the base commit and the verification
> numbers are filled in when it is cut.

---

## The headline

Sign-in grows up: two-factor authentication and OpenID Connect single sign-on.
Administrators can define named scales and download the security event log.
Deleted assessments can be restored, purged or erased. A pass over the 2.6.3
enterprise-readiness review closes a set of security and correctness findings.
The editor gets a hidden-children counter, categorised filters and a Print menu;
the report says what it measures and can leave out the model-quality warnings;
and the sidebar shows the real version.

## 1. Security and sign-in

- **Two-factor authentication and OpenID Connect sign-in** (W116, W117,
  D74–D79). TOTP with ten recovery codes; one OIDC provider per instance,
  existing accounts linked by email, new accounts only for the operator's
  `OIDC_ALLOWED_DOMAINS`. An admin **Authentication** tab (W133, D105–D108) holds
  the provider settings (client secret encrypted, write-only; environment
  variables still win), a _Check provider_ button and the _SSO only_ and
  _Require two-factor_ switches. An admin can require a second factor and can turn
  password sign-in off for non-admins. Off until configured. **Single sign-on has been tested against a mock
  provider only, not against a real identity provider**; try it against yours
  before relying on it.
- **Sessions live in the database** (W107, D71): _Log out other devices_ and
  every revocation now act on the sessions that exist.
- **A security event log** (W111) on stderr, redacted, with a `security_events`
  table behind it (see section 3).
- **Exports treat text as text** (W106); **reorder and move act only on the
  route's assessment** (W104) and **undo and redo resolve every node inside the
  operation's assessment** (W121, D82).
- **The password breach check never blocks a sign-up or change** (W120, D84):
  three-second limit.
- **Redis never evicts** (W109, D72), and the calculation cache bounds itself.
- **Dependencies** (W122, D85–D87): `symfony/yaml` patched; the npm audit in CI
  covers what is bundled.

## 2. Assessments and editing

- **Pages with a Save button warn before you leave with unsaved changes**
  (W142). Known limits: the browser's Back and Forward buttons and signing out
  are not guarded, and there is no end-to-end check of the guard (unit tests
  only).
- **"Use this template" stays on one line** (W140).
- **Templates belong to nobody** (W141, [`PLAN-2.7-admin-templates.md`](PLAN-2.7-admin-templates.md)):
  any administrator creates, edits (in the usual editor) and deletes templates
  on the Templates page. **Publish as template** in an assessment's Share dialog
  now copies the assessment: the template is not linked to it, editing one
  never changes the other, and there is no _Unpublish_ (delete the template
  instead). Everyone else uses templates exactly as before.
- **Deleted assessments** (W113, D81): restore, purge on a schedule
  (`trash_retention_days`), or erase at once. Deleting a user transfers or
  erases theirs.
- **Admin-defined scales** (W126, D92–D94): name a set of impact, likelihood
  and strength bands and pick it when creating an assessment.
- **Undo after a redo** undoes the right thing (W108); a new action clears the
  redo stack (W121, D83).
- **A share link opens the assessment** for a recipient without an account
  (W114).
- **A collapsed card shows how many children it hides** (W127, D95); the count
  is the card's expand button in the bottom bar, and the hover bar no longer
  sticks open (W130, D104).
- **The dashboard's counts are the real totals** (W125, D91).
- **Filters have named categories** (W132, W137): the Filters pop-up groups the
  editor's filters under headings, on a shared pop-up shell.
- **Print asks what to print** (W138, D109): _Chart_, _List_ or _Report_, the
  current view first.
- **The View label is gone** beside the view toggle (W134).
- **The report says what it measures** (W135): the cover's "Completeness" reads
  "Current known risk" and the ranking is "Defences ranked by cost benefit".
  **The report can leave out the model-quality warnings** (W136), saying how many
  it left out; its settings sit under _Defences_ and _Sections_ buttons.
  **Every report section has a switch** (W139, D118), the four optional
  appendices and details start off, and _Defences_ lists Implemented, Planned
  and Shelved plainly (W153, D120).
- **Report toggles are no longer rate-limited like exports** (W110, D70).
- **Unsaved edits ask before you leave** (W142, D117, D119): settings pages,
  your profile and the scale editors offer Stay or Leave when you follow a
  link.

## 3. Administration and operations

- **Download the security event log** (W128, D96–D100): CSV or JSON, filtered,
  capped at 50,000 rows; retention `SECURITY_LOG_RETENTION_DAYS` (default 90).
- **Application logs go to stderr** (W112); read them with `docker compose logs`.
- **Bulk import reads what Excel writes** (W123, D90): byte-order mark,
  UTF-16, Windows-1252, `;` and tab delimiters.
- **Generated passwords are shown** (W115): flash messages reach the page.
- **The sidebar shows the running version as one link to its notes** (W124,
  W129, D90, D103).
- **The ArgueSecure logo on the login screen** (W131).
- **Maintainers:** CI runs only in the public repository (W118, D80); test
  coverage is measured and held by a floor (W119, D88, D89).

## Upgrading from 2.6.3

- **Eight migrations** run on start: `assessment_operations.redone_at` and
  `superseded_at`, two-factor columns, single sign-on columns, `scale_sets`,
  `security_events`, the provider settings on `instance_settings` and the template
  conversion below. Back up the database first.
- **Everyone signs in again once.** Sessions move to the database
  (`SESSION_DRIVER=database`), so every existing session ends at the upgrade. Copy the new lines from `.env.example` if you keep your own.
- **Redis** now runs with `noeviction`: the compose file changes, so pull it
  with the images.
- **Optional:** OIDC (`OIDC_*`, `OIDC_ALLOWED_DOMAINS`) and
  `SECURITY_LOG_RETENTION_DAYS`.
- **Templates are converted once, by a migration (W141).** The bundled
  templates become ownerless in place, losing their former owner (the first
  administrator), and any collaborators, share link and comments they had. A
  template that someone published from their own assessment is **copied** to a
  new ownerless template and the assessment goes back to being an ordinary one,
  owner and collaborators untouched. A database CHECK now requires a template
  to have no owner and an assessment to have one. Take the backup that the
  other migrations already ask for.
- **A bundled template that was in the trash stays there**, now ownerless: it
  is not reseeded (its root uuid still exists) until an administrator erases
  it from _Deleted_.
- **Templates already loaded stay as they were**; nothing in 2.7 adds one.
- **Report links with no `include_methodology` or `include_warnings` now show neither** (W153); they used to show both. The app always writes both, so only hand-built links, bookmarks and scripts change. Download still includes both, and a report without the methodology says so.

## Verification behind this release

Run on a Windows PC with Docker, on `master` with every package above merged
(the last, W153, at `84cb556`):

- **The seven checks:** Pint (577 files), PHPStan (no errors), Pest (1,704
  passed, 2 skipped, 16,215 assertions), ESLint, Prettier, `vue-tsc`, Vitest
  (127 files, 1,241 tests). PHPStan first failed on W153 (a redundant
  `is_string()`); it was fixed and re-run clean.
- **`HelpTest`:** 16 passed; the help corpus is 7,939 of 8,000 tokens.
- **End-to-end:** 146 of 146 scenarios on a clean stack. The first run of the
  last round failed one first-run scenario by half a second against its 10 s
  limit while another job was using the CPU; it passed on a quiet re-run, and
  that scenario sits close to its limit.
- **Deployability:** `docker compose up --build -d` from a fresh `.env` and an
  empty data folder (checked on the tree before W153), six services up,
  `curl -f /up` answers 200, and the sidebar reads v2.7.0.
- **Upgrade from 2.6.3:** run on a 2.6.3 data folder with the bundled templates
  and two user-published ones: the migrations ran, the bundled templates became
  ownerless in place, the published ones were copied and their assessments
  returned to their owners, and _Use this template_ worked on all of them.
- **Flake, named plainly:** on one Vitest run, a timer in the report print code
  fired after the test environment was torn down ("window is not defined",
  `Report/Show.vue`, via `tests/js/report/ShowChrome.spec.ts`). It did not recur
  in three further runs.
- **Pint, once:** run together with PHPStan straight after setup it reported a
  failure whose output was not kept; run alone it passed (577 files).
- **Not covered:** single sign-on against a real identity provider; CI, which
  runs only in the public repository (W118).

## Still open

- **Decision numbers D101, D102, D105 and D106 are each used twice** across the companion plans
  (written in parallel on separate branches). Each file's references are
  internally consistent; renumber in a follow-up.
- Open `DEFECT:` pins at the cut: two Vitest pins (`CommentThread.spec.ts`,
  `ReferencesTab.spec.ts`: a changed node uuid reloads the original's data).
- Everything under 2.6.3's _Still open_ is unchanged.
