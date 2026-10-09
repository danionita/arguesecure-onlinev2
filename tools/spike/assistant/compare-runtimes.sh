#!/usr/bin/env bash
#
# Open question 1: llama.cpp server, Ollama, or vllm-on-CPU.
#
# Throughput is the thing everyone compares and the thing that cannot differ
# much — Ollama embeds llama.cpp, so both are running the same kernels on the
# same weights. This script measures it anyway, on the *same GGUF file*, so
# that "they are equivalent on speed" is a measurement rather than an
# assumption and the decision can be made on the things that do differ.
#
# It reuses the blob Ollama already pulled instead of downloading a second
# copy — but **only some blobs are portable**. An Ollama blob is usually a
# plain GGUF: `qwen2.5:7b-instruct` loads into llama.cpp unchanged.
# `gemma3:4b-it` does not, because Ollama ships a custom variant of it:
#
#   error loading model hyperparameters:
#     key not found in model: gemma3.attention.layer_norm_rms_epsilon
#
# So this script works for the model it is pointed at or it does not, and the
# error says which. For a model whose Ollama blob is rejected, fetch the GGUF
# from its publisher and mount that directory instead — and check the byte
# count, because a truncated download exits 0 and only fails at load.
#
# Run this AFTER bench.mjs has finished. Two inference servers competing for
# eight cores would produce two wrong numbers.
#
#   tools/spike/assistant/compare-runtimes.sh <blob-sha256-filename>
set -euo pipefail

BLOB="${1:?pass the blob filename, e.g. sha256-aeda25e6...}"
THREADS="${THREADS:-8}"
PORT="${PORT:-11435}"

cleanup() { docker rm -f arsec-spike-llamacpp >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup

docker run -d --name arsec-spike-llamacpp \
    -v arsec_spike_models:/models:ro \
    -p "${PORT}:8080" \
    ghcr.io/ggml-org/llama.cpp:server \
    -m "/models/models/blobs/${BLOB}" \
    -c 12288 -t "${THREADS}" --host 0.0.0.0 --port 8080 >/dev/null

echo "waiting for llama.cpp to load the model..."
until curl -sf "http://localhost:${PORT}/health" >/dev/null 2>&1; do sleep 2; done

# The same job B prompt the Ollama run used, through llama.cpp's own endpoint.
# `/completion` returns a `timings` object with the same two rates Ollama
# reports, so the comparison is like for like.
node --input-type=module -e "
import { jobB } from './tools/spike/assistant/fixtures.mjs';
const r = await (await fetch('http://localhost:${PORT}/completion', {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({
        prompt: jobB(),
        n_predict: 512,
        temperature: 0,
        seed: 42,
        cache_prompt: true,
    }),
})).json();
console.log(JSON.stringify({
    promptTokens: r.timings.prompt_n,
    promptTokensPerSecond: r.timings.prompt_per_second,
    outputTokens: r.timings.predicted_n,
    outputTokensPerSecond: r.timings.predicted_per_second,
}, null, 2));
" | tee tools/spike/assistant/results/llamacpp.json

echo
echo "image sizes:"
docker images --format '{{.Repository}}:{{.Tag}} {{.Size}}' \
    | grep -E 'ollama/ollama|ggml-org/llama.cpp'
