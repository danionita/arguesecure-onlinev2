#!/bin/sh
set -eu

cd /var/www/html

# The Dockerfile creates this skeleton, but compose bind-mounts
# ${DATA_PATH}/storage over /var/www/html/storage. On a first boot that host
# directory does not exist yet, so Docker creates an empty one and the mount
# hides everything the image put there — `artisan view:cache` then dies on
# "View path not found", and with `set -e` the container restart-loops without
# ever serving /up. Recreating the skeleton after the mount is what makes a
# fresh deployment work. Directories only; nothing here overwrites user data.
mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

wait_for() {
    host="$1"
    port="$2"
    tries=0
    until php -r "exit(@fsockopen('${host}', ${port}) ? 0 : 1);" >/dev/null 2>&1; do
        tries=$((tries + 1))
        if [ "$tries" -ge 30 ]; then
            echo "entrypoint: timed out waiting for ${host}:${port}" >&2
            exit 1
        fi
        sleep 2
    done
}

wait_for "${DB_HOST:-db}" "${DB_PORT:-3306}"
wait_for "${REDIS_HOST:-redis}" "${REDIS_PORT:-6379}"

# Four containers (app, reverb, queue, scheduler) share this file over the
# same bind mount and can start concurrently, so the file is written to a
# unique temp path and swapped into place with a single atomic `mv` — only
# one writer wins the race, and the losers just see the finished file.
KEY_FILE="/var/www/html/storage/app/config/app.key"
mkdir -p "$(dirname "$KEY_FILE")"

if [ ! -s "$KEY_FILE" ]; then
    # mktemp, not "${KEY_FILE}.$$.tmp". `$$` is the shell's pid, and the
    # entrypoint is pid 1 in *every* container — so all four raced onto the
    # single path `app.key.1.tmp` in this shared bind mount, each opening it
    # with `>` and truncating whatever another had just written. The file
    # that then won the `mv` could hold one container's APP_KEY beside
    # another's Reverb credentials, and nothing downstream noticed: the app
    # broadcast to one app id while Reverb served another, so real-time
    # collaboration failed with "Pusher error: No matching application for
    # ID" on every event, while presence — which the browser negotiates with
    # Reverb directly — kept working and made it look like the sockets were
    # fine.
    #
    # Timing-dependent, so it hid completely on a developer machine, where
    # container startup is staggered enough to avoid it, and appeared on CI,
    # where it is not. mktemp guarantees a distinct path per container.
    TMP_KEY_FILE="$(mktemp "${KEY_FILE}.XXXXXX")"
    {
        # Broadcasting is forced off for this one call: routes/channels.php
        # resolves the broadcaster while routes load, and the Reverb credentials
        # three lines below do not exist yet on a first boot. Without this the
        # very first `artisan` of a fresh deployment dies on a null auth key.
        BROADCAST_CONNECTION=null php artisan key:generate --force --show
        echo "REVERB_APP_ID=$(php -r 'echo random_int(100000, 999999);')"
        echo "REVERB_APP_KEY=$(php -r 'echo bin2hex(random_bytes(16));')"
        echo "REVERB_APP_SECRET=$(php -r 'echo bin2hex(random_bytes(16));')"
    } > "$TMP_KEY_FILE"
    mv -n "$TMP_KEY_FILE" "$KEY_FILE"
    rm -f "$TMP_KEY_FILE"

    tries=0
    until [ -s "$KEY_FILE" ]; do
        tries=$((tries + 1))
        if [ "$tries" -ge 15 ]; then
            echo "entrypoint: timed out waiting for ${KEY_FILE}" >&2
            exit 1
        fi
        sleep 1
    done
    echo "entrypoint: ${KEY_FILE} is ready — back it up; losing it invalidates every encrypted cookie and session"
fi

APP_KEY=$(head -n1 "$KEY_FILE")
export APP_KEY
export REVERB_APP_ID=$(sed -n 's/^REVERB_APP_ID=//p' "$KEY_FILE")
export REVERB_APP_KEY=$(sed -n 's/^REVERB_APP_KEY=//p' "$KEY_FILE")
export REVERB_APP_SECRET=$(sed -n 's/^REVERB_APP_SECRET=//p' "$KEY_FILE")

# Refuse to start on a malformed key file rather than run a container that
# looks healthy and quietly cannot broadcast. Every value here must be
# exactly one line: a duplicated key — which is what a concurrent write to
# this file produces — makes `sed` emit two, and the resulting multi-line
# environment variable is neither credential. That failure previously
# surfaced only as real-time silently not working, three container hops from
# its cause.
for var in APP_KEY REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET; do
    eval "value=\$$var"
    lines=$(printf '%s' "$value" | wc -l)

    if [ -z "$value" ] || [ "$lines" -ne 0 ]; then
        echo "entrypoint: ${KEY_FILE} is malformed — ${var} is empty or has more than one value." >&2
        echo "entrypoint: this file is written once on first boot and shared by every container." >&2
        echo "entrypoint: delete it and restart the stack to regenerate. Doing so invalidates" >&2
        echo "entrypoint: existing sessions and encrypted cookies, which is the intended cost." >&2
        exit 1
    fi
done

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force

    # The three system scales are reference data, not schema, so migrating
    # alone left a fresh deployment with none — and `assessments` has three
    # NOT NULL scale foreign keys, so creating the first assessment failed
    # on a constraint. Idempotent (firstOrCreate), so it is safe on every
    # boot, and it seeds only the scales: templates and the catalogue need an
    # owning admin, so SetupController seeds them when the first one exists.
    php artisan db:seed --class='Database\Seeders\SystemScaleSeeder' --force
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
    php artisan storage:link || true
fi

chown -R www-data:www-data storage bootstrap/cache

exec "$@"
