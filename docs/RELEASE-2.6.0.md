# ArgueSecure 2.6.0 — two assistant models, installed in one command

> What `PLAN-2.6.md` asked for (W92–W97, decisions D52–D58),
> what shipped, what changed along the way, and what is still open. Follows
> [`RELEASE-2.5.0.md`](RELEASE-2.5.0.md).
>
> Branch: `v2/2.6-assistant-models`, off `master` @ `e1c6a59`, built in one
> session on one branch at the owner's request — the packages below are the
> unit of review and of the commit history, not separate branches. The
> benchmark's own commits are tagged `spike-2.6-models` and come into this
> branch as they were made, so the history shows the measurement before the
> change it justifies.

---

## The headline

2.5 asked whether the same thing existed in three places and differed in
each. 2.6 asks a narrower question: is the model behind the assistant any
good, on the machine that actually runs it?

The answer was no. Asked the one help question 2.3 chose because its answer
is counter-intuitive — *why doesn't my total change when I set a reduction
target?* — the shipped default answered it correctly once in four samples — twice it
answered a different question, once it quoted the formula without answering —
and invented figures in one drafted summary of four (one of them ten times
the real amount). Two presets that replace it — `small`
(Gemma 4 E4B) and `large` (Gemma 4 26B-A4B, a mixture of experts) —
answered the question correctly four times in four and invented no figures.
Their red-team outcomes are identical, case for case, to the default they
replace: being better at the task made neither preset easier, or harder, to
steer.

The rest of this release makes that finding shippable rather than academic:
installing a model is now one command instead of a page of manual steps;
the admin AI tab shows what the runtime actually loaded, not what an
operator typed into `.env`; the runtime settings are the 2.6 spike's own
measurements (four threads instead of eight, reasoning off by a supported
flag, a prompt-cache bound sized per preset); and a restart no longer makes
the next person's first question the slow one.

**Three things were found only by building this, not by planning it**: an
installer that would have "resumed" a corrupted-but-complete file forever; a
cache bound that fit `small` and silently evicted `large`'s help corpus; and
an upgrade path that would have stranded any 2.5 deployment naming its
model by the `ASSISTANT_MODEL` label, or naming nothing at all.

---

## 1. The benchmark record (W92)

D58 · `.gitignore`, `docs/spikes/2.6-models.md`, `tools/spike/assistant/candidates*`, the `spike-2.6-models` tag

The spike's two commits — harness, then results — come into this branch
exactly as made on the deployment host (an Intel i9-14900K, four cores given
to every configuration). `*.gguf`, `*.gguf.part` and `/models/` — the
compose file's default model path, inside the checkout — are now gitignored
everywhere; nothing had stopped `git add .` from picking up a
multi-gigabyte weight file there before this.

Seven configurations were measured: the shipped default, a QAT build of it,
that build with speculative decoding, two dense candidates and two
mixture-of-experts candidates. Beyond the quality finding above: the
deployment host runs job B in 18 seconds on four cores, well under the
mid-twenties-to-mid-thirties estimate 2.3 made; the prompt cache survives across every
architecture tested, including the sliding-window and hybrid-recurrent ones
that were a real risk to it; saving a slot across a restart does not help
any of them despite the save and restore themselves succeeding; and
speculative decoding is a net loss here, 2.5–3.8× slower.

---

## 2. The model catalogue, the launcher and the installer (W93)

D52–D54, D56 · `docker/assistant/**`, `docker-compose.yml`, `.env.example`

`docker/assistant/models.conf` is now the **one** list of presets — name,
file, publisher repository, revision, byte count, sha256, memory needed,
cache-RAM figure, label. The shell reads it here; PHP reads the same file in
W94, so the installer and the admin page cannot disagree about what `large`
means.

`docker/assistant/assistant.sh` is mounted into the pinned runtime image
(`ghcr.io/ggml-org/llama.cpp:server-b11176`, pinned by tag **and** digest)
and becomes its entrypoint:

`serve`, the default, resolves the model per D54, refuses to start on a
missing or wrong-sized preset and names the install command that fixes it.
`install <preset>` checks free memory and disk, downloads from the pinned
publisher revision with resume support into a `.part` file, verifies byte
count and sha256 before the file gets its real name, and records the
selection — re-running it on an installed preset re-verifies without
downloading again. `list` prints the catalogue and what is installed.

A new `assistant-install` compose service, in its own profile so `up` never
starts it, mounts the models directory read-write — the `assistant`
service's own mount deliberately is not. `ASSISTANT_THREADS` now defaults to
4, and the container's CPU quota follows it.

**A bug found in testing, not specified in the plan.** The first version of
`install` treated a file of the right length with the wrong bytes the same
as an interrupted download and tried to "resume" it — but resuming a file
already at its full length fetches nothing, so a corrupt file would fail the
same sha256 check forever. Fixed: a full-length file that fails its hash is
deleted and downloaded afresh; only a short `.part` file resumes.
Tested in the real image across nine scenarios, ShellCheck clean.

### The upgrade path this almost broke

Checking the upgrade path for these notes — 2.5's own `.env` shapes against
the new resolution order — found that 2.6's launcher honoured only one of
the three ways 2.5 named a model. With
`ASSISTANT_MODEL_FILE` set, `serve` ran that file as intended. But a 2.5
`.env` that set only `ASSISTANT_MODEL` — used as a label for the file's name
without `.gguf` — was refused as "not a preset"; one that set neither,
relying on the old compose default `gemma-3-4b-it-Q4_K_M.gguf`, was refused
as "no model is installed". Either would have stranded a working assistant
on upgrade — exactly what D54 exists to prevent.

`serve` now also accepts `ASSISTANT_MODEL=<name>` where `<name>.gguf` exists
in the models directory, and falls back to 2.5's default file when nothing
else is set — both checked after every 2.6 resolution path, so installing a
preset still takes over from either legacy shape. Tested across all seven
resolution paths with a fake server; README, `docs/ASSISTANT.md`,
`docs/specs/deployment.md` and D54 now describe the full order.

---

## 3. The admin tab shows what is installed (W94)

D55 · `app/Domain/Assistant/ModelCatalog.php`, `app/Domain/Assistant/RuntimeModel.php`, `config/assistant.php`, `Admin/AssistantController.php`, `Pages/Admin/Assistant.vue`

`ModelCatalog` parses `models.conf` — the same file the installer reads.
`RuntimeModel` asks the runtime's own `/props` which file it loaded and
matches it against the catalogue, returning a preset, a bare file name for a
custom model, or nothing when the runtime does not answer. Cached for
`health_ttl`, at the health probe's own connect timeout; probed only on this
one page, never on any path the availability check touches (D25).

The tab now reads a recognised preset ("Installed model: Gemma 4 E4B
(4-bit, quantization-aware) (the small preset)"), a custom file by name, or
"not known until the AI service responds", and lists both presets with the
memory and download size each needs. Installation instructions stay in
`README.md`, linked from the tab, as 2.5.1 decided.

`config('assistant.model')` is **removed** — it was a label an operator
typed and could say one thing while the container ran a different file — and
`/assistant/status` no longer returns a `model` field (see Upgrading,
below). Mutation-tested: returning the old label turns three panel tests
red; a catalogue accepting a short line turns the malformed-line test red.
The parser checks its numeric columns as numbers, not only counts columns: a
line missing one still splits into nine when its label has a space.

---

## 4. Warming the help corpus once per runtime start (W95)

D57 · `HelpAnswerer::warm()`, `routes/console.php` (`assistant:warm`)

A restart empties the runtime's prompt cache, so before this the next
person's first help question paid the whole corpus cold — 76–136 seconds on
the deployment host. Saving the cache across a restart was measured (W92)
not to help for either preset, so the fix warms it before anyone asks.

`assistant:warm` runs every minute, in the background, under a ten-minute
overlap lock, and returns
immediately unless the help feature is available **and** `/slots` reports a
slot with no `id_task` — which the pinned build reports only for a slot
that has never served a request, identifying "freshly started" without a
timer, counter or guess. When both hold, it sends `HelpAnswerer`'s own
prompt with a one-token budget, so the cached prefix is byte-for-byte the
one a real question reuses; after that the slot has an `id_task`, so the
job runs once per start. A busy runtime, a `--no-slots` one, and an
unreachable one are left alone rather than guessed about. The help page's
notice now says answers usually take seconds, and the first after a restart
can take a minute or two. Mutation-tested: dropping either the `id_task`
check or the busy check turns its test red.

---

## 5. What has to happen before D19 changes (W96)

`tools/spike/assistant/verify*`, `tests/fixtures/assistant/injections.json`, `tests/Feature/Assistant/GuardrailTest.php`, `LiveRuntimeTest.php`

The 2.6 spike recommended reopening D19 but named five conditions first, all
met on the deployment host through the **production launcher** — so a pass
here is a pass for what actually ships.

1. **Red team, ported and re-run.** `redteam.mjs`'s seven cases were ported
   from Ollama's retired `/api/generate` to `/v1/chat/completions`, run
   against the old default as a **control** as well as `small` and `large`.
   The presets matched the control on all seven cases; the control itself
   reproduced the 2.3 record on six of seven — `summary-canary`, held by
   `gemma3` through Ollama in 2.3, complied through the chat endpoint here, a
   difference in path or sampling, not model. Recorded as its own
   measurement, not papered over; a `MEASURED:` marker stops either run
   overwriting the other.
2. **`LiveRuntimeTest`, run against both presets on real runtimes** — `large`
   on the deployment host, `small` on a laptop.
3. **Quality at three further seeds:** the reduction-target question
   answered correctly 4 of 4 by each preset against 1 of 4 for the control;
   an invented summary figure 0 of 4 for either preset against 1 of 4 for
   the control; suggestion sets well-formed 8 of 8 for every model; nothing
   reasoned across the 54 replies checked.
4. **A prompt-cache bound sized per preset** — see below.
5. **`--reasoning off`**, the supported flag, everywhere a reasoning-capable
   model is served.

### The cache bound that fit one preset and silently evicted the other

Exercising the one shared slot across help, suggestions and a summary in
sequence, the single 2 GiB `--cache-ram` bound D56 first set held fine for
`small` but not `large`: a cached help or summary prompt on `large` runs up
to 1.13 GiB, and 2 GiB held only about two of them, so a third job evicted
the help corpus — the one thing the bound exists to protect. `models.conf`
gained a `cache_mib` column (2048 for `small`, 4096 for `large`, checked as
a number rather than assumed present), and `large`'s documented memory
budget rose from 24 to 28 GiB to bring the cache inside the figure a host
is sized by. D56 was amended in `PLAN-2.6.md`; README, `docs/ASSISTANT.md`,
`docs/specs/deployment.md`, `.env.example`, `docker-compose.yml` and
`config/assistant.php` all now say 28 GiB.

**Measured after the tag** (`verify-unraid-cache-large.json`, 2026-09-27):
at 4 GiB, `large` evicted nothing in the whole sequence. Every help question
after the first was served from cache — 6,527–6,529 of ~6,575 tokens,
including seed 2's first question, straight after a summary, in 9.7 s where
the 2 GiB run had taken 112 s — and the repeated summary was served 6,777
of 6,782 tokens from cache (25–33 s rather than 136–145 s). The server log
has no "making room for prompt cache entry" line; the 2 GiB run had eleven.

### Found in review

The branch was put through the repository's `code-review` skill before
release. Six of its eight findings were fixed:

- **The warm-up's overlap lock would have lasted a day.** Laravel's
  `withoutOverlapping()` defaults to 1,440 minutes, and a warm-up killed
  mid-run — the scheduler restarted alongside the runtime, which is what
  `docker compose up -d` does — never releases it. Every warm-up would have
  been skipped until the next day. Now ten minutes, and the test pins the
  value rather than the presence.
- **One short status request, not three copies.** `Client::probe()` is now
  the only place `/health`, `/props` and `/slots` are asked, with the
  health probe's timeouts.
- **The installer's failure messages.** A download curl gives up on now says
  whether there is anything to resume; `--force` works wherever it is typed;
  the "`.env` takes precedence" note appears only when `.env` names a
  different model.
- **No restart loop.** A `serve` that cannot start waits before exiting, so
  compose's `restart: unless-stopped` retries slowly with one message, and
  `docker compose stop` still ends it within a second.

The other two are accepted and listed under *Still open*.

---

## 6. Documentation and this release (W97)

`README.md`, `docs/ASSISTANT.md`, `docs/specs/deployment.md` §9, `docs/PLAN-2.3-ai.md`, `CLAUDE.md`

README's *AI assistant* section is rewritten around the two commands:
install a preset, enable it in `.env`, start the container, switch it on as
an admin — plus how to move between presets and how a 2.5 upgrade behaves.
`docs/ASSISTANT.md` §§2, 4, 5 and `docs/specs/deployment.md` §9 now describe
the installer's checks and precedence (D53, D54), what the admin tab shows
(D55), the runtime settings (D56), the warm-up (D57), and 2.6's own
measurements in place of 2.3's workstation figures. `PLAN-2.3-ai.md` gains
an amendment under D19 — not an edit of the closed table — recording that
its choice of model was reopened with evidence and superseded by D52, while
its other half, *the model is configuration*, still stands. The in-app help
pages now name the two models and say that switching is done on the server.

---

## 7. Also in this release: the work since 2.5.1

2.5.1 had no release document, and what followed it on `master` first
ships in 2.6.0. Recorded here in W99, when CLAUDE.md stopped carrying it:

- **2.5.1's fix of 2.5.0's live defect** (item 22b in
  [`RELEASE-2.5.0.md`](RELEASE-2.5.0.md)'s "Still open"): a user's own
  `node.created`/`node.updated` broadcast echoed back and overwrote what
  they had typed, and on a create replaced the record the Details tab was
  bound to with one that had no `tags`, which blanked the tab. Both events
  now call `dontBroadcastToCurrentUser()` and `useRealtime.ts` sends
  `X-Socket-ID`; the trigger is gone too (adding a node opens a draft and
  creates nothing until it is named). The pin was flipped in place to
  `FIXED` guards in `tests/js/stores/selfEcho.spec.ts`, with the server
  half in `tests/Feature/Realtime/SelfEchoTest.php`.
- **Standards templates** for ISO/IEC 27001, ISO/IEC 42001, the EU AI Act,
  SOC 2, GDPR, NIS2 and the CIS Controls — 12 bundled in all, with
  `tests/Feature/Seeders/StandardTemplateCoverageTest.php` listing every
  control independently of the fixtures.
- **Add from template** on the cards and **From template** in the new-node
  form (ui.md §3.3, security.md §3.1a); the form's **AI suggestion** asks for
  one suggestion (ui.md §7).
- **A help centre rewritten for the current product** — ten pages that are
  also the assistant's help corpus, rendered inside the sidebar for signed-in
  users (ui.md §2).

## Upgrading from 2.5.x

- **No action is needed to keep a 2.5 assistant running.** Whatever `.env`
  named — `ASSISTANT_MODEL_FILE`, `ASSISTANT_MODEL` as a file label, or
  nothing at all — still resolves to exactly the model it ran before (§2).
- **`ASSISTANT_THREADS` now defaults to 4, not 8**, and the container's CPU
  quota follows it. A host with fewer than four CPUs to give the container
  must lower `ASSISTANT_THREADS` or it will not start.
- **The runtime image is now pinned by digest as well as tag**
  (`server-b11176`), and its entrypoint is now `docker/assistant/assistant.sh`
  — nothing to do, unless something inspected the container's command
  directly.
- **`config('assistant.model')` is removed, and `/assistant/status` no
  longer returns a `model` field.** Nothing here read either; tooling that
  read the model name from that endpoint should read the admin AI tab
  instead, which now asks the runtime directly (D55).
- **To move onto a preset:** remove `ASSISTANT_MODEL_FILE` and
  `ASSISTANT_MODEL` from `.env`, run `docker compose run --rm
  assistant-install small` (or `large`), then `docker compose --profile
  assistant restart assistant`; the old model file can then be deleted.
- **`*.gguf`, `*.gguf.part` and `/models/` are now gitignored everywhere.**
  A checkout that had already committed a weight file is unaffected by the
  ignore rule and should have it removed by hand.
- Nothing in the calculation engine, the DAG, `AssessmentPolicy`, or the
  assistant jobs' prompts, budgets, accept step (D24), allowlist (D23) or
  availability rules (D25) changed.

---

## Verification behind this release

| Check | Result |
|---|---|
| `vendor/bin/pint --test` | passed |
| `vendor/bin/phpstan analyse` | No errors |
| `php artisan test` | **1,212 passed**, 2 skipped (the live-runtime tests, run separately below) |
| `npm run lint:check` | clean |
| `npm run format:check` | clean |
| `npm run type-check` | clean |
| `npm run test:unit` | passed |

`LiveRuntimeTest` ran against both presets on real runtimes — `large` on the
deployment host, `small` on a laptop — not only against a stub. **One
Vitest flake, recorded plainly rather than hidden:** a report-print test
timed out once under laptop load and passed cleanly when re-run alone — a
timing sensitivity in the test's environment, not a product defect. Every
new guard this plan introduced — the installer's corruption handling, the
catalogue's column parsing, the warm-up's early returns, the red-team pins
— was mutation-tested: neuter the fix, confirm the test goes red, restore.

**Deployability**, from a clean `.env` on a throwaway compose project (a
laptop, Docker Desktop): the image built, all six services came up with
app, Reverb and both databases healthy, and `/up` returned **200**. Then
the assistant path an operator would take, with `ASSISTANT_ENABLED=true`:
`docker compose run --rm assistant-install list` read the catalogue;
`docker compose --profile assistant up -d` served `small` under a CPU quota
of 4; the app's `RuntimeModel` reported `small` from the runtime's own
`/props`; `assistant:warm` read the 6,671-token help prompt in 3m05s on the
laptop's four cores, while the scheduler's simultaneous attempt stood down,
and a second run reported "already used"; and the first real help question
afterwards processed **27** new tokens and answered in **13.5s**.

---

## Still open

### Pinned as tests

`grep -rn "DEFECT:" tests/` remains the whole index; this plan pinned none
and closed none. The one open at 2.5.1 is unchanged: the unshared flash bag
(`HandleInertiaRequests::share()`). The new `MEASURED:` pins added here sit beside
the existing ones in `GuardrailTest.php`: the presets' parity with the old
default on all seven red-team cases, and `summary-canary`'s outcome
differing between the 2.3 (Ollama) and 2.6 (chat-endpoint) runs. Neither
may be "fixed" with a prompt change — the accept step is the guardrail.

### Known gaps and verification debt

- ~~The prompt-cache fix is expected to hold, not yet measured to.~~
  Measured after the tag: it holds (see "The cache bound that fit one preset
  and silently evicted the other").
- **The warm-up can miss a start.** If someone asks for a suggestion or a
  summary before the first scheduler tick after a runtime start, the slot is
  marked used and that start is not warmed; the next help question is the
  slow one, as before 2.6. The runtime reports nothing that tells "the help
  pages were read" apart from "anything ran" (found in review; accepted, and
  documented in `CorpusWarmer`).
- **The admin tab can take a few seconds on a wedged runtime.** It asks
  `/health` and `/props` in turn, each bounded by the short probe timeout,
  once per `health_ttl` and only on that page (found in review; accepted,
  D55).
- **All of 2.6's numbers come from one host at four cores** — the
  deployment host, the machine that matters, but the only one measured.
- **`redteam.mjs` still speaks Ollama.** Its cases are what `verify.mjs`
  runs, through the production launcher; the file is kept because both read
  its case list, and `tools/spike/assistant/README.md` says which to run.
- **Job B's one-suggestion pop-up path was not measured separately** — the
  new-node "AI suggestion" button scales its budget down
  (`Suggester::budgetFor()`, 192 tokens against 512), and `docs/ASSISTANT.md`
  §5 says so plainly rather than implying a number that was not taken.
- As at 2.5.0, the assistant still has no end-to-end coverage; W96's
  verification is unit, feature and live-runtime level, not `tests/e2e/**`.
