#!/usr/bin/env bash
#
# The 2.6 candidate benchmark, end to end: for each configuration, start
# llama.cpp fresh, run job A cold and warm, save the help prefix, restart the
# container, restore it, run job A again, then jobs B and C. One runtime at a
# time — two inference servers on the same cores produce two wrong numbers,
# and both look plausible.
#
#   tools/spike/assistant/candidates.sh                        # every config
#   HOST_LABEL=unraid CORES=8 tools/spike/assistant/candidates.sh
#   CONFIGS="gemma3-qat qwen35-4b" tools/spike/assistant/candidates.sh
#   EXTRA_ARGS="--reasoning-budget 0" LABEL_SUFFIX=-budget0 CONFIGS=qwen35-4b ...
#
# Needs only bash, curl and docker on the host; Node runs in a container.
# Run candidates-fetch.sh to completion first, and stop anything else that uses
# the CPU — including ArgueSecure's own `assistant` service.
#
# Settings, all optional:
#   HOST_LABEL  names the machine in every result file (default: hostname)
#   CORES       threads and CPU quota, together (default 8)
#   CPUSET      pin to these host CPUs, e.g. "0-7" — needed where cores are
#               isolated for VMs, which a quota alone does not avoid
#   MODELS      directory holding the weights (default ./data-spike-models).
#               On Unraid, a pool path (/mnt/<pool>/...), never /mnt/user/...
#   MARGIN_GIB  memory needed beyond the weights (default 3; see preflight)
set -euo pipefail

cd "$(dirname "$0")/../../.."
export MSYS_NO_PATHCONV=1

CONFIGS="${CONFIGS:-baseline gemma3-qat gemma3-qat-draft gemma4-e4b qwen35-4b gemma4-26b-a4b qwen36-35b-a3b}"
HOST_LABEL="${HOST_LABEL:-$(hostname | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9\n' '-')}"
CORES="${CORES:-8}"
CPUSET="${CPUSET:-}"
CONTEXT="${CONTEXT:-12288}"
PORT="${PORT:-11437}"
MODELS="${MODELS:-$PWD/data-spike-models}"
MARGIN_GIB="${MARGIN_GIB:-3}"
EXTRA_ARGS="${EXTRA_ARGS:-}"
LABEL_SUFFIX="${LABEL_SUFFIX:-}"

# The pinned build every non-baseline configuration runs. The baseline runs
# the unpinned tag docker-compose.yml names, because "as compose runs it" is
# what it is a baseline of; its digest is recorded either way.
PINNED=ghcr.io/ggml-org/llama.cpp:server-b11176
UNPINNED=ghcr.io/ggml-org/llama.cpp:server
NODE=node:22-alpine

NAME=arsec-candidate
NET=arsec-candidate-net
SLOTS=arsec_candidate_slots
LOGS=tools/spike/assistant/results/candidates-logs
mkdir -p "$LOGS"

cleanup() { docker rm -f "$NAME" >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup
docker network inspect "$NET" >/dev/null 2>&1 || docker network create "$NET" >/dev/null

harness() { # config, then harness arguments
    local config=$1
    shift
    docker run --rm --network "$NET" -v "$PWD":/repo -w /repo "$NODE" \
        node tools/spike/assistant/candidates.mjs --host "http://$NAME:8080" \
        --host-label "$HOST_LABEL" --config "$config" "$@"
}

record() { harness "$1" --record "$2" --value "$3" >/dev/null; }

# Read from inside a container, so on Docker Desktop it is the VM the model
# actually runs in, and on a Linux host it is the host itself.
meminfo() { # field -> bytes
    docker run --rm "$NODE" awk -v f="$1:" '$1 == f { print $2 * 1024 }' /proc/meminfo
}

# The machine, once per configuration, in every result file.
host_json() {
    docker run --rm -e CORES="$CORES" -e CPUSET="$CPUSET" \
        -e DOCKER_VERSION="$(docker version -f '{{.Server.Version}}')" "$NODE" node -e '
            const fs = require("fs");
            const cpu = fs.readFileSync("/proc/cpuinfo", "utf8");
            const mem = fs.readFileSync("/proc/meminfo", "utf8");
            const field = (k) => (cpu.match(new RegExp("^" + k + "\\s*: (.*)$", "m")) ?? [])[1];
            const kib = (k) => Number((mem.match(new RegExp("^" + k + ":\\s+(\\d+)", "m")) ?? [])[1]) * 1024;
            const sockets = new Set(cpu.match(/^physical id\s*: \d+/gm) ?? ["0"]).size;
            console.log(JSON.stringify({
                cpu: field("model name"),
                logicalCpus: (cpu.match(/^processor\s*:/gm) ?? []).length,
                physicalCores: Number(field("cpu cores")) * sockets || null,
                flags: (field("flags") ?? "").split(" ").filter((f) => /^(avx2|avx512f|avx512_vnni|avx_vnni|amx_tile|fma|f16c)$/.test(f)),
                memTotalBytes: kib("MemTotal"),
                memAvailableBytes: kib("MemAvailable"),
                kernel: require("os").release(),
                docker: process.env.DOCKER_VERSION,
                cores: Number(process.env.CORES),
                cpuset: process.env.CPUSET || null,
            }));'
}

# Seconds until /health answers 200, or failure if the container dies first —
# a model llama.cpp cannot load exits, and waiting on it would hang forever.
wait_ready() {
    local started=$1
    until curl -sf "http://localhost:$PORT/health" >/dev/null 2>&1; do
        if [ "$(docker inspect -f '{{.State.Running}}' "$NAME")" != true ]; then
            echo "container exited while loading" >&2
            return 1
        fi
        sleep 1
    done
    awk "BEGIN { printf \"%.1f\", $(date +%s.%N) - $started }"
}

# Resident memory two ways. `docker stats` is what an operator sees;
# cgroup `memory.peak` is the high-water mark, which a sample taken between
# jobs can miss.
memory() {
    local stats peak
    stats=$(docker stats --no-stream --format '{{.MemUsage}}' "$NAME")
    peak=$(docker exec "$NAME" cat /sys/fs/cgroup/memory.peak 2>/dev/null || echo null)
    printf '{"dockerStats":"%s","cgroupPeakBytes":%s}' "$stats" "$peak"
}

# Refuses a configuration that would not fit, rather than benchmarking it from
# swap: a model paged from disk measures the disk, and the numbers would be
# quoted as the model's. Needed = the weights (they are memory-mapped and
# every expert is touched during prompt processing, so all of a mixture-of-
# experts file must be resident, not just its active share) plus MARGIN_GIB
# for the KV cache and compute buffers at a 12k context.
#
# MemAvailable does not count a ZFS ARC as free even though it shrinks on
# demand; on a ZFS host that makes this check conservative, never optimistic.
preflight() { # config, weight files...
    local config=$1 need=0 avail disk f
    shift
    rm -f "$LOGS/.preflight"
    for f in "$@"; do
        if [ ! -f "$MODELS/$f" ]; then
            echo "missing $MODELS/$f — run candidates-fetch.sh first"
            return 1
        fi
        need=$((need + $(stat -c%s "$MODELS/$f")))
    done
    need=$((need + MARGIN_GIB * 1024 * 1024 * 1024))
    avail=$(meminfo MemAvailable)

    # A saved help slot is ~140 KB per token on the gemma3 baseline, so
    # roughly 1 GB for the whole corpus; 5 GiB is room for any candidate.
    disk=$(docker run --rm -v "$SLOTS":/slots "$NODE" df -Pk /slots | awk 'NR == 2 { print $4 * 1024 }')

    printf '{"needBytes":%s,"memAvailableBytes":%s,"slotDiskFreeBytes":%s}' "$need" "$avail" "$disk" >"$LOGS/.preflight"

    if [ "$avail" -lt "$need" ]; then
        echo "needs $((need / 1073741824)) GiB, $((avail / 1073741824)) GiB available"
        return 1
    fi
    if [ "$disk" -lt $((5 * 1024 * 1024 * 1024)) ]; then
        echo "slot volume has $((disk / 1073741824)) GiB free, needs 5"
        return 1
    fi
}

cpu_args=(--cpus "$CORES")
[ -n "$CPUSET" ] && cpu_args+=(--cpuset-cpus "$CPUSET")

echo "host '$HOST_LABEL': $(host_json)"

for base in $CONFIGS; do
    config="$base$LABEL_SUFFIX"
    image=$PINNED
    # -np 1: one slot, so the context and the prompt cache are not divided
    # between parallel requests the assistant never makes. --cache-reuse lets
    # a prefix match survive a small shift. --slot-save-path is what makes the
    # help prefix survive a restart at all.
    common=(-c "$CONTEXT" -t "$CORES" -np 1 --cache-reuse 256 --slot-save-path /slots --host 0.0.0.0 --port 8080)
    nothink=(--jinja --chat-template-kwargs '{"enable_thinking":false}')

    case $base in
        baseline)
            # Exactly docker-compose.yml's command line; only the CPU quota is
            # added, so it is compared with the others on equal cores.
            image=$UNPINNED
            files=(gemma-3-4b-it-Q4_K_M.gguf)
            args=(-c "$CONTEXT" -t "$CORES" --host 0.0.0.0 --port 8080)
            ;;
        gemma3-qat)
            files=(gemma-3-4b-it-qat-Q4_0.gguf)
            args=("${common[@]}")
            ;;
        gemma3-qat-draft)
            # --draft-max/--draft-min were removed from llama.cpp before this
            # build; these are their replacements.
            files=(gemma-3-4b-it-qat-Q4_0.gguf gemma-3-270m-it-Q8_0.gguf)
            args=("${common[@]}" --model-draft /models/gemma-3-270m-it-Q8_0.gguf
                --spec-type draft-simple --spec-draft-n-max 16 --spec-draft-n-min 1)
            ;;
        gemma4-e4b)
            files=(gemma-4-E4B-it-qat-UD-Q4_K_XL.gguf)
            args=("${common[@]}" "${nothink[@]}")
            ;;
        qwen35-4b)
            files=(Qwen3.5-4B-Q4_K_M.gguf)
            args=("${common[@]}" "${nothink[@]}")
            ;;
        gemma4-26b-a4b)
            files=(gemma-4-26B-A4B-it-qat-UD-Q4_K_XL.gguf)
            args=("${common[@]}" "${nothink[@]}")
            ;;
        qwen36-35b-a3b)
            files=(Qwen3.6-35B-A3B-UD-Q4_K_XL.gguf)
            args=("${common[@]}" "${nothink[@]}")
            ;;
        *)
            echo "unknown configuration $base" >&2
            exit 1
            ;;
    esac
    # The first file is the model; a split GGUF would list its first part.
    args=(-m "/models/${files[0]}" "${args[@]}")
    # shellcheck disable=SC2206 # word-splitting EXTRA_ARGS is the point
    args+=($EXTRA_ARGS)

    echo
    echo "=== $config ==============================================================="
    rm -f "tools/spike/assistant/results/candidates-$HOST_LABEL-$config.json"
    docker volume rm -f "$SLOTS" >/dev/null 2>&1 || true
    docker volume create "$SLOTS" >/dev/null

    record "$config" host "$(host_json)"
    if ! reason=$(preflight "$config" "${files[@]}"); then
        echo "SKIPPED: $reason"
        record "$config" skipped "\"$reason\""
        [ -f "$LOGS/.preflight" ] && record "$config" preflight "$(cat "$LOGS/.preflight")"
        continue
    fi
    record "$config" preflight "$(cat "$LOGS/.preflight")"

    started=$(date +%s.%N)
    docker run -d --name "$NAME" --network "$NET" "${cpu_args[@]}" \
        -v "$MODELS":/models:ro -v "$SLOTS":/slots -p "$PORT:8080" \
        "$image" "${args[@]}" >/dev/null

    if ! load=$(wait_ready "$started"); then
        docker logs "$NAME" >"$LOGS/$HOST_LABEL-$config.log" 2>&1
        record "$config" failed '"container exited while loading; see candidates-logs"'
        cleanup
        continue
    fi

    record "$config" image "\"$image\""
    record "$config" imageDigest "\"$(docker image inspect -f '{{index .RepoDigests 0}}' "$image")\""
    record "$config" args "$(printf '%s\n' "${args[@]}" | docker run --rm -i "$NODE" node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>console.log(JSON.stringify(s.trim().split("\n"))))')"
    record "$config" cores "$CORES"
    record "$config" loadSeconds "$load"
    record "$config" memoryAfterLoad "$(memory)"

    harness "$config" --steps props,a-cold,a-warm,save
    record "$config" memoryAfterA "$(memory)"

    # The restart is the measurement: does the help prefix survive it? Only
    # through the slot file — the in-memory cache dies with the process.
    started=$(date +%s.%N)
    docker restart "$NAME" >/dev/null
    if ! restart=$(wait_ready "$started"); then
        docker logs "$NAME" >"$LOGS/$HOST_LABEL-$config.log" 2>&1
        record "$config" failed '"container exited on restart; see candidates-logs"'
        cleanup
        continue
    fi
    record "$config" restartSeconds "$restart"

    harness "$config" --steps restore,a-restored,b-outage,b-payment,c
    record "$config" memoryAfterC "$(memory)"

    docker logs "$NAME" >"$LOGS/$HOST_LABEL-$config.log" 2>&1
    cleanup
done

rm -f "$LOGS/.preflight"
docker volume rm -f "$SLOTS" >/dev/null 2>&1 || true
docker network rm "$NET" >/dev/null 2>&1 || true
echo
echo "results in tools/spike/assistant/results/candidates-$HOST_LABEL-*.json"
