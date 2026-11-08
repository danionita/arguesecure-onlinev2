#!/usr/bin/env bash
#
# The Playwright release scenarios, start to finish: boot the throwaway
# stack, run the suite, tear everything down. The PC (through
# `checks.sh e2e`) and CI's e2e job both run this file, so they cannot drift.
#
#   tools/dev/checks/e2e.sh                       # whole suite
#   tools/dev/checks/e2e.sh --grep "scenario 3"   # extra arguments go to `npx playwright test`
#
# The stack is a second compose project (arguesecure-e2e) on its own port and
# data directory, so it can run next to a real local stack. Scenario 1 is
# first-run setup and needs an empty users table; the script therefore always
# starts from nothing and always removes what it made, pass or fail: the
# containers, their volumes, ./data-e2e, and the .env.e2e it generated. It
# refuses to start if .env.e2e or ./data-e2e already exist (it will not delete
# files it did not make); remove them first. A
# .env is created only if absent (compose needs one to exist, see
# tests/e2e/README.md) and is removed again; an existing .env is never touched.
#
# A run that must survive its caller. The session or shell that starts a half
# hour run can be stopped meanwhile and take its children with it, so a long
# run can be started detached and read back from files:
#
#   tools/dev/checks/e2e.sh --detach [args]   # start in the background, return at once
#   tools/dev/checks/e2e.sh --wait [seconds]  # progress line every 30 s (default 480 s total);
#                                             # exit 0 passed, 1 failed, 2 still running, 3 died or aborted, 4 none
#   tools/dev/checks/e2e.sh --status          # state, pass/fail counts, last log lines
#
# State lives in $TMPDIR/arguesecure-e2e-state (lock, run.log, status). One
# run per machine: the stack has a fixed compose project and port, so a second
# run, from any checkout or worktree, would tear the first down. The lock
# refuses it and names the holder. A running run touches a heartbeat file every
# 15 s; a lock with no heartbeat for 90 s is a run that was killed (a pid is
# not trusted: it can be reused after a reboot, or invisible from another
# shell on Windows), and the next start removes that run's containers, volumes,
# data and env file itself, then proceeds. Whether a detached run really
# outlives the process that started it depends on how that process is ended;
# if it does not, run Playwright in a container instead.
#
# Where Playwright runs: on the host when `npx` is found (its browser must be
# installed, `npx playwright install chromium`; CI does that in its own step),
# otherwise in the official Playwright container, which needs no Node or browser
# on the host. The container shares the app container's network, so the suite
# dials http://localhost exactly as a host browser would, and the app's origin
# checks see the same host. E2E_PLAYWRIGHT=host|container forces one;
# E2E_PLAYWRIGHT_IMAGE overrides the image (default: the tag matching the
# locked @playwright/test version).
#
# Environment: E2E_PORT (default 8100).
set -uo pipefail
export MSYS_NO_PATHCONV=1

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$ROOT" || exit 1

PORT="${E2E_PORT:-8100}"
PW_MODE="${E2E_PLAYWRIGHT:-}"
if [ -z "$PW_MODE" ]; then if command -v npx >/dev/null 2>&1; then PW_MODE=host; else PW_MODE=container; fi; fi
case "$PW_MODE" in host|container) ;; *) echo "E2E_PLAYWRIGHT must be host or container" >&2; exit 1 ;; esac
PW_CONTAINER=arguesecure-e2e-playwright
PROJECT=arguesecure-e2e
COMPOSE=(docker compose -p "$PROJECT" --env-file .env.e2e -f docker-compose.yml -f docker-compose.e2e.yml)

STATE="${TMPDIR:-/tmp}/arguesecure-e2e-state"
LOCK="$STATE/lock"
LOG="$STATE/run.log"
STATUS="$STATE/status"
now() { date '+%Y-%m-%dT%H:%M:%S%z'; }
age() { echo $(( $(date +%s) - $(stat -c %Y "$1" 2>/dev/null || echo 0) )); }
# A run is alive while its heartbeat is fresh; a lock younger than 30 s that has
# no heartbeat yet is a run that is starting.
holder_alive() {
    [ -d "$LOCK" ] || return 1
    if [ -f "$STATE/heartbeat" ]; then [ "$(age "$STATE/heartbeat")" -lt 90 ]; else [ "$(age "$LOCK")" -lt 30 ]; fi
}
holder() { cat "$LOCK/pid" 2>/dev/null; }
field() { sed -n "s/^$1=//p" "$STATUS" 2>/dev/null | tail -1; }
counts() {
    # Playwright's list reporter prints one "ok" or "x" line per test.
    local ok bad
    ok=$(grep -cE '^[[:space:]]+(✓|ok)[[:space:]]+[0-9]+' "$LOG" 2>/dev/null)
    bad=$(grep -cE '^[[:space:]]+(✘|x)[[:space:]]+[0-9]+' "$LOG" 2>/dev/null)
    echo "passed=${ok:-0} failed=${bad:-0}"
}
# One of: none, running, passed, failed, died.
run_state() {
    local st; st=$(field state)
    [ -n "$st" ] || { echo none; return; }
    case "$st" in
        starting) [ "$(age "$STATUS")" -lt 120 ] && echo running || echo died ;;
        running) holder_alive && echo running || echo died ;;
        aborted) echo died ;;
        *) echo "$st" ;;
    esac
}
case "${1:-}" in
    --status)
        st=$(run_state)
        echo "state=$st $(counts) started=$(field started) finished=$(field finished) exit=$(field exit)"
        [ -f "$LOG" ] && tail -n "${E2E_STATUS_LINES:-8}" "$LOG"
        [ "$st" = died ] && echo "the run was killed before it finished; start it again (the leftovers are cleared automatically)" >&2
        case "$st" in passed) exit 0 ;; running) exit 2 ;; none) exit 4 ;; died) exit 3 ;; *) exit 1 ;; esac
        ;;
    --wait)
        limit=${2:-480}; waited=0
        while :; do
            st=$(run_state)
            echo "$(now) state=$st $(counts)"
            case "$st" in
                passed) exit 0 ;; failed) tail -n 20 "$LOG"; exit 1 ;;
                died) echo "the run was killed before it finished" >&2; exit 3 ;;
                none) echo "no run recorded" >&2; exit 4 ;;
            esac
            [ "$waited" -ge "$limit" ] && exit 2
            sleep 30; waited=$((waited + 30))
        done
        ;;
    --detach)
        shift
        mkdir -p "$STATE"
        if holder_alive; then
            echo "an e2e run is already in progress (pid $(holder), root $(cat "$LOCK/root" 2>/dev/null)); read it with --status" >&2
            exit 1
        fi
        id="$(now)-$$"
        { echo "state=starting"; echo "id=$id"; echo "started=$(now)"; } > "$STATUS"
        : > "$LOG"
        # setsid/nohup so the run outlives the shell that started it.
        if command -v setsid >/dev/null 2>&1; then
            E2E_RUN_ID="$id" setsid nohup "$ROOT/tools/dev/checks/e2e.sh" --foreground-run "$@" >>"$LOG" 2>&1 < /dev/null &
        else
            E2E_RUN_ID="$id" nohup "$ROOT/tools/dev/checks/e2e.sh" --foreground-run "$@" >>"$LOG" 2>&1 < /dev/null &
        fi
        disown 2>/dev/null || true
        # Report what the child did, not that it was launched.
        for _ in $(seq 1 30); do
            sleep 1
            [ "$(field id)" = "$id" ] || continue
            case "$(field state)" in
                running) echo "started; follow it with: tools/dev/checks/e2e.sh --wait   (log: $LOG)"; exit 0 ;;
                passed|failed|aborted) echo "the run ended at once:" >&2; tail -n 20 "$LOG" >&2; exit 1 ;;
            esac
        done
        echo "the run did not start within 30 s; log:" >&2; tail -n 20 "$LOG" >&2
        printf 'state=failed\nid=%s\nexit=1\nfinished=%s\n' "$id" "$(now)" > "$STATUS"
        exit 1
        ;;
    --foreground-run) shift ;;
esac

# Remove what a killed run left, found by compose label so no env file is
# needed. Only called with the lock held and a dead holder.
recover() {
    local old_root; old_root=$(cat "$LOCK/root" 2>/dev/null)
    echo "recovering from a run (pid $(holder)) that was killed" >&2
    local c v n
    c=$(docker ps -aq --filter "label=com.docker.compose.project=$PROJECT")
    [ -z "$c" ] || echo "$c" | xargs docker rm -f >/dev/null 2>&1
    docker rm -f "$PW_CONTAINER" >/dev/null 2>&1
    v=$(docker volume ls -q --filter "label=com.docker.compose.project=$PROJECT")
    [ -z "$v" ] || echo "$v" | xargs docker volume rm -f >/dev/null 2>&1
    n=$(docker network ls -q --filter "label=com.docker.compose.project=$PROJECT")
    [ -z "$n" ] || echo "$n" | xargs docker network rm >/dev/null 2>&1
    if [ "$old_root" = "$ROOT" ]; then
        wipe_data; rm -f .env.e2e
    elif [ -n "$old_root" ]; then
        echo "its files are in $old_root (.env.e2e, data-e2e); remove them there" >&2
    fi
}

wipe_data() {
    # The database wrote ./data-e2e as its own uid, so a plain rm may be
    # refused; a throwaway container removes it, and plain/sudo rm is the
    # fallback when the image cannot be pulled (a rate-limited registry).
    [ -e data-e2e ] || return 0
    docker run --rm -v "$(pwd -W 2>/dev/null || pwd):/w" alpine rm -rf /w/data-e2e 2>/dev/null \
        || rm -rf data-e2e 2>/dev/null || sudo -n rm -rf data-e2e 2>/dev/null
    if [ -e data-e2e ]; then
        echo "WARNING: could not remove ./data-e2e; remove it by hand before the next run:" >&2
        echo "  docker run --rm -v \"\$PWD:/w\" alpine rm -rf /w/data-e2e   (or sudo rm -rf data-e2e)" >&2
    fi
}
hex() { head -c "$1" /dev/urandom | od -An -tx1 | tr -d ' \n'; }
b64() { head -c 24 /dev/urandom | base64; }

owned=0
made_env=0
# shellcheck disable=SC2317  # called through the trap
cleanup() {
    local code=$?
    trap - EXIT
    # Only what this run made: refusing to start (below) leaves a developer's
    # own .env.e2e or ./data-e2e alone.
    if [ "$owned" -eq 1 ]; then
        "${COMPOSE[@]}" down -v --remove-orphans >/dev/null 2>&1
        wipe_data
        rm -f .env.e2e
    fi
    [ "$made_env" -eq 1 ] && rm -f .env
    if [ "$locked" -eq 1 ]; then
        state=failed; [ "$code" -eq 0 ] && state=passed; [ "$aborted" -eq 1 ] && state=aborted
        {
            echo "state=$state"
            echo "exit=$code"; echo "finished=$(now)"
        } >> "$STATUS"
        [ -z "$hb_pid" ] || kill "$hb_pid" 2>/dev/null
        rm -f "$STATE/heartbeat"
        rm -rf "$LOCK"
    fi
    exit "$code"
}
locked=0
aborted=0
hb_pid=
trap cleanup EXIT
trap 'aborted=1; exit 130' INT
trap 'aborted=1; exit 143' TERM
# The session that started this run may be stopped; the run is not to stop with it.
trap '' HUP

# One run per machine (see the header). mkdir is the atomic step.
mkdir -p "$STATE"
if ! mkdir "$LOCK" 2>/dev/null; then
    if holder_alive; then
        echo "another e2e run is in progress (pid $(holder), started $(field started), root $(cat "$LOCK/root" 2>/dev/null)); wait for it or read it with --status" >&2
        exit 1
    fi
    recover
    rm -rf "$LOCK"; rm -f "$STATE/heartbeat"
    mkdir "$LOCK" || { echo "could not take the e2e lock at $LOCK" >&2; exit 1; }
fi
locked=1
echo $$ > "$LOCK/pid"; echo "$ROOT" > "$LOCK/root"
touch "$STATE/heartbeat"
# The heartbeat stops when this script does, however it ends.
me=$$
( while kill -0 "$me" 2>/dev/null; do touch "$STATE/heartbeat"; sleep 15; done ) &
hb_pid=$!
{ echo "state=running"; echo "id=${E2E_RUN_ID:-manual-$$}"; echo "started=$(now)"; echo "root=$ROOT"; } > "$STATUS"

# A suite that fails for want of memory looks like a flaky test; say so first.
# The memory that counts is the Docker engine's (the WSL2 VM on Windows).
engine_bytes=$(docker info --format '{{.MemTotal}}' 2>/dev/null)
case "$engine_bytes" in ''|*[!0-9]*) engine_mb=0 ;; *) engine_mb=$(( engine_bytes / 1048576 )) ;; esac
if [ "$engine_mb" -le 0 ]; then
    echo "note: could not read the Docker engine's memory, so it was not checked" >&2
elif [ "$engine_mb" -lt 6144 ]; then
    echo "WARNING: the Docker engine has ${engine_mb} MB; the stack and the browser need about 6 GB" >&2
else
    echo "docker engine memory: ${engine_mb} MB" >&2
fi
others=$(docker ps --format '{{.Names}}' 2>/dev/null | grep -vc "^$PROJECT" || true)
[ "${others:-0}" -le 6 ] || echo "WARNING: $others other containers are running and use memory; stop the ones this run does not need" >&2

if [ -e .env.e2e ] || [ -e data-e2e ]; then
    cat >&2 <<MSG
found .env.e2e or ./data-e2e from an earlier run (or one you made by hand).
If nothing of yours is in them, clear the leftovers and run again:
  docker compose -p $PROJECT --env-file .env.e2e -f docker-compose.yml -f docker-compose.e2e.yml down -v
  docker run --rm -v "\$PWD:/w" alpine rm -rf /w/data-e2e   (or: sudo rm -rf data-e2e)
  rm -f .env.e2e
A .env that an interrupted run made (it holds the e2e port and passwords) can
go too, if you did not write it yourself.
MSG
    exit 1
fi
(git check-ignore -q .env.e2e && git check-ignore -q data-e2e/) || {
    echo ".env.e2e or data-e2e/ is not gitignored; refusing to write secrets" >&2; exit 1; }

# Secrets are generated here for this run and exist nowhere else.
SECRETS=$(hex 16)$(hex 16)$(hex 16)$(hex 16)
[ ${#SECRETS} -eq 128 ] || { echo "could not read /dev/urandom" >&2; exit 1; }
owned=1
cp .env.example .env.e2e
{
    echo "APP_PORT=$PORT"
    echo "DATA_PATH=./data-e2e"
    # In container mode the browser reaches the app on its own port 80.
    if [ "$PW_MODE" = container ]; then echo "APP_URL=http://localhost"; else echo "APP_URL=http://localhost:$PORT"; fi
    echo "ARGUESECURE_VERSION=e2e"
    echo "DB_PASSWORD=${SECRETS:0:32}"
    echo "DB_ROOT_PASSWORD=${SECRETS:32:32}"
    echo "REVERB_APP_ID=$(( $(od -An -N3 -tu4 /dev/urandom | tr -d ' ') % 900000 + 100000 ))"
    echo "REVERB_APP_KEY=${SECRETS:64:32}"
    echo "REVERB_APP_SECRET=${SECRETS:96:32}"
} >> .env.e2e

# Compose appends env_file lists when it merges an overlay, so .env must
# exist too. It is a copy, so the merge order cannot matter.
if [ ! -f .env ]; then cp .env.e2e .env; made_env=1; fi

# Retry the boot: anonymous composer downloads are rate limited.
delay=${E2E_BOOT_RETRY_DELAY:-60}
booted=0
for attempt in 1 2 3 4; do
    if "${COMPOSE[@]}" up -d --build; then booted=1; break; fi
    if [ "$attempt" -eq 4 ]; then break; fi
    echo "boot attempt $attempt failed; retrying in ${delay}s" >&2
    # A half-started database would keep its first password on the retry.
    "${COMPOSE[@]}" down -v --remove-orphans >/dev/null 2>&1; wipe_data
    sleep "$delay"; delay=$(( delay * 2 ))
done
# On the last failure the stack is still there, so its logs are readable;
# the EXIT trap tears it down afterwards.
[ "$booted" -eq 1 ] || { echo "boot failed after 4 attempts; container logs:" >&2; "${COMPOSE[@]}" logs --no-color --tail=400; exit 1; }

up=0
for _ in $(seq 1 60); do
    if curl -fsS "http://localhost:$PORT/up" >/dev/null 2>&1; then up=1; break; fi
    sleep 2
done
[ "$up" -eq 1 ] || { echo "e2e stack never became healthy" >&2; "${COMPOSE[@]}" logs --no-color; exit 1; }

# Order-dependent suite: one worker, no sharding (playwright.config.ts).
# Passwords are per run and never stored.
E2E_ADMIN_PASSWORD="$(b64)" E2E_COLLABORATOR_PASSWORD="$(b64)" E2E_OUTSIDER_PASSWORD="$(b64)"
[ -n "$E2E_ADMIN_PASSWORD" ] || { echo "could not generate passwords" >&2; exit 1; }
export E2E_ADMIN_PASSWORD E2E_COLLABORATOR_PASSWORD E2E_OUTSIDER_PASSWORD
if [ "$PW_MODE" = host ]; then
    E2E_BASE_URL="http://localhost:$PORT" npx playwright test "$@"
else
    ver=$(awk '/"node_modules\/@playwright\/test"/ {f=1} f && /"version"/ {gsub(/[^0-9.]/, "", $2); print $2; exit}' package-lock.json)
    image="${E2E_PLAYWRIGHT_IMAGE:-mcr.microsoft.com/playwright:v${ver}-noble}"
    app_cid=$("${COMPOSE[@]}" ps -q app)
    if [ -z "$app_cid" ] || [ -z "$ver" ]; then
        echo "cannot start the Playwright container (app container or locked version not found)" >&2; exit 1
    fi
    echo "running Playwright in $image" >&2
    # node_modules lives in a named volume so the host checkout stays clean and
    # the install is reused until package-lock.json changes. Secrets are passed
    # by name, never as values.
    docker run --rm --name "$PW_CONTAINER" --ipc=host --network "container:$app_cid" \
        -v "$(pwd -W 2>/dev/null || pwd):/work" -v arguesecure-e2e-node-modules:/work/node_modules -w /work \
        -e E2E_ADMIN_PASSWORD -e E2E_COLLABORATOR_PASSWORD -e E2E_OUTSIDER_PASSWORD -e E2E_BASE_URL=http://localhost \
        "$image" bash -c 'cmp -s package-lock.json node_modules/.lock-copy || { npm ci --no-audit --no-fund && cp package-lock.json node_modules/.lock-copy; } && npx playwright test "$@"' _ "$@"
fi
code=$?
if [ "$code" -ne 0 ]; then
    echo "=== container logs (e2e failed with $code)" >&2
    "${COMPOSE[@]}" logs --no-color --tail=400
fi
exit "$code"
