#!/usr/bin/env bash
#
# CLAUDE.md's seven checks, on any machine with Docker and bash (Git Bash on
# Windows is fine) — including a fresh clone, such as a Claude Code cloud
# thread's.
#
#   tools/dev/checks/checks.sh setup                  # once: services, .env files, deps
#   tools/dev/checks/checks.sh                        # all seven
#   tools/dev/checks/checks.sh pint phpstan pest      # some
#   tools/dev/checks/checks.sh pest --filter=Assistant
#   tools/dev/checks/checks.sh fix app/X.php resources/js/Y.vue   # pint/prettier --write
#   tools/dev/checks/checks.sh teardown               # remove everything it created
#
# Two modes, chosen by CHECKS_MODE (default: native when php, composer and
# npm are all on PATH, containers otherwise):
#
#   containers  PHP and Node in containers, for a machine without them. The
#               checks run against a copy in a Docker volume, not the
#               checkout: on Docker Desktop for Windows, PHP's
#               RecursiveDirectoryIterator was found to silently under-read a
#               bind-mounted directory (6 of 33 migrations, every time), and
#               Larastan's migration scanner uses it — so phpstan reported
#               ~400 bogus errors against the bind mount and none against a
#               volume copy. Every run starts with an rsync of the checkout
#               into the volume, and `fix` copies formatted files back.
#   native      the machine's own PHP 8.3 and Node, in the checkout — what CI
#               does, and what a cloud thread's VM has (W100). `setup` first
#               checks the PHP extensions the suite needs and names the
#               packages if one is missing (tools/dev/checks/cloud-setup.sh
#               installs them).
#
# MariaDB and Redis run in Docker in both modes, published on loopback only
# (CHECKS_DB_PORT, CHECKS_REDIS_PORT) for the native side.
#
# Everything it creates is named with CHECKS_PREFIX (default arsec-check) and
# removed by `teardown`. `.env` and `.env.testing` are written only if absent,
# only after confirming git ignores them, with keys and a password generated
# here and nowhere else.
set -uo pipefail
export MSYS_NO_PATHCONV=1

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
HERE="$ROOT/tools/dev/checks"
host_path() { if command -v cygpath >/dev/null 2>&1; then cygpath -w "$1"; else printf '%s' "$1"; fi; }
ROOT_HOST="$(host_path "$ROOT")"

PREFIX="${CHECKS_PREFIX:-arsec-check}"
NET="$PREFIX-net"
VOL="$PREFIX-vol"
PHP_IMAGE="$PREFIX-php"
SYNC_IMAGE="$PREFIX-sync"
DB="$PREFIX-db"
REDIS="$PREFIX-redis"
NODE_IMAGE=node:22-alpine
DB_PORT="${CHECKS_DB_PORT:-33306}"
REDIS_PORT="${CHECKS_REDIS_PORT:-36379}"

if [ -z "${CHECKS_MODE:-}" ]; then
    if command -v php >/dev/null && command -v composer >/dev/null && command -v npm >/dev/null; then
        CHECKS_MODE=native
    else
        CHECKS_MODE=containers
    fi
fi
case "$CHECKS_MODE" in
    native | containers) ;;
    *) echo "CHECKS_MODE must be native or containers, not '$CHECKS_MODE'" >&2; exit 64 ;;
esac

# The PHP extensions the suite needs: CI's list (.github/workflows/ci.yml)
# plus what the framework itself loads. composer check-platform-reqs covers
# the ones composer.json declares; phpredis and pdo_mysql are configuration
# choices it cannot see.
PHP_EXTENSIONS=(pdo_mysql redis intl bcmath zip pcntl mbstring xml curl gd)

RESULTS=()
FAILED=0

random_hex() { docker run --rm alpine sh -c 'head -c 16 /dev/urandom | od -An -tx1 | tr -d " \n"'; }
random_key() { docker run --rm alpine sh -c 'head -c 32 /dev/urandom | base64'; }

if [ "$CHECKS_MODE" = native ]; then
    sync() { :; }
    # .env.testing names the database by its container name, for the
    # container mode; a real environment variable wins over it (Dotenv never
    # overwrites one), so the native side reaches the published ports instead.
    php_run() {
        (cd "$ROOT" && APP_ENV=testing DB_HOST=127.0.0.1 DB_PORT="$DB_PORT" \
            REDIS_HOST=127.0.0.1 REDIS_PORT="$REDIS_PORT" "$@")
    }
    node_run() { (cd "$ROOT" && "$@"); }
else
    sync() {
        docker run --rm -v "$ROOT_HOST:/src:ro" -v "$VOL:/dst" "$SYNC_IMAGE" \
            rsync -a --delete --exclude .git --exclude vendor --exclude node_modules /src/ /dst/
    }
    php_run() { docker run --rm --network "$NET" -v "$VOL:/app" -w /app -e APP_ENV=testing "$PHP_IMAGE" "$@"; }
    node_run() { docker run --rm -v "$VOL:/app" -w /app "$NODE_IMAGE" "$@"; }
fi

record() { # name status
    if [ "$2" -eq 0 ]; then RESULTS+=("PASS  $1"); else RESULTS+=("FAIL  $1 (exit $2)"); FAILED=$((FAILED + 1)); fi
}

# Refuses to write a file git would commit. A subshell cd, not `git -C
# "$ROOT"`: with MSYS_NO_PATHCONV set for docker's sake, Git Bash would hand
# git.exe an unconverted /c/… path.
ignored() { (cd "$ROOT" && git check-ignore -q "$1") || { echo "$1 is not gitignored; refusing to write a secret into it" >&2; exit 1; }; }

native_preflight() {
    local missing=() ext
    php -r 'exit(version_compare(PHP_VERSION, "8.3", ">=") ? 0 : 1);' || { echo "PHP 8.3 or later is needed; found $(php -r 'echo PHP_VERSION;')" >&2; exit 1; }
    for ext in "${PHP_EXTENSIONS[@]}"; do php -m | grep -qix "$ext" || missing+=("$ext"); done
    if [ ${#missing[@]} -gt 0 ]; then
        echo "PHP extensions missing: ${missing[*]}" >&2
        echo "On Ubuntu: sudo apt-get install -y $(printf 'php8.3-%s ' "${missing[@]/pdo_mysql/mysql}")" >&2
        echo "(or run tools/dev/checks/cloud-setup.sh, or CHECKS_MODE=containers)" >&2
        exit 1
    fi
    (cd "$ROOT" && composer check-platform-reqs --lock --no-interaction >/dev/null 2>&1) || {
        (cd "$ROOT" && composer check-platform-reqs --lock --no-interaction | grep -v ' success')
        echo "composer.lock needs the above; see tools/dev/checks/cloud-setup.sh" >&2
        exit 1
    }
}

# A fresh clone has no .env, and every artisan command aborts without one:
# .env.example selects the reverb broadcaster but ships no credentials, and
# routes/channels.php resolves the broadcaster as routes load. Written the
# way CI's "Prepare .env" step and docker/entrypoint.sh do it, so the real
# code path stays under test.
write_env() {
    local env="$ROOT/.env"
    [ -f "$env" ] && return 0
    ignored .env
    {
        sed "s|^APP_KEY=.*|APP_KEY=base64:$(random_key)|" "$ROOT/.env.example"
        echo "REVERB_APP_ID=$((100000 + RANDOM % 900000))"
        echo "REVERB_APP_KEY=$(random_hex)"
        echo "REVERB_APP_SECRET=$(random_hex)"
    } >"$env"
    echo "wrote .env from .env.example (gitignored)"
}

write_env_testing() {
    local env="$ROOT/.env.testing" password
    [ -f "$env" ] && return 0
    ignored .env.testing
    password="$(random_hex)"
    cat >"$env" <<EOF
APP_ENV=testing
APP_KEY=base64:$(random_key)
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=mysql
DB_HOST=$DB
DB_PORT=3306
DB_DATABASE=arguesecure_test
DB_USERNAME=arguesecure
DB_PASSWORD=$password

REDIS_CLIENT=phpredis
REDIS_HOST=$REDIS
REDIS_PORT=6379
REDIS_PASSWORD=null
EOF
    echo "wrote .env.testing (gitignored)"
}

setup() {
    local started=$SECONDS password
    echo "mode: $CHECKS_MODE"
    [ "$CHECKS_MODE" = native ] && native_preflight
    docker info >/dev/null 2>&1 || { echo "Docker is not running (the test database runs in it)" >&2; exit 1; }
    if [ "$CHECKS_MODE" = containers ]; then
        docker build -q -t "$PHP_IMAGE" -f "$(host_path "$HERE/Dockerfile.php")" "$(host_path "$HERE")" >/dev/null || exit 1
        docker build -q -t "$SYNC_IMAGE" -f "$(host_path "$HERE/Dockerfile.sync")" "$(host_path "$HERE")" >/dev/null || exit 1
        docker volume inspect "$VOL" >/dev/null 2>&1 || docker volume create "$VOL" >/dev/null
    fi
    docker network inspect "$NET" >/dev/null 2>&1 || docker network create "$NET" >/dev/null

    write_env
    write_env_testing
    password="$(sed -n 's/^DB_PASSWORD=//p' "$ROOT/.env.testing")"

    if ! docker inspect "$DB" >/dev/null 2>&1; then
        docker run -d --name "$DB" --network "$NET" -p "127.0.0.1:$DB_PORT:3306" \
            -e MARIADB_ROOT_PASSWORD="$password" -e MARIADB_DATABASE=arguesecure_test \
            -e MARIADB_USER=arguesecure -e MARIADB_PASSWORD="$password" mariadb:11 >/dev/null
    fi
    docker start "$DB" >/dev/null
    # A database container from before W100 publishes no port, and the
    # native side could not reach it.
    if [ "$CHECKS_MODE" = native ] && [ -z "$(docker port "$DB" 3306 2>/dev/null)" ]; then
        echo "$DB publishes no port; run '$0 teardown' once, then setup again" >&2
        exit 1
    fi
    docker inspect "$REDIS" >/dev/null 2>&1 ||
        docker run -d --name "$REDIS" --network "$NET" -p "127.0.0.1:$REDIS_PORT:6379" redis:7-alpine >/dev/null
    docker start "$REDIS" >/dev/null

    sync
    php_run composer install --no-interaction --prefer-dist --quiet
    record install-php $?
    node_run npm ci --no-audit --no-fund --loglevel=error
    record install-node $?

    # MariaDB takes a while to initialise a fresh volume; a check started
    # before it accepts connections fails for a reason that is not the code's.
    local up=1
    for _ in $(seq 1 60); do
        docker exec "$DB" healthcheck.sh --connect >/dev/null 2>&1 && { up=0; break; }
        sleep 2
    done
    [ "$up" -eq 0 ] || echo "the test database did not come up" >&2
    record database "$up"
    echo "setup took $((SECONDS - started))s"
}

teardown() {
    docker rm -f "$DB" "$REDIS" >/dev/null 2>&1
    docker volume rm "$VOL" >/dev/null 2>&1
    docker network rm "$NET" >/dev/null 2>&1
    docker image rm "$PHP_IMAGE" "$SYNC_IMAGE" >/dev/null 2>&1
    echo "removed; .env and .env.testing are left in place (gitignored)"
}

# Pest under pcov and Vitest under v8, then the floors in coverage-floors.json
# (D88). Reports go to the git-ignored coverage/ directory; the gate runs where
# the reports are, so container mode needs no copy back.
coverage() {
    php_run php -m | grep -qix pcov || { echo "pcov is not loaded: install php8.3-pcov (cloud-setup.sh), or use CHECKS_MODE=containers" >&2; return 1; }
    php_run php -d pcov.directory=app -d memory_limit=-1 artisan test --coverage-clover=coverage/php/clover.xml "${PEST_ARGS[@]}" || return 1
    node_run npx vitest run --coverage || return 1
    node_run node tools/dev/checks/coverage-gate.mjs
}

# Formats the named files where vendor/ and node_modules/ are, and in
# container mode copies them back into the checkout.
fix() {
    local js=() php=() f
    for f in "$@"; do case "$f" in *.php) php+=("$f") ;; *) js+=("$f") ;; esac; done
    sync
    [ ${#php[@]} -eq 0 ] || php_run vendor/bin/pint "${php[@]}"
    [ ${#js[@]} -eq 0 ] || node_run npx prettier --write "${js[@]}"
    [ "$CHECKS_MODE" = native ] && return 0
    for f in "$@"; do
        docker run --rm -v "$VOL:/app" -v "$ROOT_HOST:/w" alpine cp "/app/$f" "/w/$f"
    done
}

case "${1:-}" in
    setup) setup ;;
    teardown) teardown; exit 0 ;;
    fix) shift; fix "$@"; exit $? ;;
    *)
        CHECKS=() PEST_ARGS=()
        for arg in "$@"; do
            case "$arg" in
                pint | phpstan | pest | lint | format | types | vitest | coverage) CHECKS+=("$arg") ;;
                --*) PEST_ARGS+=("$arg") ;;
                *) echo "unknown check: $arg" >&2; exit 64 ;;
            esac
        done
        [ ${#CHECKS[@]} -gt 0 ] || CHECKS=(pint phpstan pest lint format types vitest)
        # `coverage` is not one of the seven: it reruns both suites under a
        # coverage driver (W119), so it is asked for by name.
        if [ "$CHECKS_MODE" = native ] && { [ ! -d "$ROOT/vendor" ] || [ ! -d "$ROOT/node_modules" ]; }; then
            echo "no vendor/ or node_modules/ yet: run '$0 setup' first" >&2
            exit 1
        fi
        started=$SECONDS
        sync
        for c in "${CHECKS[@]}"; do
            echo "=== $c"
            case "$c" in
                pint) php_run vendor/bin/pint --test ;;
                phpstan) php_run vendor/bin/phpstan analyse --memory-limit=-1 ;;
                pest) php_run php artisan test "${PEST_ARGS[@]}" ;;
                lint) node_run npm run lint:check ;;
                format) node_run npm run format:check ;;
                types) node_run npm run type-check ;;
                vitest) node_run npm run test:unit ;;
                coverage) coverage ;;
            esac
            record "$c" $?
        done
        echo "checks took $((SECONDS - started))s ($CHECKS_MODE)"
        ;;
esac

echo
printf '%s\n' "${RESULTS[@]}"
[ "$FAILED" -eq 0 ]
