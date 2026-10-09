# 2.3 assistant spike — the harness

Throwaway measurement code for the spike that
[`docs/PLAN-2.3-ai.md`](../../../docs/PLAN-2.3-ai.md) asks for before its
packages can be written. **It is not application code.** Nothing in `app/`,
`resources/js/` or `routes/` imports it, it is excluded from Pint, PHPStan,
ESLint and both test runners, and it ships no feature.

It lives in the repository rather than in a scratch directory for one reason:
the numbers in [`docs/spikes/2.3-inference.md`](../../../docs/spikes/2.3-inference.md)
were measured on a developer workstation, and the machine whose numbers
actually decide the design is the deployment host. Re-running this there is the
only way to find that out, and a benchmark you cannot re-run is an anecdote.

## Running it

The benchmark needs a runtime with models it can pull:

```bash
docker run -d --name arsec-spike-ollama \
  -v arsec_spike_models:/root/.ollama -p 11434:11434 ollama/ollama

node tools/spike/assistant/bench.mjs          # ~2.5h on 8 CPU cores
node tools/spike/assistant/retrieval.mjs      # instant, no runtime needed
```

Results land in `results/` as JSON, rewritten after each model so an
interrupted run keeps what it finished. `bench.mjs` pulls roughly 16 GB of
model weights into the named volume on its first run.

Flags, all optional: `--host`, `--models a,b` to benchmark a subset,
`--jobs a,b,c,rt` to run a subset of the jobs, `--out name.json`.

### Pull first, benchmark second

`bench.mjs` pulls a model if it is missing, and a multi-gigabyte write evicts
page cache and competes for I/O with the model being loaded — worth about 30%
on the first measurements after it. The numbers in the spike report needed two
extra passes to undo this. Pull everything first, then benchmark:

```bash
for m in gemma3:4b-it-q4_K_M qwen2.5:7b-instruct-q4_K_M; do
  curl -s http://localhost:11434/api/pull -d "{\"model\":\"$m\"}" >/dev/null
done
```

### If a run reports `fetch failed`

Node's `fetch` is undici, and its `headersTimeout` defaults to 300 seconds. A
non-streamed generation sends no headers until it has finished, so on a CPU
every answer longer than five minutes dies on the client while the server is
still working on it — which reads as a server fault and is not one. Both the
generation and the model pull are streamed here for that reason. **The
application client W50 builds needs the same treatment**, and Guzzle's default
timeouts are shorter than undici's.

Tear the whole thing down with:

```bash
docker rm -f arsec-spike-ollama && docker volume rm arsec_spike_models
```

## The 2.6 candidate benchmark (`candidates*`)

Other models, and other ways of running the shipped one, measured through
`/v1/chat/completions` the way `App\Domain\Assistant\Client` calls it. Needs
only `bash`, `curl` and `docker` on the host — Node runs in a container — so
it runs as-is on a Linux server such as Unraid. Results:
[`docs/spikes/2.6-models.md`](../../../docs/spikes/2.6-models.md).

```bash
# 1. Resolve and pin every weight file (already done; re-run to re-pin).
docker run --rm -v "$PWD":/repo -w /repo node:22-alpine \
  node tools/spike/assistant/candidates-weights.mjs resolve

# 2. Download and verify: 9.8 GB dense, +36.6 GB for the two MoE models.
#    Checks free disk first; size and sha256 checked before a file is usable.
DEST=/mnt/<pool>/spike-models tools/spike/assistant/candidates-fetch.sh

# 3. Benchmark. Each configuration is pre-flighted: one whose weights plus
#    MARGIN_GIB do not fit in available RAM is skipped and recorded, never run
#    from swap.
HOST_LABEL=unraid CORES=8 MODELS=/mnt/<pool>/spike-models \
  tools/spike/assistant/candidates.sh

# 4. Tables for the report, one host at a time.
docker run --rm -v "$PWD":/repo -w /repo node:22-alpine \
  node tools/spike/assistant/candidates-report.mjs unraid
```

On Unraid, keep the weights on a pool path (`/mnt/<pool>/…`), never under
`/mnt/user/…`: that is the shfs FUSE layer, and llama.cpp memory-maps the
model through it. Stop the deployed `assistant` service first. If cores are
isolated for VMs, set `CPUSET` as well as `CORES`.

The driver removes its container, network and volume when it finishes. What
is left afterwards is the weights directory and two images
(`ghcr.io/ggml-org/llama.cpp:server-b11176`, `node:22-alpine`).

| File | What it does |
|---|---|
| `candidates-weights.mjs` | The weight list, and the pinned manifest (`results/candidates-manifest.json`). |
| `candidates-fetch.sh` | Resumable download from the pinned commit, verified by size and sha256. |
| `candidates.sh` | The driver: pre-flight, start, jobs, save, restart, restore, jobs. |
| `candidates.mjs` | The HTTP side: one streamed chat completion per step. |
| `candidates-estimate.mjs` | Bytes read per generated token, from GGUF headers alone. |
| `candidates-report.mjs` | The markdown tables. |

## Before a model becomes a preset (`verify*`, 2.6 W96)

**This is the red team to run.** `redteam.mjs` keeps the case list, but it
speaks Ollama, which D17 retired; `verify.mjs` sends the same cases the way
the application does, through the production launcher
(`docker/assistant/assistant.sh`, the image and flags `docker-compose.yml`
pins), and adds three seeds of every job with mechanical quality checks.
Changing `docker/assistant/models.conf` without running it — and recording
the outcome in `tests/fixtures/assistant/injections.json` — is changing a
guardrail's evidence without evidence.

```bash
# Every configuration: the old default as a control, then small and large.
HOST_LABEL=unraid CORES=4 tools/spike/assistant/verify.sh

# One preset, quality and caching only (a runtime-setting change cannot move
# what an injection does), leaving it serving for LiveRuntimeTest:
KEEP=1 CONFIGS=large VERIFY_ARGS="--only quality" tools/spike/assistant/verify.sh

docker run --rm -v "$PWD":/repo -w /repo node:22-alpine \
  node tools/spike/assistant/verify-report.mjs unraid
```

`KEEP=1` leaves an unauthenticated inference endpoint on `PORT`; stop it with
`docker rm -f arsec-verify` when the live test is done.

## What each file is

| File | What it answers |
|---|---|
| `bench.mjs` | Open questions 2 and 3 — which model, and how long it takes. |
| `retrieval.mjs` | Open question 4 — whether job A needs a retrieval step at all. |
| `fixtures.mjs` | Builds all three prompts from real repository content. |
| `redteam.mjs` | The injection cases. A prototype of what `tests/Feature/Assistant/` gets. |
| `prompts/` | The prompts themselves, versioned as files, as the plan requires. |

## The one thing to keep when this is deleted

`prompts/` and `redteam.mjs` are drafts of things the packages will own for
real: W50–W54 take the prompts, and W53 takes the red-team cases and reruns
them against a stub so they are deterministic and CI-safe. The rest of this
directory can go the moment the spike report stops being the reason anyone
opens it.
