# ArgueSecure 2.5 — The second workshop pass

> Follow-on to [`PLAN.md`](PLAN.md), [`PLAN-2.1.md`](PLAN-2.1.md),
> [`PLAN-2.2.md`](PLAN-2.2.md), [`PLAN-2.3-ai.md`](PLAN-2.3-ai.md) and
> [`PLAN-2.4.md`](PLAN-2.4.md), which took the rewrite to 2.4.0 (W1–W81).
> This plan covers **W82–W88**.
>
> The ground rules in [`CLAUDE.md`](../CLAUDE.md) apply unchanged: one package
> per session, own only your listed paths, write the test with the code,
> commit to `v2/<package-id>`, never to `master`.

---

## Why

2.4 was the first plan written from someone using the thing. This is the
second, and the difference between them is the point: 2.4's items were mostly
*one surface at a time* — this card, that tab, that button. 2.5's are mostly
about **the same thing existing in three places and being different in each**.

Three views — chart, list, report — and the report is not really one of them.
It loses the sidebar, has no search, has no zoom, and puts its options in a
panel the other two do not have. Meanwhile History and Assessment settings sit
in the *application* sidebar, one level above the assessment they belong to,
while Share sits in the assessment's own action bar. The assessment list offers
Report and Download; the editor offers Export. None of this is a bug in any one
file. It is the absence of one shared answer to "what can I do with this
assessment", and six items here are that absence said six ways.

The rest divide into three groups. **Density and geometry** (items 11–16): the
list is too tight, the chart and list do not centre, zoom is one setting shared
across two views that need different ones. **The scales** (item 10), which are
edited on a page of their own, ask for a colour and a code that nothing shows
the user, and warn about a default in a tone that suggests something is wrong.
And **four things that do not work** (items 19–23): the assistant is invisible
in the view most people use, a node with several parents cannot have its title
edited, and the Details tab still blanks itself.

Two items look cosmetic and are not. Item 8 and item 15 are dialogs with no
padding — which is what an unstyled container looks like when a wrapper was
assumed and never applied.

### What this plan does not touch

Nothing here changes the calculation, the DAG, `AssessmentPolicy`, or any
closed decision from D1–D38. In particular **item 10's "remove code" does not
mean what it sounds like** — see D44. A literal reading of it breaks the
catalogue and the engine's published payload, and that is the single most
dangerous line in this document.

---

## Decisions (closed)

| # | Decision | Rationale |
|---|---|---|
| D39 | **The report keeps the sidebar for an authenticated user and renders bare for a share-link guest.** One page, two chromes, chosen on whether `auth()->user()` exists. | `Pages/Report/Show.vue` is deliberately not wrapped in `AuthenticatedLayout` because the report's audience includes anonymous guests and that layout requires a `{name,email}` user. "Just wrap it" is the obvious fix and it breaks every share link. The sidebar is a signed-in affordance; a guest has nowhere to navigate to. |
| D40 | **One component, `AssessmentActions`, holds Settings · History · Share · Export · Print**, and all three views render it. | Five actions currently exist in four arrangements across three views and a list. The fix for "they should be the same" is one component, not three edits that agree by inspection — which is exactly the failure W81 recorded when the report's toolbar drifted 6px from the editor's before it shipped. |
| D41 | **History and Assessment settings leave the sidebar** and join the action bar. | The sidebar is application-scoped: dashboard, assessments, templates, admin, profile. History and settings are scoped to *one* assessment and are meaningless without one — the sidebar renders them conditionally on `currentAssessment` today, which is the tell. |
| D42 | **Print prints the current view.** Chart, list and report each become printable, through `print.css` and per-view print rules — not a per-view print route. | The report is server-assembled and its print pipeline is D6, unchanged. Chart and list are already rendered; printing them is a stylesheet problem, and a second server-rendered artefact for each would be a second thing to keep true. |
| D43 | **Zoom is persisted per view.** The storage key gains the view mode. | `useCanvas.ts` keys zoom on `(assessmentUuid, userId)` and both views read it, so setting the chart to 70% to see the whole graph sets the list to 70% too. They are different jobs at different sizes. |
| D44 | **`scale_levels.code` becomes fully internal.** It stays in the database, in the catalogue's resolution path and in the engine's payload; it is derived from the name on create, frozen on rename, and **shown to the user nowhere** — including the exported methodology table, which prints it as a column today. | An id cannot do this job: a custom scale is *cloned per assessment* (`AssessmentCloner::cloneScaleIfCustom`), so its levels get fresh ids that do not exist until the clone happens, and a catalogue fixture shipped once cannot name them. `code` is the same string in every calibration, which is exactly what a fixture needs. It is the unique key `(scale_id, code)`, it is `impact_level_code`/`aro_level_code` in `calculation-engine.md` §8.3's normative payload, and `AssessmentCloner::resolveLevelDefault` looks levels up by it. Dropping it detaches every catalogue entry from every calibration; re-deriving it on rename does the same thing quietly. |
| D45 | **`scale_levels.color` stops being editable; the column stays with its `#9CA3AF` default.** | Audited: outside `ScaleCalibrator` and the calibration screen itself, nothing reads it. The tag and avatar colours found by a naive grep are a different column on a different table. The column stays because removing it buys nothing and touches snapshot restore. |
| D46 | **Scale editing moves into Application settings and `Pages/Scales/Calibrate.vue` is deleted**, along with its route — not left reachable. | A screen that still answers its URL after its entry points are removed is how a second, divergent editor survives a consolidation. |
| D47 | **Admin gets a `Defaults` tab** — default impact, likelihood and strength bands, plus currency — separate from Instance settings. | Instance settings is about the deployment (registration, assistant, retention). Defaults are about the *content* a new assessment starts with. D36 made `default_scale_calibration` a real control by pointing it at seeded system scales; this is the same idea taken to the bands themselves, and it wants the same editor the assessment gets, not a different one. |
| D48 | **Each of the three assistant features gets its own admin switch**, and every one is subordinate to `Availability`. A switch cannot make a feature work where the runtime is absent or unreachable. | D25's three conditions are unchanged; this adds a fourth that can only ever subtract. Help costs a corpus read, suggestions cost ~30s of CPU, a summary costs ~250s — an admin may reasonably want the cheap one and not the expensive one. |
| D49 | **Items 21 and 22 are reproduce-first**, like D38 before them. Do not fix from the hypothesis below without first making the failure happen. | The leading hypothesis is recorded in W88 because it is specific and testable, not because it is established. A fix written to a plausible story, against a defect that reproduces only sometimes, is indistinguishable from a fix that did nothing. |
| D50 | **The assistant's suggest action belongs on the node card in every view that has cards**, not on the tree card alone. | `ChartCard.vue` contains the string `assistant` zero times. The chart is the default view. "The AI suggestions are not working" is, in the first instance, a control that was built once and mounted once. |
| D51 | **The list gets both: more spacing, and a more zoomed-in start.** Row spacing and indent grow, *and* the list's baseline scale rises so it opens larger — via a per-view `CANVAS_BASE_SCALE`, never by moving `CANVAS_ZOOM_DEFAULT`. | These are two different complaints that look like one. Spacing fixes density — rows too close together — and no amount of zoom fixes that, because zoom scales the gaps with the text. The baseline fixes *starting size*. D28 already established the mechanism and the reason: moving the default to 1.1 makes the control read **110%** on first load, telling a user who never touched zoom that they are zoomed in, and it silently changes the meaning of every stored preference. Moving the baseline changes neither. D43 makes the baseline per-view, so the list can start larger than the chart. |

---

## The twenty-three items, and where each lands

| # | Item | Package |
|---|---|---|
| 2 | History and settings leave the sidebar for the action bar | **W82** |
| 6 | All three views carry Settings · History · Share · Export · Print | **W82** |
| 17 | Collapsed sidebar items show a tooltip on hover | **W82** |
| 18 | A Help item at the bottom of the sidebar, above Notifications | **W82** |
| 3 | The report keeps the sidebar | **W83** |
| 4 | The report gets a search bar | **W83** |
| 5 | Report options on one row, always visible, beside the search | **W83** |
| 13 | The report gets zoom | **W83** |
| 11 | The list centres in its viewport | **W84** |
| 12 | The chart centres when narrower than the page | **W84** |
| 14 | Zoom is remembered per view | **W84** |
| 16 | The list breathes: more row spacing, larger indent, opens larger | **W84** |
| 1 | The occurrence tag becomes an icon and a number | **W85** |
| 19 | The assistant's suggest action reaches the chart card | **W85** |
| 7 | The assessment list: no Report, Export not Download, add Settings | **W86** |
| 8 | The create-assessment dialog has padding | **W86** |
| 9 | Assessment settings: a way back, and no duplicate title/description | **W86** |
| 15 | "Use this template" has padding | **W86** |
| 10 | Scales: relocated, simplified, resettable, and defaulted from admin | **W87** |
| 20 | An admin switch per assistant feature | **W87** |
| 21 | Editing a multi-parent node's title fights itself | **W88** |
| 22 | The Details tab blanks after a second | **W88** |
| 23 | A new node offers "AI suggestion" before you type | **W88** |

---

## W82 — One action bar, and what the sidebar is for

**Depends on** W81. **Owns**
`resources/js/Components/Chrome/**`,
`resources/js/Components/Nav/**`,
`resources/js/Components/Toolbar/AssessmentActions.vue` (new),
`resources/js/Layouts/AuthenticatedLayout.vue`,
`resources/css/print.css`,
`docs/specs/ui.md` §2 and §3.1,
`tests/js/{nav,chrome,toolbar}/**`,
`tests/e2e/16-toolbar-one-row.spec.ts`.

### The action bar (items 2 and 6, D40, D41, D42)

Build `AssessmentActions.vue`: **Settings · History · Share · Export · Print**,
in that order, in the chrome's `anchor` region. It takes the assessment, the
abilities, and the current view mode; it renders nothing a viewer cannot do.

- **Settings** and **History** are the two arriving from the sidebar. Remove
  them from `Sidebar.vue` in the same commit — an affordance may not leave one
  surface in one commit and arrive on another in the next (W36's rule, which
  W72 broke and integration had to close by hand).
- **Share** and **Export** move as they are. `ExportMenu` is W74's; it keeps
  its five formats.
- **Print** prints the current view (D42). In the editor this is new: add the
  chart and list print rules to `print.css`, which today only dresses the
  report. Hide the sidebar, the chrome and every action; print the canvas.

W81 put the geometry in `Components/Chrome/*` precisely so a change like this
lands once. Render `AssessmentActions` from there, not from each page.

### The sidebar's remaining job (items 17 and 18)

- **Tooltips when compact.** `Sidebar.vue` already computes `compact` and
  already sets `aria-label` on the logo and toggle when it is true. The nav
  items get the visible half of the same thing. Use the existing `Tooltip`
  component; do not build a second one.
- **A Help item**, at the bottom, immediately above Notifications, pointing at
  `help.index`. The help pages have existed since W51 and are reachable only by
  typing the URL.

### Acceptance

- One component test asserting all five actions render in one place, and a
  second asserting a viewer sees the subset their abilities allow.
- Mutation-test the ability gating: neuter it, confirm the viewer test goes
  red, restore.
- `Sidebar.spec.ts` asserts History and Assessment settings are **absent**, by
  route name — a presence assertion inverted is still a presence assertion, so
  assert the specific routes are not rendered rather than that the item count
  fell.
- A print test per view. Asserting "the sidebar has `no-print`" is the trap
  W68 wrote up: assert the specified rule, not that a class is somewhere.

---

## W83 — The report becomes the third view, not a different page

**Depends on** W82. **Owns**
`resources/js/Pages/Report/Show.vue`,
`resources/js/Components/Report/**`,
`app/Http/Controllers/ReportController.php`,
`docs/specs/ui.md` §9,
`tests/js/report/**`,
`tests/e2e/10-report.spec.ts`.

### The sidebar, conditionally (item 3, D39)

**Read the header docblock of `Pages/Report/Show.vue` before touching it.** The
report is not wrapped in `AuthenticatedLayout` for a reason that is still true:
its audience includes anonymous share-link guests, and that layout requires a
`{name,email}` user.

So: sidebar when `auth()->user()` exists, bare page when it does not. The
controller already knows which. Both paths need a test, and the guest path is
the one that will be forgotten — it is also the one whose regression is
invisible until a share link is opened by someone outside the organisation.

### Search, options, zoom (items 4, 5, 13)

- **Search** matching the other two views' behaviour and component.
- **The options panel becomes a row**: every option on one line, always
  visible, immediately beside the search. Changing one still re-requests the
  page with a new query string — the report is server-computed and nothing
  here may re-derive an engine figure client-side.
- **Zoom**, per D43's per-view key, applied to the report body only. The
  chrome does not scale, and neither does the print output.

### Acceptance

- Guest and authenticated renders both asserted, by the presence and absence of
  the sidebar landmark.
- An assertion that no report figure is computed in the client — the existing
  architecture test's spirit, applied to the new search.
- Zoom persists under a key that contains the view mode, and does **not**
  disturb the chart's or list's stored value.

---

## W84 — Density, centring, and one zoom per view

**Depends on** W82. **Owns**
`resources/js/composables/{useCanvas,useEditorView,useEditorViewMode,useTreeLayout}.ts`,
`resources/js/Components/List/**`,
`resources/js/Components/Chart/ChartView.vue`,
`resources/js/Components/Toolbar/ZoomControls.vue`,
`docs/specs/ui.md` §5 and §6,
`tests/js/{composables,list,chart}/**`,
`tests/e2e/{15,16,18}`.

### Per-view zoom (item 14, D43)

`useCanvas.ts`'s `storageKey(assessmentUuid, userId)` gains the view mode. A
value stored under the old key is read once, as the seed for both views, and
then each diverges. Do not silently discard it — a user who set 70% last week
should not find 100% after an upgrade.

### Centring (items 11 and 12)

The list centres in its viewport. The chart centres **when it is narrower than
the page** and left-aligns when it is not, because a graph wider than the
viewport that starts centred has its root off-screen.

### The list breathes, and opens larger (item 16, D51)

Two changes, and they are not substitutes for each other. **More space between
rows and a larger indent per level** — zoom cannot fix density, because it
scales the gaps along with the text. **And a higher baseline scale for the
list**, so it opens more zoomed in than it does today.

The baseline is `CANVAS_BASE_SCALE`, which D28 introduced for exactly this and
which D43 now makes per-view. **Do not raise `CANVAS_ZOOM_DEFAULT` instead.**
W69 wrote down why: the control would read 110% on first load, telling a user
who has never touched zoom that they are zoomed in, and every stored preference
would quietly change meaning. Read that docblock before editing the constants.

`CANVAS_BASE_SCALE` multiplies into both `canvasStyle` and `listStyle`, and
`ChartView.vue`'s `scaledSize` and `fitToWidth` convert between layout units
and painted pixels — those three move together or the chart's fit-to-width
lies. The e2e assertion W81 rewrote measures in the list's own coordinate space
because CSS `zoom` scales a capped element's rendered width; keep it that way.

### Acceptance

- A test that the chart's stored zoom and the list's stored zoom are
  independent, and one that the pre-upgrade key seeds both.
- Centring asserted at two viewport widths, above and below the content width.
- Row spacing asserted as a specified value, not as "greater than zero".

---

## W85 — The node card: one tag, and an assistant that is present

**Depends on** W81. **Owns**
`resources/js/Components/Chart/ChartCard.vue`,
`resources/js/Components/Tree/NodeCard.vue`,
`resources/js/Components/Node/AppearsUnderPopover.vue`,
`tests/js/{chart,tree}/**`,
`tests/e2e/07-duplicate-highlighting.spec.ts`.

### The occurrence tag (item 1)

"Appears under 2" becomes the same compact icon-and-number chip a defence
already uses. Same component, same size, same position on both card types —
integration wired the popover onto both cards in 2.4 and the two are already
meant to be the same control.

### The assistant reaches the chart (item 19, D50)

`ChartCard.vue` has no assistant trigger. `NodeCard.vue` has one, gated on
`showAddChild && abilities.update && store.assistantAvailable`, and `store` is
already populated for both views. Mount the same trigger on the chart card,
with the same three gates and the same `data-testid`.

**This is the whole of item 19 as far as this package is concerned.** Item 23 —
suggestions offered when a *new* node opens — is the modal's, and is W88's.

### Acceptance

- A chart-card test asserting the trigger appears with all three gates true and
  is absent when each one is false in turn. Three separate assertions: a single
  "renders when available" test passes with two of the gates deleted.

---

## W86 — The assessment list, the dialogs, and the settings page

**Depends on** W81. **Owns**
`resources/js/Pages/Assessment/{Index,Shared}.vue`,
`resources/js/Pages/Assessment/Settings/**`,
`resources/js/Pages/Templates/**`,
`resources/js/Components/Assessment/**`,
`app/Http/Controllers/AssessmentSettingsController.php`,
`docs/specs/ui.md` §10,
`tests/js/assessment/**`,
`tests/e2e/{18,19}`.

### The list's actions (item 7)

Remove **Report**. Replace **Download** with **Export**, opening W74's
`ExportMenu` — the same menu, not a second one with the same name. Add
**Settings**. The list's row actions and W82's action bar should offer the same
verbs for the same object.

### Two dialogs with no padding (items 8 and 15)

The create-assessment dialog and "Use this template". Both are almost certainly
a dialog body rendered without the wrapper its siblings use; find the wrapper
before adding padding to two call sites.

### The settings page (item 9)

- A **"Return to assessment"** link, top right.
- **Remove title and description.** They live on the root node and are edited
  there; two editors for one field is how they diverge. Check what the settings
  form currently does with them on submit before deleting the inputs — if it
  writes them, that write must go too, and if `RevisionRecorder` sees it, the
  history page has been recording an edit from a second place.

### Acceptance

- The list offers exactly Settings · Share · Export, asserted by name.
- A test that the settings form no longer accepts `title` or `description` —
  a removed input is not a removed endpoint, and `UpdateSettingsRequest` is
  where the guarantee lives.

---

## W87 — Scales, reduced and relocated; and the assistant's three switches

**Depends on** W81. **Owns**
`resources/js/Pages/Scales/**` (deleted),
`resources/js/Pages/Admin/**`,
`resources/js/Components/Scales/**`,
`app/Http/Controllers/ScaleController.php`,
`app/Http/Controllers/Admin/**`,
`app/Http/Requests/Admin/**`,
`app/Domain/Scales/**`,
`app/Domain/Accounts/InstanceSettings.php`,
`database/migrations/` (new only),
`docs/specs/{schema,ui}.md` (§3 scales, §8),
`tests/{Feature/Scales,Feature/Admin,js/scales}/**`.

**This is the largest package in the plan and the one with a trap in it.**

### Read D44 before writing a line

**`code` disappears from the interface and stays in the machinery.** Those are
different things and this package must do both.

Gone from the interface: no input on any scale editor, and **remove the code
column from the exported methodology table** — `ReportSectionBuilder::methodology()`
prints it today, which is the one place a user actually sees it.
`CoverSection.vue` uses it as a Vue `:key`, which is not a display and can
stay.

Kept in the machinery, because an id cannot replace it. A custom scale is
cloned per assessment — `AssessmentCloner::cloneScaleIfCustom()` builds fresh
`ScaleLevel` rows with fresh ids — so the id of "major" differs in every
assessment and does not exist until the clone runs. A catalogue fixture is
written once and must resolve in all of them, which is why
`catalog-iso27001-27002.json` says `"impact_level_code": "major"` and why
`AssessmentCloner::resolveLevelDefault()` looks up by code within a scale.

So "use name as code" means: **stop asking for it, derive it on create, and
freeze it on rename.** Deriving it again on rename would silently detach every
catalogue entry that referenced the old value — the same class of failure as
W78's silent half, and it would pass every test that only checks the level
still renders. Handle collisions against the unique index explicitly.

If you conclude the spec requires something else here, **stop and report**.

### The rest of item 10

- **Relocate.** Scale editing moves into Application settings.
  `Pages/Scales/Calibrate.vue` and its route are deleted (D46).
- **Simplify.** No colour input (D45 — the column stays, the input goes). No
  code input. Remove the calculator at the foot of the Impact, Likelihood and
  Strength cards.
- **Reset.** A "Reset to default" button in each of the three cards under
  Assessment settings, resetting to the admin defaults of D47.
- **Remove the "Still using the system defaults" warning.** Using the defaults
  is the normal state; a warning says something is wrong.

### Admin defaults (D47) and the assistant's switches (item 20, D48)

A new **Defaults** tab: impact, likelihood and strength bands, plus currency,
using the same band editor the assessment uses. Separate from Instance
settings.

Three assistant switches — help, suggestions, summary — each subordinate to
`Availability`. `UpdateSettingsRequest` validates `assistant_enabled` as
`declined` when `config('assistant.enabled')` is false; the three new flags need
the same treatment, and every call site asks `Availability`, never a flag alone.

### Acceptance

- A test that renaming a level does **not** change its code, and that a
  catalogue entry still resolves afterwards. This is the package's most
  important test.
- A test that no rendered surface and no export column contains a level code.
- Collision behaviour asserted against the unique index.
- Each of the three assistant switches asserted independently, including that
  none of them can enable a feature when the deployment is not configured.
- Identity columns: `scale_id` on `ScaleLevel` is deliberately absent from
  `$fillable`. Assign it as a property. This has broken three packages already.

---

## W88 — The modal: two defects, reproduced first, and a suggestion before typing

**Depends on** W85. **Owns**
`resources/js/Components/NodeModal/**`,
`resources/js/stores/assessmentStore.ts`,
`resources/js/Components/Tree/useNodeSuggestions.ts`,
`docs/specs/ui.md` §7,
`tests/js/{modal,stores}/**`,
`tests/e2e/{03,08}`.

### Reproduce first (D49)

Items 21 and 22 are the two that "sometimes" happen. Write the failing test
before the fix. If it does not fail, you have not found the defect yet — say
so and stop rather than changing code until the symptom stops being reported.

**The leading hypothesis, which is specific and may still be wrong:**

`DetailsTab.vue`'s title field commits on **`@input`** — `onTitleInput()` calls
`commitTitle()` on every keystroke. `assessmentStore.updateNode()` reads
`baseUpdatedAt = node.updated_at` when it is called, sends it, and on success
does `Object.assign(node, response.data.node)`, which bumps `updated_at`.

Two keystrokes close together therefore overlap: the second PATCH is built from
the node *before* the first response lands, carries a stale `updated_at`, and
the server answers 409. The store turns that into `saveState = 'conflict'` and
the "Someone changed this while you were editing" banner. The someone is you,
one keystroke ago.

That predicts both symptoms. It predicts item 21 — a fast typist on any node,
more visible on one that renders several cards. And it predicts item 22, if a
409's `theirs` or a late response is assigned over the node while the tab is
open, replacing the fields the tab is bound to about a second after it opened.

If it holds, the fix is a per-node in-flight guard and a debounce, not a wider
conflict window: serialise writes to one node so a request's own echo can never
be mistaken for a third party's edit. `RevisionRecorder`'s 120-second
coalescing window (schema.md §3.9) is what makes per-keystroke saving cheap in
the *database*; nothing makes it cheap over the wire.

Whatever the cause turns out to be, the conflict banner must still work for a
real second editor. That is `ui.md` §3.5 and it has an e2e test.

### "AI suggestion" on a new node (item 23)

When a newly created node opens, offer an **"AI suggestion"** button before the
user types — do not auto-generate. It costs ~30s of CPU per press and D24's
accept step means nothing generated reaches the graph unseen.

This sits beside W79's existing "link, don't duplicate" list, which is a
different feature with a different source: that one searches *existing* nodes
on `modal.placeholderPath`, this one asks the model. Both are offers on an
empty title and they must not be confused in the code or in the UI. Read
`DetailsTab.vue`'s W79 block before adding to it.

### Acceptance

- A test that reproduces the overlapping-write failure and goes green with the
  fix — and a mutation test: remove the guard, confirm red.
- A test that a genuine second editor still raises the conflict banner. A fix
  that suppresses 409s entirely would pass the first test and break this one.
- The suggestion button is not rendered where the assistant is unavailable, and
  nothing it returns reaches the graph without an accept.

---

## Sequencing

W82 first: W83 and W84 both consume its chrome, and W86's list actions should
offer the same verbs its action bar does.

```
W82 ─┬─ W83
     ├─ W84
     └─ W86

W85 ─── W88

W87 (independent)
```

W85, W87 and W82 can start together. W88 depends on W85 only because both touch
the assistant's trigger and the second should see the first.

---

## Still to decide by measurement, not by argument

- Whether item 22 is the same defect as item 21 or a second one. The hypothesis
  says one; the hypothesis is not evidence.
- Whether the report's zoom should scale its typography or its page width.
  Decide it against a printed page, not a screenshot.
