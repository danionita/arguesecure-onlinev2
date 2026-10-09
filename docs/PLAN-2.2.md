# ArgueSecure 2.2 — Three views, one shell

> Follow-on to [`PLAN-2.1.md`](PLAN-2.1.md), which took the product to W33.
> This plan covers **W34–W49**. The ground rules in [`CLAUDE.md`](../CLAUDE.md)
> apply unchanged: one package per session, own only your listed paths, write
> the test with the code, commit to `v2/<package-id>`.
>
> The AI assistant is **not** in this plan. It is sketched separately in
> [`PLAN-2.3-ai.md`](PLAN-2.3-ai.md), because it adds an inference runtime, a
> prompt-injection threat model and CPU-latency constraints to a codebase that
> has none of those, and none of the sixteen packages below should wait on it.

---

## Why

2.1 built a chart view, a list view and a report, and then left them arguing
about who shows what. Every number the engine produces appears in all three.
The baseline selector — one control, three states — drives figures on cards in
a view whose entire purpose is brainstorming. The toolbar has grown to two rows
and a docked totals panel, eating the vertical space the chart needs. And the
navigation above all of it is a search box that searches nothing when you are
not inside an assessment.

The organising idea of 2.2 is that **the three views have three jobs**:

| View | Job | Shows |
|---|---|---|
| **Chart** | Brainstorming, in a room, on a projector | Title, description, and whether values are set. Nothing computed. |
| **List** | Filling in the values | What a human entered, per node. No engine output except the ToS totals. |
| **Report** | Reading the answer | Everything the engine computed, in full. |

Once that line is drawn, the baseline selector has nothing left to drive on a
card and comes out of the toolbar entirely, which is what makes the top bar fit
on one row, which is what gives the chart the vertical space it needs. Items 1,
2, 3, 6 and 7 of the brief are one change, sequenced.

The second half of the plan is the shell: a collapsible left sidebar replacing
the top bar, a real dashboard behind it, and the features that only make sense
once there is somewhere to put them — labels, assignees, notifications,
assessment settings, history.

---

## Decisions (closed)

Carried forward from 2.1: D1 is **reversed** (see D8), D2–D7 stand.

| # | Decision | Rationale |
|---|---|---|
| D8 | **The baseline selector is removed from the editor.** It survives only as a report option. | It was introduced in W29 to stop two cards disagreeing. 2.2 removes the disagreement at the source: no card shows a baseline-dependent figure any more. A control that changes nothing visible is worse than no control. |
| D9 | **Zoom means type size in the list, and geometry in the chart.** In the list, zoom scales the root font size and the container keeps its width. In the chart, zoom scales the layout in both axes, and the viewport gains vertical scrolling once the content exceeds it. | 2.1's D3 said the chart "scrolls horizontally, never vertically". That held at 100%. Zooming in to read a dense branch necessarily makes the content taller than the viewport, and refusing to scroll to it makes zoom useless. Horizontal-only remains true at fit-to-width, which is the default. |
| D10 | **Report is the third position in the view switcher**, not a link out of the editor. The options panel gets an explicit close. | Chart / List / Report is the actual workflow, and the switcher is already where a user goes to change what they are looking at. The separate **Download report** button leaves the toolbar; it lives on the report itself, which is where someone who wants a file already is. |
| D11 | **Dragging a node onto a new parent offers Move or Link, never Copy.** Move re-parents the edge; Link keeps both parents and one shared node. | The schema is a DAG and a shared attack is the capability it exists for — the "appears in N places" badge already surfaces it. An independent copy is a different intent and stays where it is, in the node menu. |
| D12 | **Templates are public-read, copy-to-edit.** Saving an assessment as a template makes it world-readable; only the owner or an admin edits the original. | `security.md`: `is_public` grants read only, and the v1 behaviour where it meant world-writable is logged as a defect. World-editable templates would reintroduce it and let one user silently rewrite what everyone else is building from. |
| D13 | **An assignee must already be a collaborator on the assessment.** Assigning someone who is not offers to share it with them at `viewer` first, as one confirmed step. | Every route touching an assessment goes through `AssessmentPolicy`, without exception. A node-scoped grant around it would be a second authorization surface guarding the same data, and two authorization paths to one row is how the v1 defects happened. |
| D14 | **Currency is display-only, per assessment.** A currency code drives symbol and locale formatting; amounts are stored and computed as bare numbers. | `RiskCalculator` is pure and normative. Conversion needs a rate source, a decision about historical rates inside frozen snapshots, and a dependable free feed — the third of which does not exist. |
| D15 | **History is a timeline over `node_revisions`; reverting is performed by the snapshot restore path.** | Three mechanisms already exist and are tested: per-node revisions, the linear operation stack, and snapshots with diff-and-restore. Point-in-time replay by inverting revisions would need the revision log to be a complete event log, and a gap in it produces a silently wrong restore. Feeding the timeline's chosen moment into the tested restore keeps the dangerous half in code that already works. |
| D16 | **The sidebar collapses to an icon rail inside an assessment**, and is expanded everywhere else. Persisted per user; the rail expands on click. | Item 1 of the brief wants the full window width for the chart, and item 13 wants navigation always at hand. A 48px rail costs 4% of a 1080p width and keeps every destination one click away. |

---

## Package map

| # | Package | Brief items | Depends on | Delegable |
|---|---|---|---|---|
| W34 | The top bar: one row, real zoom, full width | 1, 2, 3, 8 | W33 | No — layout arithmetic |
| W35 | Report as the third view | 5 | W34 | **Yes** |
| W36 | The list card | 6 | W34 | **Yes** |
| W37 | The chart card | 7a–d, 7f | W34 | Partly |
| W38 | Re-parenting in the chart: Move or Link | 7e, 10 (drag half) | W37 | No — mutation semantics |
| W39 | Node modal: tabs renamed, removed, and Statistics | 9, 11 | W36 | **Yes** |
| W40 | Add an existing attack or defence | 10 (autocomplete half) | W37, W39 | Partly |
| W41 | The app shell: sidebar and theme | 4, 13 (nav) | — (parallel to W34) | No — everything hangs off it |
| W42 | Dashboard and the assessment list | 13 (search), 17, 20 | W41 | **Yes** |
| W43 | Labels | 18 | W41 | Partly |
| W44 | Assessment settings | 12 | W41 | **Yes** |
| W45 | Save as template | 14 | W41 | **Yes** |
| W46 | Assignees | 16 | W39, W41 | No — authorization |
| W47 | Notifications | 15 | W42, W46 | Partly |
| W48 | History and revert | 21 (history) | W41 | No — data safety |
| W49 | Print any view | 21 (print) | W34, W37 | **Yes** |

"Delegable" means: the package is a bounded change to files nobody else is
touching, with the design already decided here, and a cheaper agent can execute
it against this document. "No" means the package contains a decision that has to
be made while looking at the code — geometry, mutation semantics, an
authorization boundary, or a destructive path — and should be run at full
capability. See **Delegation** at the end.

---

## W34 — The top bar: one row, real zoom, full width

**Owns**
`resources/js/Components/Toolbar/**`,
`resources/js/composables/{useCanvas.ts,useEditorView.ts,useFilters.ts}`,
`resources/js/composables/useBaseline.ts` (reduced, not yet deleted),
`resources/js/Pages/Assessment/Show.vue`,
`resources/js/Components/Chart/ChartView.vue` (the scroll container only),
`docs/specs/ui.md` §5 and §3.7,
`tests/js/{composables,toolbar}/**`, `tests/e2e/15-chart-view.spec.ts`.

Four changes to the same bar, which is why they are one package.

### Full width (item 1)

`Pages/Assessment/Show.vue` renders the editor inside the layout's centred
`max-w-7xl` container. The editor is a canvas, not a document: it takes the full
viewport width, with the layout container applying only to the pages that read
as documents. Verify at 2560px that the chart actually uses it — a `max-w` that
survives on an inner wrapper produces the same letterboxing one level down.

### Zoom (item 2, D9)

`useCanvas` currently applies one CSS transform for both views. Split the
consumer, not the composable: `zoom` stays a single persisted number, and each
view decides what it means.

- **List** — CSS `zoom` on the list container. **Corrected from this plan's
  first draft**, which called for a container `font-size`: Tailwind's `text-*`
  utilities are `rem`, resolved against the root element, so a font size on the
  container would move nothing at all, and making it move would mean scaling
  `<html>` and dragging the navigation along with it. CSS `zoom` gives the
  element its own coordinate space — width stays what the container gives it,
  type grows, rows reflow, and zooming out refills the viewport instead of
  leaving a gutter. No change to `Components/Tree/**` is required, so W34 does
  not need to reach into W36's paths; the draft's "most of the diff" was wrong.
- **Chart** — the transform stays, the scroll container gains `overflow: auto`
  on both axes and a viewport-bounded height, and an outer sizer carries
  `layout × zoom` because a transform does not resize the layout box and the
  container would otherwise clip the zoomed chart. At fit-to-width it still
  does not scroll vertically, which is 2.1's D3 intact where it was true.

`ZoomControls` moves to sit immediately beside `ViewSwitcher`, and gains a
tooltip that says what zoom does in the current view, because it now does two
different things.

### The bar fits on one row (item 3)

Remove `BaselineControl` from `Toolbar.vue` and delete the component.
`useBaseline` is left in place with its consumers reduced to the cards W36
owns — W36 deletes the composable and its storage key.

**`TotalsPanel` stays until W36 — changed from this plan's first draft.** It
sits on its own row below the controls, so it costs the one-row fit nothing,
and it is the only place on screen showing the three totals. Deleting it here
would produce a commit where the product displays no totals at all, and would
force three e2e specs (06, 11, 12) to be weakened and then re-strengthened one
package later. W36 removes it in the same change that puts Base / Current /
Target ALE on the ToS card, so the figures never vanish and
`tests/e2e/support/tree.ts`'s `totalsPanel()` helper is re-pointed exactly once.

Final order, left to right, one row:
`SearchBox` · `FilterBar` · (spacer) · `ViewSwitcher` · `ZoomControls`.
`FocusBreadcrumb` stays on its own row below, because it is present only in
focus mode and is a location, not a control.

**The filters have to collapse for this to fit.** Five type chips, three status
chips and a toggle rendered inline are wider than the rest of the bar put
together and wrap it by themselves at 1280px. They move behind a single
**Filters** trigger with an active count; the *active* chips stay on the bar, so
ui.md §5's "shows what is active with a one-click clear" is still satisfied
without opening anything.

The bar must hold one row at 1280px without wrapping. Below that it may wrap.
**Assert this in Playwright, not in Vitest** — jsdom has no layout engine, so
every `offsetTop` is 0 and a three-row bar is indistinguishable from a one-row
bar. The unit spec guards the `flex-wrap` class that would permit wrapping;
`tests/e2e/16-toolbar-one-row.spec.ts` compares real bounding boxes. This is
the measurement the chart's vertical budget in W37 depends on.

### Missing values (item 8)

Rename the filter to **Missing values** — label, `aria-label`, and the
shortcuts overlay entry.

**Corrected on reading the code:** the second half of this item was already
built. `useFilters.ts`'s `hasMissingValue()` does look at the edge, and it does
so *per occurrence* — a defence rated against one attack but not another counts
as missing in exactly the one place where it is, which is more precise than the
"any edge into it" rule this plan originally called for, and it is already
pinned by a test that says why. Do not replace it. Extend the spec text to
record the behaviour instead.

Note the asymmetry, from `calculation-engine.md`: a missing impact or likelihood
*excludes* the pair from the total, a missing strength is *treated as zero
reduction*. Both are "missing" to this filter, and they are not the same
failure — the filter's job is to find work, not to model the engine.

### Tests

- Zooming the list changes computed font size and **not** container width;
  zooming out leaves no horizontal gap. Zooming the chart changes the transform
  and enables vertical scroll past the viewport height.
- The toolbar renders one row at 1280×800 (assert `offsetTop` equality across
  its children, not a screenshot).
- No baseline control and no totals panel are present.
- The missing-values filter matches a defence whose only fault is an unset
  strength on one of two edges.

---

## W35 — Report as the third view

**Owns**
`resources/js/Components/Toolbar/ViewSwitcher.vue`,
`resources/js/composables/useEditorViewMode.ts`,
`resources/js/Pages/Report/**`,
`resources/js/Pages/Assessment/Show.vue` (the report link only),
`docs/specs/ui.md` §9,
`tests/js/toolbar/**`, `tests/e2e/10-report.spec.ts`.

`ViewSwitcher` becomes three positions: **Chart · List · Report**. Chart and
List switch client-side as today; Report navigates to
`assessments.report.show`, and the report page renders the same switcher with
Report selected, so returning is symmetric.

The report page is a route, not a client view mode — do not fold it into
`useEditorViewMode`'s persisted state, or a user who last looked at a report
lands on one next time they open the assessment. Persist only `chart | list`;
the switcher takes the third position from the current route.

**Remove the Download report button from the editor toolbar** (W33 put it in
three places). It stays on the report page and on each row of the assessment
list. The `?download=1` behaviour and its forced options are unchanged — W33's
`ReportOptions::fromQuery` test is the one that matters and must stay green.

The options panel gets a **Close** button in its header, and its open/closed
state persists per user. Closing it does not reset the options; it hides the
controls. In `download=1` mode the panel is absent entirely, as W33 specified.

---

## W36 — The list card

**Owns**
`resources/js/Components/Tree/**`,
`resources/js/composables/useBaseline.ts` (deleted),
`resources/js/composables/useEditorView.ts` (the baseline key only),
`resources/js/constants/valueHints.ts`,
`docs/specs/ui.md` §3.2,
`tests/js/tree/**`, `tests/e2e/{05,12,13}`.

The list card shows **what a human entered**, plus one exception at the root.

| Type | Shows | Removed |
|---|---|---|
| root | `Base €X` · `Current €Y` · `Target ALE €Z` · completeness bar | reduction % |
| risk | `Impact: €X` (or level) | annual loss, % of annual loss bar |
| attack | `Likelihood: 0.25/yr` (or level) | annual loss |
| defence | status pill · `Reduces … by 40%` | saves, cost |

The three root figures are the only computed numbers left in the editor, and
they are what a facilitator glances at while working. Labels are **Base**,
**Current**, **Target ALE** — "base" instead of "inherent" on the card; the
report and the methodology appendix keep the formal term, and `valueHints.ts`
carries the sentence that connects them (*"Base is the inherent loss, before any
defence is applied"*). One vocabulary per surface, one place that maps them.

Everything removed here is an engine output and reappears in W39's Statistics
tab and in the report. Nothing is lost; it stops being ambient.

**`useBaseline.ts` is deleted by W37, not here — corrected on opening the
files.** `Chart/ChartCard.vue` reads the baseline too, and it belongs to W37.
W36 removes the *list's* dependence on it and asserts that moving the baseline
no longer changes anything the list card renders; W37 deletes the composable,
its spec and its `arguesecure:baseline:*` key when it strips figures from the
chart card. Leave the stale storage keys behind — they expire with the browser
and a cleanup pass is not worth a migration.

**W36 also deletes `TotalsPanel.vue`** and removes it from `Toolbar.vue` — a
deliberate reach into W34's directory, for the reason W34 recorded when it left
the panel standing: the totals had to move and be deleted in one commit, or the
product would have shipped an intermediate with no totals at all.

`?` chips keep their W29 meaning and both tooltips.

**Tests:** each type renders exactly the figures above and none of the removed
ones (assert absence explicitly — a card that silently keeps rendering `Annual
loss` is the regression this package exists to prevent); the root renders three
labelled totals matching `store.results` for all three baselines at once;
nothing in `resources/js/Components/Tree` imports `useBaseline`.

---

## W37 — The chart card

**Owns**
`resources/js/Components/Chart/**`,
`resources/js/composables/useTreeLayout.ts`,
`docs/specs/ui.md` §3.7,
`tests/js/chart/**`, `tests/e2e/15-chart-view.spec.ts`.

The chart is the simplest view: it is for agreeing what the boxes are, not what
they are worth.

### What a card shows (7a, 7b)

Title, clamped to **two lines**. Description, clamped to **three lines**. One
status affordance, and nothing else:

- **A values indicator on every card, always visible** — set or unset, two
  states, never absent. Clicking it opens the node modal **directly on that
  type's values tab** (Impact / Likelihood / Cost, per W39's renaming). This is
  the one number-adjacent affordance in the view and it is a navigation
  control, not a readout.
- **Defences also show a status icon** — implemented / todo / shelved — icon
  only, with the label in its tooltip and its `aria-label`. Colour is never the
  only channel.

Everything else comes off: contribution bars, likelihood dots, strength rings,
`CueRamp`. The "appears in N places" badge and the unresolved-comment dot stay —
both are structural, not computed.

### Geometry (7c, 7d)

Delete the left tier-label gutter. The tiers are Target → Risks → Attacks →
Defences, in fixed order, on every assessment; a permanent label for a fixed
fact costs horizontal space forever. Put the tier name in each card's
`aria-label` instead, so screen-reader users keep the information the gutter was
carrying, and state that in the commit — this is an accessibility trade, not a
free deletion.

Card height must fit two title lines and three description lines at 1080p with
the W34 top bar. Derive it: measure, do not guess, and record the arithmetic
(viewport height − chrome − four tiers − gutters) in `useTreeLayout.ts`'s
docblock so the next person changing the toolbar knows what they are spending.
If it does not fit, **stop and report** rather than clamping the description to
two lines — the budget was the reason for W34's ordering.

### The action panel moves (7f)

Today's hover panel sits over the top-right of the card, which is exactly where
the always-visible values indicator now lives.

- The hover panel moves to the **bottom edge** of the card and keeps **two**
  actions: collapse/expand, and add child.
- **Double-click anywhere on the card opens the node modal.** Single click still
  selects — in a chart, clicking a card is how you point at it while talking
  (2.1, unchanged).
- **Delete moves into the modal**, as a destructive action in the modal header,
  visible on every tab. Same `ConfirmDialog`, same wording.

Inline title editing in the chart is gone with the double-click reassignment —
titles are edited in the modal's Details tab, or in the list view. Say so in
`ui.md` §3.7; it is a real removal, not an oversight.

**Tests:** every card renders a values indicator in both states, and clicking it
opens the modal on the values tab; a defence renders a status icon with an
accessible label and no visible text; no card renders a bar, ramp or currency;
the tier gutter is absent and the tier name is in each card's `aria-label`;
double-click opens the modal, single click selects; the hover panel contains
exactly two actions; layout tests from W32 still pass with the new card height.

---

## W38 — Re-parenting in the chart: Move or Link

**Owns**
`resources/js/composables/useDragDrop.ts`,
`resources/js/Components/Chart/**` (the drag handlers only),
`resources/js/Components/UI/ConfirmDialog.vue` (if a variant is needed),
`app/Http/Controllers/LinkController.php`,
`app/Domain/Structure/LinkService.php`,
`docs/specs/ui.md` §3.7,
`tests/js/chart/**`, `tests/Feature/Links/**`, `tests/e2e/15-chart-view.spec.ts`.

`useDragDrop` exists and works in the list. Wire it into the chart, where the
drop target is a card at the tier above rather than a row gap, and add the
choice D11 settles.

Dropping an attack on a different risk, or a defence on a different attack,
opens a small dialog with two actions and a cancel:

| Action | Effect |
|---|---|
| **Move here** | Delete the source edge, create the target edge. One node, one parent. |
| **Link here** | Create the target edge, keep the source edge. One node, two parents — the card now appears in both places and the duplicate badge says so. |

Both are one operation on the undo stack with a summary naming which was chosen
(`Moved 'Phishing' to 'Fraud'` / `Linked 'Phishing' to 'Fraud'`), because
undoing the wrong one of these is otherwise indistinguishable.

Guard rails, each with a test:

- **Link to a parent it already has** is a no-op — offer only Move, or refuse
  with a message. Never write a duplicate edge; the unique index would reject it
  and the user would see a 500.
- **Cross-type drops are refused** before the dialog opens (an attack cannot
  parent a defence). The drop target must not accept the drag in the first
  place, so the cursor says no before the click lands.
- **Order on the target edge**: appended, not inherited. Ordering lives on the
  edge (`CLAUDE.md`), so a moved node has no position at its destination.
- `LinkService`'s docblock is this codebase's reference example for **not**
  mass-assigning identity columns. Do not break it — `risk_node_id`,
  `attack_node_id` are assigned as properties.

Reordering within a parent in the chart is **out of scope** and stated here
rather than discovered: the list keeps it.

---

## W39 — Node modal: tabs renamed, removed, and Statistics

**Owns**
`resources/js/Components/NodeModal/**`,
`docs/specs/ui.md` §7,
`tests/js/nodeModal/**`.

### Tabs by type (item 9)

| Type | Tabs |
|---|---|
| root | Details · Statistics · References · Comments · History |
| risk | Details · **Impact** · Statistics · References · Comments · History |
| attack | Details · **Likelihood** · Statistics · References · Comments · History |
| defence | Details · **Cost** · **Strength** · Statistics · References · Comments · History |

The Links tab disappears from risks and attacks and survives on defences,
renamed **Strength** — which is what it is: the per-edge strength editor W31
extracted, listing the attacks this defence applies to. Structure is now edited
by dragging (W38) and by the add-child affordance, not by a tab.

Nothing is deleted from the codebase: `LinksTab.vue` becomes the Strength tab,
and `LinkStrengthEditor.vue` is unchanged. The tab key stays `links` in
`useNodeModal`'s query-parameter deep link so existing links keep working — the
label changes, the identifier does not. Say why in the docblock or someone will
"tidy" it.

### Statistics (item 11)

A new read-only tab holding everything W36 took off the cards, per type, for
**all three baselines side by side** — this is where the baseline vocabulary
lives now that no control selects one.

| Type | Statistics |
|---|---|
| root | total risk per baseline; reduction inherent→current and current→target, in currency and percent; completeness |
| risk | ALE per baseline; % of total annual loss; reduction bought by each baseline |
| attack | ALE per baseline; entered vs residual likelihood per baseline |
| defence | saves per baseline; annual, one-off and amortised cost; MC; the edges it applies to and the strength on each |

Every figure comes from `store.results`, which already carries all three
baselines. **`RiskCalculator` is not touched.** If a figure appears to need an
engine change, stop and report.

Missing values print `—` with the exclusion tooltip, never `0`. Reuse
`Money.vue`, `Percent.vue` and `valueHints.ts` — the tab must not invent a
second way to phrase a number the report already phrases.

**Tests:** each type renders its tab set exactly, and the removed Links tab is
absent for risks and attacks; `?tab=links` still opens the Strength tab on a
defence; the Statistics tab shows three baseline columns and matches
`store.results` for a fixture with a shelved defence, a todo defence and an
unpriced one.

---

## W40 — Add an existing attack or defence

**Owns**
`resources/js/Components/Tree/**` and `resources/js/Components/Chart/**` (the
add-child affordance only),
`resources/js/Components/UI/Combobox.vue`,
`app/Http/Controllers/NodeController.php` (a search endpoint),
`routes/web.php` (one route),
`docs/specs/ui.md` §3.3,
`tests/js/**`, `tests/Feature/Nodes/**`.

Adding a child currently creates a new empty node. Make the title field an
autocomplete over **attacks and defences that already exist in this
assessment**, of the type being added. Choosing an existing one **links** it —
one node, a second parent, exactly D11's Link — rather than creating a
same-titled twin. Typing something new still creates a new node, on Enter,
with no extra click: the fast path must not get slower.

The suggestion list shows the title, the description snippet, and where it
already appears (*"under 2 risks"*), because linking to a node you cannot see is
the part that needs explaining.

Suggestions come from a new authorized endpoint scoped to the assessment —
`GET /assessments/{uuid}/nodes/search?type=attack&q=…`, through
`AssessmentPolicy`, `validated()` only, `type` validated against an
`Rule::in` list and never used to instantiate a class. Cap at 10 results.

**Out of scope, stated:** suggestions from the catalogue and from templates.
`catalog_entries` already has an insert path (`assessments.catalog-entries.insert`)
and merging the two suggestion sources into one field is a separate design
question, not a bolt-on.

**Tests:** typing an existing attack's title offers it, and choosing it creates
an edge rather than a node (assert node count unchanged); typing a new title
creates a node on Enter; the endpoint 403s for a non-collaborator and never
returns nodes from another assessment.

---

## W41 — The app shell: sidebar and theme

**Owns**
`resources/js/Layouts/AuthenticatedLayout.vue`,
`resources/js/Components/UI/TopNav.vue` (deleted),
`resources/js/Components/Nav/**` (new),
`resources/js/Components/UI/useTheme.ts`,
`app/Http/Middleware/HandleInertiaRequests.php` (the nav props only),
`docs/specs/ui.md` §2 "Navigation",
`tests/js/nav/**`, `tests/e2e/**` (the navigation locators).

`TopNav` carries a global search box whose placeholder says "Search this
assessment", on every page including the ones with no assessment. Delete the bar
and replace it with a collapsible left sidebar.

```
┌────────────────────┐
│ ▣ ArgueSecure      │   logo → dashboard
├────────────────────┤
│ ⌂ Dashboard        │
│ ▸ My assessments   │   expands to a list, owned by the user
│   + New assessment │
│ ▸ Shared with me   │   expands; each row names the owner
│ ▸ Templates        │
├────────────────────┤   (bottom)
│ ⚑ Notifications  ③ │
│ ◐ Theme            │   light / dark / system
│ ⚙ Admin            │   only when the user is an admin
│ ◔ Profile          │
│ ⏻ Log out          │
└────────────────────┘
```

Two states, one persisted preference plus one contextual override (D16):
expanded by default, collapsed to a **48px icon rail inside an assessment**.
Clicking the rail expands it over the content as an overlay — it must not
reflow the chart, or expanding the nav rearranges the picture someone is
pointing at.

`useTheme.ts` already exists and `app.css` already defines the tokens and the
`.dark`/`.light` classes with a `prefers-color-scheme` fallback. This package
only adds the control: a three-way light / dark / system toggle at the bottom of
the sidebar, persisted, applied before first paint. **Audit the tokens while you
are here** — the chart, report and print surfaces were built after the theme
work and may have hard-coded colours; `grep` for hex literals in
`resources/js/Components/{Chart,Report}` and fix what you find in those two
directories only, reporting anything outside them.

Accessibility is the substance of this package, not a footnote: the sidebar is a
`<nav>` with a labelled landmark, the rail's expand control is a real button
with `aria-expanded`, expanded groups are `aria-expanded` disclosures, the whole
thing is keyboard reachable in DOM order, and a skip-to-content link precedes
it. The e2e suite navigates through this chrome, so its locators change with it —
this package owns updating them.

**Tests:** every destination is reachable by keyboard; the rail auto-collapses
on an assessment route and restores elsewhere; expanding the rail does not
change the chart's width (assert the canvas `clientWidth` is unchanged); the
theme toggle sets the class on `<html>` and survives a reload; the admin item is
absent for a non-admin **and** the route still 403s — the hidden item is not the
guard.

---

## W42 — Dashboard and the assessment list

**Owns**
`resources/js/Pages/Dashboard.vue`,
`resources/js/Pages/Assessment/Index.vue`,
`app/Http/Controllers/AssessmentController.php` (index and dashboard only),
`app/Domain/Dashboard/**` (new),
`docs/specs/ui.md` §8,
`tests/Feature/Dashboard/**`, `tests/js/pages/**`, `tests/e2e/**`.

### Dashboard (item 17)

Four sections, in this order:

1. **Assigned to me** — nodes assigned to the user, with assessment, type, title
   and what is missing. Empty until W46 ships; build the section against an
   empty relation and let W46 fill it, rather than W46 rewriting this page.
2. **Mentions** — comments mentioning the user, unresolved first. Same
   arrangement with W47.
3. **My assessments** — owned, most recently updated first.
4. **Shared with me** — with the **owner's name on every row**, which is the
   thing that is missing today.

Every section is empty-state-first: a new user's dashboard must read as an
invitation, not four blank boxes. `EmptyState.vue` exists.

### Assessment list (items 13, 20)

- A **search field that filters the list** — client-side over the loaded rows,
  matching title and description. This is the search the deleted top bar was
  pretending to offer.
- Per-row actions, each **gated on the actual permission**, not just hidden:
  **Report**, **Download report**, **Share** (manager+), **Duplicate**
  (anyone who can view — it clones into the actor's ownership), **Delete**
  (owner or admin, soft delete, confirm dialog naming the assessment).

Duplicate goes through the existing `AssessmentCloner`. Read its docblock first:
it has broken twice on mass-assigned identity columns, and `nodes.root_marker`
is a generated column that must be in every `replicate()` exclusion list.

**Tests:** a viewer sees no Delete and `DELETE` 403s; a manager sees Share; the
owner's name renders on shared rows; duplicating produces an independent
assessment owned by the actor with remapped uuids; the filter narrows the list
without a request.

---

## W43 — Labels

**Owns**
`app/Models/Tag.php`, `app/Domain/Tags/**` (new),
`app/Http/Controllers/TagController.php` (new), `routes/web.php` (the tag routes),
`app/Http/Controllers/AssessmentController.php` (the tag payload only),
`resources/js/stores/assessmentStore.ts` (the tag fields only),
`resources/js/Components/NodeModal/tabs/DetailsTab.vue`,
`resources/js/Components/Tags/**` (new),
`resources/js/composables/useFilters.ts` (the `tagsForNode` injection only),
`resources/js/Pages/Assessment/Index.vue` (ToS labels only),
`docs/specs/{schema.md,ui.md}`,
`tests/Feature/Tags/**`, `tests/js/**`.

The `tags` / `node_tag` tables exist and are assessment-scoped. **No migration
is needed** — verify that before writing one. What is missing is everything
above them: `useFilters.ts` documents its own "tag gap" (the store carries no
tag data, so `tagsForNode` defaults to "no node has any tags"), and there is no
way to create a tag at all.

Four pieces:

1. **API** — attach/detach a tag on a node; create-on-first-use by name within
   the assessment, resolving to the existing tag when the slug matches. Never
   mass-assign `assessment_id` on `Tag` — it is deliberately absent from
   `$fillable`; assign it as a property.
2. **Store** — `NodeRecord.tags`, eager-loaded with the graph. The whole
   assessment must still load in four queries; a per-node tag query is exactly
   the N+1 `CLAUDE.md` forbids.
3. **UI** — a label chip row in the Details tab with an autocomplete over tags
   already used in this assessment. Chips render in the modal only; they do
   **not** go on the chart or list cards, which W36 and W37 just cleared.
   Labels on the **root node** render on the assessment list row (item 18).
4. **Filter** — close the gap by passing the real `tagsForNode`. The multi-select
   already exists in `FilterBar`; it has had nothing to filter.

**Tests:** two nodes tagged "PCI" filter to two; a tag name colliding with an
existing slug reuses the tag rather than creating a second; tags are scoped —
an assessment never sees another's; the four-query budget holds with 200 tagged
nodes.

---

## W44 — Assessment settings

**Owns**
`database/migrations/2026_*_add_assessment_settings.php` (new),
`app/Models/Assessment.php`,
`app/Http/Controllers/AssessmentSettingsController.php` (new),
`app/Http/Requests/**` (new request),
`resources/js/Pages/Assessment/Settings.vue` (new),
`resources/js/Components/UI/format.ts`,
`docs/specs/{schema.md,ui.md}`,
`tests/Feature/Assessments/SettingsTest.php`, `tests/js/**`.

One settings screen, reachable from the sidebar rail inside an assessment,
gated on `manager`. It gathers what is scattered or absent:

| Setting | Today | Notes |
|---|---|---|
| Scale calibration | its own screen (`ui.md` §8) | Link to it; do not rebuild it. |
| Amortization period | on the assessment | Move the control here. |
| Currency | none | **Display only** (D14): ISO 4217 code, drives symbol and locale in `format.ts`. No conversion, no rates. |
| Risk tolerance | none | A single annual-loss threshold. **Display only** — it renders a marker on report charts and a badge on the ToS card. It must not enter `RiskCalculator` and must not band-map results into low/medium/high, which `calculation-engine.md` forbids. |
| Title, description | root node | Editable here too; they live on the root node, not the assessment row. |

Migration adds `currency` char(3) NOT NULL default `EUR` and `risk_tolerance`
decimal NULL. Validate the currency against a fixed list in
`config/arguesecure.php` — never a free string, and never used to build a class
name or a column name.

`format.ts` takes the currency as a parameter. Do not read it from a module-level
global: the report, the CSV export and snapshot views can each be rendering a
different assessment's numbers.

**Tests:** a viewer gets 403 on the settings route; an invalid currency is
rejected; changing the currency changes the symbol in the card, the report and
the CSV; changing the risk tolerance does not change any figure in
`store.results` — the pinned form of "display only".

---

## W45 — Save as template

**Owns**
`resources/js/Components/Share/**`,
`app/Http/Controllers/TemplateController.php`,
`app/Policies/AssessmentPolicy.php` (the template ability only),
`routes/web.php` (the template routes),
`docs/specs/security.md` §templates,
`tests/Feature/Templates/**`, `tests/js/**`.

Add **Save as template** to the Share dialog, for the owner or an admin only.
It sets `is_template = 1` and `is_public = 1` in one action, behind a confirm
dialog that says plainly what happens:

> Saving as a template makes this assessment **readable by everyone with an
> account**, including its risks, attacks, defences and values. Anyone can
> create their own assessment from it. Only you can edit this template.
> You can unpublish it at any time.

D12 is the security content of this package: `is_public` grants **read only**,
and being a template does not change that. The tests are the point.

- A second user can view a template and clone it via `templates.use`.
- A second user **cannot** PATCH any node in it, cannot share it, cannot delete
  it, cannot toggle its template flag. Assert each verb separately; a single
  "cannot edit" test would pass while one route stays open.
- Unpublishing clears both flags and the second user's view 403s afterwards.
- Cloning a template clones its scales and tags and remaps every uuid
  (`AssessmentCloner` — identity columns, again).

Mutation-test the policy: neuter the template branch, confirm the write tests go
red, restore. `CLAUDE.md`: a presence assertion is not a guard.

---

## W46 — Assignees

**Owns**
`database/migrations/2026_*_create_node_assignees.php` (new),
`app/Models/**` (the relation),
`app/Http/Controllers/AssignmentController.php` (new), `routes/web.php`,
`resources/js/Components/NodeModal/tabs/AssigneesTab.vue` (new),
`resources/js/Components/NodeModal/NodeModal.vue` (the tab entry),
`app/Domain/Reporting/**` (the assignee option only),
`resources/js/Pages/Report/**` (the toggle),
`app/Domain/Dashboard/**` (the assigned-nodes section),
`docs/specs/{schema.md,security.md,ui.md}`,
`tests/Feature/Assignments/**`, `tests/js/**`.

`node_assignees`: `node_id` + `user_id`, composite PK, both FK ON DELETE
CASCADE, plus `node_assignees_user_index (user_id, node_id)` — that index is
what makes the dashboard's "assigned to me" query cheap.

An **Assignees** tab on every node type: multi-select over the assessment's
collaborators. Per D13, an assignee must already be a collaborator; picking
someone who is not offers, as one confirmed step, to share the assessment with
them at `viewer` first. Say so in the dialog — a silent share is a data
disclosure the user did not ask for.

Assignees are **not shown on chart or list cards** (item 16, and consistent with
W36/W37). They appear in the modal, on the dashboard, and in the report behind a
**Show assignees** report option, default off.

Authorization is the whole risk here and it is deliberately small: assigning is
`update` on the assessment, and an assignee gains **nothing** beyond what their
collaborator role already grants. There is no node-scoped grant, no second
policy path.

**Tests:** assigning a non-collaborator without accepting the share is refused;
accepting it creates the `assessment_user` row at `viewer` and nothing more; a
`viewer` assignee still cannot PATCH the node; removing a collaborator removes
or orphans their assignments deliberately (decide, document, test); the report
option toggles the column.

---

## W47 — Notifications

**Owns**
`database/migrations/2026_*_create_notifications.php` (or Laravel's own table),
`app/Domain/Notifications/**` (new),
`app/Http/Controllers/NotificationController.php` (new), `routes/web.php`,
`app/Domain/Comments/**` (the mention parser),
`resources/js/Components/Nav/NotificationPanel.vue` (new),
`resources/js/Components/Nav/**` (the badge),
`docs/specs/{schema.md,ui.md}`,
`tests/Feature/Notifications/**`, `tests/js/**`.

Two sources, one feed: **you were mentioned in a comment**, and **a node was
assigned to you**. Use Laravel's `notifications` table rather than inventing
one — it is a framework table with a tested API and nothing here needs a custom
shape.

Mentions are parsed from comment bodies on write, in `CommentService`, matched
against **collaborators on that assessment only**. Matching against all users
would let anyone probe for the existence of an account by typing `@` — that is
the security content of this package and it needs its own test.

The panel opens from the sidebar's Notifications item, lists newest first with a
per-item link to the node (the modal's existing `?node=` deep link), and marks
read on open. The badge count is unread only.

In-app only. **No email** — that needs a mail configuration, a digest policy and
an unsubscribe path, and is out of scope, stated here rather than discovered.

Real-time delivery over the existing Reverb connection is optional; polling on
navigation is acceptable for a first cut and is what the badge should do if the
socket work looks like it will grow the package.

**Tests:** `@dana` in a comment notifies Dana when Dana is a collaborator and
does **not** when she is not; the same mention twice on an edit does not
double-notify; the feed 404s another user's notification; the badge clears on
open.

---

## W48 — History and revert

**Owns**
`app/Domain/History/**`,
`app/Http/Controllers/AssessmentHistoryController.php` (new), `routes/web.php`,
`resources/js/Pages/Assessment/History.vue` (new),
`resources/js/Components/History/**`,
`docs/specs/{schema.md,ui.md}`,
`tests/Feature/History/**`, `tests/js/**`, `tests/e2e/**`.

Per D15: a **timeline**, and a **revert that goes through snapshots**.

The timeline is an assessment-wide feed over `node_revisions`, grouped by
`operation_id` so one user action reads as one entry (*"Dana added 3 defences
under 'Phishing'"*), newest first, paginated, filterable by user, by action, and
by node. Every column it needs already exists, including `node_uuid`
denormalized so history survives a purge. This half is a read model and a page;
it is genuinely straightforward.

**Revert is not.** "Revert to here" does not invert revisions. It:

1. takes an automatic snapshot of the current state, named
   *"Before revert to {timestamp}"* — non-negotiable, and taken **first**;
2. reconstructs the graph as of the chosen moment;
3. applies it through the existing snapshot restore path;
4. records the whole thing as one operation on the undo stack.

Step 2 is where the risk lives. The revision log covers node fields and link
actions; it does **not** cover scale recalibration, and it may not cover
everything else. **Audit what `node_revisions` actually records before writing
step 2, and if any mutation reaches the graph without writing a revision, stop
and report** — a reconstruction with a gap in it produces a silently wrong
assessment, which is worse than no revert at all. If the audit finds gaps, the
honest fallback is to offer revert only back to the nearest snapshot, and say
so in the UI.

Reverting requires `manager`. It is confirmed with a dialog naming the target
moment and the automatic snapshot.

**Tests:** the timeline groups by operation and pages correctly; a revert takes
the pre-revert snapshot before touching anything; reverting then restoring the
pre-revert snapshot returns the graph byte-identical (the round-trip is the test
that matters); a `viewer` gets 403; a revert past a scale recalibration behaves
as the audit decided, with the decision written in the test's docblock.

---

## W49 — Print any view

**Owns**
`resources/css/print.css`,
`resources/js/Components/{Chart,Tree}/**` (print classes only),
`resources/js/Layouts/AuthenticatedLayout.vue` (print classes only),
`docs/specs/ui.md` §9 "Print",
`tests/e2e/**`.

`print.css` was written for the report. Extend it so the chart and list views
print as what is on screen, minus the chrome:

- **List** — sidebar, toolbar and hover affordances hidden; `break-inside:
  avoid` per risk block; the tree indentation preserved; the current zoom
  ignored in favour of a fixed print type size.
- **Chart** — the transform reset to fit the page width, connectors preserved
  (they are SVG, so they scale), and landscape suggested via `@page`. A chart
  wider than one page tiles across pages horizontally; that is acceptable and
  should be stated, not silently produced.
- Both — the assessment title, the date and the current filters in a running
  header, so a printed page says what it is a picture of.

D6 stands: this is the browser print pipeline, not a renderer. No new
dependency.

**Tests:** Playwright with `page.emulateMedia({ media: 'print' })` — the
sidebar and toolbar are hidden in all three views, a risk block is not split,
and the running header carries the title. Verify A4 and Letter by hand and say
in the report which browsers were checked.

---

## Order and parallelism

Two chains, independent of each other until W46:

```
W34 ─┬─ W35
     ├─ W36 ── W39 ─┬─ W40
     └─ W37 ─┬─ W38 ─┘
             └─ W49

W41 ─┬─ W42 ─┐
     ├─ W43  ├─ W47
     ├─ W44  │
     ├─ W45  │
     └───────┴─ W46 ── (W42's assigned section fills in)
```

W34 and W41 can run at the same time by two agents — W34 owns the toolbar and
the editor page, W41 owns the layout and the nav, and they meet only at the
`max-w` container, which W34 owns. Everything downstream of each is sequential
within its chain.

`docs/specs/ui.md` is edited by twelve of the sixteen packages, in different
sections. That is the same situation 2.1 handled by running sequentially within
a chain; keep doing that, and each package edits only its own sections.

---

## Delegation

The brief asked for a plan cheaper agents can execute. That works where the
design is already decided and the file ownership is disjoint — which is why the
package boundaries above follow file ownership rather than feature grouping.

**Safe to delegate wholesale** (W35, W36, W39, W42, W44, W45, W49): the change
is bounded, the target state is written out above in full, and the tests are
enumerated. Hand the agent `CLAUDE.md`, this document, and the spec sections the
package names.

**Delegate with a review gate** (W37, W40, W43, W47): these have one judgement
call each — the chart's height budget, merging two suggestion sources, the
four-query eager-load, the mention-matching scope. Let the agent build, and read
that specific decision before the package is called done.

**Do not delegate** (W34, W38, W41, W46, W48): each contains something that
cannot be judged from a document — layout arithmetic against a real viewport,
mutation semantics on a DAG, the shell everything else hangs off, an
authorization boundary, and a destructive data path. W48 in particular has a
stop-and-report condition in the middle of it.

**Never fan out sub-agents inside a package.** The 2.1 rule stands and the
reason is unchanged: these are coherent designs that fragment badly. `Explore`
is free to use anywhere.

## Session prompt template

> Read `CLAUDE.md`, `docs/PLAN.md`, `docs/PLAN-2.1.md` and `docs/PLAN-2.2.md`,
> plus the spec sections named in the package. Implement work package **W34**.
> Own only the paths listed for W34. Follow the ground rules. Commit to branch
> `v2/w34-top-bar` when the full check is green.
