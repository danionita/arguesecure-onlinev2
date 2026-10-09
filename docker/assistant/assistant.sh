#!/usr/bin/env bash
#
# The assistant runtime's entrypoint: install a model, or serve one
# (PLAN-2.6.md D53, D54, D56).
#
# Runs inside the pinned llama.cpp image, which already carries bash, curl and
# sha256sum — so installing needs Docker and nothing else, on any host.
#
#   docker compose run --rm assistant-install small     # download + verify + select
#   docker compose run --rm assistant-install large
#   docker compose run --rm assistant-install list      # what exists, what is installed
#   docker compose --profile assistant up -d            # serve (the default command)
#
# Which model `serve` runs (D54), first match wins:
#   1. ASSISTANT_MODEL_FILE — any GGUF in the models directory, by file name.
#   2. ASSISTANT_MODEL=small|large — a preset from models.conf.
#   3. ASSISTANT_MODEL=<name> with <name>.gguf present — 2.5's label form.
#   4. The preset most recently installed (recorded by `install`).
#   5. 2.5's default file, gemma-3-4b-it-Q4_K_M.gguf, if it is there.
# 1, 3 and 5 are why an upgraded 2.5 deployment keeps running exactly the
# model it ran before, whichever way its .env named it, until someone changes
# it.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CATALOGUE="${ASSISTANT_CATALOGUE:-$HERE/models.conf}"
MODELS="${ASSISTANT_MODELS_DIR:-/models}"
SELECTED="$MODELS/.arguesecure-model"
SERVER="${ASSISTANT_SERVER:-/app/llama-server}"
# What docker-compose.yml served in 2.5 when ASSISTANT_MODEL_FILE was unset.
LEGACY_DEFAULT=gemma-3-4b-it-Q4_K_M.gguf
GIB=1073741824

say() { printf '%s\n' "$*" >&2; }
die() {
    say "assistant: $*"
    exit 1
}

# `serve`'s refusal. Not an instant exit: under compose's `restart:
# unless-stopped` that is a restart loop, printing the same message every few
# seconds. Waiting first makes it a slow retry with one legible message, and
# the trap still lets `docker compose stop` end it at once.
refuse() {
    say "assistant: $*"
    trap 'exit 1' TERM INT
    sleep "${ASSISTANT_REFUSE_WAIT:-300}" &
    wait $!
    exit 1
}

# One preset's fields into P_* variables; fails for an unknown name.
preset() {
    local line
    line=$(awk -v n="$1" '!/^[[:space:]]*(#|$)/ && $1 == n { print; exit }' "$CATALOGUE")
    [ -n "$line" ] || return 1
    read -r _ P_FILE P_REPO P_REVISION P_BYTES P_SHA256 P_MEMORY P_CACHE P_LABEL <<<"$line"
}

presets() { awk '!/^[[:space:]]*(#|$)/ { print $1 }' "$CATALOGUE"; }

size_of() { stat -c%s "$1" 2>/dev/null || echo 0; }

cmd_list() {
    local selected="" name
    [ -f "$SELECTED" ] && selected=$(cat "$SELECTED")
    printf '%-6s %-9s %-7s %-10s %s\n' PRESET DOWNLOAD MEMORY STATE MODEL
    for name in $(presets); do
        preset "$name"
        local state="-"
        if [ "$(size_of "$MODELS/$P_FILE")" = "$P_BYTES" ]; then
            state=installed
            [ "$name" = "$selected" ] && state=selected
        fi
        printf '%-6s %-9s %-7s %-10s %s\n' "$name" "$((P_BYTES / 1000000000)) GB" "$P_MEMORY GiB" "$state" "$P_LABEL"
    done
}

# Size and sha256 against the catalogue. Size first: it is instant, and it is
# what catches the truncated download that exits 0.
verify() { # file
    local size
    size=$(size_of "$1")
    if [ "$size" != "$P_BYTES" ]; then
        say "  $size bytes, expected $P_BYTES"
        return 1
    fi
    say "  checking sha256 (a minute for a large file)..."
    [ "$(sha256sum "$1" | cut -d' ' -f1)" = "$P_SHA256" ] || {
        say "  sha256 does not match the catalogue"
        return 1
    }
}

cmd_install() {
    local name="" force="" arg
    # --force anywhere: the refusal message suggests adding it, and people
    # add it wherever it occurs to them to.
    for arg in "$@"; do
        case "$arg" in
            --force) force=--force ;;
            *) name="$arg" ;;
        esac
    done
    if [ -z "$name" ] || [ "$name" = list ]; then
        cmd_list
        [ -n "$name" ] || say "usage: install <$(presets | paste -sd'|')> [--force]"
        return 0
    fi
    preset "$name" || die "no preset '$name' in the catalogue; choose one of: $(presets | paste -sd' ')"
    [ -w "$MODELS" ] || die "$MODELS is not writable; run this through the assistant-install service"

    local target="$MODELS/$P_FILE" part="$MODELS/$P_FILE.part"
    say "$P_LABEL"
    say "  $P_REPO@${P_REVISION:0:12}, $((P_BYTES / 1000000)) MB, needs about $P_MEMORY GiB of memory"

    # Memory first: a model that does not fit runs from swap, which is slow
    # enough to look broken, and the download would have been for nothing.
    local total
    total=$(awk '$1 == "MemTotal:" { print $2 * 1024 }' /proc/meminfo)
    if [ "$total" -lt $((P_MEMORY * GIB)) ] && [ "$force" != --force ]; then
        die "this host has $((total / GIB)) GiB of memory and '$name' needs about $P_MEMORY GiB.
  Install the small preset instead, or add --force to install it anyway."
    fi

    if [ -f "$target" ]; then
        say "  already downloaded; verifying"
        if verify "$target"; then
            select_preset "$name"
            return 0
        fi
        # Not resumed: a file of the right length with the wrong bytes has
        # nothing a resume could fix, and "resuming" a complete file fetches
        # nothing at all — the same wrong file would fail the same check on
        # every run, forever.
        say "  the file on disk is not the one in the catalogue; downloading it again"
        rm -f "$target"
    fi

    # The same for a .part already at full length: only a *short* file is
    # worth resuming.
    [ "$(size_of "$part")" -lt "$P_BYTES" ] || rm -f "$part"

    local have need free
    have=$(size_of "$part")
    [ "$have" -gt 0 ] && say "  resuming an interrupted download at $((have / 1000000)) MB"
    need=$((P_BYTES - have + GIB))
    free=$(df -Pk "$MODELS" | awk 'NR == 2 { print $4 * 1024 }')
    [ "$free" -ge "$need" ] || die "$((free / GIB)) GiB free in the models directory, need $((need / GIB)) GiB"

    local progress=--no-progress-meter
    [ -t 2 ] && progress=--progress-bar
    # Resumable, and into a .part file that llama.cpp will never be pointed
    # at: only a file that passes both checks below gets its real name.
    if ! curl -L --fail --retry 5 --retry-all-errors -C - "$progress" \
        "https://huggingface.co/$P_REPO/resolve/$P_REVISION/$P_FILE" -o "$part"; then
        # Nothing fetched is a different failure from a partial fetch: there
        # is nothing to resume, and saying so would send someone round a loop.
        if [ "$(size_of "$part")" -eq 0 ]; then
            rm -f "$part"
            die "the model could not be downloaded (curl's error is above); nothing was installed.
  Check this host can reach huggingface.co, then run the same command again."
        fi
        die "the download was interrupted at $(($(size_of "$part") / 1000000)) of $((P_BYTES / 1000000)) MB and kept as $part.
  Run the same command again to resume it."
    fi

    if ! verify "$part"; then
        if [ "$(size_of "$part")" -ge "$P_BYTES" ]; then
            rm -f "$part"
            die "the download is complete but is not the file in the catalogue, so it was deleted.
  Nothing was installed. If this repeats, the catalogue entry or the publisher's file has changed."
        fi
        die "the download stopped short and was kept as $part.
  Run the same command again to resume it."
    fi
    mv -f "$part" "$target"
    select_preset "$name"
}

select_preset() {
    printf '%s\n' "$1" >"$SELECTED"
    say "  installed and selected: $1"
    say ""
    say "Start the assistant, or restart it if it is already running:"
    say "  docker compose --profile assistant up -d"
    say "  docker compose --profile assistant restart assistant"
    # Only when .env names something *else*: ASSISTANT_MODEL=large with
    # `install large` is agreement, not an override.
    if [ -n "${ASSISTANT_MODEL_FILE:-}" ]; then
        say ""
        say "Note: .env sets ASSISTANT_MODEL_FILE, which takes precedence over this selection."
    elif [ -n "${ASSISTANT_MODEL:-}" ] && [ "$ASSISTANT_MODEL" != "$1" ]; then
        say ""
        say "Note: .env sets ASSISTANT_MODEL=$ASSISTANT_MODEL, which takes precedence over this selection."
    fi
}

cmd_serve() {
    # A custom file has no catalogue entry to size its cache from; 2 GiB held
    # the small models' working set with nothing evicted (W96).
    local file="" label="" cache=2048 name="${ASSISTANT_MODEL:-}"

    if [ -n "${ASSISTANT_MODEL_FILE:-}" ]; then
        file="$ASSISTANT_MODEL_FILE"
        label="custom model $file"
        [ -f "$MODELS/$file" ] || refuse "ASSISTANT_MODEL_FILE is $file, and $MODELS/$file does not exist"
    elif [ -n "$name" ] && ! preset "$name"; then
        # In 2.5, ASSISTANT_MODEL was a *label* for the file, and the README
        # set it to the file's name without `.gguf`. Honour that rather than
        # refuse: an upgrade must not strand a deployment that worked (D54).
        [ -f "$MODELS/$name.gguf" ] || refuse "ASSISTANT_MODEL is '$name', which is not a preset; choose one of: $(presets | paste -sd' ')"
        file="$name.gguf"
        label="custom model $file (named by a 2.5-style ASSISTANT_MODEL)"
    else
        if [ -z "$name" ] && [ -f "$SELECTED" ]; then
            name=$(cat "$SELECTED")
        fi
        if [ -z "$name" ] && [ -f "$MODELS/$LEGACY_DEFAULT" ]; then
            # 2.5's compose file served this name when nothing was set, so a
            # 2.5 deployment may set nothing at all. Same reasoning as above.
            file="$LEGACY_DEFAULT"
            label="custom model $file (2.5's default)"
        else
            [ -n "$name" ] || refuse "no model is installed. Install one, then start the assistant again:
  docker compose run --rm assistant-install small"
            preset "$name" || refuse "the selected model '$name' is not in the catalogue; install a preset again"
            # Size only: hashing a 14 GB file on every start would add minutes
            # to each restart, and `install` already hashed it. Size is what
            # catches the truncated file.
            [ "$(size_of "$MODELS/$P_FILE")" = "$P_BYTES" ] || refuse "the '$name' model is not installed, or its file is incomplete. Install it:
  docker compose run --rm assistant-install $name"
            file="$P_FILE"
            label="$P_LABEL"
            cache="$P_CACHE"
        fi
    fi
    cache="${ASSISTANT_CACHE_RAM_MIB:-$cache}"

    say "assistant: serving $label (prompt cache up to $cache MiB)"
    # D56. One slot: the application serialises generations per user and
    # never needs the context divided. --reasoning off: the presets can think
    # aloud, and on a CPU that spends the whole token budget on deliberation
    # (2.3's qwen3:4b returned nothing). --cache-ram bounds the host-RAM prompt
    # cache that keeps the help corpus warm across other jobs — per preset
    # (models.conf), because its default of 8 GiB is an unbudgeted surprise on
    # a small host and 2 GiB is too little for the large model's prompts.
    exec "$SERVER" \
        -m "$MODELS/$file" \
        -c "${ASSISTANT_CONTEXT_TOKENS:-12288}" \
        -t "${ASSISTANT_THREADS:-4}" \
        -np 1 \
        --reasoning off \
        --cache-ram "$cache" \
        --host 0.0.0.0 --port 8080 \
        "$@"
}

command="${1:-serve}"
[ $# -gt 0 ] && shift
case "$command" in
    serve) cmd_serve "$@" ;;
    install) cmd_install "$@" ;;
    list) cmd_list ;;
    *) die "unknown command '$command'; use serve, install <preset> or list" ;;
esac
