#!/usr/bin/env bash
#
# PLAN-2.6.md W96: red-team and multi-sample quality runs for the assistant's
# presets, through the *production* launcher — docker/assistant/assistant.sh,
# the same entrypoint, flags and pinned image docker-compose.yml runs — so a
# pass here is a pass for what ships, installer included.
#
#   HOST_LABEL=unraid CORES=4 tools/spike/assistant/verify.sh
#   CONFIGS="small" tools/spike/assistant/verify.sh
#   KEEP=1 CONFIGS=large tools/spike/assistant/verify.sh   # leave it serving
#
# Configurations:
#   control  the 2.3-2.5 default (gemma-3-4b-it Q4_K_M) via ASSISTANT_MODEL_FILE.
#            If it does not reproduce the 2.3 red-team record, the port to the
#            chat endpoint changed the measurement and nothing else here counts.
#   small    the `small` preset, installed by `assistant.sh install small`.
#   large    the `large` preset, likewise.
#
# `install` finds a file already in MODELS by name and only verifies it, so
# weights fetched by candidates-fetch.sh are reused rather than downloaded
# again. KEEP=1 leaves the last configuration serving on PORT, for
# LiveRuntimeTest from another machine; stop it with `docker rm -f
# arsec-verify` — an inference endpoint has no authentication.
set -euo pipefail

cd "$(dirname "$0")/../../.."
export MSYS_NO_PATHCONV=1

CONFIGS="${CONFIGS:-control small large}"
HOST_LABEL="${HOST_LABEL:-$(hostname | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9\n' '-')}"
CORES="${CORES:-4}"
PORT="${PORT:-11437}"
MODELS="${MODELS:-$PWD/data-spike-models}"
KEEP="${KEEP:-}"

# The llama.cpp build the assistant image is based on, read from its
# Dockerfile so the two cannot drift (W101 moved the pin there). The launcher
# is mounted from the checkout below, exactly what that image bakes in.
IMAGE=$(awk '/^FROM / { print $2; exit }' docker/assistant/Dockerfile)
[ -n "$IMAGE" ] || { echo "could not read the assistant image from docker/assistant/Dockerfile" >&2; exit 1; }
NODE=node:22-alpine
NAME=arsec-verify
NET=arsec-verify-net

cleanup() { docker rm -f "$NAME" >/dev/null 2>&1 || true; }
trap '[ -n "$KEEP" ] || cleanup' EXIT
cleanup
docker network inspect "$NET" >/dev/null 2>&1 || docker network create "$NET" >/dev/null
mkdir -p "$MODELS" tools/spike/assistant/results/verify-logs

for config in $CONFIGS; do
    echo
    echo "=== $config ($HOST_LABEL, $CORES cores) ==================================="
    envs=(-e ASSISTANT_THREADS="$CORES")

    case $config in
        control)
            # Pinned and verified like the presets; present means verified in
            # place, not downloaded again.
            DEST="$MODELS" tools/spike/assistant/candidates-fetch.sh gemma3-4b-q4km
            envs+=(-e ASSISTANT_MODEL_FILE=gemma-3-4b-it-Q4_K_M.gguf)
            ;;
        small | large)
            # The installer itself, as an operator would run it: memory check,
            # disk check, download or verify-in-place, select.
            docker run --rm -v "$MODELS":/models -v "$PWD/docker/assistant":/opt/arguesecure:ro \
                --entrypoint bash "$IMAGE" /opt/arguesecure/assistant.sh install "$config"
            envs+=(-e ASSISTANT_MODEL="$config")
            ;;
        *)
            echo "unknown configuration $config" >&2
            exit 1
            ;;
    esac

    cleanup
    docker run -d --name "$NAME" --network "$NET" --cpus "$CORES" -p "$PORT:8080" \
        "${envs[@]}" -v "$MODELS":/models:ro -v "$PWD/docker/assistant":/opt/arguesecure:ro \
        --entrypoint bash "$IMAGE" /opt/arguesecure/assistant.sh serve >/dev/null

    until curl -sf "http://localhost:$PORT/health" >/dev/null 2>&1; do
        if [ "$(docker inspect -f '{{.State.Running}}' "$NAME")" != true ]; then
            docker logs "$NAME" 2>&1 | tail -20
            echo "$config: the runtime exited while loading" >&2
            continue 2
        fi
        sleep 1
    done

    rc=0
    # shellcheck disable=SC2086 # word-splitting VERIFY_ARGS (e.g. "--seeds 1,2") is the point
    docker run --rm --network "$NET" -v "$PWD":/repo -w /repo "$NODE" \
        node tools/spike/assistant/verify.mjs --host "http://$NAME:8080" --host-label "$HOST_LABEL" --config "$config" \
        ${VERIFY_ARGS:-} || rc=$?

    echo "[$config] resident: $(docker stats --no-stream --format '{{.MemUsage}}' "$NAME")"
    log="tools/spike/assistant/results/verify-logs/$HOST_LABEL-$config.log"
    # The server log is evidence for the results beside it (W96 read the
    # large preset's cache evictions out of one). A run that measured nothing
    # (exit 3: the label already had every step) must not replace it with an
    # idle log, and a run that failed keeps its log separately.
    case $rc in
        0) docker logs "$NAME" >"$log" 2>&1 ;;
        3) echo "[$config] nothing measured under HOST_LABEL=$HOST_LABEL; $log left as it was" >&2 ;;
        *) docker logs "$NAME" >"${log%.log}.error.log" 2>&1
           echo "[$config] the harness failed (exit $rc); server log in ${log%.log}.error.log" >&2 ;;
    esac
done

[ -n "$KEEP" ] && echo "left serving on port $PORT; stop it with: docker rm -f $NAME"
[ -n "$KEEP" ] || docker network rm "$NET" >/dev/null 2>&1 || true
echo
echo "results in tools/spike/assistant/results/verify-$HOST_LABEL-*.json"
