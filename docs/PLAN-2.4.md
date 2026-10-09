# ArgueSecure 2.4 — The workshop pass

> Follow-on to [`PLAN.md`](PLAN.md), [`PLAN-2.1.md`](PLAN-2.1.md),
> [`PLAN-2.2.md`](PLAN-2.2.md) and [`PLAN-2.3-ai.md`](PLAN-2.3-ai.md), which
> took the rewrite to 2.3.1 (W1–W68). This plan covers **W69–W76**.
>
> The ground rules in [`CLAUDE.md`](../CLAUDE.md) apply unchanged: one package
> per session, own only your listed paths, write the test with the code,
> commit to `v2/<package-id>`, never to `master`.

---

## Why

2.3 finished the feature list. This plan is the first one written from
**someone using the thing** rather than from a specification, and that shows in
its shape: twenty-four items, almost all small, almost all about a surface that
is *present and wrong* rather than absent.

They are not cosmetic. Six of them (items 1, 3, 6, 16, 19, 20) are things that
do not work — a tab that sometimes renders nothing, a dropdown you cannot see,
a button that goes to the wrong page. Another six are the same complaint said
six ways: **the card is mostly chrome and not enough content**. The bottom row
of a chart card holds icons and nothing else while the values indicator eats
the title's width; the tiers are all one width when the content is not; the
list carries a progress bar nobody reads. And item 17 is not a bug at all —
the assistant shipped in 2.3 and is invisible, because it is off by default
*and* needs a container the default compose stack does not start.

Nothing here changes the calculation, the schema's shape, or any closed
decision.

### One item was dropped

**Item 14 — "when making an assessment public, allow edits" — is not in this
plan.** It reverses defect #4 in [`specs/security.md`](specs/security.md):
`is_public` meant world-writable in 1.x, which let any logged-in user edit any
public assessment's contents, and `AssessmentPolicy` was written specifically
to close it. Raised with the author; the decision stands. Collaboration on an
assessment goes through a named collaborator or a share-link token, both of
which put a real user on every revision row.

---

## Decisions (closed)

| # | Decision | Rationale |
|---|---|---|
| D26 | **The chart card's bottom row becomes the value row.** The values indicator leaves the title row for the bottom-left, and stops being a checkbox: it shows the value itself, short — `x%` for a defence, `y/yr` for an attack, `Z$` for a risk, and a two-segment bar (current, target, as a share of inherent loss) for the ToS. A defence keeps its status glyph immediately to its left. | The bottom row was icons and whitespace; the title row was a title fighting an indicator for two lines of `line-clamp`. This gives the title the width and the reader an actual figure. W37's argument that the chart carries no numbers held while the alternative was four ramps in four visual languages — one short figure per card in a fixed slot is a different proposition. |
| D27 | **Tiers get different widths**, ToS > risk > attack > defence, as multiples of one base unit. | Card width has been uniform since W32 because the layout was a plain sum of leaf widths. The content is not uniform: a defence title is "MFA on admin accounts" and a ToS title is a paragraph. |
| D28 | **Zoom is rebased so that what reads 110% today reads 100%.** One constant, applied in both views; the persisted value and the label keep their current meaning relative to the new base. | The default was set from a spec, not from a projector. |
| D29 | **A dropdown that opens inside a scrolling dialog is positioned against the viewport, not against the dialog.** `Combobox` gains a teleported, anchored listbox. | Item 6 is one instance; the same clipping applies to every combobox in the modal, and fixing it at four call sites would be four fixes. |
| D30 | **The node modal's tab strip wraps; it does not scroll.** | Eight tabs at the current sizes overflow an `lg` modal, and an `overflow-x-auto` strip with no scrollbar affordance hides the last two with nothing to say they are there. |
| D31 | **"Appears under" is not deleted — it moves.** Item 12 takes it off the attack's Details tab; the detach and link-under-a-second-risk affordances go to the **parent counter's popover** (item 11), which is where a user asks the question. | The section is the *only* place in the application that can detach an attack from one of several risks. Deleting it outright removes a capability, which is not what item 12 asks for. |
| D32 | **The dashboard is two sections of tabs, not four lists** (item 21): *Notifications* (Assigned to me · Mentions) and *Assessments* (Mine · Shared with me). | Four stacked sections meant the two that answer "what needs me" were half a screen apart from the two that answer "where do I go", and neither pair fit above the fold. |
| D33 | **"Shared with me" is a page.** New route `assessments.shared`, titled *Assessments shared with me*, reachable from the sidebar group header and the dashboard tab's "see all". | It is a real collection with its own emptiness, and today the sidebar sends it to a list that does not distinguish it. |
| D34 | **Export is one menu with five formats** — HTML, CSV, JSON, Excel, Word — built on **`phpoffice/phpspreadsheet`** and **`phpoffice/phpword`**. PDF remains the browser print pipeline (D6, not reopened). | Both are LGPL, free, self-hosted, no service — the licence bar in CLAUDE.md. Faking `.xlsx` with CSV and `.docx` with HTML produces files that open with a warning dialog, which is worse than not offering them. |
| D35 | **"Download report" goes; "Print as configured" becomes "Print".** | W33's argument for a no-options download button was that the default path to a document should not require understanding five toggles. That is now served by the export menu, where the same forced-full render is one of five formats and is named by what it produces. Two buttons that both print, one of which ignores the panel above it, is the confusion the options panel was already accused of. |
| D36 | **`default_scale_calibration` becomes a real control** (item 13): a select over the seeded system scales, naming which qualitative scale set a new assessment starts with. | It is a free-text `varchar(64)` defaulting to the string `default`, validated as `required\|string\|max:64` and read by nothing that branches on it. It is a text box that does nothing. |
| D37 | **The assistant stays off by default and stays deployment-gated (D25, not reopened).** What W75 adds is *discoverability*: the admin page says the feature exists, why it is not available here, and exactly what to do — it does not become reachable without an inference container. | D25's reasoning is unchanged: the runtime needs ~2.8 GiB resident and a second container, and ArgueSecure must run anywhere. Item 17 is "I could not find any trace of it", and the fix for that is words, not a switch. |
| D38 | **Item 2 is a reproduce-first package.** The five assessment fixtures and two catalogue fixtures were audited while writing this plan and are structurally complete — no risk without attacks, no attack without defences, no orphan node, no missing description, no missing impact/likelihood/strength. | So the emptiness the author saw is either stale seed data in a running deployment or a defect in the seed/clone/insert path. Rewriting content that is already correct would leave the actual bug in place. |

---

## The twenty-four items, and where each lands

| # | Item | Package |
|---|---|---|
| 4 | Values indicator to the bottom-left, as a short figure | **W69** |
| 5 | 110% becomes 100% | **W69** |
| 7 | Per-tier card widths | **W69** |
| 11 | Parent-counter tooltip naming the parents | **W69** |
| 8 | List viewport slightly narrower | **W70** |
| 22 | Remove the list's progress bar | **W70** |
| 23 | Node-specific values as chips in the list | **W70** |
| 1 | Details tab sometimes does not load | **W71** |
| 3 | "Move or link" has no padding | **W71** |
| 6 | Recommendation panel below the fold | **W71** |
| 16 | Last tabs need horizontal scrolling | **W71** |
| 24 | Type chip becomes an icon, left of the title | **W71** |
| 9 | Strength before cost; strength off the Cost tab | **W72** |
| 10 | A currency-and-scales tab on the ToS | **W72** |
| 12 | "Appears under" off the attack's Details tab | **W72** |
| 18 | Expand/collapse control in one place | **W73** |
| 19 | "New assessment" creates one, under My assessments | **W73** |
| 20 | "Shared with me" is a page | **W73** |
| 21 | Dashboard: two sections of tabs | **W73** |
| 15 | Report view: stable switcher, grouped options | **W74** |
| 25 | HTML, CSV, JSON, Excel, Word | **W74** |
| 13 | Default scale calibration means something | **W75** |
| 17 | The assistant is findable and documented | **W75** |
| 2 | Template content audit | **W76** |

---

## W69 — The chart card's bottom row, tier widths, and zoom

**Depends on** W68. **Owns**
`resources/js/Components/Chart/**`,
`resources/js/composables/useTreeLayout.ts`,
`resources/js/composables/useCanvas.ts`,
`resources/js/Components/Toolbar/ZoomControls.vue`,
`resources/js/Components/UI/format.ts` (short-form helpers),
`docs/specs/ui.md` §3.7 and §5,
`tests/js/{chart,composables}/**`,
and `tests/e2e/{02,04}` where the chart card's indicator is asserted.

### The value row (item 4, D26)

`ChartCard.vue`'s bottom row currently holds the defence status glyph on the
left and the occurrence/comment counters on the right, with `mt-auto` and
nothing between. The values indicator sits in the title row, competing with a
two-line `line-clamp` for width.

Move it. Bottom-left, after the status glyph for a defence, and render the
**figure** rather than a checkmark:

| Type | Set | Unset |
|---|---|---|
| Risk | `€400k` — impact, abbreviated | `?` in the existing missing-value styling |
| Attack | `0.4/yr` — ARO | `?` |
| Defence | status glyph, then `60%` — strength **on this edge** | glyph, then `?` |
| ToS | a two-segment bar: current and target loss as a fraction of inherent | nothing — the root has no own values |

Three things that are easy to get wrong here:

- **The defence figure is per occurrence**, because strength lives on the
  `attack_defence` pivot (CLAUDE.md). The same defence under two attacks shows
  two different percentages, and that is correct.
- **The qualitative case is not a number.** A node valued on a qualitative
  scale has a `*_level_id`, not an amount; render the level's short code, and
  do not invent a figure by mapping it. `ScaleMapper` is the only thing that
  maps, and it maps for the engine, not for a card.
- **The space stays reserved in both states**, which is why W37 made the
  indicator permanent. A card must not reflow when a value is filled in.

The ToS bar is the one item that reads a computed figure, and it is the one
place a computed figure belongs: it is the whole assessment's answer. It is a
bar and not a number precisely so it is not mistaken for one — two segments
against a full-width inherent ground, current and target, with the figures in
the tooltip.

The indicator keeps its click-to-open-the-right-tab behaviour and its
`data-values-set` attribute; the e2e suite locates it by that attribute.

### Tier widths (item 7, D27)

`useTreeLayout.ts` takes one `cardWidth` and sums leaf widths bottom-up.
Take a **per-tier** width instead — a base unit times a factor per tier
(defence 1, attack 1.25, risk 1.5, root 2 are the starting numbers; tune
against the four seeded archetypes, which is what they are for).

The layout algorithm does not change shape: a parent still occupies the sum of
its children or its own width, whichever is greater. Only the constant becomes
a lookup. Connector geometry reads box coordinates and is unaffected.

Watch the horizontal-scroll promise: the chart scrolls horizontally and never
vertically at fit-to-width (D3), and fit-to-width divides the viewport by
`layout.width`. Widening the upper tiers widens `layout.width` only where a
tier's own width exceeds its children's sum — a real effect on a shallow
assessment, none on a wide one. Assert it on both.

### Zoom (item 5, D28)

One exported constant — call it `CANVAS_BASE_SCALE = 1.1` — multiplied into
`chartStyle`'s `transform: scale()` and `listStyle`'s `zoom`. `zoom.value`,
its persistence key, its min/max/step and its label are untouched, so 100%
now renders what 110% rendered and every stored preference keeps its meaning.

Do **not** move `CANVAS_ZOOM_DEFAULT` to 1.1 instead. That reads 110% in the
control on first load, which is the thing being complained about.

### The parent counter (item 11)

The shuffle counter (`⤨3`) has a tooltip saying *"Appears in 3 places"* and
does not say which. Name them — the parent titles, one per line, capped at
about eight with "and N more".

`store.risksForAttack` / `store.attacksForDefence` already return them.
`Tooltip` renders a string today, so this needs either a slot or a
newline-tolerant variant; a slot is the smaller change and `Tooltip` is
W69-adjacent, not W69-owned — **if the slot cannot be added without editing
`Components/UI/Tooltip.vue`, that is a cross-package edit: stop and report.**

W72 builds the interactive version of this popover (D31). W69 ships the
read-only tooltip, on both the chart card and the list card.

---

## W70 — The list view

**Depends on** W69 (shares the short-form helpers in `format.ts`). **Owns**
`resources/js/Components/Tree/**`,
`resources/js/Pages/Assessment/Show.vue` (the list viewport width only),
`docs/specs/ui.md` §3.2,
`tests/js/tree/**`, `tests/e2e/{05,18}`.

- **Item 8 — narrower viewport.** The list column gets a tighter `max-w`.
  Measure against a long risk title at 100%: the target is a comfortable
  reading measure, not a full-bleed row. Print is unaffected — `print.css`
  sets its own fixed size and ignores zoom (W33), and there is an e2e test
  pinning exactly that.
- **Item 22 — the progress bar goes.** `NodeCard.vue` renders a `Bar` labelled
  *"Assessment completeness"* on the root. Delete the usage and its
  `completenessHint` wiring from the card. Keep `Bar.vue` — the report uses it,
  and W69's ToS bar will use it. The completeness figure itself stays reachable
  in Statistics and in the report; it stops being a bar on a row.
- **Item 23 — every node-specific value as a chip.** `ValueChip` already does
  this for defences. Extend to risk (impact), attack (likelihood) and the
  edge-level strength and reduction target, same component, same
  label-colon-value shape, same tooltip discipline. The chips are the *typed*
  values, not computed ones; computed figures keep their existing treatment.
  A missing value renders the missing-value chip rather than nothing — an
  absent chip and a zero look identical, and `calculation-engine.md` is
  emphatic that a missing value is not a zero.

---

## W71 — The node modal shell

**Depends on** W68. **Owns**
`resources/js/Components/NodeModal/NodeModal.vue`,
`resources/js/Components/NodeModal/useNodeModal.ts`,
`resources/js/Components/UI/{Tabs,Modal,Combobox,ReparentDialog}.vue`,
`docs/specs/ui.md` §7,
`tests/js/{ui,nodemodal}/**`, `tests/e2e/{05,13}`.

### Item 1 — the Details tab sometimes does not load

**Reproduce before fixing.** Two candidates, both real, and the fix differs:

1. **`Tabs.vue` mounts every panel.** Panels are `v-show`, not `v-if`, so
   opening any node mounts all eight — History, Comments, References and
   Statistics each fetch on mount. A throw in any one of them during render
   takes the whole `Tabs` subtree down, and Details is the visible casualty.
2. **`DetailsTab` is `v-if="node"` with no else.** When
   `store.nodes[uuid]` is not there — a `?node=` deep link restored before
   hydration, or a realtime delete arriving while the modal is open — the panel
   renders *empty*, which is exactly "does not load".

Fix both regardless of which reproduces: make panels lazy (`v-if` on first
activation, `v-show` after, so a tab keeps its state once opened) and give
`DetailsTab` a loading and a gone state. Lazy panels also stop four fetches
firing every time anyone opens any node, which is the same class of waste W68
removed on the write path.

### Item 16 — tabs wrap (D30)

`role="tablist"` loses `overflow-x-auto` and gains `flex-wrap`. Check the
`aria-selected` / roving-focus behaviour survives wrapping, and that the
strip's bottom border still reads as one edge across two rows.

### Item 24 — icon, not chip

The `#actions` slot renders `<Badge tone="neutral">{{ label }}</Badge>` in the
header's right-hand group, next to Delete. Move the type to the **left of the
title** as `NODE_TYPES[cardType].icon`, tinted with the type's colour and with
the label as its accessible name and tooltip. This needs a `#title-prefix`
slot on `Modal.vue`, or the header title becoming a slot — `Modal` is W71-owned,
so that is in scope. Delete stays where W37 put it.

### Item 3 — "Move or link" padding

`Modal.vue`'s body is `<div class="max-h-[75vh] overflow-y-auto">` with no
padding: every other consumer pads its own content, and `ReparentDialog`
does not. Pad the dialog rather than the `Modal` body — padding the body
double-pads the four consumers that already handle it, `NodeModal` included,
where the tab strip is deliberately flush to the edges.

### Item 6 — the dropdown below the fold (D29)

`Combobox`'s listbox is `absolute z-30 mt-1` inside that same scrolling body,
and both offending comboboxes are the **last element** of their section, so the
list opens into clipped space. Teleport the listbox to `body` and anchor it to
the input with a viewport-aware flip (open upward when there is no room below).

Two things this must not break: the listbox is referenced by `aria-controls` /
`aria-activedescendant`, which survive teleporting but must be asserted; and
the outside-click-to-close handler currently relies on the listbox being inside
the component's own root.

---

## W72 — The node modal's tabs

**Depends on** W71. **Owns**
`resources/js/Components/NodeModal/tabs/**`,
`resources/js/Components/NodeModal/{LinkStrengthEditor.vue,useLinkMutations.ts}`,
`docs/specs/ui.md` §7,
`tests/js/nodemodal/**`, `tests/e2e/{05,07}`.

- **Item 9 — a defence's Cost tab is about cost.** Strength moves off it
  entirely (it is already the *Strength* tab's subject, per-edge, which is
  where it belongs) and everything else on that tab that is not capex/opex/
  amortisation moves to Details. Within the Strength tab, strength comes before
  reduction target. The tab labels from W39 (`Impact` / `Likelihood` / `Cost`)
  are unchanged.
- **Item 10 — the ToS gets a Scales tab.** Currency, risk tolerance,
  amortisation period and the three qualitative scales already exist on
  `Pages/Assessment/Settings.vue`, at a different URL, reachable only from the
  sidebar. Surface them as a root-node tab. **Read and write the same
  controller and the same request objects** — do not add a second write path to
  the same columns. Editing a scale's bands stays on
  `Pages/Scales/Calibrate.vue` (W65); the tab links to it.
- **Item 12 — "Appears under" leaves Details** (D31) and becomes the
  interactive parent popover W69 stubbed: the parent list, a detach action per
  parent under the same "not the last one" guard, and the
  link-under-another-risk combobox. Its `data-testid="appears-under"` moves
  with it; `tests/e2e/07` drives it.

---

## W73 — Navigation and the dashboard

**Depends on** W68. **Owns**
`resources/js/Components/Nav/**`,
`resources/js/Pages/Dashboard.vue`,
`resources/js/Components/Dashboard/**`,
`resources/js/Pages/Assessment/Shared.vue` (new),
`app/Http/Controllers/{DashboardController,AssessmentController}.php`,
`app/Domain/Dashboard/**`,
`routes/web.php` (the two new routes only),
`docs/specs/ui.md` §2 and §8,
`tests/js/{nav,dashboard}/**`, `tests/Feature/{Dashboard,Assessments}/**`,
`tests/e2e/{17,18}`.

- **Item 18 — one location for the toggle.** Expanded, it is `ml-auto` in the
  logo row; collapsed, it is a full-width button in its own row below. Same
  row in both states. W41's reasoning for splitting them — two 16px targets in
  a 48px column is a coin toss — is real, so the fix is to keep the dedicated
  row and put the logo row's control in it too, not to merge the collapsed one
  upward.
- **Item 19 — "New assessment" creates one.** It currently `href`s to
  `assessments.index`. Make it a `POST` to a create endpoint that makes an
  untitled assessment and redirects into it, and move it inside the *My
  assessments* group, below the list. Check whether `AssessmentController@store`
  already accepts an empty create; if it requires a title, the new assessment
  gets the placeholder title the editor already renders for untitled nodes.
  **Authorization is not optional here** — it is a route that writes.
- **Item 20 — the shared page (D33).** `GET /assessments/shared`, page titled
  *Assessments shared with me*, listing what `sharedWithMe` already computes,
  with the owner's name on each row. The sidebar group header and the dashboard
  tab both point at it. `AssessmentPolicy` gates it; the query must not leak an
  assessment the user has no `assessment_user` row for.
- **Item 21 — two sections of tabs (D32).** *Notifications* (Assigned to me ·
  Mentions) and *Assessments* (Mine · Shared with me). The four
  `DashboardSection` empty states are good and survive as tab-panel empty
  states. `DashboardSection` grows a tabbed variant, or a `DashboardTabs`
  wraps it — either is fine; both are W73-owned. The unresolved counts stay on
  the tab labels, because a tab whose count is hidden until you click it is a
  notification you will not see.

---

## W74 — The report view and the export menu

**Depends on** W68. **Owns**
`resources/js/Pages/Report/Show.vue`,
`resources/js/Components/Report/**`,
`app/Domain/Reporting/**`,
`app/Http/Controllers/ReportController.php`,
`routes/web.php` (the report/export block only),
`composer.json`,
`docs/specs/ui.md` §9,
`tests/js/report/**`, `tests/Feature/Reporting/**`, `tests/e2e/10`.

### Item 15 — the view survives the switch

The view switcher (chart / list / report) must occupy the **same screen
position** across all three views, so switching is a change of content and not
a change of layout. Today the report page builds its own sticky header. Reuse
the editor toolbar's switcher slot, or place the report's header so the
switcher lands on the same coordinates; either way, assert it — a pixel claim
in prose is not a guarantee.

The options panel is regrouped:

- The three radio groups (baseline, figures, and whichever of the toggles reads
  as a choice rather than an inclusion) go **inline, one row**, not stacked.
- The four include/exclude toggles group together, separately.
- **Every export becomes one menu** (D34).
- **"Download report" is removed** and **"Print as configured" becomes
  "Print"** (D35). The forced-full URL W33 defined does not go away — it is
  what the HTML and Word exports render from, so its query-parameter contract
  and its e2e coverage stay. The e2e test named *"Download report gives
  everything expanded…"* is renamed and repointed at the export menu, not
  deleted: the behaviour it pins is still a requirement.

### Item 25 — five formats (D34)

| Format | Source | Notes |
|---|---|---|
| HTML | The forced-full report render | Self-contained, inline CSS, no CDN — the CSP rule applies to a file that leaves the server as much as to a page served by it |
| CSV | `DefenceCsvExporter`, **widened** | Today it is defences only. The report is risks, attacks, defences and edges; a CSV that carries a quarter of it is mislabelled |
| JSON | `AssessmentJsonExporter` | Exists; keep the shape stable, it is the snapshot/restore interchange format |
| Excel | `phpoffice/phpspreadsheet` | One sheet per section, headers frozen, the same figures the report shows |
| Word | `phpoffice/phpword` | The report's prose sections, in order, with the tables |

Four things to hold on to:

- **The exporters read `ReportAssembler`'s output**, not the database. There is
  one assembled report and five renderings of it. Any exporter that re-queries
  is a second source of truth for the same figures, and they will diverge.
- **`ReportOptions` binds all five.** An export that silently ignores
  "include shelved" is the failure W33 called out for the download button.
- **Currency formatting is the assessment's, not the server locale's.**
- **Generation is not synchronous if it is slow.** A 40-control assessment
  rendering five formats is fine; measure it, and if any format exceeds a
  second or two, it goes to the queue and reports back through the W47
  notification path, which exists for exactly this.

---

## W75 — Admin: scale defaults, and finding the assistant

**Depends on** W68. **Owns**
`resources/js/Pages/Admin/Settings.vue`,
`app/Domain/Accounts/InstanceSettings.php`,
`app/Http/Requests/Admin/UpdateSettingsRequest.php`,
`app/Http/Controllers/Admin/SettingsController.php`,
`database/migrations/` (one new migration),
`docs/specs/deployment.md` §5, `docs/ASSISTANT.md` (new),
`tests/Feature/Admin/**`, `tests/js/admin/**`.

### Item 13 — default scale calibration (D36)

`instance_settings.default_scale_calibration` is `varchar(64)` defaulting to
the literal string `default`, rendered as a free-text `Input`, validated as
`required|string|max:64`, and **read by nothing that branches on it**. Whatever
an admin types is stored and ignored.

Replace it with a select over the scale sets `SystemScaleSeeder` installs, and
**wire it**: a new assessment starts with the chosen set. If more than one
system set does not exist yet, this package creates the second one — a select
with one option is the same non-control in a different shape.

Migration notes, because this table has bitten before: `scale_id` is
deliberately absent from `ScaleLevel::$fillable`, so **assign it as a property,
never through `create()`** (CLAUDE.md — this has broken three packages, twice
silently). Existing rows hold the string `default`; the migration maps them to
the default set's id and says so in its docblock.

### Item 17 — the assistant is invisible (D37)

It is not missing. W50–W54 shipped it: `app/Domain/Assistant/**`, routes at
`/assistant/*` and `/assessments/{uuid}/assistant/*`, `config/assistant.php`,
and a profile-gated container in `docker-compose.yml`. It is invisible because
**all three of these must be true and by default none is**:

1. `ASSISTANT_ENABLED=true` in the environment — the routes are not registered
   otherwise, deliberately (D25), so the feature 404s rather than 403s.
2. The container is running: `docker compose --profile assistant up -d`, with
   a GGUF mounted from the host. Weights are never downloaded at run time.
3. The admin toggle *Enable the assistant* is on — and it does not render at
   all unless (1) and (2) hold.

So the deliverable is **words and a diagnosis, not a switch**:

- A permanent panel on the admin settings page. Where the assistant is not
  configured it says what the feature is, that it runs locally and sends
  nothing off the machine, and links the setup document. Today there is
  *nothing* — W50 chose absence over a dead control, which was right for a
  control and wrong for the explanation.
- Where it is configured, the panel reports the runtime state it already
  knows: reachable or not, which model, the `Health` check's answer.
- **`docs/ASSISTANT.md`** — the three switches above, the compose invocation,
  where the weights go, what the four features are (help answers, child
  suggestions, node drafting, summary narrative), where each appears in the UI,
  and the measured latencies from [`spikes/2.3-inference.md`](spikes/2.3-inference.md)
  so nobody enables it on a 2-core VM and files a bug.
- The document states D24 plainly: **nothing a model generates reaches the
  graph, the report or another user without a person accepting it first.** The
  accept step is the guardrail, not a safety net behind one, and
  `tests/Feature/Assistant/GuardrailTest.php` pins the measurement that says
  prompt instructions cannot substitute for it.

**Do not weaken the gate.** Do not register assistant routes when there is no
runtime, do not add a "try anyway" affordance, and do not "fix" a `MEASURED:`
pin with a prompt change.

---

## W76 — The template content audit

**Depends on** W68. **Owns**
`database/seeders/templates/*.json`,
`database/seeders/{TemplateAssessmentSeeder,CatalogLibrarySeeder}.php`,
`database/seeders/Support/**`,
`tests/Feature/Seeders/**`.

**Reproduce first (D38).** The fixtures were audited while writing this plan:

| Fixture | Risks | Attacks | Defences | Structural holes |
|---|---|---|---|---|
| `archetype-cloud-saas` | 3 | 5 | 8 | none |
| `archetype-internal-network` | 4 | 6 | 8 | none |
| `archetype-iot-embedded` | 4 | 6 | 8 | none |
| `archetype-public-web-app` | 4 | 6 | 7 | none |
| `demo-webshop` | 3 | 4 | 6 | none |
| `catalog-iso27001-27002` | 5 | 6 | 23 | none |
| `catalog-iso42001-ai-act` | 4 | 8 | 16 | none |

No risk without attacks, no attack without defences, no orphan node, no missing
description, no missing impact, likelihood or strength, every `defence_refs`
and `attack_refs` resolving. So the emptiness reported in item 2 is **not in
the fixture files**, and the package starts by finding where it is:

1. Seed a clean instance and compare what lands in the database against the
   fixture, node for node and edge for edge.
2. Instantiate a template into a new assessment and compare again — this is the
   `AssessmentCloner` path, which has silently dropped identity columns twice
   before (CLAUDE.md) and which is exactly the shape of bug that leaves
   structure present and children absent.
3. Insert a catalogue bundle and compare a third time.

If step 1 diverges the seeder is wrong; step 2, the cloner; step 3, the
catalogue inserter. **If nothing diverges, the running deployment holds data
seeded before the fixtures were completed**, and the fix is a documented
reseed, not an edit to correct JSON.

Only then, the content pass the item also asks for: read every title and
description for accuracy and for generality. These are archetypes — they are
meant to be a starting point for a room, not a finished assessment for one
company. Anything that names a specific product, vendor or regulation where a
category would do is too specific; anything that could be said of any system
whatsoever is too vague. `AssessmentFixtureValidator` and
`CatalogFixtureValidator` gain assertions for whatever structural rule steps
1–3 turn out to need — a fixture that passes validation and seeds an empty tree
means the validator is not checking the thing that matters.

---

## Sequencing

W69 → W70 and W71 → W72 are the only hard chains. Everything else is
independent:

```
W69 ──► W70          (chart card, then list card: shared format helpers)
W71 ──► W72          (modal shell, then its tabs)
W73                  (nav + dashboard)
W74                  (report + exports)
W75                  (admin + assistant docs)
W76                  (templates)
```

Four packages can start at once. No two packages own the same file — check
your ownership list before your first edit, and if you need a file another
package owns, **stop and report** rather than editing it.

## Definition of done

Per CLAUDE.md, for every package, before it is done:

```
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test
npm run lint:check && npm run format:check && npm run type-check
npm run test:unit
docker compose up --build -d && curl -f http://localhost:8000/up
```

The e2e suite is **not** part of that and is run once at the end of the plan,
against a throwaway stack, per [`../tests/e2e/README.md`](../tests/e2e/README.md)
— including its warning that a double-digit failure count is a reason to re-run
before investigating.
