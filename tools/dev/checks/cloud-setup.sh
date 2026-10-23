#!/usr/bin/env bash
#
# The setup script for ArgueSecure's Claude Code cloud environment (W100):
# paste this file's contents into Project settings → Environment → the
# environment's **Setup script** (docs/CLAUDE-PROJECT.md, step 5).
#
# The cloud VM is Ubuntu 24.04 with PHP 8.3, Composer, Node 22 and Docker,
# and runs this as root before Claude starts, then snapshots the disk and
# reuses it for about seven days — so it installs only what the VM lacks for
# `tools/dev/checks/checks.sh`, and pulls the service images so a thread
# does not wait for them. It must finish in about five minutes to be cached.
#
# Everything comes from hosts on the environment's default (Trusted)
# allowlist: Ubuntu's archive and Docker Hub. pecl is not on it, which is
# why phpredis comes from apt rather than the way the check image builds it.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

# PHP extensions the suite needs that a stock PHP CLI lacks (checks.sh's
# PHP_EXTENSIONS; pcntl ships in php8.3-cli on Ubuntu).
need=()
for pair in pdo_mysql:mysql redis:redis intl:intl bcmath:bcmath zip:zip mbstring:mbstring xml:xml curl:curl gd:gd; do
    php -m | grep -qix "${pair%%:*}" || need+=("php8.3-${pair#*:}")
done
# pcov is the coverage driver for `checks.sh coverage` (W119).
php -m | grep -qix pcov || need+=(php8.3-pcov)
command -v shellcheck >/dev/null || need+=(shellcheck)
if [ ${#need[@]} -gt 0 ]; then
    apt-get update -qq
    apt-get install -y -qq "${need[@]}" >/dev/null
fi

# The images checks.sh runs; kept in the cached snapshot. Skipped, not
# failed, if the daemon is not up during setup — a thread pulls them then.
if docker info >/dev/null 2>&1; then
    for image in mariadb:11 redis:7-alpine alpine; do
        docker pull -q "$image" >/dev/null || echo "warning: could not pull $image; a thread will pull it" >&2 &
    done
    wait
fi

php -m | grep -cixE 'pdo_mysql|redis|intl|bcmath|zip|mbstring|xml|curl|gd|pcntl' | xargs echo "PHP extensions ready (of 10):"
