# ArgueSecure 2.6 — Two assistant models, installed in one command

> Follow-on to [`PLAN-2.5.md`](PLAN-2.5.md), which took the rewrite to 2.5.1
> (W82–W88; W89–W91 are taken by branches that were folded into 2.5.1 or are
> still open). This plan covers **W92–W97** and decisions **D52–D58**.
>
> The measurement behind every decision here is
> [`spikes/2.6-models.md`](spikes/2.6-models.md): seven configurations on the
> deployment host (i9-14900K, four cores), through `/v1/chat/completions` the
> way the application calls it.
>
> Unlike earlier plans, 2.6 was built in **one session on one branch**,
> `v2/2.6-assistant-models`, at the owner's request. The packages below are
> still the unit of review and of the commit history; they are not separate
> branches.

---

## Why

The 2.6 spike found three things about the assistant as it ships:

1. **The default model gets things wrong that matter.** `gemma-3-4b-it`
   answered the one help question whose answer is counter-intuitive — *why the
   total does not change when you set a reduction target* — with an answer to a
   different question, and invented two of the four figures it quoted in an
   executive summary. Gemma 4 E4B, at the same size and nearly the same speed,
   did neither; the two mixture-of-experts models did better still.
2. **The real host is fast enough for a much better model.** Job B took 18s on
   four cores of the deployment host. The large candidates are 16–29% slower
   and still well inside the 30-second line — if the host has ~28 GiB to give.
3. **Installing a model is a page of instructions.** Fetch a GGUF by URL,
   check its byte count by hand, set three environment variables that must
   agree with each other, start a profile. Every step is a place to get it
   wrong, and D18 exists because one of them (a truncated download, exit 0)
   already went wrong once.

So 2.6 offers **two models — a small one that runs anywhere the current one
does, and a large one for hosts with memory to spare** — makes installing
either one command, shows the admin which one is actually running, and applies
the runtime settings the spike measured as worth having.

### What this plan does not touch

The three jobs, their prompts, their token budgets, the accept step (D24), the
allowlist (D23), the availability rules (D25) and the per-feature switches
(D48). A better model changes what comes back, not what the application is
allowed to do with it.

---

## Decisions (closed)

| # | Decision | Rationale |
|---|---|---|
| D52 | **Two presets. `small` is Gemma 4 E4B (QAT, `UD-Q4_K_XL`); `large` is Gemma 4 26B-A4B (QAT, `UD-Q4_K_XL`), a mixture of experts.** `small` is the default. This supersedes D19's *choice of model*; D19's other half — *the model is configuration* — stands and is what this builds on. | E4B: 5–16% slower than gemma3 on jobs A and B, 4% faster on C, +0.6 GiB, and right where gemma3 was wrong (2.6 spike, findings 1 and 6). 26B-A4B over Qwen3.6-35B-A3B: faster on jobs B and C and on repeat help answers, gives back less of the prompt cache, a 14 GB file against 22 GB — and **the same family as `small`**, so one chat template, one set of runtime flags and one reasoning switch cover both. **Conditional on W96**: if the red-team or the multi-sample quality run contradicts the spike, stop and report rather than ship. |
| D53 | **Installing a model is one command, run in the runtime's own image: `docker compose run --rm assistant-install small`.** It downloads from the publisher at a pinned revision, and verifies byte count *and* sha256 before the file gets its real name. D17 and D18 are unchanged: nothing is fetched at run time, and nothing unverified is ever loadable. | The pinned llama.cpp image already carries `bash`, `curl` and `sha256sum` (checked), so the installer needs no second image and works wherever Docker does — Linux, Unraid, Docker Desktop. A host-side script would need a host toolchain; an artisan command would need the app container to write into the runtime's volume. |
| D54 | **Which model runs: `ASSISTANT_MODEL=small\|large` in `.env` if set; otherwise the preset most recently installed; `ASSISTANT_MODEL_FILE` still runs any GGUF by name and wins over both; and 2.5’s two other ways of naming a model — `ASSISTANT_MODEL` as a label for the file, and nothing at all — still run the file they named.** The runtime refuses to start on a preset whose file is missing or the wrong size, and says which command installs it. | The common path is two commands and no `.env` editing beyond `ASSISTANT_ENABLED`. The explicit variable is there for whoever wants the choice written down. `ASSISTANT_MODEL_FILE` winning, and the two legacy forms (added when W96 checked the upgrade path), mean **every 2.5 deployment keeps running exactly what it runs today** until someone changes it — an upgrade must neither silently swap a model nor strand one. |
| D55 | **The admin AI tab shows the model the runtime reports it loaded** (`/props` → `model_path`), matched to the catalogue by file name — not what `.env` claims. Probed only for that page, cached for `health_ttl`, with the health probe's connect timeout. | `config('assistant.model')` was a label an operator typed; it could say one thing while the container ran another, and the page had no way to know. The runtime is the only source that cannot drift from itself. The probe stays off every render path but the admin tab's (D25). |
| D56 | **Runtime settings, from the 2.6 measurements:** the image pinned by tag *and digest* (`server-b11176`); `-np 1`; `--reasoning off`; `--cache-ram` per preset — 2 GiB for `small`, 4 GiB for `large`; `ASSISTANT_THREADS` defaulting to **4**, with the container's CPU quota set to the same number. **Not adopted, measured:** speculative decoding, slot save/restore, `--cache-reuse`. | `-np 1`: one slot, so the context is never divided between requests the application serialises anyway. `--reasoning off` replaces the deprecated `enable_thinking` kwarg and was verified to stop Gemma 4 reasoning. `--cache-ram`: the host-RAM prompt cache is what keeps the help corpus warm after a suggestion takes the only slot (measured: 5,986 of 6,505 tokens served), but its default is **8 GiB**, an unbudgeted surprise on a small host. *Amended by W96:* one bound does not fit both presets. `small` evicted nothing under 2 GiB; on `large` a cached help or summary prompt is up to 1.13 GiB, 2 GiB held about two, and the help corpus was evicted after a summary — so the bound is a catalogue column, and `large`’s memory budget rose from 24 to 28 GiB to include it. Confirmed by a re-run at 4 GiB: nothing evicted, the help corpus served from cache straight after a summary (9.7 s, against 112 s at 2 GiB). Threads = quota (2.3-threads): `-t` above the quota is slower, and past four to six cores generation barely improves. Rejected: a draft model made generation 2.5–3.8× *slower* on CPU; a restored slot was found and then not used, on every architecture tested; `--cache-reuse` is disabled by the runtime itself for every model in the catalogue. |
| D57 | **The help corpus is warmed once per runtime start**, by the scheduler, when — and only when — the help feature is available and the runtime reports a slot that has never served a request. | A restart otherwise makes the next person's first question the 76–136-second one. The runtime's `/slots` carries no `id_task` until its first request (checked), which identifies "freshly started" without timers, counters or guesses, so the warm-up runs once per start and never repeats on its own. It uses `HelpAnswerer`'s own prompt, so the cached prefix is byte-for-byte the one a real question reuses. |
| D58 | **Benchmark results live in the repository; model weights never do.** Harness and raw results under `tools/spike/assistant/`, the write-ups under `docs/spikes/`, as 2.3's already are; `*.gguf` is ignored everywhere, not just in the spike directory; the spike's own commits are tagged `spike-2.6-models`. | A result a reader cannot find next to the decision it justifies is an anecdote. The results contain model output, timings, a CPU model and a kernel version — nothing about a network, a host name or a person — and were scanned for addresses and paths before they were committed, so they are safe in a public repository. Weights are multi-gigabyte, separately licensed, and one command away (D53); a `.gitignore` entry scoped to one directory is how the next one gets committed from somewhere else. |

---

## The packages

| # | Package | Owns |
|---|---|---|
| **W92** | The benchmark record (D58) | `.gitignore`, `docs/spikes/2.6-models.md`, `tools/spike/assistant/candidates*`, the `spike-2.6-models` tag |
| **W93** | The model catalogue, the launcher and the installer (D52–D54, D56) | `docker/assistant/**`, `docker-compose.yml` (`assistant`, `assistant-install`), `.env.example` |
| **W94** | The admin tab shows the installed model (D55) | `app/Domain/Assistant/ModelCatalog.php`, `app/Domain/Assistant/RuntimeModel.php`, `config/assistant.php`, `Admin/AssistantController.php`, `Pages/Admin/Assistant.vue`, their tests |
| **W95** | Warm the help corpus once per runtime start (D57) | `HelpAnswerer::warm()`, `routes/console.php` (`assistant:warm`), its test |
| **W96** | What has to happen before D19 changes | `tools/spike/assistant/verify*`, `tests/fixtures/assistant/injections.json`, `tests/Feature/Assistant/GuardrailTest.php`, `LiveRuntimeTest.php` |
| **W97** | Documentation and the release | `README.md`, `docs/ASSISTANT.md`, `docs/specs/deployment.md` §9, `docs/PLAN-2.3-ai.md` (an amendment under D19, not an edit of it), `docs/RELEASE-2.6.0.md`, `CLAUDE.md` |

### W92 — The benchmark record

The spike's two commits (harness, then results) come into this branch as they
were made, so its history shows the measurement before the change it
justified. Add `*.gguf` and `/models/` to `.gitignore`: `docker-compose.yml`'s
default model directory is `./models`, inside the checkout, and nothing stops
`git add .` from picking up 2.5 GB today.

**Acceptance:** `git check-ignore` matches a `.gguf` at the root, under
`models/`, and under an arbitrary directory; the tag exists and points at the
results commit.

### W93 — Catalogue, launcher, installer

`docker/assistant/models.conf` is the **one** list of presets: name, file,
publisher repo, revision, byte count, sha256, the memory it needs, a label.
The shell reads it, and so does PHP (W94) — one file, two readers, so the
installer and the admin page cannot disagree about what `large` means.

`docker/assistant/assistant.sh` is mounted into the pinned runtime image and
becomes its entrypoint:

- `serve` (the default) resolves the model per D54, refuses a missing or
  wrong-sized preset file with the install command in the message, and `exec`s
  `llama-server` with D56's flags.
- `install <preset>` checks free disk and memory, downloads with resume from
  the pinned revision into a `.part` file, verifies size and sha256, renames,
  and records the selection. Re-running it on an installed preset re-verifies
  and re-selects without downloading.
- `list` prints the catalogue and what is installed.

`docker-compose.yml`: the `assistant` service runs `serve` with the models
directory read-only; a new `assistant-install` service, in a profile of its
own so `up` never starts it, mounts the same directory read-write.

**Acceptance:** `docker compose config` is valid with the profiles on and off;
`install` refuses a corrupted file and a truncated one; `serve` refuses a
missing preset with the install command; a 2.5 `.env` with
`ASSISTANT_MODEL_FILE` runs that file unchanged.

### W94 — The admin tab shows what is installed

`ModelCatalog` parses `models.conf`. `RuntimeModel` asks the runtime's
`/props` which file it loaded and returns the catalogue entry it matches, or
the bare file name for a custom model, or nothing when the runtime does not
answer. The tab shows the running model's label and preset, what the other
preset would need, and keeps pointing to the README for installing — as 2.5.1
decided, installation instructions belong to whoever holds the compose file.

**Acceptance:** Pest covers a preset, a custom file, an unreachable runtime and
the not-configured state (no probe at all); Vitest covers the tab's three
renderings; the probe is never made on any page but this one.

### W95 — Warm the corpus

`assistant:warm`, scheduled every minute without overlap: returns at once
unless the help feature is available *and* `/slots` shows a slot with no
`id_task`; otherwise sends `HelpAnswerer`'s prompt with a one-token budget.

**Acceptance:** Pest covers each early return, the warm request's prompt
sharing the real prefix, and a runtime that has already served a request not
being warmed again.

### W96 — What has to happen before D19 changes

The five conditions `spikes/2.6-models.md` set:

1. **Red-team the chosen models.** `redteam.mjs`'s cases, ported from
   Ollama's `/api/generate` to `/v1/chat/completions`, run against `small`
   and `large` *and* against the old default as a control — if the control
   does not reproduce the 2.3 record, the port is wrong, not the models.
2. **Update the `MEASURED:` pins deliberately**, from that run, in
   `injections.json` and `GuardrailTest.php`.
3. **Run `LiveRuntimeTest`** against both presets.
4. **Quality with more than one sample**: every job at three further seeds,
   with the checks that can be mechanical made mechanical (figures against the
   input, well-formed JSON, reasoning off) and the rest read.
5. **`--reasoning off`** in the launcher, and the memory figures in
   `config/assistant.php` and the docs updated to what the presets measure.

The measurement runs on the deployment host, through the launcher W93 ships —
so it verifies the installer and the production flags as well as the models.

### W97 — Documentation and release

README's *AI assistant* section rewritten around the two commands; ASSISTANT.md
and deployment.md §9 updated to the presets, the installer and 2.6's numbers;
an amendment under D19 in PLAN-2.3-ai.md pointing here; RELEASE-2.6.0.md;
CLAUDE.md's "where the work has got to".

---

## Sequencing

W92 first (it is already done by the spike's commits). W93 before W96, because
the verification runs through the launcher. W94 and W95 in parallel with W96's
measurement, which runs for about an hour on the deployment host. W97 last.
