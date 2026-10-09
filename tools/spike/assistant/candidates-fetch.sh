#!/usr/bin/env bash
#
# Downloads the candidate weights named in results/candidates-manifest.json,
# from the commit the manifest pinned, and verifies every one.
#
# Why each part of this is here:
#
# - From `resolve/<sha>/`, not `resolve/main/`. `main` moves; a benchmark whose
#   weights changed underneath it between two runs compares nothing.
# - `--fail --retry 5 --retry-all-errors -C -`, into a `.part` file. A plain
#   `curl -sL` of the 2.49 GB baseline once produced a 1.36 GB file and exit 0
#   (D18); resuming means a dropped connection costs the remainder, not the
#   whole file.
# - Byte count *and* sha256, before the file gets its real name. Only a file
#   that has passed both is ever visible to llama.cpp, so a container cannot be
#   started on a truncated or substituted model by accident.
# - A file already present under its real name is re-verified rather than
#   trusted, which is what makes it safe to hard-link an existing copy in.
#
# Run it to completion before benchmarking anything: writing gigabytes evicts
# page cache and was worth ~30% on the 2.3 spike's first measurements.
#
#   tools/spike/assistant/candidates-fetch.sh                 # everything
#   tools/spike/assistant/candidates-fetch.sh gemma4-e4b-qat  # by id
set -euo pipefail

cd "$(dirname "$0")/../../.."

# On Unraid, a pool path (/mnt/<pool>/...), never /mnt/user/...: that is the
# shfs FUSE layer, and llama.cpp memory-maps the weights through it.
DEST="${DEST:-$PWD/data-spike-models}"
mkdir -p "$DEST"

# Node runs in a container because the harness must not depend on a host
# toolchain; the manifest is the only thing it reads here.
list=$(MSYS_NO_PATHCONV=1 docker run --rm -v "$PWD":/repo -w /repo node:22-alpine \
    node tools/spike/assistant/candidates-weights.mjs tsv "$@")

verify() { # file bytes sha256
    local size
    size=$(stat -c%s "$1")
    if [ "$size" != "$2" ]; then
        echo "  size $size, expected $2"
        return 1
    fi
    [ "$(sha256sum "$1" | cut -d' ' -f1)" = "$3" ] || { echo "  sha256 mismatch"; return 1; }
}

# Disk first: a download that fills a pool halfway through fails late and
# leaves a large .part behind. Counts only what is not already complete.
needed=0
while IFS=$'\t' read -r _ _ path bytes _; do
    file="$DEST/$(basename "$path")"
    have=0
    [ -f "$file" ] && have=$bytes
    [ -f "$file.part" ] && have=$(stat -c%s "$file.part")
    needed=$((needed + bytes - have))
done <<< "$list"
free=$(df -Pk "$DEST" | awk 'NR == 2 { print $4 * 1024 }')
echo "to download: $((needed / 1000000)) MB, free in $DEST: $((free / 1000000)) MB"
if [ "$free" -lt $((needed + 2 * 1024 * 1024 * 1024)) ]; then
    echo "REFUSED: not enough disk (keeps 2 GiB spare)" >&2
    exit 1
fi

while IFS=$'\t' read -r repo sha path bytes sha256; do
    file="$DEST/$(basename "$path")"
    echo "== $(basename "$path") ($bytes bytes) from $repo@${sha:0:10}"

    if [ -f "$file" ]; then
        if verify "$file" "$bytes" "$sha256"; then
            echo "  present, verified"
            continue
        fi
        echo "  present but wrong; moving aside to resume from"
        mv "$file" "$file.part"
    fi

    curl -L --fail --retry 5 --retry-all-errors -C - --no-progress-meter \
        "https://huggingface.co/$repo/resolve/$sha/$path" -o "$file.part"

    if ! verify "$file.part" "$bytes" "$sha256"; then
        echo "  REFUSED: $file.part does not match the manifest; left in place for inspection" >&2
        exit 1
    fi
    mv "$file.part" "$file"
    echo "  downloaded, verified"
done <<< "$list"
