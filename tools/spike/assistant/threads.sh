#!/usr/bin/env bash
#
# The thread sweep, end to end: start llama.cpp under a CPU budget, measure the
# three jobs, stop it, repeat.
#
# Why this is a script and not a note in the README: two inference servers
# competing for the same cores produce two wrong numbers, and the failure is
# silent — both runs finish and both report plausible figures. Starting and
# stopping the runtime around each measurement is the only way the sweep means
# anything.
#
# Both knobs move together. `--cpus` is the quota the container actually gets;
# `-t` is how many threads llama.cpp spawns inside it. Real deployments set the
# second (ASSISTANT_THREADS) and often forget the first, which is how an
# assistant ends up starving the database it shares a host with.
#
#   MODEL=/abs/path/to/gemma-3-4b-it-Q4_K_M.gguf tools/spike/assistant/threads.sh
#   THREADS="4 6" tools/spike/assistant/threads.sh
#
# Roughly six minutes per setting on eight cores and proportionally longer on
# fewer, dominated by job C.
set -euo pipefail

MODEL="${MODEL:?set MODEL to the absolute path of the GGUF}"
THREADS="${THREADS:-4 6 8}"
PORT="${PORT:-11436}"
CONTEXT="${CONTEXT:-12288}"
NAME=arsec-threads-sweep

cd "$(dirname "$0")/../../.."

cleanup() { docker rm -f "$NAME" >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup

for t in $THREADS; do
    echo
    echo "=== ${t} threads, ${t} cores ==========================================="

    # The model is mounted read-only from its own directory, never taken from
    # an Ollama volume: Ollama ships a custom gemma3 variant whose metadata
    # upstream llama.cpp rejects outright (deployment.md §9.2).
    docker run -d --name "$NAME" \
        --cpus "$t" \
        -v "$(dirname "$MODEL")":/models:ro \
        -p "${PORT}:8080" \
        ghcr.io/ggml-org/llama.cpp:server \
        -m "/models/$(basename "$MODEL")" \
        -c "$CONTEXT" -t "$t" --host 0.0.0.0 --port 8080 >/dev/null

    echo "waiting for the model to load..."
    until curl -sf "http://localhost:${PORT}/health" >/dev/null 2>&1; do sleep 2; done

    node tools/spike/assistant/threads.mjs --host "http://localhost:${PORT}" --label "t${t}"

    # Resident size, read from the container rather than from the runtime: this
    # is the number an operator budgets RAM against, and it includes the KV
    # cache for the context window, which the model file size does not.
    docker stats --no-stream --format '{{.MemUsage}}' "$NAME" \
        | sed "s/^/[t${t}] resident: /"

    cleanup
done

echo
echo "results in tools/spike/assistant/results/threads-*.json"
