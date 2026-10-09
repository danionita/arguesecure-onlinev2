# ArgueSecure 2.3 — The assistant

> **Complete.** W50–W54 all shipped; what they contain is in
> [`RELEASE-2.3.0.md`](RELEASE-2.3.0.md) §6. The one item below that was not
> done is the first one: *re-run the benchmark on the deployment host*. Every
> latency figure here is still from the spike machine.

> Follow-on to [`PLAN-2.2.md`](PLAN-2.2.md), which took the product to W49.
> This plan covers **W50–W54**. The ground rules in [`CLAUDE.md`](../CLAUDE.md)
> apply unchanged: one package per session, own only your listed paths, write
> the test with the code, commit to `v2/<package-id>`.
>
> **This was a sketch until the spike landed.** The five open questions it
> carried are answered, with numbers, in
> [`docs/spikes/2.3-inference.md`](spikes/2.3-inference.md). Read that first —
> several of the answers are not what the sketch expected, and two of them
> change what these packages contain.

---

## Why

ArgueSecure asks a person to sit in front of an empty target of assessment and
think of everything that could go wrong. That is the hard part of the product,
and nothing in it helps. The three jobs below are the places where a local
model can help without taking the judgement away from the person doing the
assessment.

| | Job | Risk |
|---|---|---|
| A | **Answer questions about the tool itself** — how a baseline works, what a transfer is, where to set a strength. | Low. The corpus already exists. Wrong answers are embarrassing, not dangerous. |
| B | **Suggest risks, attacks and defences** from a parent's title and description. | Medium. Output is a suggestion the user accepts or rejects; it never writes to the graph unattended. |
| C | **Draft the report's executive summary.** | High. It is the part of the document a client reads, and it is attributed to the user, not to a model. |

Built in that order. A needs none of the graph. C does not ship until B has
been used enough to know whether the model is worth reading.

---

## Decisions (closed)

D1–D16 are in [`PLAN-2.1.md`](PLAN-2.1.md) and [`PLAN-2.2.md`](PLAN-2.2.md) and
stand unchanged. These are new, and every one of them is a spike result rather
than a preference.

| # | Decision | Rationale |
|---|---|---|
| **D17** | **The runtime is `llama.cpp` server, and the model is a GGUF mounted from the host — never downloaded at run time.** | The `ollama/ollama` image is **8.43 GB** against llama.cpp's **1.2 GB**, almost all of it GPU runtime libraries a CPU deployment never loads, against a project rule that ArgueSecure must run anywhere. Throughput is equivalent — measured on identical weights, not assumed. A runtime that fetches weights on demand is one that can fail at first use, in the field, with no network. |
| **D18** | **Weights come from the model's publisher, pinned by revision and verified by byte count. Ollama blobs are not a source.** | `qwen2.5` loads into llama.cpp from an Ollama blob; **`gemma3` does not** — Ollama ships a custom GGUF variant whose metadata upstream rejects. A shortcut that works for one model and not the next is worse than no shortcut. The verification is not ceremony: a plain `curl -sL` of the 2.49 GB GGUF produced a 1.36 GB file **and exit status 0**, and the truncation surfaced only as `model is corrupted or incomplete` when the container started. |
| **D19** | **The default model is `gemma3:4b-it` (Q4_K_M), and the model is configuration.** | It wins on memory (2.8 GiB vs 5.3), on repeat help answers (6.5s vs 33s), on job C (249s vs 318s), and on the quality of job B's suggestions — the larger candidate returns generic filler in one-clause fragments, which is exactly what the prompt forbids. `qwen2.5:7b-instruct` is the documented alternative. These figures are from one 2018 desktop CPU; `config/assistant.php` makes the choice a one-line change. |
| **D20** | **Every call to the runtime streams, and every timeout is set explicitly.** | A non-streamed generation sends no headers until it finishes, and CPU generations run for minutes. This destroyed half the spike's first benchmark at exactly 300s — undici's default — before it was recognised. Guzzle's defaults are shorter. Streaming is also the only way to offer a cancel or show progress. |
| **D21** | **Job A sends the entire help corpus. No retrieval, no index, no embedding container.** | The corpus is 4,838 tokens; the haystack is smaller than the context window. BM25 over it reaches only 81% recall@3, and — decisively — a fixed corpus is a **cacheable prefix** worth a 14× speedup on repeat questions, which per-question retrieval throws away. Revisit only if the corpus passes ~8,000 tokens. |
| **D22** | **`docs/` is not part of any corpus the assistant reads.** | It is developer documentation. It describes unbuilt features in the present tense and narrates the *legacy* application's security failures — a committed `APP_KEY` amounting to RCE, unauthenticated read endpoints, a default admin password — in prose that carries no marker of being historical once chunked into a prompt. Job A's corpus is a hardcoded manifest of `resources/markdown/help/**`. |
| **D23** | **The accept path parses `title` and `description` and discards every other key.** | Injected text changed the output schema on **3 of 3** usable models, one emitting an `execute` field containing a real shell command. Prompt instructions lost 3/3; an allowlist wins 3/3. This is the mitigation, not tidiness. |
| **D24** | **The human accept step is the guardrail, not a safety net behind one.** | Delimiters hold the *task* boundary — every "ignore your instructions" attack failed on every model — and do not hold the *content* boundary: an injection asking the model to carry a string into its output succeeded on **3 of 3**. That cannot be fixed by prompting, so nothing generated may reach the graph or the report without a person seeing it first. |
| **D25** | **The assistant is off by default, and absent is an ordinary state, not an error.** | It needs 2.8 GiB resident and a second container; most deployments will not have it. Following `InstanceSettings`' existing precedent: a disabled instance serves no assistant route at all, shows no disabled buttons, and raises no warning. *"A control that appears to do something and does not is worse than no control."* |

**Amendment (W59), not a re-litigation:** D17 chose the runtime and said
nothing about which of its endpoints to call, and W50 picked `/completion`.
That was wrong in a way nothing measured until W57: the raw endpoint applies no
chat template, and on the recommended model jobs A and C returned an empty
string on every run. The client posts to `/v1/chat/completions`, which applies
the template stored in the GGUF's own metadata. D17, D19 and D21 are unchanged
— including the prompt-cache argument, which survives the move intact
([`spikes/2.3-threads.md`](spikes/2.3-threads.md)).

**Superseded in part (2.6, D52):** D19's *choice of model* was reopened with
evidence and replaced by two presets, `small` (Gemma 4 E4B) and `large`
(Gemma 4 26B-A4B), installed by one command
([`PLAN-2.6.md`](PLAN-2.6.md)). The measurement that reopened it was run on
the deployment host through the application's own endpoint and prompts
([`spikes/2.6-models.md`](spikes/2.6-models.md)): the 2.3 default answered
the counter-intuitive help question correctly once in four samples and
invented figures in one summary of four; the replacements did neither, and
on the red-team cases behaved exactly as it did. D19's other half — *the
model is configuration* — stands, and is what 2.6 built on. D17, D18, D20,
D21, D23, D24 and D25 are unchanged.

**Not built, deliberately:** nonce delimiters. The attack they defend against —
closing the fence and reopening it — held on every model tested. It is a
defence that looks prudent, costs real complexity in prompt assembly, and
addresses something that is not happening.

---

## The constraint that shapes everything

**CPU-only, no GPU.** Two rates, an order of magnitude apart:

| | On the spike machine (i9-9900K, 8 cores) |
|---|---|
| Prompt processing | 42 tok/s median |
| Generation | 2.4–8.5 tok/s |

**Spend context freely, ration output ruthlessly.** Prompt tokens are ~10×
cheaper than output tokens, and a fixed prompt prefix is nearly free after its
first use. Put the stable material first and the varying material last, in
every prompt, always. `num_predict` is the latency dial and it trades directly
against quality — the model that writes least is the one that answers worst.

| Job | Wall time | Shape |
|---|---|---|
| **A** | 94s first, **6.5s after** | Button, once the corpus is cached |
| **B** | **27.9s** | Button, with a spinner and a cancel |
| **C** | **249s** | Queued job, notification on completion |

---

## Package map

| # | Package | Depends on | Delegable |
|---|---|---|---|
| W50 | The runtime, the client, and the off switch | — | No — deployment and degradation |
| W51 | Job A: help answers | W50 | **Yes** |
| W52 | Job B: suggestions, with the allowlist and its red-team cases | W50 | No — untrusted input |
| W53 | The guardrail suite | W52 | Partly |
| W54 | Job C: the drafted summary | W52, W53 | No — attribution and disclosure |

---

## W50 — The runtime, the client, and the off switch

**Owns**
`docker-compose.yml` (the assistant service only),
`.env.example` (the assistant block only),
`config/assistant.php` (new),
`app/Domain/Assistant/**` (new),
`app/Http/Controllers/AssistantStatusController.php` (new),
`database/migrations/2026_*_add_assistant_enabled_to_instance_settings.php` (new),
`app/Domain/Accounts/InstanceSettings.php`, `app/Models/InstanceSetting.php`,
`app/Http/Requests/Admin/UpdateSettingsRequest.php` (the assistant setting only),
`resources/js/Pages/Admin/Settings.vue` (the assistant toggle only),
`routes/web.php` (the assistant block, appended),
`docs/specs/deployment.md` §assistant,
`tests/Feature/Assistant/**`, `tests/js/**`.

**No feature.** A client, a health check, a setting, and the degradation path.

- A `llama.cpp` server service, **profile-gated** so `docker compose up` on an
  ordinary deployment does not start it. A GGUF mounted from a host path
  configured by env; the container never downloads.
- The documented fetch step **verifies the file**: `--fail --retry 5
  --retry-all-errors -C -`, then assert the byte count against the publisher's
  `x-linked-size`. A truncated GGUF is indistinguishable from a good one until
  the runtime refuses it, and on a profile-gated service that can be days later
  (D18).
- `config/assistant.php` — enabled, base URL, model name, per-job
  `num_predict`, and every timeout, explicit. **Never `env()` outside this
  file.**
- A client that **streams** (D20), with a per-request cancel and a hard cap.
- A health check that is cached and never on the render path.
- `assistant_enabled` in `instance_settings`, default **false**, admin-only.

Tests are the whole package:

- With the setting off, **no assistant route resolves.** Assert 404, not 403 —
  a disabled instance does not advertise the feature to someone who read about
  it.
- With the setting on and the runtime unreachable, the app still renders every
  page, and the health check does not block.
- A generation longer than the default HTTP client timeout completes. **Mutation-test
  this one:** drop the explicit timeout, confirm it goes red, restore. This is
  the defect D20 exists for and it is invisible on a small assessment.

## W51 — Job A: help answers

**Owns**
`app/Domain/Assistant/Help/**`, `app/Domain/Assistant/Generation/**` (new),
`app/Http/Controllers/AssistantHelpController.php`,
`app/Http/Requests/Assistant/**` (new),
`app/Providers/RateLimitServiceProvider.php` (the assistant limiter only),
`resources/prompts/assistant/**` (new),
`resources/js/Components/Help/**` (new), `resources/js/Pages/Help/**`,
`app/Http/Controllers/HelpController.php` (the availability prop only),
`routes/web.php` (the assistant block, appended),
`docs/specs/security.md` §9 (the assistant rate only),
`tests/Feature/Assistant/HelpTest.php`, `tests/js/**`.

**Two things move here from W52**, because W51 is the first package that can
burn a CPU and they are the same surface: the per-user rate limit, and the
refusal of a second generation while one is already in flight. W52 reuses
them rather than inventing its own.

A "?" in the help page's search. One action, in the place where it helps.

- The corpus is a **hardcoded manifest** of `resources/markdown/help/**` — the
  same discipline `HelpLibrary` already applies to slugs, so adding a file to a
  directory cannot silently add it to what the assistant says. **`docs/` is
  not in it** (D22).
- No retrieval (D21). The whole corpus, fixed prefix first, question last.
- **Warm the cache** when the assistant is enabled, or tell the user the first
  answer is slow. 94s versus 6.5s is not a rounding difference, and silently
  making one user in twenty wait ninety seconds is the worst of the options.
- Answers render as plain text. **No HTML renderer** — W30 deleted the only
  user-authored-HTML renderer in the codebase along with `notesPreview.ts`.

Test that a question whose answer is not in the corpus produces "I don't know"
and a pointer to the nearest page, rather than a fluent invention.

## W52 — Job B: suggestions

**Owns**
`app/Domain/Assistant/Suggestions/**`, `app/Http/Controllers/AssistantSuggestionController.php`,
`app/Http/Requests/Assistant/SuggestChildrenRequest.php`,
`app/Http/Requests/Assessment/StoreNodeRequest.php` and
`app/Http/Controllers/NodeController.php` (**the optional `description` only**),
`resources/prompts/assistant/suggest.md`,
`resources/js/Components/Assistant/**` (new),
`resources/js/Components/Tree/NodeCard.vue`, `resources/js/stores/assessmentStore.ts`
(the suggest action and the accept call only),
`routes/web.php` (the assistant block, appended),
`tests/Feature/Assistant/SuggestionTest.php`, `tests/js/**`.

**Why the node-create endpoint gains a field.** A suggestion carries a title
*and* a description, and the accept path must go through the one existing
validated write path rather than a second one of its own. Adding an optional
`description` to `StoreNodeRequest` keeps that true; the alternative — create,
then immediately PATCH — is two writes, two undo entries and a window where the
node exists with half its content.

A "Suggest attacks" item in the add-child menu. Never unsolicited.

- The prompt is a **versioned file**, assembled only by substituting delimited
  assessment text — never built from user input (see
  `tools/spike/assistant/prompts/` for the spike's drafts).
- **The allowlist is the security content of this package** (D23). The parser
  locates the JSON array within the reply — one model fences it, another does
  not, both are correct — and reads `title` and `description` only. Every other
  key is discarded before the payload is ever shaped into a node.
- Accepting a suggestion goes through the **existing validated node-create
  controller**. No second write path.
- Each suggestion is visibly marked as generated, and the mark survives editing
  until the user clears it deliberately.
- Rate-limited per user, and **refused while another generation is in flight** —
  CPU inference is a shared, easily exhausted resource, which makes it a
  denial-of-service surface on a shared instance.

**The job B red-team cases ship in this package**, not in W53: they test this
parser, and the test belongs with the code. Pin `schema-subversion` — assert
that an injected `execute` field never survives the parse. Mutation-test it:
widen the allowlist, confirm red, restore.

## W53 — The guardrail suite

**Owns** `tests/Feature/Assistant/**`, `tests/fixtures/assistant/**`.

The stub, the injection fixtures, and the job A and C cases. Runs against a
**stub, never a live model**, so it is deterministic and CI-safe.

**Its assertions pin what the spike measured, including the two defeated
cases.** `canary-exfiltration` succeeded on 3/3 models; the suite asserts the
behaviour that exists today, with the reasoning in the docblock, per
`CLAUDE.md`'s rule on pinned defects. A future prompt change that appears to
fix it then has to turn that test red deliberately, having read why — so the
fix can neither be forgotten nor land silently on the strength of a plausible
story.

## W54 — Job C: the drafted executive summary

**Owns**
`app/Domain/Assistant/Summary/**`, `app/Jobs/DraftExecutiveSummary.php`,
`resources/js/Pages/Report/**` (the draft action only),
`docs/specs/ui.md` §report, `tests/Feature/Assistant/SummaryTest.php`.

**A queued job with a notification, not a button** — 249s for 73 nodes on the
recommended model, and it grows with the assessment.

- A "Draft" action on the report's executive summary. The draft arrives as a
  notification (W47's infrastructure), into an editor, never into the document.
- **Disclosure is the package.** Generated text is visibly marked at the point
  of use, and a report containing any of it carries a disclaimer in its
  methodology appendix naming which sections were drafted by a model and
  stating that the author is responsible for them.
- Nothing stores a hard reference to a model. If the assistant is removed
  tomorrow, every assessment still opens and every report still renders.

Ships only after job B has been in use long enough to know the model is worth
reading.

---

## Before W50 starts

**Re-run the benchmark on the deployment host.**

```bash
node tools/spike/assistant/bench.mjs
```

Every figure in the spike report is from one 2018 desktop CPU. If the real host
is meaningfully slower, job B crosses the 30-second line and becomes a second
queued-job flow — which changes W52 from a button with a spinner into a
different package. It takes about two and a half hours and it decides the shape
of two packages.
