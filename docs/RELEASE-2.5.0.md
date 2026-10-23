# ArgueSecure 2.5.0 — the second workshop pass

> What `PLAN-2.5.md` asked for (W82–W88, items 1–23,
> decisions D39–D51), what shipped, what changed along the way, and what is
> still open. Follows [`RELEASE-2.4.0.md`](RELEASE-2.4.0.md).
>
> Branch: `v2/integration-2.5`, 55 commits off `v2/integration-2.4` @ `8407eb5`.

---

## The headline

2.4 was the first plan written from someone using the thing, and its items were
mostly *one surface at a time* — this card, that tab, that button. 2.5's are
mostly about **the same thing existing in three places and being different in
each**, and that is the difference between the two releases.

Six of the twenty-three items were one absence said six ways: there was no
shared answer to "what can I do with this assessment". There is now.
`AssessmentActions` — Settings · History · Share · Export · Print — is one
component, mounted by the editor and by the report, and mirrored verb-for-verb
by the assessment list. History and Assessment settings left the application
sidebar, where they had been sitting one level above the assessment they belong
to. The report stopped being a different kind of page and became the third
view: it keeps the sidebar for a signed-in reader, renders bare for a
share-link guest, and has search, an always-visible options row and its own
zoom.

The rest divided into three groups and all three landed: **density and
geometry** (the list breathes and opens larger, both views centre, zoom is
remembered per view), **the scales** (edited from Application settings, no
colour input, no code input, resettable, defaulted from a new admin Defaults
tab), and **four things that did not work** — of which three are fixed and one
is pinned as a live defect this release could not reach.

**The most valuable thing this release produced is not in the item list.** Two
of its packages found defects that the plan's own instructions would otherwise
have shipped, and both are described below: D44's freeze would have left stale
internal keys rendering as labels on every card, and W88's write serialisation
deadlocked autosave on its first attempt.

---

## 1. One action bar, and what the sidebar is for (W82)

Items 2, 6, 17, 18 · D40, D41, D42

`resources/js/Components/Toolbar/AssessmentActions.vue` holds all five actions
in one place and is rendered from the shared chrome W81 factored out, not from
each page. It takes the assessment, the abilities and the current view mode,
and renders nothing a viewer cannot do.

History and Assessment settings were **removed from `Sidebar.vue` in the same
change that mounts them in the action bar**. That is W36's rule, and it is
worth recording that the package broke it first: its opening commit removed
them from the sidebar while the new component was still unmounted, leaving both
affordances unreachable. It escalated rather than reaching into a file it did
not own — `Pages/Assessment/Show.vue` turned out to be owned by **no package in
the plan**, which is why the bar could be built but not mounted.

The sidebar keeps its own job: tooltips when compact (reusing the existing
`Tooltip`, not a second one), and a Help item above Notifications pointing at
`help.index`, which had existed since W51 and was reachable only by typing the
URL.

**Print prints the current view** (D42). The chart and list print rules joined
`print.css`, which until now only dressed the report.

Two things the package found on its own: `ChromeHeaderBand` sat outside the
editor's print-CSS scope, so the title-row actions printed uncontrolled; and a
full-suite Vitest flake where a heavy page mount exceeded the default hook
timeout under parallel contention — fixed at the root, not by raising a
timeout, and verified stable across three consecutive runs.

---

## 2. The report becomes the third view (W83)

Items 3, 4, 5, 13 · D39, D43

`Pages/Report/Show.vue` is deliberately **not** wrapped in
`AuthenticatedLayout`, and the reason is still true: the report's audience
includes anonymous share-link guests and that layout requires a `{name,email}`
user. "Just wrap it" is the obvious fix and it breaks every share link. So the
page chooses its chrome on whether `auth()->user()` exists, and both paths are
tested — the guest path by mocking `usePage()` to return `auth: { user: null }`,
a genuinely unauthenticated render rather than an authenticated one with a flag
flipped.

`AssessmentActions` is mounted for an authenticated viewer only. That is a
deliberate divergence from D40's literal "all three views render it", reasoned
and tested rather than accidental: History has no ability gate anywhere it
renders because its route carries `auth` middleware, so the bar is not mounted
for a guest at all. **Export and Print stay reachable for a guest** through the
guest branch's own controls — neither route carries `auth`.

Search reuses the other views' component. The options panel became a row,
always visible beside the search; changing an option still re-requests the page
with a new query string, and an architecture test asserts no report figure is
computed in the client. Zoom applies to the report body only, under a key
containing the view mode.

**One question the plan left open remains open by design.** Whether the
report's zoom should scale typography or page width is listed under "Still to
decide by measurement, not by argument", and the plan says to decide it against
a *printed page*. That cannot be produced in this environment. Type size was
chosen, implemented, and recorded in `Show.vue`'s header as a default pending
that measurement, together with the specific check that would change it. It is
not presented as settled.

---

## 3. Density, centring, and one zoom per view (W84)

Items 11, 12, 14, 16 · D43, D51

Zoom is persisted per view: `useCanvas.ts`'s storage key gained the view mode.
A value stored under the old key is read **once**, as the seed for both views,
and then each diverges — a user who set 70% last week does not find 100% after
the upgrade. Both halves are tested.

The list centres in its viewport. The chart centres **when it is narrower than
the page** and left-aligns when it is not, because a graph wider than the
viewport that starts centred has its root off-screen. That conditional turns
out to need no conditional code: `mx-auto` on an explicit-width sizer resolves
exactly this way under CSS 2.1 §10.3.3, auto margins collapsing to zero once
content exceeds the container. Asserted at two viewport widths in both
directions.

The list got **both** more spacing **and** a larger starting size, because they
are two different complaints. Zoom cannot fix density — it scales the gaps
along with the text. Row spacing and indent grew, and `CANVAS_BASE_SCALE`
became per-view (`{chart: 1.1, list: 1.35}`) so the list opens larger.
**`CANVAS_ZOOM_DEFAULT` was not touched**, which is the point of D51 and D28:
raising the default would make the control read 110% on first load, telling a
user who has never touched zoom that they are zoomed in, and would silently
change the meaning of every stored preference.

The list's baseline value, 1.35, is a judgement call rather than a specified
number. It is pinned as an exact test value so changing it has to be
deliberate.

---

## 4. The node card: one tag, and an assistant that is present (W85)

Items 1, 19 · D50

The occurrence tag became the same compact icon-and-number chip a shared
defence already used — `<Icon name="shuffle" :size="10" />` plus the count, on
both card types, with the accessible name preserved now that the visible words
are gone.

`ChartCard.vue` contained the string `assistant` zero times, and the chart is
the default view — so "the AI suggestions are not working" was, in the first
instance, a control built once and mounted once.

**Mounting the trigger was not enough, and that is the interesting part.** The
first implementation added the button with the correct three gates and the
correct `data-testid`, and wired its click to `emit('assistantRequest')` — an
event **nothing in the application listened for**. The tree card does not emit
at all; it owns about a hundred lines of state, a call to
`store.suggestChildren`, D24's accept panel and W58's progress polling. A
literal reading of D50 therefore produced a button that did nothing, which is
precisely the complaint D50 exists to fix, and `NodeCard.vue` states the rule
it broke: *"a control that cannot work is worse than no control"*.

The fix was to extract, not duplicate: `Components/Node/useAssistantChildren.ts`
and `AssistantPanel.vue` are now shared by both cards. D24's accept step routes
each suggestion through `store.addChild` one at a time, there is no "accept
all", and no HTML renderer was introduced for generated output.

---

## 5. The assessment list, the dialogs, and the settings page (W86)

Items 7, 8, 9, 15

The list offers exactly **Settings · Share · Export**, asserted by name, using
W74's existing `ExportMenu` rather than a second menu with the same name. Report
and Download are gone.

The settings page gained a "Return to assessment" link and **lost its title and
description inputs** — they live on the root node and are edited there. Two
editors for one field is how they diverge. The guarantee lives in the request
class, not in the absence of an input: `title` and `description` are now
`prohibited`, with the acceptance test at the request layer.

**That removal closed a defect in the history log.** The old write went through
`$root->save()` → `NodeObserver` → `RevisionRecorder::recordUpdate()` — the
ordinary tree-edit path — so the history page really had been recording an
assessment title edit from a second place. The test that exercised the
resulting double revision was rewritten in place as a `FIXED (W86)` guard
rather than deleted.

**Item 15 was a plan error, not a missing feature.** The plan grouped it with
item 8 as "dialogs with no padding", but there is no dialog: "Use this
template" is a plain button and the cards already carry their own padding. The
package escalated rather than inventing UI. The real defect was
`Pages/Catalog/Templates.vue`'s bordered panel nesting a self-padded
`EmptyState`, so the empty state sat flush against the border while the
populated list looked correct.

---

## 6. Scales, reduced and relocated (W87)

Item 10, item 20 · D44, D45, D46, D47, D48

The largest package in the plan, and the one the plan itself said had a trap in
it.

### D44, and the defect it would have shipped

`scale_levels.code` is now **fully internal**: no input on any scale editor, and
the Code column removed from the exported methodology table, which was the one
place a user actually saw it. It stays in the database, stays the
`(scale_id, code)` unique key, stays in `AssessmentCloner::resolveLevelDefault()`'s
lookup and stays as `impact_level_code`/`aro_level_code` in
`calculation-engine.md` §8.3's normative payload. It is derived from the name
on create and **frozen on rename**.

An id cannot do this job: a custom scale is cloned per assessment, so the id of
"major" differs in every assessment and does not exist until the clone runs,
while a catalogue fixture is written once and must resolve in all of them.
Re-deriving on rename would silently detach every catalogue entry that named
the old value — the level still renders and every naive test still passes.

**Implemented exactly as written, D44 would have introduced a new defect.**
`ChartCard.vue` rendered `formatLevelCode(level.code)` — printing the internal
key *as if it were the label*, invisible only because the seeded codes happen to
match the seeded labels. The moment codes freeze, renaming "Moderate" to
"Medium" would leave the card and its tooltip saying "moderate" permanently.
The same was true of `NodeCard.vue`, `valueHints.ts`, and — found later —
`ScaleValidator`'s rejection messages, which named a band by its code. All were
changed to render the label. The card's qualitative branch had never been
rendered by any test before; it is now, using deliberately divergent code and
label values so the assertion cannot pass by seeded coincidence.

### The rest

`Pages/Scales/Calibrate.vue` **and its route** are deleted (D46) — a screen that
still answers its URL after its entry points are removed is how a second,
divergent editor survives a consolidation. No colour input (the column stays
with its `#9CA3AF` default). No calculator at the foot of the three cards. A
"Reset to default" per card. The "Still using the system defaults" warning is
gone, because using the defaults is the normal state and a warning says
something is wrong.

A new admin **Defaults** tab (D47) edits impact, likelihood and strength bands
plus currency, using the same band editor the assessment uses — enforced by a
test comparing the two screens' payloads key-for-key rather than by inspection.

**Three assistant switches** (D48), one per feature, each subordinate to
`Availability`: a switch can only ever subtract, never make a feature work
where the runtime is absent. Every call site asks `Availability`, never a flag
alone — including the chart's and the report's `assistantAvailable` props,
because gating only the controllers would leave a control that then 403s.

### Two more defects found while building it

**An ordinal collision that the new work would have hit immediately.**
Renumbering `scale_levels.ordinal` collides on `scale_levels_ordinal_unique`
when a band is removed or reordered. It was unreachable from the old screen —
the first save clones the scale — but the Defaults tab edits the seeded set in
place and walks straight into it. `LevelOrdinals::park()` moves the whole range
out of the way in one statement (source 1..n, target 101..112, disjoint, inside
a transaction) before renumbering.

**A flash bag that reaches nobody.** `HandleInertiaRequests::share()` never
shares it, so four controllers' `->with('success', …)` are invisible. Pinned as
a `DEFECT:` test with the trade-off in the docblock and worked around on the
two screens this package owns; the general fix is outside its ownership.

---

## 7. The modal: two defects, reproduced first (W88)

Items 21, 22, 23 · D49

D49 required that items 21 and 22 be **reproduced before being fixed**, because
"a fix written to a plausible story, against a defect that reproduces only
sometimes, is indistinguishable from a fix that did nothing." The commit order
shows it was honoured: a reproduction commit containing no production change,
then the fix.

### What the reproduction found

The plan's hypothesis was that per-keystroke autosave 409s against its own echo:
`updateNode` reads `updated_at` when called, and the previous response has
already bumped it. **The hypothesis held for item 21, but not in the shape it
predicted** — the failing case is an *in-order* response that was already stale
when it landed, not an out-of-order race. The first draft of that reproduction
*passed* when failure was expected, and chasing that discrepancy rather than
banking the green test is what found the real mechanism.

**Item 22 turned out to be two defects, not one and not "the same as 21".** The
plan listed "whether item 22 is the same defect as item 21 or a second one"
under "still to decide by measurement". The answer is neither:

- **22a** is the same overlap as item 21 and is fixed with it.
- **22b is independent and is not fixed** — see Still open.

The fix serialises writes per node so a request's own echo can never be
mistaken for a third party's edit. **The conflict banner still fires for a
genuine second editor**, which has its own test: a fix that suppressed 409s
would have passed the reproduction and broken `ui.md` §3.5.

### The deadlock the fix introduced, and closed

The first serialisation attempt **deadlocked autosave permanently** on any
non-409 failure. A keystroke arriving during the failed request stayed in
`pendingWrites` while the rejection cleared `activeWrites`, so every later
keystroke merged into that orphan and returned a resolved promise without
starting a drain. No request was ever sent for that node again, silently — and
it was a regression, because before serialisation the next keystroke recovered.
Closed by merging into the queue *before* the decision, so that starting a drain
depends on the queue being non-empty rather than on which branch the caller
took.

### Item 23

A newly created node offers an **"AI suggestion"** button before the user
types — a button, not an automatic call, because a suggestion costs about
thirty seconds of CPU. It sits beside W79's "link, don't duplicate" list, which
is a different feature with a different source: that one searches *existing*
nodes, this one asks the model. The codebase's `assistant*`-never-`suggest*`
naming discipline is kept for exactly that reason. The button is absent, not
disabled, where the assistant is unavailable, and nothing it returns reaches the
graph without an accept.

---

## 8. Three defects that only integration could find

Every package passed its own review before merging. These three existed only in
the combination.

**The zoom collision (W83 × W84).** W84 made `UseCanvasOptions.view` required —
deliberately, because a caller that forgets it silently shares one view's zoom
with the other, which is the bug D43 fixes. W83, branched earlier, had folded
`::report` into the `assessmentUuid` argument to avoid editing a composable it
did not own. Type-check failed on merge. Worse, W83 read `canvas.listStyle`, so
once W84 raised the list baseline to 1.35 the report would have rendered at
1.35× while its zoom control read "100%". Resolved by widening the canvas's own
type — `CanvasView = EditorViewMode | 'report'` with a `report: 1.0` baseline —
rather than widening `EditorViewMode`, which also drives the view switcher and
the persisted editor preference and would have made the report a selectable
editor view.

**The switcher's 21px drift (found by e2e).** The view switcher sat 21px lower
on the report than in the list. D40 exists because the report's toolbar drifted
*6px* from the editor's before it shipped, so this is that defect returning at
four times the size. Fixed in `ChromeToolbarBand.vue`.

**The report inheriting the editor's print header (found by e2e).**
`AuthenticatedLayout`'s `editorPrint` regex, `/^\/assessments\/[^/]+/`, also
matched `/assessments/{uuid}/report`, so W82's new editor print rules put the
editor's running header on the report's printed output. Narrowed to
`/^\/assessments\/[^/]+\/?$/`.

That last one is worth a note, because two fixes composed correctly across
three steps. The whole-branch review had flagged that the report's options row
was hidden on print only by an *accident* of that same regex, and warned that
"a future tightening of `inAssessment` to exclude non-editor assessment routes
would silently reprint" the report's chrome. W83 responded by putting an
explicit `no-print` on both branches. The tightening then happened — and was
safe, because nothing depended on the accident any more.

---

## Upgrading from 2.4.0

- **One new migration**, adding three boolean columns to `instance_settings`
  for the per-feature assistant switches — `assistant_help_enabled`,
  `assistant_suggestions_enabled`, `assistant_summary_enabled`. All three
  default **on**, which is the right default precisely because they can only
  ever subtract (D48): the master `assistant_enabled` still defaults off, so an
  upgrade changes nothing until a deployment turns the assistant on, and when it
  does it gets all three features rather than requiring four switches to be
  found. Every one is subordinate to `Availability`, so none can make a feature
  work where the runtime is absent or unreachable.
- **A route is gone.** `assessments.scales.edit` no longer exists (D46). Any
  bookmark to an assessment's scale calibration screen now 404s; scale editing
  is on the Assessment settings page.
- **Stored zoom preferences are migrated on read**, once, seeding both views.
  No action needed.
- **`scale_levels.code` is no longer shown anywhere**, including the exported
  methodology table. The column, the unique key and §8.3's payload are
  unchanged, so catalogue fixtures and the calculation engine are unaffected.
- Nothing in the calculation, the DAG or `AssessmentPolicy` changed.

---

## Verification behind this release

Run on the integration branch, all seven commands from `CLAUDE.md`:

| Check | Result |
|---|---|
| `vendor/bin/pint --test` | passed |
| `vendor/bin/phpstan analyse` | No errors |
| `php vendor/bin/pest` | **1157 passed**, 5312 assertions, 2 skipped |
| `npm run lint:check` | clean |
| `npm run format:check` | clean |
| `npm run type-check` | clean |
| `npm run test:unit` | **863 passed**, 94 files |
| `npx playwright test` | **139 passed**, one clean ordered run |

Each package also had a task review and, where findings were raised, a fix
round and a scoped re-review — eight fix rounds across seven packages. A
whole-branch review returned "merge after fixes" with two Important findings,
both fixed.

### On mutation evidence

Every package was required to mutation-test its guards — neuter, confirm red,
restore — and reviewers were asked to audit each claim against the actual test
code rather than accept the table. That audit was worth running: **two packages
reported mutations that provably could not have happened.** One claimed that
deleting a gate would turn a test red when the test set a non-reactive store
field *after* mount, so the component rendered with two gates false and the
mutation changed nothing; the other's table described a mutation whose stated
failure count was impossible.

The second of those was resolved **in the implementer's favour**: it had
re-run the mutation rather than accept the correction, and the row's prose was
ambiguous rather than its count wrong — and the reviewer's misreading described
a real, previously untested property, which the implementer then closed.

One package declined to claim mutation evidence it did not have, reporting that
a mutation of its `finally` placement left the suite green because that window
is not deterministically reachable from a test. That is the behaviour the
convention wants.

### The end-to-end run

The first run returned 5 failures out of 139, identical across two clean runs —
deterministic, not the dropped-keypress cascade `tests/e2e/README.md` warns
about, which shows as a double-digit count. Two were genuine product
regressions (§8), three were test defects: a spec that assumed chart view
without setting it, an assertion chasing an accessible name that had been
deliberately corrected, and exact pixel equality against the new 1.35 baseline.

**A trap worth recording for the next release.** The e2e containers build from
a Dockerfile with **no source mount**, so a reset that omits `--build` tests a
stale image. Two "final" runs appeared to still fail for that reason before it
was noticed. `docker-compose.e2e.yml`'s own instructions say to use `--build`;
they mean it.

---

## Still open

### Pinned as tests (the index `CLAUDE.md` points at)

`grep -rn "DEFECT:" tests/` is the whole index.

- **Item 22b — a user receives the broadcast echo of their own writes.**
  `NodeObserver` dispatches `NodeUpdated`/`NodeCreated` via `event(...)` rather
  than `broadcast(...)->toOthers()`, and `useRealtime.ts` applies node payloads
  regardless of actor, so a user's own `node.created` echo can blank a title
  they have typed since. **This is the one live user-visible defect knowingly
  shipping in 2.5.** W88 could not fix it — `useRealtime.ts` and `app/Events/**`
  are outside its ownership and no package in the plan owns them. Pinned in
  `tests/js/stores/selfEchoDefect.spec.ts` with both candidate fixes and their
  trade-offs, plus a third test guarding what any fix must not break: a genuine
  second editor's update must still be applied.
- **The flash bag is not shared.** `HandleInertiaRequests::share()` omits it, so
  four controllers' `->with('success', …)` reach nobody. Worked around on W87's
  two screens; the general fix is a separate package. The pin says to remove the
  workaround when it lands.

### Known gaps

- **The assistant still has no end-to-end coverage.** 2.4 left this under Still
  open, and this plan's prompt noted it was closeable here because the runtime
  is available on this machine. **It was not closed.** No package's brief
  included it, packages were instructed not to run e2e, and no dedicated task
  was dispatched for it. `grep -rn "assistant" tests/e2e/` returns nothing. The
  assistant's behaviour is covered at unit and feature level, and the guardrail
  pins in `tests/Feature/Assistant/GuardrailTest.php` are unchanged.
- **The report's zoom scaling is a default, not a decision.** Type size was
  chosen; the plan says to settle it against a printed page, which this
  environment cannot produce.

### Verification debt

- **e2e is not run per package.** It is run once, at integration. Both product
  regressions in §8 existed inside individually-green packages and would have
  been caught earlier by a per-package run, at a cost the plan judged too high.
- **`ChromeToolbarBand` wraps only below 1024px** (`max-lg:flex-wrap`). A bare
  `flex-wrap` would break `Toolbar.spec.ts`'s assertion that the token is absent,
  which guards W81's 1280px one-row requirement. The narrow-viewport behaviour
  above 1024px is unchanged and untested in a real browser.

### Documentation that overstates or has gone stale

- `RevisionCoverageTest.php`'s `FIXED (W86)` docblock claims its second half
  "specifically proves the old double-write's shape cannot reappear". It does
  not: any request carrying `title` trips the first half's `prohibited` rule
  first, so the second half is unreachable by a reintroduced defect. The
  coverage is real — the claim is too strong.
- `assessmentStore.ts`'s drain docblock says "a non-empty queue always has a
  drain behind it". True at every decision point, not at every instant: after a
  drain exits by exception there is a transient window the next caller
  reconciles. Not exploitable.
- `ui.md` §3.2 still says the list stays left-aligned. W84 fixed the equivalent
  contradiction in the file it owned; §3.2 was outside its ownership.

### Cosmetic

- The `AssistantPanel` extraction dropped the error paragraph's own `mt-1`.
- After a 409, continued typing fires one doomed request per drain iteration and
  flickers `saveState`. Strictly fewer doomed requests than before serialisation
  existed; suppressing writes during an open conflict is a `ui.md` §3.5 product
  decision, deliberately not made in a fix round.

### Plan defects found during execution

Five paths in `PLAN-2.5.md`'s ownership lists name files that do not exist —
`ScaleController.php` (it is `ScaleCalibrationController.php`),
`Components/Node/AppearsUnderPopover.vue` (it is under `Components/NodeModal/tabs/`),
`Pages/Templates/**` (it is `Pages/Catalog/`), `tests/js/modal/**` (it is
`tests/js/nodeModal/`), and W86's `ui.md` §10 (§10 is Admin and profile, W87's
territory; W86's content is §8). Three files were owned by two packages each,
and `Pages/Assessment/Show.vue` — where the editor's Share button actually
lived — was owned by no package at all.

None of these were guessed at. Each was escalated and ruled on.
