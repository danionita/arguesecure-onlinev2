# ArgueSecure 2.1 — Legibility and the brainstorming view

> Follow-on to [`PLAN.md`](PLAN.md), which took the rewrite to 2.0.1 (W1–W28).
> This plan covers **W29–W33**. The ground rules in [`CLAUDE.md`](../CLAUDE.md)
> apply unchanged: one package per session, own only your listed paths, write
> the test with the code, commit to `v2/<package-id>`.

---

## Why

2.0 shipped every number the engine can produce, on every card, all the time.
That is faithful to `ui.md` §1.4 ("the numbers are always on screen") and it is
also unreadable: a risk row carries three unlabelled figures, a defence row
five, and nothing on screen says which of them the engine computed and which a
human typed. The same instinct put five action icons on every row, two of which
open the same modal and one of which does nothing.

Meanwhile the product's actual pitch — *the fastest way to get a room full of
people to agree on a threat model* — is served by a view that does not exist.
The indented list is a reporting artefact. A workshop needs a picture.

Five changes, in the order they should be built:

| | Change | Package |
|---|---|---|
| 1 | Fewer, labelled, explained values; one baseline at a time | **W29** |
| 2 | Fewer icons; notes removed | **W29**, **W30** |
| 3 | Edit per-link strength from the defence's own Links tab | **W31** |
| 4 | A new default org-chart view for brainstorming | **W32** |
| 5 | A report generated from the list view, with programme summaries, and a one-click **Download report** giving everything expanded in both states | **W33** |

---

## Decisions (closed)

| # | Decision | Rationale |
|---|---|---|
| D1 | The baseline selector is **one control in the toolbar** — Inherent / Current / Target — driving every card and the totals panel together. Not per card. | Two cards showing different baselines side by side is the confusion this plan exists to remove. Persisted per user per assessment, exactly like zoom and collapse state. |
| D2 | **`notes` is removed everywhere**, including the column. | It duplicated `description`, was never surfaced except as an indicator dot, and every consumer (search, report, JSON export, snapshots, catalogue) pays for it. Existing note content is destroyed; W30 says so out loud in its migration docblock. |
| D3 | The new view is an **org chart: four stacked tiers**, Target → Risks → Attacks → Defences, siblings side by side, connectors drawn downward. It scrolls horizontally, never vertically. | The depth is fixed at 4 by the schema. That is the one structural guarantee this product has, and it is what makes a fits-on-screen layout possible at all. |
| D4 | The org chart is the **default** view; the indented list stays, reachable from a view switcher, and remains what reports are built from. | The list is better for reading and for paper. The chart is better for a room. |
| D5 | A shared attack or defence is **drawn once per occurrence**, not once with several incoming edges. | Matches the list view and the existing duplicate-highlighting affordance, and keeps the layout a plain tiered tree rather than a general DAG layout problem. |
| D6 | **PDF stays the browser print pipeline.** | Settled in `PLAN.md` §"On PDF" and not reopened. W33 extends `print.css`; it does not add a renderer. |
| D7 | **Download report** is one button, no options step: it renders the report with every section expanded and both states (current *and* target, with inherent for reference) and goes straight to the save dialog. The options panel stays for anyone who wants a tuned report. | The default path to a document must not require understanding five toggles first, and a report handed to a client that silently omitted shelved controls or the target state is worse than no report. |

---

## W29 — Node card: one baseline, labelled values, fewer icons

**Depends on** W28. **Owns**
`resources/js/Components/Tree/**`,
`resources/js/Components/Toolbar/**`,
`resources/js/composables/useBaseline.ts`,
`resources/js/composables/useEditorView.ts`,
`resources/js/constants/valueHints.ts` (new),
`resources/js/Components/UI/{Money,Percent,Bar,SegmentedControl}.vue`,
`resources/js/Components/UI/format.ts` (new),
`docs/specs/ui.md` §3.2 and §5,
`vitest.config.ts`, `tests/js/{tree,constants,composables}/**`,
and — crossing into W25's paths, deliberately — `tests/e2e/{05,12,13}` plus
`tests/e2e/support/tree.ts`, because removing the "Edit title" button removes
the locator three scenarios drove the editor with. A package that changes an
affordance owns the tests that press it.

### The baseline selector

New composable `useBaseline.ts`, instantiated once in `createEditorView()` and
consumed through `EDITOR_VIEW_KEY` like every other view concern. State is a
single `'inherent' | 'current' | 'target'`, persisted under
`arguesecure:baseline:{userId}:{assessmentUuid}`, defaulting to `current`.

`NodeCard` reads its figures from `store.results.value[baseline]` instead of
hard-coding `.current`. `TotalsPanel` follows the same selector, so the docked
totals and the cards can never disagree.

A defence whose `status` excludes it from the selected baseline (a `todo`
defence under *current*, anything `shelved`) renders its reduction chip dimmed
with the tooltip *"Not applied in this baseline"* — visible, but visibly not
counting. Silence there would read as "this control does nothing".

### What each card shows

Every currency and rate figure gets a **text label before it**, and every label,
chip and bar gets a tooltip. One value per idea; the rest live in the modal.

| Type | Line 1 |
|---|---|
| root | `Total risk: €X` · `Reduction: N%` · completeness bar |
| risk | `Impact: €X` · `Annual loss: €Y` · contribution bar |
| attack | `Likelihood: 0.25/yr` · `Annual loss: €Y` |
| defence | status pill · `Reduces likelihood by 40%` · `Cost: €X/yr` · `Saves: €Y/yr` |

Notes on the table:

- The attack's **residual** likelihood chip goes away as a permanent fixture; it
  is a computed intermediate and belongs in the tooltip on `Likelihood`
  (*"0.25/yr entered; 0.15/yr after the defences in this baseline"*).
- The defence's strength and reduction target **merge into one chip**:
  `Reduces likelihood by 40%`, `Reduces impact by 40%`, `Reduces overall loss by
  40%`. Strength missing renders `Reduces overall loss by ?`, never by 0%.
- `?` chips keep their current meaning and gain the tooltip *"No value entered —
  this pair is excluded from the total"* (`calculation-engine.md`: a missing
  impact or likelihood excludes the pair; a missing strength is treated as zero
  reduction). The two tooltips must say the different things, because the two
  behaviours are different and the difference is the honest part.

### The bars

Both progress bars become explainable on hover:

- root completeness — *"Values entered for 12 of 20 risk/attack pairs (60%)"*
- risk contribution — *"18% of total annual loss (€22,500 of €125,000)"*

`Bar.vue` gains a `tooltip` prop rather than each call site wrapping it, so the
hoverable area is the bar itself.

### Tooltip copy lives in one module

`resources/js/constants/valueHints.ts` — one exported record, keyed by
`type.field`, holding the label and the hint. Copy that explains a calculation
is product knowledge, it will be edited by people who are not editing Vue, and
it must be assertable in a test. The chart view in W32 imports the same module,
which is the point: the two views must never explain the same number
differently.

### Icons removed

- **Notes indicator** — deleted (see W30).
- **Edit-title pencil** — deleted. The title becomes editable on a **single
  click** (currently `@dblclick`), and the settings icon still opens the modal.
  Two affordances, not three.
- **Links (`link-2`)** — deleted. The settings icon opens the modal; the Links
  tab is one click further and is not a hot path.

What remains: add-child `+`, settings, delete. `ui.md` §3.2's "actions" line
must be rewritten to match — the spec currently lists five.

### Tests

There are **no component tests for `NodeCard` today** — `tests/js` covers
composables, the store, the calc engine and two report components only. W29
creates `tests/js/tree/NodeCard.spec.ts` and it is not optional: this package's
entire content is what the card renders.

- Each type renders its labelled figures for each of the three baselines.
- Switching the toolbar selector changes the figures on the card (one editor
  view, mounted once — this is the regression that D1 exists to prevent).
- A missing impact renders `?` with the exclusion tooltip; a missing strength
  renders `?` with the zero-reduction tooltip.
- A `todo` defence under *current* renders dimmed with the not-applied tooltip.
- The three deleted icons are absent; a single click on the title starts editing.

**Done when** the full check passes, plus `docs/specs/ui.md` §3.2/§5 describe the
card and the selector as built.

---

## W30 — Remove `notes`

**Depends on** W29. **Owns**
`database/migrations/2026_*_drop_notes_columns.php` (new),
`app/Models/{Node,CatalogEntry}.php`,
`app/Domain/{Reporting,Snapshots,Structure,Catalog}/**` (the `notes` lines only),
`app/Http/Controllers/{Assessment,Node,Catalog,Lock}Controller.php` (likewise),
`app/Events/NodeBroadcastPayload.php`,
`app/Http/Requests/**` (likewise),
`resources/js/Components/NodeModal/tabs/{DetailsTab.vue,notesPreview.ts}`,
`resources/js/composables/useSearch.ts`,
`resources/js/stores/assessmentStore.ts` (the `notes` field only),
`resources/js/Components/{Report,Snapshots,Catalog}/types.ts`,
`resources/js/Components/Toolbar/SearchBox.vue`,
`database/factories/{Node,CatalogEntry}Factory.php`,
`database/seeders/{CatalogLibrarySeeder,TemplateAssessmentSeeder}.php`,
`database/seeders/templates/*.json`,
`tests/js/nodeModal/notesPreview.spec.ts`, `tests/js/composables/**` (fixtures),
`tests/Feature/Snapshots/SnapshotDiffTest.php`,
`docs/specs/{ui.md,schema.md,security.md}`.

**The content is not merged into `description` first**, though that is the
obvious kindness. `nodes.description` is `varchar(500)` and validated at
`max:500` because it is the snippet the card and the chart view show, while
`notes` was `mediumText` long-form markdown: merging would overflow the column
or truncate someone's writing, and widening `description` would break the one
property that makes it usable as a snippet. So the drop destroys content —
take a dump first — and `down()` restores the columns empty. The same applies
to the ~130 curated notes in `database/seeders/templates/*.json`: they are
stripped, and survive only in git history.

One migration dropping `nodes.notes` and `catalog_entries.notes`. The
`nodes_search_fulltext` index covers `title` and `description` only, so it does
not need rebuilding — verify that rather than assuming it.

Work outward from `grep -rn "notes" app resources database tests routes` and
leave none behind. Known consumers: the Details tab field and its markdown
preview, `useSearch`'s match string and the SearchBox placeholder,
`ReportAssembler` (three sites), `AssessmentJsonExporter`, `SnapshotService`,
`CatalogInserter`, `AssessmentCloner`, `NodeBroadcastPayload`, `LockController`'s
lockable-field list, and the `NodeRecord`/report/snapshot/catalog TS types.

Two things that are not simply deletions:

- **Old snapshots** carry a `notes` key in their stored JSON. The restore path
  must ignore unknown keys rather than blow up — assert that with a test using a
  fixture payload that still contains one.
- **Template JSON** in `database/seeders/templates/` may carry `notes`. Strip it
  from the files and make the importer tolerant, in that order.

`notesPreview.ts` and its spec are deleted with the field. That module exists
only because notes rendered markdown; it also carries a documented XSS fix, so
the deletion commit must say why it is safe to remove (nothing renders
user-authored HTML any more) rather than dropping it quietly.

**Done when** the full check passes, `grep -rn "notes" app resources database`
returns nothing about node notes, and `docs/specs/ui.md` §7 and
`docs/specs/schema.md` no longer list the field.

---

## W31 — Per-link strength from the defence side

**Depends on** W29. **Owns**
`resources/js/Components/NodeModal/tabs/LinksTab.vue`,
`resources/js/Components/NodeModal/LinkStrengthEditor.vue` (new),
`resources/js/Components/NodeModal/useLinkMutations.ts`,
`resources/js/stores/assessmentStore.ts` (the `AttackDefenceLink` type only),
`docs/specs/ui.md` §7,
`tests/js/nodeModal/**`.

The `app/Domain/Graph/**` paths this package reserved turned out not to need
editing — see below.

Today the defence section of the Links tab lists the attacks it applies to with
a read-only percentage and a line of text telling the user to go and open the
attack instead. That is the legacy app's indirection, rebuilt: to change one
number you navigate to a different node.

Extract the strength/reduction-target/rationale block the attack section already
renders into `LinkStrengthEditor.vue`, taking `attackUuid` and `defenceUuid`, and
render it from **both** ends. The edge is the same row in `attack_defence`; only
the heading differs ("Defences applied to this attack" / "Attacks this defence
applies to"). Removing the duplicated markup is most of the work; the mutation
path already exists and is symmetric.

While in here, close the load-time gap `LinksTab`'s own docblock records: a
saved rationale could not be displayed after a reload, so the tab kept a local
draft map to paper over it.

**Found on opening the file: the backend half was already fixed.**
`AssessmentGraphLoader::mapAttackDefenceLinks()` selects `rationale`, the DTO
carries it as an optional key, `LinkController` echoes it on every write, and
`AssessmentGraphLoaderTest` already pins it as a regression. What was still
missing was purely on the client: `AttackDefenceLink` did not declare the
field, so nothing could read it and the draft map survived. The remaining work
is the type plus deleting the workaround.

### Tests

- Setting a strength from the defence side writes the same edge as setting it
  from the attack side (mount both, assert one `PATCH` shape).
- A saved rationale survives a store reload — the pinned form of the gap above.
- Qualitative mode from the defence side picks levels from the strength scale.

---

## W32 — The chart view

**Depends on** W29. **Owns**
`resources/js/Components/Chart/**` (new),
`resources/js/composables/{useTreeLayout.ts,useEditorViewMode.ts}` (new),
`resources/js/composables/useEditorView.ts`,
`resources/js/Pages/Assessment/Show.vue`,
`resources/js/Components/Toolbar/{ViewSwitcher.vue,Toolbar.vue}`,
`docs/specs/ui.md` §3 (new §3.7) and §5,
`tests/js/chart/**`, `tests/js/composables/useEditorViewMode.spec.ts`,
`tests/e2e/15-chart-view.spec.ts` (new), and — crossing into W25's paths
again — `tests/e2e/support/tree.ts` plus `tests/e2e/07`: making the chart the
default changes what every existing scenario finds on load, so
`openAssessment()` now switches to the list explicitly rather than leaving it
an unstated precondition.

### Layout

`useTreeLayout.ts` is a **pure function**: occurrences in, positions out. No
DOM, no measurement, no store — it takes the same occurrence enumeration the
existing composables use (`composables/occurrences.ts`) plus fixed card
dimensions, and returns `{ pathKey, tier, x, y }` per occurrence and a list of
parent→child connector segments. Pure because it is the one piece here with real
arithmetic in it, and a layout bug that can only be reproduced by taking a
screenshot is a layout bug nobody fixes.

Algorithm: bottom-up width accumulation. A leaf occupies one card width plus a
gutter; a parent occupies the sum of its visible children and centres itself over
them. Collapsed occurrences count as leaves, so collapsing a risk narrows the
chart — that is the primary tool for making a large assessment fit.

Four tiers at fixed `y`, labelled down a left gutter (Target of Assessment /
Risks / Attacks / Defences). The viewport scrolls horizontally only. A
**fit-to-width** button sets the zoom so the widest tier fits; zoom, pan and
`Ctrl +/−/0` come from `useCanvas` unchanged.

### DOM shape

Keep the nested `<ul role="tree">` structure of the list view and position each
card with a transform from the layout result. Flattening the chart into
tier-ordered divs would be simpler and would destroy the ARIA tree semantics,
the keyboard outline (`useKeyboardOutline`), focus mode and collapse state — all
of which key on occurrence path and work unchanged if the nesting survives.
Connectors are one absolutely-positioned SVG layer behind the cards,
`aria-hidden`.

### What a chart card shows

Title (two lines, clamped), a one-line description snippet, and **one** row of
cues — no currency figures:

| Type | Cue row |
|---|---|
| root | completeness bar |
| risk | contribution bar, tone from the magnitude ramp |
| attack | likelihood dot (5-step ramp) |
| defence | status dot, strength ring |

Plus, on any card: a `?` marker when a value the engine needs is missing, the
"appears in N places" badge, and an unresolved-comment dot. Colour is never the
only channel — every cue carries its number in its tooltip, using the **same**
`valueHints.ts` strings W29 introduces.

Interactions: click selects, `Enter` or double-click opens **the existing
`NodeModal`** unchanged, hovering reveals an add-child `+` under the card,
`Delete` deletes with the same confirm dialog. Search, filters, focus mode and
duplicate highlighting apply as they do in the list, because they are all driven
by path key.

**Out of scope, stated rather than discovered:** drag-and-drop reordering is not
implemented in the chart for 2.1 — the list view keeps it. Inline title editing
in the chart is double-click only; single-click is selection, because in a
chart, clicking a card is how you point at it while talking.

### View switching

`useEditorViewMode.ts` holds `'chart' | 'list'`, persisted per user per
assessment under `arguesecure:viewmode:{userId}:{assessmentUuid}`, **defaulting
to `chart`**. `ViewSwitcher.vue` is a two-way segmented control in the toolbar.
`Show.vue` renders one or the other; both consume the same store, the same
editor view and the same modal, so switching never reloads and never loses
state.

### Tests

- `tests/js/chart/useTreeLayout.spec.ts` — the arithmetic, without a DOM:
  siblings do not overlap; a parent is centred over its children; a collapsed
  parent occupies one card; a node occurring twice yields two positions; total
  width matches the sum of visible leaves.
- Component tests: four tiers render; a card shows title, snippet and cue row
  and **no currency**; double-click opens the modal.
- `tests/e2e/15-chart-view.spec.ts` — the chart is what loads by default,
  switching to the list and back preserves collapse state, and the page does not
  scroll vertically at a 1280×800 viewport for the sample assessment.
- Re-run the existing performance fixture against the layout function: it must
  stay linear in occurrence count.

---

## W33 — Reports from the list view

**Depends on** W29. **Owns**
`app/Domain/Reporting/ReportAssembler.php`,
`app/Http/Controllers/ReportController.php`,
`resources/js/Pages/Report/**`,
`resources/js/Components/Report/**`,
`resources/css/print.css`,
`app/Domain/Reporting/ReportOptions.php`,
`resources/js/Pages/Assessment/{Show,Index}.vue` (the report links only),
`docs/specs/ui.md` §9,
`tests/Feature/Report/ReportDownloadTest.php` (new), `tests/e2e/10-report.spec.ts`.

The report button went into `Pages/Assessment/Show.vue`'s own header rather
than the toolbar: the toolbar is view state (search, filters, zoom, baseline,
chart-or-list) and a link out of the page is not that.

### First: it is unreachable

`grep -rn "assessments.report" resources/js` matches only `Pages/Report/Show.vue`
itself. The whole of W17 — cover, executive summary, risk register, defence
ranking, transfers, appendices, charts, CSV and JSON export — ships behind a URL
nothing links to. Add a **Report** button to the editor toolbar next to Share,
and a report action on each row of the assessment list — each paired with the
**Download report** action below, so both paths (read it on screen / put it on
disk) are one click from where the user already is. That is the single
highest-value line of code in this plan.

### The tree section becomes the list view

`AssessmentTreeSection.vue` currently prints titles with a current and a target
ALE. Rebuild it as the indented list view with **everything**, per node:

- risk — impact (amount or level, both when calibrated), inherent / current /
  target ALE, reduction bought by each;
- attack — likelihood entered and residual under each baseline, ALE under each;
- defence — status, transfer flag, strength **on this link** with its reduction
  target and rationale, one-off cost, annual cost, amortised annual cost, MC.

Baseline columns still honour the `baseline` report option; `both` shows all
three, which is the point of the section. Missing values print `—` with a
footnote marker, never `0`.

### Programme summary

`executiveSummary()` counts costs for `implemented` defences only. The figure a
reader actually asks for — *what does the plan cost and what does it buy* — is
not in the report. Add a `programme` block:

| Figure | Definition |
|---|---|
| Planned annual cost | Σ annual cost of `todo` defences (priced only) |
| Planned one-off cost | Σ one-off cost of `todo` defences |
| Planned amortised annual | one-off ÷ amortization years + annual |
| Unpriced planned controls | count — never folded into a total |
| Total risk, per baseline | already present; surfaced here too |
| Risk reduced, inherent → current | currency, not just percent |
| Risk reduced, current → target | currency — what the plan buys |
| Return on the planned programme | `(current − target) ÷ planned annual cost`, using the engine's existing ROSI definition, labelled as a programme-level aggregate |

Keep the three cost buckets separate (`ui.md` §9: never print `∞` for a
zero-cost control), and never sum a priced total across defences with unknown
cost — print the count of unknowns beside it. `RiskCalculator` is **not**
touched: every input above already exists on `results.target.defences`. If a
figure appears to need an engine change, stop and report.

### The Download report button

Today the report page carries a `Download PDF` button that calls
`window.print()` against whatever options happen to be in the query string, and
nothing outside that page links to it at all. Two separate problems: it is
unreachable, and when reached it can quietly produce a report missing the target
state, the shelved controls, or both.

Add an explicit **Download report** button in **three** places — the editor
toolbar next to Share, each row of the assessment list, and the report page
itself — all pointing at the same URL:

```
GET /assessments/{uuid}/report?download=1
```

`download=1` is handled in `ReportOptions::fromQuery()` and **overrides every
other option in the query string** rather than merging with it. It is not a
default that a stale link can partially undo; it forces:

| Option | Forced value |
|---|---|
| `baseline` | `both` — current and target side by side, inherent as the reference column |
| `includeShelved` | true |
| `includeComments` | true |
| `includeMethodology` | true |
| `figures` | `full` |

Everything expanded means exactly that, and the report page has to be audited
for it rather than assumed: the full tree section renders every risk, every
attack under it, every defence link with its strength, reduction target,
rationale and all three cost figures; the risk register lists every attack row
rather than a summary line; the defence ranking prints all three buckets in
full; the appendices and methodology are present. No section is collapsed, and
no section is behind a "show more".

In this mode `Pages/Report/Show.vue` also:

1. **hides the options panel entirely** (not just `no-print` — it must not be on
   screen either, because the state it would show is not editable here);
2. sets `<Head :title>` to `ArgueSecure — {assessment title} — {YYYY-MM-DD}`,
   because every browser derives the suggested PDF filename from the document
   title, and `Report — Assessment` is not a filename anyone wants on disk;
3. calls `window.print()` once on mount, after the next paint, so the save
   dialog appears without a second click. Guard it with a `sessionStorage` key so
   an Inertia back-navigation onto the same URL does not re-open the dialog.

Keep the existing first-use hint — *"Choose 'Save as PDF' as the destination"* —
and show it here too, before the dialog opens rather than after.

The print pipeline itself is unchanged (D6). The new tree section is long, so
`print.css` needs `break-inside: avoid` per risk block, repeated table headers on
the register, and a verified pass at A4 and Letter.

**Noted, not chosen:** if a file that needs no save dialog is wanted later, the
same route can serve the assembled report as a self-contained HTML file with
`Content-Disposition: attachment` — inlining `print.css` and the SVG charts, no
new dependency and no headless browser. That is an addition, not a replacement,
and it is out of scope for 2.1.

### Tests

- `ReportAssembler` unit test over a fixture with implemented, todo, shelved,
  free and unpriced defences: planned totals, amortisation, unpriced count, and
  the two currency reductions.
- A zero planned cost must not produce `Infinity` in the programme return.
- Component test for the rebuilt tree section: a defence row prints strength,
  reduction target and all three cost figures.
- `ReportOptions::fromQuery(['download' => '1', 'baseline' => 'current',
  'include_shelved' => '0', 'figures' => 'qualitative'])` returns the full forced
  set. This is the test that matters most in the package: `download=1` losing to
  a stale query string is exactly the failure that ships a report missing the
  target state, and it is invisible on screen because the document still looks
  complete.
- `tests/e2e/10-report.spec.ts` extended: reach the report **from the editor**,
  assert the programme summary numbers against the seeded sample assessment,
  then follow **Download report** and assert on the rendered page that both the
  current and target columns are present, a shelved defence appears, the
  methodology appendix is present, the options panel is absent, and the document
  title carries the assessment name and date. Stub `window.print` in the
  Playwright context — the auto-print would otherwise hang the run on a native
  dialog.

---

## Order and parallelism

W29 first — W32 and W33 both consume its `valueHints.ts` and its baseline
selector. W30 and W31 are independent of each other and of W32/W33; either can
slot in wherever convenient after W29.

`docs/specs/ui.md` is edited by four of the five packages, in different
sections. Run them sequentially, as the ground rules already require, and each
package updates only its own sections — the spec is the contract, so a package
that changes behaviour without changing the spec is not done.

**Do not fan out sub-agents inside W29 or W32.** Each is a single coherent
design that fragments badly. W30's grep-and-remove sweep and W33's section work
are reasonable fan-outs if the sub-agents own disjoint files, and `Explore` is
free to use anywhere.

## Session prompt template

> Read `CLAUDE.md`, `docs/PLAN.md` and `docs/PLAN-2.1.md`, plus the spec
> sections named in the package. Implement work package **W29**. Own only the
> paths listed for W29. Follow the ground rules. Commit to branch
> `v2/w29-node-card` when the full check is green.
