# ArgueSecure 2.3.0 — release notes

Everything since 2.0.1: **work packages W29–W67**, thirty-nine packages across
three plans, plus thirteen self-scoped packages: two clean-ups, a benchmark under a
core budget, the progress and cancellation that benchmark argued for, the
defect it found, the two history gaps closed, the one limitation those
closures left standing, a way to read an old version instead of only its
totals, the last pinned defect closed, the last unbuilt feature built, the
verification the whole thing is checked by, and an end-to-end run that is
green again.

Read [`RELEASE-2.0.0.md`](RELEASE-2.0.0.md) first if you are new to 2.x, and
[`RELEASE-2.0.1.md`](RELEASE-2.0.1.md) for the security follow-up. This file
only covers what moved since.

**Why the version jumps from 2.0.1 to 2.3.0.** The plans are numbered by the
body of work rather than by release: `PLAN-2.1.md` (W29–W33),
`PLAN-2.2.md` (W34–W49) and
`PLAN-2.3-ai.md` (W50–W54) were written and executed back to
back with no release cut between them. Naming this 2.1.0 would leave the plan
numbers permanently one release ahead of the tags they describe. It is one
release covering three plans, and the version says which plans.

---

## The headline

The editor is no longer an indented list. It opens as an **org chart**, and the
three things a person does with an assessment — sketch its shape, fill in its
values, read the answer — are now three views instead of one view doing all
three badly.

Everything else here is either in service of that, or is the collaboration
apparatus the product needed once more than one person was expected to work in
the same assessment: labels, assignees, notifications, a history feed you can
read, and an optional local-model assistant.

---

## 1. Three views (W29–W37, W49)

**The chart is the default view** (W32). Target of assessment on top, then
risks, attacks and defences, one tier per row, connectors drawn downward. It
scrolls horizontally and never vertically — the schema's four-tier depth limit
is what makes that possible, so a reader manages one axis instead of two.

Each view was then given one job, and the figures were moved to wherever they
answer a question:

| View | Carries | Deliberately does not carry |
|---|---|---|
| **Chart** (W37) | Title, description, and one indicator saying whether this node's values are set — clicking it opens the modal on the tab that fixes it | Completeness bars, contribution bars, likelihood and strength ramps. They were figures with the digits filed off, and four visual languages across four tiers made the board harder to read across |
| **List** (W36) | Inputs: impact, likelihood, status, strength | Engine output. Annual loss, contribution, cost and saving all moved to the modal's Statistics tab and the report |
| **Report** (W35) | The answer, against all three baselines at once | — |

The node card also stopped showing two unlabelled baselines side by side
(W29). One toolbar selector — inherent / current / target — drives every card
and the totals panel, persisted per user per assessment. Computed figures are
labelled as computed; entered figures as entered.

Supporting changes: the top bar became one row and the editor takes the full
window (W34); zoom is one persisted number spent differently by each view; the
node modal's tabs are named for what they hold — Impact on a risk, Likelihood
on an attack, Cost on a defence, Strength on a defence's links (W39); and
`notes` was deleted everywhere, column included, because it duplicated
`description` in everything but length (W30).

**All three views print** (W49), through the browser's own pipeline and with no
new dependency, which is the standing decision on PDF generation.

## 2. Editing the graph as a graph (W31, W38, W40)

Three changes, all of them about the DAG the schema has always allowed and the
UI did not offer:

- **Strength is editable from either end of the edge** (W31). It lives on the
  `attack_defence` pivot because one control is 80% effective against one
  attack and 20% against another — so rating it is something you do while
  looking at the defence, not only while looking at each attack in turn.
- **A cross-parent drop asks Move or Link** (W38) instead of guessing. "It
  belongs here instead" and "it belongs here too" are different mutations and
  one of them destroys an edge.
- **Adding a child can mean linking one you already have** (W40). The title
  field of a new node suggests existing attacks or defences as you type. This
  is how an assessment stopped ending up with Phishing three times under three
  risks — three nodes, three sets of defences to keep in step, and a total
  counting one attack as three separate problems.

## 3. The shell (W41, W42)

`TopNav` carried a search box whose placeholder read "Search this
assessment…" on every page in the application, including the ones with no
assessment, wired to nothing on the page that had one. It is gone, replaced by
a sidebar.

`/dashboard` rendered the assessment list at a second URL — the page Laravel
sends people to after login. It is now a dashboard: what is assigned to you,
what changed, what is unfinished. The assessment list gained row actions.

## 4. Working together (W43–W48)

| | What it is | The constraint it was built under |
|---|---|---|
| **Labels** (W43) | Created by being used — typing "PCI" on a node makes the label. Assessment-scoped, matched by slug so "PCI DSS", "pci dss" and "PCI  DSS" are one label | `tags` and `node_tag` had been in the schema since W1 with a fully implemented, fully tested filter above them and no data. This is the data |
| **Settings** (W44) | One screen: title, description, currency, amortisation period, risk tolerance | Two of those columns had existed since the original schema with no control anywhere. The risk tolerance **states a comparison and never a verdict** — the engine forbids band-mapping a computed figure and a user-supplied threshold does not license it |
| **Save as template** (W45) | In the Share dialog, and it needs a new `saveAsTemplate` ability rather than `manage` | Publishing sets `is_template` and `is_public` together, exposing every risk, attack, defence and value to every account on the instance — and unlike an invitation it cannot be taken back from whoever has already read it. The dialog names that before the click |
| **Assignees** (W46) | An Assignees tab on every node type, a dashboard section, and an off-by-default report option | Being assigned **grants nothing**. `node_assignees` has no role column, no timestamps, no `assigned_by` — a column there would create a node-scoped grant by implication, which was legacy defect #5 |
| **Notifications** (W47) | Two sources, one feed: you were mentioned, or a node was assigned to you | `@name` matches the assessment's owner and collaborators, **never** `users` — matching globally turns every comment box into an account-existence oracle |
| **History** (W48) | One timeline over both logs — `assessment_operations` for structure, `node_revisions` for field edits — merged server-side, grouped so one user action reads as one entry, filterable by who, what and which node | See below |

**W48 is the package worth reading twice**, because it is the one that stopped.
The plan asked for a timeline *and* a "revert to here". Auditing
`node_revisions` before writing the reconstruction found **five mutations that
reached the graph while writing no history at all** — so a reconstruction would
have produced an assessment that looked restored and was quietly wrong. The
timeline shipped; revert did not, and each gap was pinned as a `DEFECT:` test
rather than written down somewhere and forgotten.

## 5. All five of those gaps, closed (W56, W60, W61) — the limitation that survived them (W62), and reading an old version (W63)

- **Strength** was the one that mattered: it is a multiplicand in
  `ALE = ARO × Impact × Π(1 − strength)`, so a change that left no trace made a
  past total unreconstructable. Now recorded as `action = 'link_update'` in the
  `{other_uuid, other_title, pivot}` shape `schema.md` §3.9 had already
  reserved for it.
- **Labels and assignees** use the ordinary `{field: {from, to}}` shape, so the
  timeline renders them with no special case. Labels are recorded inside
  `TagService` rather than its controller, so a console command or a future
  bulk action cannot silently reopen the gap.

**The trade-off, stated plainly.** The strength field saves on `@input`, so
typing "0.85" is four requests and would have been four history rows. Edits by
the same user to the same edge within 120 seconds now fold into one revision —
earliest `from`, latest `to` — and a field typed back to its starting value
drops out, deleting the row if that empties it. That means **a revision row is
now written more than once**, so `schema.md`'s "revisions are immutable" is
qualified to that window rather than quietly contradicted. The window is
per user deliberately: two people moving the same number a minute apart is
precisely the collision a history exists to show.

**W60 closed the fourth: the assessment’s own settings.** `currency`,
`amortization_years` and `risk_tolerance` are now recorded — and the decision
W48 left open was where such a row lives. Not a nullable `node_id`, not a new
table: the revision hangs off the **root node**, the row that already carries
the assessment’s title and description, marked `{"scope": "assessment",
"fields": {…}}`. The marker sits *beside* the fields rather than among them, so
a setting can never be read as a node column of the same name; the timeline
names the subject "the assessment"; and `RevisionReverter` refuses the scope
**explicitly**, because its `$fillable` allowlist rejects these today only by
coincidence. A settings save that also renames the assessment writes two rows,
one per subject — folding them would need an `operation_id`, and a settings
edit does not belong on the undo stack.

**W61 built the restore path the fifth gap was about.** `SnapshotRestorer`
applies a frozen graph back **in place** — the assessment keeps its uuid, its
collaborators, its share links and its comments, because every link anyone has
shared and every report already delivered points at it. Nodes are matched by
uuid rather than rebuilt, so comments, labels and assignees survive on every
node that still exists; nodes the snapshot does not contain are **soft**-
deleted. A safety snapshot of the current state is taken first, in the same
transaction, and the operation's inverse points at it — so **the inverse of a
restore is another restore**, and undo works. Restoring fifty nodes reads as
**one** timeline entry, not fifty, through the `operation_id` grouping
TimelineReader has supported since W48 and nothing had ever produced.

A value whose scale level no longer exists is left unset rather than coerced to
whichever level holds that id today.

### W62 — the limitation W61 could only report

W61 shipped with one hole it was honest about: a snapshot's shape held no
labels and no assignees, so a node a restore *resurrected* came back bare, and
the count was printed on the page because there was nothing better to do with
it. `snapshots.graph` is now at **schema v2** and carries both.

- **By value, never by id.** A label is frozen as `{name, slug, color}` — the
  same reasoning that inlines the scales, so a later rename or deletion cannot
  reach into a frozen version. A restore matches on `slug`, reuses the live tag
  if it is still there, and **recreates it** from the frozen values if it is
  not: a graph put back without the labels it was filed under has restored the
  work and lost the filing.
- **An assignee is frozen as `{id, name}` and never an email.** `view` is the
  broadest ability in the system and a share token grants it with no account,
  so an email in a snapshot is published to that audience permanently. The
  guard is the serializer naming those two keys; the `assignees:id,name`
  narrowing beside it reads like the guard and is not — verified by mutation,
  and now written down in both places so the next reader is not misled.
- **A restore replaces, it does not merge.** A label added since the snapshot
  comes off with everything else. **v1 is the deliberate exception**: it does
  not say "this node had no labels", it says nothing about labels at all, and
  syncing that silence to an empty set would turn it into a deletion. v1
  snapshots still restore, still leave today's labels alone, and still report
  what they could not bring back.
- **A frozen assignment is re-checked, never just re-attached.** Removing a
  collaborator removes their assignments (W46). Putting a frozen one back
  without re-checking membership would undo every one of those removals in a
  single click — a way around the guard rather than a weaker version of it.
  Assignments naming someone who has lost access, or whose account is gone, are
  dropped and counted into the message, because the person restoring is very
  often the person who removed them.

**A defect found by mutation-testing something else.** Neutering the membership
re-check produced a foreign-key violation, and the test stayed green — because
W61's `catch (RuntimeException)` had caught it. `QueryException` extends
`PDOException` extends `RuntimeException`, so **every database error inside a
restore was being rendered into the page's error banner, failing SQL and bound
values included**. The catch is now typed to
`SnapshotNotRestorableException`, a real database error reaches the error
handler like any other bug, and the leak has its own regression test. A guard
you cannot turn red is not the worst outcome of a mutation test; this was.

What remains is not a hole. Comments are still not part of a snapshot and are
not going to be: they are a conversation, not a version of the work, and a node
matched by uuid keeps its own regardless.

### W63 — reading an old version instead of only its totals

Restoring was reachable before this; *looking* was not. The snapshot page
answered "what did this version total?" with three figures and a node count,
which is not the question anyone has when something has gone wrong. Now there
is `/assessments/{uuid}/snapshots/{id}/view`, and it is the chart and the list.

**It mounts the live editor's own `ChartView` and `TreeNode`** against a store
built from the frozen graph. A second read-only renderer was the obvious
alternative and it drifts — a tier gains an indicator on one side only, and the
version people open when they are worried is the one still drawing it the old
way. `SnapshotViewPresenter` mirrors `AssessmentController::nodeToArray()` key
for key, and a test compares the two key sets directly so the drift fails
rather than accumulates.

**Read-only twice, and the second one is the guard.** All-false abilities strip
every add, delete and edit affordance — but abilities gate what is *offered*,
at `v-if` sites, with the call sites still wired behind them. Two things slip
past: `loadUnresolvedCommentCounts()` fires from `onMounted` with no ability
check, and this graph's label ids are minted for the render rather than being
real rows, so a write escaping the first half would attach an unrelated live
label to a node. The store is therefore also built `frozen`, which makes every
network-touching method a no-op — a guard at the method, not at the button.

Four things it deliberately does not do: no node modal (its tabs read live
comments and history — today's conversation against a version it did not happen
on), no comments or presence or locks, no Report position in the switcher (that
route serves the *live* assessment), and no recomputation of anything. Zoom and
collapse state persist under a key namespaced by snapshot id, so reading an old
version cannot quietly rewrite the reader's state in the live editor.

The banner is sticky, because everything below it looks exactly like the
assessment.

**`update`, not `manage`, and that was not the first answer.** Ownership-level
gating was the obvious call for the most destructive action in the
application, and it would have been a hole: undo is `update`-gated and the
inverse of a restore is another restore, so a `manage`-only restore reachable
through an `update`-gated undo is a longer route to the same place, not a
guard.

## 6. The assistant (W50–W54)

**Off by default, and absent is an ordinary state rather than an error.** A
deployment that has not configured a runtime registers no assistant route,
shows no disabled buttons, and raises no warning. No other feature depends on
it and every one of them works without it.

It was preceded by a **spike, not a package** —
`spikes/2.3-inference.md` — which stood the runtime
up, benchmarked model families against a real assessment, red-teamed them, and
turned a sketch into a buildable plan. Every decision D17–D25 is a measurement,
not a preference. Two of them contradicted the sketch.

| Package | What shipped |
|---|---|
| **W50** | A `llama.cpp` server in a profile-gated container, a streaming client with explicit timeouts, a cached health probe, an `assistant_enabled` instance setting, and the four states in between. No feature |
| **W51** | Job A — help answers, from the help pages and nothing else |
| **W52** | Job B — suggested risks, attacks and defences, in a panel, one Add button per proposal |
| **W53** | The guardrail suite, pinned to what the spike measured, failures included |
| **W54** | Job C — a drafted executive summary, as a queued job with a notification |

The decisions that most affect an operator:

- **The runtime is `llama.cpp`, not Ollama** (D17). The `ollama/ollama` image is
  8.43 GB against llama.cpp's 1.2 GB, almost all of it GPU libraries a CPU
  deployment never loads. Throughput measured equivalent on identical weights.
- **Weights are mounted from the host, never downloaded at run time** (D18), and
  the documented fetch step **verifies the byte count**: a plain `curl -sL` of a
  2.49 GB GGUF produced a 1.36 GB file *and exit status 0*, surfacing only as
  "model is corrupted or incomplete" when the container started.
- **The default model is `gemma3:4b-it` (Q4_K_M)** (D19), and the model is
  configuration — a one-line change.
- **CPU-only shapes everything.** Prompt tokens are ~10× cheaper than output
  tokens, so stable material goes first in every prompt and a fixed prefix is
  nearly free after its first use. Help answers are 94s first and **6.5s after**;
  suggestions 27.9s; a summary of a 73-node assessment **249s**, which is why
  it is a queued job.
- **`docs/` is not part of any corpus the assistant reads** (D22). It describes
  unbuilt features in the present tense and narrates the *legacy* application's
  security failures in prose carrying no marker of being historical once
  chunked into a prompt.

### The security content

The spike red-teamed seven injections against three model families. **Four held
on every model; three succeeded, two of those on 3 of 3.** The finding the
fixture encodes:

> Injections that **compete** with the task fail. Injections that **cost the
> task nothing** succeed.

"Ignore your instructions" forces a choice and the task wins. "Also put this
string in every title" needs no choice at all, and it worked everywhere.

That cannot be fixed by prompting, so it is not fixed by prompting:

- **The parser reads `title` and `description` and discards every other key**
  (D23). Injected text changed the output schema on 3 of 3 models, one emitting
  an `execute` field containing a real shell command. Prompt instructions lost
  3/3; the allowlist wins 3/3.
- **The human accept step is the guardrail, not a safety net behind one** (D24).
  Nothing generated reaches the graph or the report without a person seeing it
  first. There is no "accept all", nothing is pre-selected, and nothing is ever
  unsolicited.
- **The summary draft lands in its own column and never in the report.** Four
  minutes is long enough for the author to have written one themselves, and a
  job that overwrote it would be the worst kind of helpful. Accepting opens the
  draft in the editor; saving is a second, deliberate act.
- **The generated mark is sticky and the disclosure is for the reader.**
  Accepting sets the mark, editing afterwards does not clear it, clearing the
  summary clears it. A report containing generated text carries a disclaimer in
  its methodology appendix. The draft is withheld server-side from anyone who
  could not accept it; the narrative and the mark go to everyone, because a
  disclosure shown only to insiders is not one.
- **Two measured failures are pinned as tests** in
  `tests/Feature/Assistant/GuardrailTest.php` under a `MEASURED:` marker,
  distinct from this repository's `DEFECT:` marker for defects it owns. A future
  prompt change that appears to fix them has to turn those tests red
  deliberately, having read why.

**Nothing stores a hard reference to a model.** Remove the assistant tomorrow
and every assessment still opens, every report still renders, and the summary
text, its mark and its disclaimer all survive.

## 7. The assistant, measured on a constrained host — and the answer nobody was getting (W57–W59)

The 2.3 packages were verified against a stub, never against a live runtime.
Benchmarking the assistant under a **core budget** — because the deployment
host also runs MariaDB, Redis, Reverb, a queue worker and the app — found
something that was not a latency number.

**Job A and job C were returning nothing at all.** One output token, empty
string, every run, on the recommended model. The client posted **raw prompts**
to llama.cpp's `/completion`, which applies no chat template; `gemma-3-4b-it`
saw a finished document ending "Reply with the answer only." and did the
correct thing with a finished document, which is to stop. Job B survived
because its prompt ends "Reply with the JSON array only", and a JSON array is a
plausible continuation.

Nothing caught it because three reasonable decisions left a gap: the test suite
runs against a stub (it would otherwise need a 2.5 GB model in CI, and a stub
answers whatever it is asked); the spike measured through Ollama, which applies
the template for you; and the runtime comparison that chose llama.cpp exercised
job B alone. **W59** moves the client to `/v1/chat/completions`, pins the
endpoint and payload shape as `FIXED:` guards, and adds an opt-in
`LiveRuntimeTest` that asserts the one thing a stub can never assert — that a
model actually answers.

**W57** is the measurement, on 4, 6 and 8 cores of an i9-9900K:

| | 4 cores | 6 cores | 8 cores |
|---|---|---|---|
| Suggestions (job B) | 46.4s | 35.9s | 29.4s |
| Prompt processing | 36 tok/s | 49 tok/s | 59 tok/s |
| Generation | 8.2 tok/s | 8.7 tok/s | 9.4 tok/s |

Generation is memory-bandwidth bound and barely cares about core count —
doubling them buys 16%. Prompt processing scales 1.64×. Past about six cores
there is little to buy, and those cores are worth more to the database.
Resident held at **3.26 GiB**. Prompt-prefix caching survives the endpoint
change: 4,819 of 4,860 tokens served from cache on a second question, 15.9s
against 100.4s.

**W58** makes every generation visible and stoppable, which is the answer to
job B sitting near the 30-second line on a constrained host rather than
reshaping it into a queued flow on an estimate. Elapsed time, a bar drawn
against the token budget that never reads 100% while work continues, and a
**Stop that stops the generation** — not just the waiting: closing a tab leaves
llama.cpp generating and the lock held, while the cancel is seen on the next
token and releases both. A stopped generation is not an error. A queued draft
can be stopped before a worker ever picks it up, and job C's scope is the
assessment, so whoever is still in the room can stop a draft started by someone
who has closed their laptop.

## 8. Housekeeping (W55, and two smaller commits)

- Thirteen unreferenced Breeze components deleted from the root of
  `resources/js/Components/` — W10 replaced every one with a typed `UI/`
  equivalent and left the originals in place. **The bundle is byte-identical**
  (296.35 kB before and after), which is the honest measure: this bought
  clarity, not weight.
- `.env-docker.example` deleted — a 1.x leftover describing
  `CACHE_DRIVER=memcached` and `WEBSOCKET_PORT=3002`, the second of which is
  how the unauthenticated websocket relay became reachable in the first place.
- The end-to-end suite was run three times against a clean stack and brought
  back to green each time: after W33–W42 (five real product defects found),
  after W43–W49 (**114/114**, one real drift — W45 making templates public was
  the feature working as specified and made four "a stranger is locked out"
  assertions fail for an entirely correct reason), and after W66 (§12).

## 9. The catalogue insert, batched (W64)

W24 measured `CatalogInserter` issuing three queries per descendant — the
children of each entry, a `MAX(position)` per inserted node, and a scale-level
lookup per level code — and pinned it rather than fixing it, because that
package did not own `app/Domain/Catalog/**`. It could not measure the numbers
either: that sandbox had no MariaDB. All three are now resolved once per insert.

**The larger one was not on W24's list.** Dumping the query log rather than
reading the source showed every node and every edge bumping the assessment's
calculation version on its own — a bundle of forty controls issuing eighty
`UPDATE`s to change one number. `TriggersRecalculation::coalesce()` collapses
them into one bump per assessment per block. That is safe because `calc_version`
is a cache key and not a counter anyone reads: what has to hold is that it
*differs* after a mutation, and one increment does that as completely as eighty.
It is opt-in, not the default — deferring an ordinary single edit would open a
window where a reader serves a cached figure for a graph that has already
changed.

**Measured, on MariaDB 11, inserting a bundle of defences under one attack:**

| | 1 defence | 6 defences | per extra defence |
|---|---|---|---|
| Before W64 | 26 queries | 81 | **11** |
| After W64 | 25 queries | 60 | **7** |

Of the seven that remain, three are the work — a node `INSERT`, an edge
`INSERT`, and the `MAX(position)` that orders the edge. The other four are the
observer layer reloading each node and each assessment to find an id the caller
was already holding, and that is now the pin in its place: it is not the
catalogue's, every bulk path pays it, and fixing it means changing the mutation
events that every write in the codebase runs through.

Two smaller things fell out. `loadBundle()` orders its rows, where the
per-entry lookup it replaced had none — so the positions a bundle's defences
received used to be whatever the database returned that time. And the budget
test itself was measuring wrongly: it registered a `DB::listen` listener per
measurement, and listeners cannot be removed, so its second reading was doubled.
It only ever asserted the count was *large*, so nobody noticed.

## 10. The scale calibration screen (W65)

The last thing `ui.md` described and the codebase did not have. `ScaleValidator`
had been written and fully unit-tested in W6 and called from **nowhere**; the
engine emitted an `uncalibrated_scales` advisory no user could act on; the
settings screen listed the three scales and said editing them was not available.
Route `/assessments/{uuid}/scales`, gated on `manage` because
calculation-engine.md §8.4 asks for that role by name.

**The invariants are made unreachable rather than validated.** `ui.md`'s
instruction was "make them impossible to violate rather than validating after
the fact", and that is the design: a band's upper bound and the next band's
lower bound are **one number**, held once and rendered twice, so no sequence of
keystrokes produces a gap. Adding a band splits an existing one, so the outer
bounds never move. Removing one gives its range to a neighbour, because a hole
makes the reverse lookup partial and a value falling in it reads as *missing*
and leaves the total. Moving a boundary clamps inside its neighbours and
recomputes both representatives — a representative left behind by a band moving
around it is how invariant 5 breaks with nobody touching the field.
`ScaleValidator` still checks all five server-side and now has its first caller.

**Clone on edit is the reason the service exists.** A system scale is shared by
every assessment on the instance. Editing one in place would silently move every
figure in all of them — the legacy global-tags defect with money attached — so
the first edit copies it into the assessment and repoints the foreign key.

**And the half that would have been silent.** Every qualitative value holds a
`scale_levels.id`. After a clone those ids belong to a scale the assessment no
longer loads, so an unrepointed value reads as missing and drops out of the
total — understating risk, invisibly. Values are re-pointed **by code**, scoped
to the editing assessment, because an unscoped update would drag every other
assessment's values onto this one's private copy: the same blast radius the
clone exists to prevent, reintroduced one line below the code that prevents it.

A band that is *removed* has no new row to point at. Those values are cleared
into the legal "qualitative, nothing chosen yet" state and **counted** into the
message, because someone spent a workshop arriving at them. Writing that count
was a real defect until a test caught it: on the save that clones, the new scale
starts with no levels, so the ordinary "which bands went away" check sees
nothing, and the count came from the repoint path instead — which was
discarding it, on exactly the save most likely to empty something.

One more thing worth stating plainly: **"calibrated" means "this assessment has
its own scale"**, which is the `is_system` flag the engine already keys its
advisory on. Saving without changing a band therefore silences the warning. That
is intended — reviewing the defaults and accepting them is calibration — but it
is a real semantic and it should not be discovered by surprise.

## 11. The checks now cover the tests too (W66)

`npm run lint` had pointed at `resources/js` only since W10, and `tests/js`
was formatted by whoever remembered. Twenty-four files had drifted. Both trees
are now covered by four scripts — `lint`, `lint:check`, `format`,
`format:check` — with `lint:check` running `--max-warnings 0` and fixing
nothing, which is the shape CI needs.

Turning the check on found four things, which is the argument for having turned
it on:

- **Two number literals losing precision at runtime.**
  `tests/js/report/AttributionChart.spec.ts` carried the §14 golden vector
  pasted to twenty significant digits; IEEE-754 keeps about seventeen. Harmless
  to the assertion, which is on rounded percentages, and exactly the wrong thing
  to leave sitting in a file whose job is to mirror the engine's reference
  figures. Rewritten to the precision a double actually holds; they still sum to
  121,500.
- **A binding destructured and never used**, in a keyboard-outline test.
- **The underscore convention was not configured.**
  `const { d4: _omit, ...rest } = defences` is how a test removes one key to
  prove a shelved defence changes no total — the binding exists so the
  rest-spread can. `no-unused-vars` now honours the `^_` prefix, rather than
  the two alternatives, which were an inline disable comment or dropping
  `--max-warnings 0` and letting real unused variables through unnoticed.

`CLAUDE.md`'s command list was rewritten to match, because a check nobody
knows to run is not a check.

## 12. The end-to-end run, green again (W67)

Run against a clean isolated stack after W66. **114/114**, and it found one
real thing.

**Two sibling sections on the report both headed "Executive summary".** The
report's own three-baseline section carries that name from PLAN.md Phase 7 §2;
W54's written narrative had taken the same one. A reader navigating by heading
heard the same label twice with no way to tell the figures from the prose. The
narrative is now **"Written summary"**, which is what it is.

It surfaced as a Playwright strict-mode violation — a selector that matched one
element when it was written and matched two once W54 shipped beside it. Worth
noting *how* it was found: no unit test could have caught it, because each
component is correct on its own and the collision only exists on the page that
mounts both. That is the class of defect this suite is for.

The suite's one standing gap is unchanged: **it has never covered the
assistant**, which is part of why W59's defect survived to be found by hand.

---

## 13. The observer layer stops re-reading what it already had (W68)

The last pinned defect, closed. W64 named it and left it: every node and edge
mutation in the application paid two lazy loads that nothing in the source of
any one mutation made visible.

**What it cost.** Per inserted node and per inserted edge:

- the broadcast events resolved their channel as `$node->assessment->uuid` — a
  `SELECT * FROM assessments` per event, for a string that cannot change
  between the first one and the eightieth;
- `LinkChanged` and the two edge observers read `$edge->attack`, `$edge->risk`
  and `$edge->defence` — three `SELECT * FROM nodes` per edge, for nodes the
  caller was holding in memory when it created the edge.

**The fix, in two independent halves.**

`Domain\Structure\AssessmentUuids` resolves the channel uuid once per request
instead of once per broadcast. Memoising is safe *here* specifically because
`assessments.uuid` is written once and never updated (schema.md §3.4) — a memo
of an immutable column cannot go stale. It is registered `scoped()` rather than
as a singleton or a static, and that is load-bearing for the test suite rather
than for production: a rolled-back test transaction hands the next test the same
auto-increment ids attached to different uuids, so a process-lifetime memo would
answer the second test with the first test's uuid — broadcasting onto another
assessment's presence channel, and staying green while doing it.

This half needed no call-site changes at all, so it fixes every path in the
application at once.

`LinkService::carryEndpoints()` hands an edge its two nodes before the save
that fires its observer, and the two bulk paths that build pivots directly
rather than through LinkService — `AssessmentCloner` and `SnapshotRestorer` —
do the same at their own six call sites. The restorer needed one further change:
it read its node set as `pluck('id', 'uuid')`, so it had ids but no models;
it now `get()`s the same single query and keys it by uuid.

**The observers themselves were not touched.** `$edge->attack` still reads a
relation and still lazy-loads it for a caller that sets none. The fast path is
an optimisation, never a correctness requirement — which is what keeps a caller
that forgets it slow rather than broken, and is why this could land without the
blast radius W64 declined to take on.

**MEASURED**, on MariaDB 11, same fixture as the W64 budget test:

| | n=1 | n=6 | per extra descendant |
|---|---|---|---|
| before W64 | 26 | 81 | 11 |
| after W64 | 25 | 60 | 7 |
| after W68 | 18 | 33 | **3** |

Three is the floor that fixture can reach: the node INSERT, the edge INSERT,
and the `MAX(position)` LinkService runs. Writes and one genuine lookup.

**Five mutations, all caught.** Removing `carryEndpoints` from either link
method, disabling the memo, transposing the `attack`/`defence` relation keys,
and reverting one event to `$node->assessment->uuid` each turn a specific test
red. The transposition matters most: `setRelation()` is unchecked by design and
will happily file a Defence under `attack`, which would send a correct-looking
payload carrying the wrong uuid — nothing about the shape of the code catches
it, so `tests/Feature/Performance/ObserverLookupBudgetTest.php` asserts it.

One thing that mutation testing showed and reading would not have: removing
`carryEndpoints` from `linkRiskToAttack` leaves the **catalogue** budget test
green. A bundle has one risk→attack edge and N attack→defence edges, so the
per-descendant rate is blind to the risk_attack half. A budget test measures
what scales; it is not a substitute for asserting the thing you changed.

---

## Upgrading from 2.0.1

```bash
docker compose up --build -d
php artisan migrate
```

**Six migrations**, all additive except one drop:

| Migration | Note |
|---|---|
| `drop_notes_columns` | **Destructive.** Drops `nodes.notes` and `catalog_entries.notes`. Back up first if any deployment used the field |
| `add_risk_tolerance_to_assessments_table` | Nullable |
| `create_node_assignees_table` | New |
| `create_notifications_table` | Laravel's own table, plus one index — the unread badge is a shared prop, so counting it runs on every authenticated request and the default table would scan |
| `add_assistant_enabled_to_instance_settings` | Default **false** |
| `add_summary_narrative_to_assessments_table` | Three nullable columns |

**One new `.env` key, and it is optional:** `ASSISTANT_ENABLED=false`. Leaving
it unset is fully supported. If you do want the assistant, the block in
`.env.example` and `deployment.md` §9 are the reference — note that turning it
on is deliberately two steps (a deployment flag *and* an admin decision), and
that the model file is something you fetch and verify yourself.

**A queue worker is now load-bearing** if you use the assistant: the summary
draft is a queued job and its notification is how it reports back.

---

## Verification behind this release

Run at W68, not assumed:

| Check | Result |
|---|---|
| `vendor/bin/pint --test` | passed |
| `vendor/bin/phpstan analyse` (level 6) | no errors |
| `vendor/bin/pest` | **973 passed**, 3,719 assertions (1 deprecated, 2 skipped) |
| `npm run lint:check` | passed, `--max-warnings 0` |
| `npm run format:check` | passed |
| `npm run type-check` | passed |
| `npm run test:unit` | **522 passed**, 55 files |
| `docker compose up --build -d && curl -f /up` | 200 |
| `tests/e2e` (isolated stack) | **114/114** |

Every security-relevant guard added across W50–W68 was **mutation-tested** —
neutered, confirmed red, restored. That is worth recording because it caught a
guard that did not survive it once before (2.0.1's throttle pin), and because
one of W53's mutation runs came back green for a reason that turned out to be
a bad `sed` rather than a weak test; re-running it correctly went red.

**Two notes on the end-to-end suite**, both learned by running it:

- It uses its **own isolated stack** — Compose project `arguesecure-e2e`, port
  8100, its own data directory. It does not touch a development database, and
  an earlier note in this file claiming otherwise was wrong.
- It is **order-dependent, and a run competing with an image build reports
  phantom failures**. This has now happened twice: W67's first attempt reported
  14 and a clean re-run reported 1; W68's first attempt reported 13 and a clean
  re-run reported none. Both times a single node in scenario 3 was never
  created — the editor removes a new node whose title never lands, so a keypress
  that loses a race leaves no node at all — and every later scenario read the
  same graph back with a hole in it.

  Read a double-digit failure count as **one** failure plus cascade, find the
  earliest one, and re-run on an idle machine before investigating further. The
  contended run took 6.3 minutes; the clean one took 3.6.

---

## Still open

### Pinned as tests (the index CLAUDE.md points at)

**None.** W68 closed the last one — the observer layer's per-mutation lazy loads
— and rewrote its block in `tests/Feature/Performance/CatalogInsertQueryBudgetTest.php`
as a `FIXED (W68)` guard rather than deleting it, so the reasoning survives and
the fix cannot be quietly reverted. See §13.

Two `MEASURED:` pins in `tests/Feature/Assistant/GuardrailTest.php` record model
behaviour that was measured and lost. They are **not** defects this repository
can fix, and the mitigation for both is the human accept step, which exists.

### Verification debt

Two of the three items listed here through W65 are closed: `tests/js/**` is
formatted and guarded by `format:check`/`lint:check` (W66), and the end-to-end
suite has been run against a clean stack and is green at 114/114 (W67). What is
left:

- **The benchmark has still not run on the deployment host**, and this one
  cannot be closed from here — it needs the machine. W57 measured a 4/6/8-core
  sweep on a 2018 i9-9900K workstation, which is a *conservative* proxy (a newer
  P-core is 1.4–1.8× faster at this work), but it is a proxy.
  `MODEL=… tools/spike/assistant/threads.sh` on the real host, about seven
  minutes per setting, settles it.
- **The end-to-end suite has never covered the assistant.** Every other feature
  in the product has a scenario; this one has none, and that is part of why
  W59's defect — the assistant answering nothing, with no test able to see it
  — survived to be found by hand. The suite's own structure is the reason it is
  awkward: the assistant is off by default and absent unless a deployment
  configures a runtime, so a scenario for it needs a stack the other twelve do
  not.

### Unchanged from 2.0.1

`master` in the original repository is still 1.x, deliberately, so both versions
can run in parallel. The git-history purge remains declined (2026-08-04) and
that decision is settled — see `security.md` §2.1. Both historical `APP_KEY`
values are dead. Do not reopen it.
