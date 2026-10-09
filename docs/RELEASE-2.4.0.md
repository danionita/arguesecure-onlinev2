# ArgueSecure 2.4.0 — release notes

Everything since 2.3.1: **work packages W69–W81**, thirteen packages, plus the
integration work that joined them. Five of those thirteen did not exist when the
plan was written — they were created from findings the plan could not have had,
and from a decision to close everything the first pass left open rather than
carry it. A sixth was commissioned and **correctly refused**: the feature it was
asked for had already shipped as W40, and building it again would have made a
second write path for one edge. That refusal is in §7.

**Nothing the plan opened is left open.** The single pinned defect is closed
(§11), the chart's missing type-ahead is built (§12), and the report chrome that
drifted is now one shared component rather than two hand-matched copies (§13).
The two items still listed under *Still open* are the two that cannot be closed
from a development machine: both need hardware or a model file this repository
does not and must not carry.

Read [`RELEASE-2.3.0.md`](RELEASE-2.3.0.md) for what came before. This file only
covers what moved since.

**This is the first plan written from using the thing rather than from a
specification**, and that shows in its shape: twenty-four items, almost all
small, almost all about a surface that was *present and wrong* rather than
absent. Nothing here changes the calculation, the schema's shape, or any closed
decision. The plan is [`PLAN-2.4.md`](PLAN-2.4.md); its decision table runs
D26–D38.

---

## The headline

**The card stopped being mostly chrome.** Six of the twenty-four items were the
same complaint said six ways — the bottom row of a chart card held icons and
whitespace while the values indicator ate the title's width; every tier was the
same width when the content was not; the list carried a progress bar nobody
read. A card now carries a figure where it used to carry a checkmark, tiers are
sized to what they hold, and 100% zoom renders what 110% used to.

The other half is things that did not work: a tab that sometimes rendered
nothing, a dropdown you could not see, a button that went to the wrong page, and
an assistant that shipped in 2.3 and was invisible.

**And the two packages the plan did not contain are the ones worth reading.**
W76 was told to reproduce before fixing, and proved the template fixtures were
never the problem — the bugs were downstream, in the cloner and the catalogue
inserter. W77 finished an item another package had correctly refused to fake.

---

## 1. The chart card, tier widths and zoom (W69)

**The values indicator moved to the bottom row and became the figure** (item 4,
D26). It renders `€400k` for a risk, `0.4/yr` for an attack, `60%` for a
defence, and a two-segment bar — current and target against a full-width
inherent ground — for the target of assessment. Unset renders `?` in the
existing missing-value styling.

Three constraints hold it together, and all three are easy to get wrong:

- **A defence's figure is per occurrence.** Strength lives on the
  `attack_defence` pivot, so the same defence under two attacks shows two
  different percentages, and both are correct. The edge is a prop rather than
  something looked up from the uuid.
- **A qualitative value is not a number.** Such a node carries a `*_level_id`
  and no amount; the level's short code is rendered. `ScaleMapper` is the only
  thing that turns a level into a figure, and it does that for the engine
  against the assessment's own calibration — not for a card.
- **The space stays reserved in both states.** Filling a value in must not
  reflow the card, which is why W37 made the indicator permanent in the first
  place.

W37's argument that the chart carries no numbers held while the alternative was
four ramps in four visual languages. One short figure in a fixed slot is a
different proposition.

**Tiers get different widths** (item 7, D27): root 2, risk 1.5, attack 1.25,
defence 1, times a 176px base. The layout algorithm did not change shape, but
the recursion split into measure-then-place — with a single width a parent could
never be wider than its children, so the clamp was never exercised; a root over
one risk is now the ordinary case.

**Zoom is rebased** (item 5, D28). One exported constant, `CANVAS_BASE_SCALE =
1.1`, multiplied into the chart's transform and the list's zoom.
`CANVAS_ZOOM_DEFAULT` stays at 1, deliberately — moving *it* to 1.1 would leave
the control reading 110% on first load, which is the thing being complained
about. There is a test named for exactly that.

**The parent counter names the places** (item 11). It said "Appears in 3 places"
and did not say which; it now lists the parent titles, one per line, capped at
eight with "and N more". The same names go on the trigger's accessible name,
because the bubble is `pointer-events-none` decoration a screen reader never
reaches — without that this would be a sighted-only feature.

This needed a `#content` slot on `Tooltip.vue`, which **no package in the plan
owned**. W69 stopped and reported rather than editing it or duplicating its
markup; ownership was extended and it finished the item. That is the ground rule
working as intended.

---

## 2. The list view (W70)

- **A narrower reading measure** (item 8). `max-w-4xl` on the `<ul role="tree">`
  rather than on the scroll container, so the container still scrolls the full
  viewport and the cap sits on the element carrying CSS `zoom` — the measure
  grows with the type. Print is genuinely unaffected: `print.css` fixes type at
  9pt and 64rem is wider than an A4 text column, so the cap never binds on paper.
- **The completeness bar is gone from the root row** (item 22). A bar states a
  fraction of a whole, and the whole — how many risk/attack pairs exist — was
  never on the row and could not be inferred from it, so the fill was a shape
  without a scale. The figure is unchanged and still reachable in Statistics and
  in the report. `Bar.vue` stays; the report uses it and so does W69's ToS bar.
- **Every entered value is a `ValueChip`** (item 23). The plan had this
  inverted — the chip already covered root, risk and attack, and the *defence*
  was the holdout, rendering a bare sentence. It now reads `Reduces likelihood:
  40%`, the reduction target as label and the strength as value, which keeps
  W29's finding that the number and the words are one fact.

Two correctness points are pinned: an unset `reduction_target` is the documented
default "overall loss", **not** missing — a `?` there would collide with the `?`
that means a missing strength — and strength is read from the edge, never from
the defence.

---

## 3. The node modal (W71, W72)

### The Details tab that sometimes rendered nothing (item 1)

**Both candidates reproduced**, and the tests were written against the
unmodified component first.

`Tabs.vue` mounted every panel — `v-show`, not `v-if` — so opening any node
mounted all eight, and History, Comments, References and Statistics each fetched
on mount. A throw in any one of them during render took the whole subtree down
and Details was the visible casualty. Stubbing one tab to throw did not merely
fail an assertion: the error propagated out of `mount()` and nothing rendered at
all, which is the reported symptom exactly. Separately, `DetailsTab` was
`v-if="node"` with no else, so a `?node=` deep link restored before hydration
produced a literally empty modal.

Panels are now lazy — `v-if` until first activation, `v-show` after, so a tab
keeps its state once opened — and the shell renders distinct *loading* and
*gone* states. `NodeModal` keys `<Tabs>` by `node.uuid`, without which lazy
activation leaked across nodes: opening Comments on one node eagerly mounted
Comments on the next. Four fetches no longer fire every time anyone opens any
node, which is the same class of waste W68 removed on the write path.

### The rest of the shell

- **Tabs wrap, they do not scroll** (item 16, D30). Eight tabs overflowed an
  `lg` modal, and an `overflow-x-auto` strip with no scrollbar affordance hid
  the last two with nothing to say they were there.
- **The type is an icon left of the title** (item 24), not a chip next to
  Delete. `Modal.vue` gained a `#title-prefix` slot.
- **"Move or link" is padded** (item 3) — on the dialog, not on `Modal`'s body,
  because padding the body would double-pad the four consumers that already
  handle it, `NodeModal` included, where the tab strip is deliberately flush.
- **The dropdown is teleported and viewport-anchored** (item 6, D29), with a
  flip when there is no room below. `aria-controls` and `aria-activedescendant`
  survive teleporting and are asserted; the outside-click handler no longer
  relies on containment, since the listbox is no longer a descendant.

### The tabs (W72)

- **A defence's Cost tab is about cost** (item 9). Capex, opex and the
  annualised total stayed; status and the transfer toggle moved to Details. The
  read-only strength summary was deleted rather than moved — strength is the
  Strength tab's subject, per edge, one click away on the same node.
- **The ToS gets a Scales tab** (item 10). It reads and writes **the same
  controller and the same request objects** as the settings page — no second
  write path to the same columns. Two shapes needed explaining and are
  commented: the read is a hand-made Inertia request because `Assessment/Show`
  is not sent `currencies`, and the write is axios rather than `router.patch`
  because the controller's redirect would throw the user out of the assessment.
- **"Appears under" left the Details tab** (item 12, D31) — see §7.

---

## 4. Navigation and the dashboard (W73)

- **One location for the sidebar toggle** (item 18). The defect was that the
  button which changes the width *moved when the width changed*. W41's reason
  for splitting them — two 16px targets in a 48px column is a coin toss — is
  real, so the dedicated row stayed and the logo row's control moved into it.
  The test asserts the control's **row index**, identical at both widths, rather
  than that a toggle exists: the latter was true before the fix, which is the
  defect.
- **"New assessment" creates one** (item 19). `POST /assessments/new`, inside
  the *My assessments* group. It takes no request body at all and writes the
  placeholder title the editor already renders for untitled nodes.
- **"Shared with me" is a page** (item 20, D33). The query is scoped by
  membership **first** and policy-checked **second**, so the policy pass can
  only remove rows — it cannot reintroduce the public-assessment leak it would
  be on its own.
- **The dashboard is two sections of tabs** (item 21, D32): *Notifications*
  (Assigned to me · Mentions) and *Assessments* (Mine · Shared with me). The
  four empty states survive as tab-panel empty states. Counts ride on the tab
  labels as plain text inside the button, so they are part of the accessible
  name — a tab whose count is hidden until you click it is a notification you
  will not see.

**On history**, deliberately neither log: `assessment_operations` records
*within*-assessment changes and is a per-assessment undo stack, `node_revisions`
records edits to nodes that already existed. An assessment's creation is where
both logs start, and undoing it is `assessments.destroy`.

---

## 5. The report and five export formats (W74)

**One assembled report, five renderings of it.** `ReportAssembler` →
`ReportSectionBuilder` → five exporters that only know how to write a heading, a
paragraph and a table. A `Cell` carries *what a value is* rather than what it
looks like, so Excel gets a summable number with the currency in the format code
while HTML and Word get the formatted string and CSV gets machine digits — all
from one source.

**A test proves nothing re-queries**: an exporter renders correctly from an
assembled array *after the assessment has been deleted*. Any exporter that
re-queried would be a second source of truth for the same figures, and they
would diverge.

| Format | Notes |
|---|---|
| HTML | Self-contained, inline CSS, no CDN — the CSP rule applies to a file that leaves the server as much as to a page served by it |
| CSV | **Widened** to the whole report; it was defences only, which is a quarter of what a report is |
| JSON | Shape kept stable — it is the snapshot/restore interchange format |
| Excel | `phpoffice/phpspreadsheet`, one sheet per section, headers frozen |
| Word | `phpoffice/phpword`, the prose sections in order, with the tables |

Both packages are LGPL, free and self-hosted. **PDF remains the browser print
pipeline** (D6, not reopened); `dompdf` appears in `composer.lock` only as
`require-dev`/`suggest` metadata inside the phpoffice packages and is never
installed.

**Measured, and kept as a test.** A 40-control assessment (8 risks, 40 attacks,
80 edges): assemble 36–51ms, HTML 39–50ms, CSV 6–10ms, JSON <1ms, Excel
517–755ms, Word 270–297ms. The slowest single format is ~755ms, comfortably
inside the "second or two" threshold, so **nothing goes to the queue**. A
companion test asserts the query count is identical at 4 and 40 controls, so
this cannot degrade into an N+1 that is fine at 40 and unusable at 400.

**"Download report" is gone and "Print as configured" is now "Print"** (D35).
The forced-full URL W33 defined survives as the export menu's *Everything
expanded* item — it is what the HTML and Word exports render from. The e2e test
that pinned it was renamed and repointed, not deleted.

### The Docker build this required

Both phpoffice packages hard-require `ext-gd`, which was missing from **both**
Dockerfile stages — verified, not assumed: the app container and the `composer:2`
image both lack it, so `composer install` failed in the vendor stage before the
runtime stage was reached.

The fix splits the problem. The vendor stage resolves with
`--ignore-platform-req=ext-gd`, **scoped to one extension by name** so a
genuinely missing requirement in a future dependency still stops the build —
that stage never runs the application, and with `--no-scripts` it fetches dists
and writes an autoloader, so its own extensions are not the ones that matter.
The runtime stage really installs gd (`--with-freetype --with-jpeg`) and then
**asserts in its own `RUN` step that gd loaded**. That assertion is what makes
the ignore honest rather than a hole, and as a separate step it fails at a line
whose name says what went wrong — folded into the install, a future edit
dropping `gd` would surface as a fatal in a queue worker on a running host,
hours later. Image size: 434MB → 466MB.

---

## 6. The assistant is findable (W75)

**It was never missing.** W50–W54 shipped it. It was invisible because three
things must all be true and by default none is: `ASSISTANT_ENABLED=true` in the
environment (the routes are not registered otherwise, deliberately, so the
feature 404s rather than 403s), the profile-gated container running with a GGUF
mounted from the host, and the admin toggle on — which does not render at all
unless the first two hold.

So the deliverable is **words and a diagnosis, not a switch** (D37; D25 not
reopened). The admin settings page now carries a **permanent panel**. Where the
assistant is not configured it is entirely words: what the feature is, that it
runs on this server and sends nothing off the machine, that its absence is
normal and fully supported, the three switches in order, and a pointer to the
new [`ASSISTANT.md`](ASSISTANT.md). Where it is configured, the panel reports
the runtime state it already knows.

`docs/specs/deployment.md` §5 had **no `ASSISTANT_*` row at all**, which is part
of why the feature was undiscoverable. It has them now, plus a paragraph saying
that setting them is not enough and that an operator who sets them and sees
nothing is looking at correct behaviour.

**No gate was weakened**: no routes registered without a runtime, no "try
anyway" affordance, no prompt changes, no `MEASURED:` pin touched.
`ASSISTANT.md` states D24 plainly — nothing a model generates reaches the graph,
the report or another user without a person accepting it first — and carries the
measured latencies so nobody enables it on a two-core VM and files a bug.

---

## 7. "Appears under", and the affordance that moved (item 12, D31)

The section is the **only** place in the application that can detach an attack
from one of several risks, so D31 moved it rather than deleting it: off the
attack's Details tab and onto the parent counter's popover, which is where a
reader asks the question.

**It is wired per type, not swapped wholesale.** `AppearsUnderPopover` renders
nothing for a non-attack, and D31 is entirely about attacks under risks, so an
unconditional swap would have *deleted* the shared-defence counter rather than
upgrading it. An attack's counter is the interactive popover; a shared defence
keeps W69's read-only naming tooltip. Both cards use the same shape, because two
views must not explain one figure differently (ui.md §3.2).

### A consequence, decided rather than discovered

The counter only renders above **one** occurrence — long-standing card
behaviour, and D26 deliberately keeps chrome off the card. The popover carries
both affordances, detach *and* link-under-another-risk. So **an attack under a
single risk no longer has a keyboard path to a second link**; the W38 drag
gesture is the only one.

This was raised and decided, not overlooked. It is recorded here because it is a
capability that existed in 2.3 and does not exist in 2.4. The mitigation already
shipped, in W40: typing a title in the list view's add-a-child field **suggests
existing attacks and links the one you pick instead of creating a duplicate** —
which catches the problem at the moment a duplicate would be created rather than
offering a repair afterwards. That path does not exist in the chart view, where
a new node is created and its title typed in the modal; see *Still open*.

`tests/e2e/03` now builds its DAG with the drag gesture, because
`07-duplicate-highlighting` depends on that step producing a two-parent attack.

---

## 8. The template audit that found the bug somewhere else (W76)

**Reproduce first** (D38). The five assessment fixtures and two catalogue
fixtures were audited while the plan was written and found structurally
complete; W76 re-derived that audit independently from the JSON and matched it
exactly. So the emptiness reported was not in the fixtures, and rewriting
correct content would have left the real bug in place.

- **Step 1, the seeder: clean.** Every fixture lands exactly as declared —
  nodes, edges, tags, references, every title, description, impact/likelihood
  mode-and-value and per-pivot strength. Both catalogues seed all 62 entries and
  all 63 bundle links. Zero divergence.
- **Step 2, the cloner: diverges.** See §9.
- **Step 3, the inserter: diverges.** See §9.

**A third finding, and it is the one that explains what was actually seen.**
`root_uuid` is an existence check, so a fixture that has seeded once is **skipped
whole forever** and every later content edit is invisible to that instance —
with `db:seed` reporting success. Reading the development database confirmed it:
two archetypes hold *more* nodes than their fixtures, i.e. seeded from an older
file or hand-edited. That default is correct and stays, but it means "the fixture
is correct" and "the instance is correct" are two different claims.
`TemplateAssessmentSeeder`'s docblock now carries a per-fixture reseed procedure
and the trade-off is pinned as a test.

Both fixture validators gained the rules the investigation showed were missing —
a non-empty description per node, an attack no risk names, duplicate refs within
one list, a risk bundling no attack. A fixture that passes validation and seeds
an empty tree means the validator is not checking the thing that matters.

One content change, not a structural one: an archetype root titled "Aggregate
SaaS" became "Multi-tenant SaaS — cloud-hosted B2B product". A company name where
its three siblings all carry a category.

---

## 9. Three defects in the cloner, and one in the inserter (W78)

W76 found the first two and pinned them; W77 found the third. All three are
fixed.

**Every `node_references` row was silently discarded on clone.** Measured across
the fixtures: source 5 / 7 / 8 / 6 / 3 references, clone **0**, every time. This
is the template-instantiation path, so every CVE/CWE/CAPEC/ATT&CK citation a
template author attached vanished the moment somebody started an assessment from
that template — structure intact, part of the content gone, nothing erroring,
because `node_references` is a plain child of `nodes` with no NOT NULL pointing
back. Now 5 / 7 / 8 / 6 / 3 in the clone too, via one SELECT and one bulk insert:
**2 queries per clone regardless of assessment size**, asserted, with the
per-row version turning that test red at 4.

`cloneSubtree()` was fixed too. W76's pin on `insertCatalogEntry()` turned out to
be **wrong** — its source is a `CatalogEntry`, which has no references relation
and no such column, so there was nothing to drop. That is documented in the
flipped pin rather than papered over with an invented assertion.

**The catalogue inserter created a duplicate node per edge it walked.**
`loadBundle()` guarded against revisiting a parent; the recursion below it did
not. One case shipped: a defence reachable from two attacks in the ISO
27001+27002 bundle arrived as **10 nodes for 9 distinct controls**. Over-full
rather than empty, which is why it survived — ALE stayed correct because strength
is per-edge, but cost rollups double-counted and the library showed the control
twice. Now one node with two edges, which is what a DAG is for.

**And the cloner could not clone a calibrated assessment at all.**
`cloneScaleIfCustom()` mass-assigned two identity columns. This is the **third
and fourth** time this trap has fired in this one class — CLAUDE.md records two
in W6 — and the scales path had no coverage, so the suite stayed green over it.

The two halves failed **differently**, and only one was loud:

- `scale_levels.scale_id` is NOT NULL, so the dropped value died on the
  constraint. The insert's own SQL is the proof: `scale_id` absent from the
  column list entirely.
- `scales.assessment_id` is **nullable** — NULL means system scale — so the
  dropped value there wrote a *wrong row* without complaint, silently promoting
  a custom calibration to a shared system scale. It was masked only because the
  level insert two lines later killed the transaction first.

**A fix aimed only at the loud half would have left a silent data defect
behind.** Both are now guarded independently. The clone is built field by field
rather than `replicate()`d, so W77's new `set_key` column is left NULL by
construction — carrying it across would make the clone look like a member of a
system set, and every assessment creation on that set would start throwing.

---

## 10. The default scale calibration means something (W77)

`instance_settings.default_scale_calibration` was a free-text `varchar(64)`
defaulting to the literal string `default`, validated `required|string|max:64`,
and **read by nothing that branches on it**. Whatever an admin typed was stored
and ignored.

W75 was asked to fix it and **correctly refused**, reporting that it could not be
done inside its ownership and that the obvious first step was actively
dangerous. W77 was created for it. What the work actually needed:

- **A grouping key**, because the schema had no concept of a scale *set* at all
  — a set was an implicit trio sharing `is_system = 1, assessment_id IS NULL`
  and nothing else. `scales.set_key` is nullable, because it only means
  something for a shared system scale; an assessment's own calibration belongs
  to that assessment and to no set, and NULL says so rather than a sentinel
  every query would have to exclude.
- **A second system set**, seeded not migrated (schema.md §3.2), and a real
  alternative rather than a copy. `small-organisation` shifts impact down two
  decades: the default set needs a million-euro loss before it says "severe",
  which compresses every loss a ten-person firm will ever suffer into the bottom
  two bands, and a scale whose top three bands are unreachable cannot rank the
  risks it is used on. Level *codes* are identical across sets deliberately — a
  calibration says what "moderate" is worth, not which words exist.
- **A fix to `AssessmentCreator::systemScales()`**, which did `pluck('id',
  'kind')`. The moment a second set exists, two rows share `kind = 'impact'` and
  `pluck` silently keeps one arbitrarily. This was **confirmed empirically**:
  with two sets seeded, the whole suite stayed green while creation picked an
  arbitrary calibration. It now resolves by set and **throws** on a duplicate
  kind, with a fallback to the default set when a configured set has been
  deleted — creation is the path ordinary users are on, and they can neither
  diagnose nor fix an admin's setting.
- **A fix to `seedScale()`'s `firstOrCreate`**, which keyed on exactly what two
  sets would share. Adding `set_key` to the keys would have matched correctly
  and then, on the *create* path, had Eloquent silently drop it — writing a trio
  belonging to no set. That is the W22 failure in the same file, so the lookup
  is `first()`-then-`save()` instead.

**The migration was verified against real pre-W77 rows**, not just the test
suite: the test database is always built from empty and never exercises a
backfill. Rolling back, inserting a legacy-shaped trio plus an assessment-owned
scale plus `default_scale_calibration = 'strict'`, and migrating produced the
right three `set_key = default` rows, NULL on the assessment-owned one, and the
setting rewritten to `default`. That last rewrite is deliberate and goes beyond
the letter of the item: the column held whatever an admin typed into a box
nothing read, and the moment the field becomes a reference, a value naming no set
is either a validation failure on some unrelated save or a silent fallback
forever.

The guard that this cannot regress **asserts both sets in one test**, on
purpose. Selection by kind returns one fixed answer whatever the setting says, so
it cannot satisfy both halves — whichever set a reintroduced `pluck` happened to
keep, the other half goes red. A single-set assertion would have passed or failed
on row order, which is not a guard: with the setting on `small-organisation`
alone, the buggy version would likely have *passed*.

---

## 11. The `figures` option is gone (W80)

`ReportOptions::$figures` had been inert since W17 — parsed, validated, echoed
into the payload, honoured by no renderer. W74 found it while building the
export menu and **pinned rather than fixed it**, because binding it in the
exports alone would make a downloaded file disagree with the page it came from.
It was the only open pinned defect in the codebase.

**It is removed, and the argument is stronger than "nothing read it".** The
deciding constraint is `calculation-engine.md` §8.3, a normative table fixing
which displayed quantities have a scale at all: impact, ARO and strength each
have one; **pair, risk and assessment ALE have none and render as currency**.
§11.2 step 8 says it outright — *"Do not band-map ALEs."* So no option could
stop the report printing currency in every register row, every ranked list and
every programme figure. **A control labelled "Qualitative only" above that
document is a false statement about it.**

Three findings closed the question:

- **The honest half already happens, per node.** `RiskRegisterSection` branches
  on the node's own `impactMode` — currency where the author typed an amount, a
  level code where they picked one. That is exactly what item 23 specifies for
  the cards: the typed values are qualitative where the author made them so, and
  computed figures keep their existing treatment.
- **What remained was dishonest in both directions.** Forcing qualitative
  band-maps an amount the author elicited precisely; forcing full prints a
  level's `representative` as currency, inventing a figure — which §8.4's fourth
  pitfall calls *"the tool lying with a straight face"* on an uncalibrated scale.
- **The document had been contradicting the control since W17.**
  `ReportSectionBuilder` prints, to every reader, that computed losses are
  reported as currency and never mapped to a band. That line sat inside reports
  generated with "Qualitative only" selected.

`assessments.display_mode` already exists as the persisted, per-assessment
version of this idea (schema.md §3.3), so `figures` was a second control over
the same concept with none of its wiring.

The pin is **flipped in place** as `FIXED (W80)` guards asserting the absence of
the key and the constant — not a resolved default, because a reintroduced
`figures` defaulting to `'full'` would satisfy "the document is unchanged" while
being exactly the dead control that was removed.

---

## 12. "Link, don't duplicate" reaches the chart (W79)

W40 put the type-ahead in the list view's inline add-a-child field and
deliberately left the chart out, because the chart has no inline field: it
creates the node and opens the modal, so the title is typed there. §7's decision
made that gap load-bearing — an attack under one risk has no keyboard path to a
second link — so this closes it.

Two things decide whether it is correct:

- **The trigger is not "an attack with an empty title".** `ChartNode`'s
  add-child gesture already computed the created node's occurrence path and
  discarded it; that token is now handed to the modal and must name *this* node.
  It is reassigned on every open and cleared on close, so a pasted `?node=` deep
  link to an untitled node offers nothing, and a titled field is always a
  rename. Renaming an attack to match another is not a request to merge them —
  W40 refuses that case and so does this.
- **The placeholder is removed with `deleteNode`, not `removeIfEmpty`.** This
  field autosaves per keystroke, so by the time a row is chosen the placeholder
  is called "Phis" — and `removeIfEmpty` refuses titled nodes, which would leave
  standing the exact twin the feature exists to prevent. The order is link,
  then delete, then close: a failed link costs a retry rather than a stray node.

`Input.vue` gained two optional props (`role`, `expanded`) defaulting to
`undefined`, so every existing input renders identical markup. They are props
rather than fallthrough attributes because fallthrough lands on the wrapper
`<div>`, and `role="combobox"` on something that is not the text box is a role
no screen reader can act on.

---

## 13. One chrome, shared by the editor and the report (W81)

Item 15's chrome was a **hand-made copy**: the report cannot use
`AuthenticatedLayout`, because a share-link guest has no `{name,email}` user for
it to render, so W74 reproduced the shape. It drifted, and the drift shipped —
the editor has *two* paddings above its toolbar and the copy reproduced one, so
the switcher sat 6px high. Horizontal parity was never reached at all, because
closing it needed `Toolbar.vue`, which W74 did not own.

Both are closed. `resources/js/Components/Chrome/` now holds the band, the title
row and the toolbar band — the last carrying **both** paddings — and the editor
and report render the same components rather than agreeing by inspection. Three
components rather than one because the editor reaches its header through a
layout slot and its toolbar through another, and one component cannot span two
slots of a layout it does not control.

**Horizontal parity is closed by construction, not by measurement.** The zoom
controls moved to the *left* of the switcher, and the switcher sits in an
`anchor` slot that is always last in the right-hand group. The editor fills the
`trailing` slot with zoom and the report with the options Close button, and
neither reserves space for the other — so the switcher's inset from the right
edge is the band's own padding and nothing else, for any siblings either page
adds later. **No empty spacer**, which W74 had refused as asserting the fix
rather than the property.

The end-to-end assertion was tightened to match: `report.fromRight < 200` became
`report.fromRight === list.fromRight`, with bounds so three pages drifting the
same way still fail, and the measurement base moved from a literal `1280` to
`clientWidth` — the report scrolls and the editor does not, so against a literal
the test would fail on a scrollbar rather than on drifted chrome.

`AuthenticatedLayout` changed by exactly one edit — its inline header markup
became `<ChromeHeaderBand>`, rendering byte-identical output with the slot
interface unchanged. It is the file with application-wide blast radius, which is
why that matters and why the full unit suite is the proof.

---

## Item 14 was dropped, deliberately

**"When making an assessment public, allow edits" is not in this release.** It
reverses defect #4 in [`specs/security.md`](specs/security.md): `is_public` meant
world-writable in 1.x, which let any logged-in user edit any public assessment's
contents, and `AssessmentPolicy` was written specifically to close it. Raised
with the author; the decision stands. Collaboration goes through a named
collaborator or a share-link token, both of which put a real user on every
revision row.

---

## Upgrading from 2.3.1

- **One migration**, adding `scales.set_key` and backfilling it. It stamps every
  existing system scale `default`, leaves assessment-owned scales NULL, and
  rewrites any `default_scale_calibration` that is not literally `default` to
  `default`. Verified against legacy-shaped rows, not only against a database
  built from empty.
- **The image must be rebuilt.** `ext-gd` is a new hard requirement of the Excel
  and Word exporters; an old image will not run the new code.
- **Two new composer dependencies**, both LGPL and self-hosted:
  `phpoffice/phpspreadsheet` and `phpoffice/phpword`.
- **Reseed templates if you want the content fixes.** `root_uuid` is an
  existence check, so a fixture that seeded once is skipped forever — see §8 and
  `TemplateAssessmentSeeder`'s docblock for the per-fixture procedure.
- **The assistant is unchanged.** Still off by default, still deployment-gated.
  If it was not running before, it is not running now, and the admin page will
  now tell you why.

---

## Verification behind this release

Run on the integration branch, not assumed:

| Check | Result |
|---|---|
| `vendor/bin/pint --test` | passed |
| `vendor/bin/phpstan analyse` (level 6) | no errors |
| `vendor/bin/pest` | **1,077 passed**, 5,056 assertions (1 deprecated, 2 skipped) |
| `npm run lint:check` | passed, `--max-warnings 0` |
| `npm run format:check` | passed |
| `npm run type-check` | passed |
| `npm run test:unit` | **696 passed**, 70 files |
| `docker compose up --build -d && curl -f /up` | 200, all six services healthy |
| `tests/e2e` (isolated stack) | **127/127**, 4.0 minutes |

### What the end-to-end run found, and why it had to be run

It is worth recording in detail, because this release is the strongest
argument yet for the rule that the suite runs once at the end: **the first
execution failed 105 of 127, and everything it found was real.**

No package could have caught any of it. The suite is not part of a package's
checks — it needs its own throwaway stack and an empty users table — so ten
packages each verified their own work and none of them saw the seams between
them.

- **105 of those failures were one root cause.** W73 gave the sidebar's "New
  assessment" its own POST endpoint creating an untitled assessment, and the
  assessment list already had a button of exactly that accessible name opening
  a dialog that asks for a title. Two identically named controls, two different
  behaviours, on one page — unresolvable for a test locator, and
  indistinguishable to a screen reader, which announces both the same way. See
  §4; the endpoint is gone and both controls now open the one dialog.
- **Two were genuine layout defects, in assertions their own package wrote and
  could not run.** The report's view switcher sat 6px above the editor's,
  because the editor has two paddings above its toolbar and the report
  reproduced one. The sidebar toggle still moved between rail and panel — the
  exact defect item 18 exists to fix — because the logo row and the toggle
  button each change height with the width. Both are fixed heights now.
- **The rest were tests pinning behaviour 2.4 deliberately changed**, inverted
  rather than deleted, keeping whatever part of the old reasoning survived. The
  chart-currency test now asserts the *abbreviated* form, which is the half of
  W37's argument D26 does not overturn.

Two of those rewrites are worth naming, because in both cases the test was
passing for the wrong reason or would have:

- `10-report`'s forced-full test clicked an export item that now **downloads a
  file rather than navigating**, so every assertion after the click was being
  answered by the report page still sitting behind the menu. It reads the
  downloaded HTML now, and additionally checks the no-CDN rule D34 requires of
  a file that leaves the server.
- `10-report`'s rate-limit retry helpers wait out a `Retry-After` of up to 60s,
  which cannot fit inside a 30s test — so a test that hit the limit failed
  *inside* the mitigation it was correctly performing. W74's five new export
  formats made that reachable. The per-test budget is 90s here; the limit
  itself is untouched.

One correction the integrator made to its own work, recorded because the
mistake is instructive: the first attempt at both geometry fixes **guessed at
the cause instead of reading the markup**, and one of them made the report
switcher worse (6px out became 12px). The second attempt read the editor's
actual structure. A pixel claim in prose is not a guarantee, and neither is a
pixel fix.

Every guard added across W69–W78 was **mutation-tested** — neutered, confirmed
red, restored, re-confirmed green. Three things that came out of doing it
properly, all worth recording:

- **A mutation that does not mutate certifies a guard it never touched.** One
  agent's `perl` did not match because Prettier had reformatted the target line;
  the suite came back green and would have read as a surviving mutant. Mutations
  are now verified as applied *before* the run is trusted, and a second case was
  caught the same way.
- **Two mutations survived and were not papered over.** One because the chosen
  fixture had a single reference per node, so a hardcoded position passed — the
  test was rewritten over all five fixtures with a precondition. The other is
  genuinely uncoverable given the bundle shape, and is kept as a defensive bound
  with that stated explicitly rather than claimed as covered.
- **A presence assertion is not a guard**, again. W73's first sidebar test
  counted toggles and stayed green with the button back in the wrong row; it
  asserts the row index now. W74's throttle test asserts the limiter's specified
  values, not that something named `throttle` is present.

**One environment note that cost several hours.** A worktree's `vendor`
directory must be **copied, never symlinked**: Pest derives its project root from
the location of its own vendor directory, so through a junction it roots on the
main checkout, stops applying `TestCase` to `tests/Feature`, and produces ~854
spurious failures all reading `Target class [hash] does not exist`. Nothing to do
with any code under test.

---

## Still open

### Pinned as tests (the index CLAUDE.md points at)

**None.** W80 closed the last one — `ReportOptions::$figures` — by removing the
option rather than binding it, and rewrote its block as `FIXED (W80)` guards
rather than deleting them, so the reasoning survives and the fix cannot be
quietly reverted (§11).

Six pins were opened during this plan and all six were closed inside it. Two
`MEASURED:` pins in `tests/Feature/Assistant/GuardrailTest.php` are unchanged and
are **not** defects this repository can fix; the mitigation for both is the human
accept step, which exists.

That the table is empty is a fact about today, not a rule — the same caveat
CLAUDE.md carries. It was empty at 2.3.1 too, and this release opened six.

### Known gaps

- **A single-parent attack has no keyboard path to a second link from the parent
  counter** (§7). Drag is one route; typing a title that matches an existing
  attack and accepting the suggestion (§12) is the other, and it is the one that
  catches the duplicate at the moment it would be created rather than offering a
  repair afterwards. The counter itself still appears only above a node that
  already appears more than once, which is deliberate — D26 keeps chrome off the
  card.
- **Accepting a chart suggestion leaves a short history trail** — one coalesced
  revision row for the partial title, then the delete — because the modal's title
  field has always saved as you type. The list view has no such trail. Matching
  it would mean converting that field to a draft committing on close, which would
  lose typing if the modal were dismissed. Judged not worth it, and recorded
  rather than hidden.

### Verification debt

Both items carried from 2.3.0 are **unchanged**:

- **The assistant benchmark has still not run on the deployment host.** It needs
  the machine; `MODEL=… tools/spike/assistant/threads.sh` settles it.
- **The end-to-end suite still does not cover the assistant.** It is off by
  default and absent unless a deployment configures a runtime, so a scenario for
  it needs a stack the others do not.

### Unchanged from 2.0.1

`master` in the original repository is still 1.x, deliberately, so both versions
can run in parallel. The git-history purge remains declined (2026-08-04) and that
decision is settled — see `security.md` §2.1.
