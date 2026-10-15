#!/bin/sh
set -eu

PROJECT_DIR=${CLEVER_BOOT_PROJECT_DIR:-/home/clever-web}
DOCKER=${CLEVER_BOOT_DOCKER:-/usr/bin/docker}
CURL=${CLEVER_BOOT_CURL:-/usr/bin/curl}
SLEEP=${CLEVER_BOOT_SLEEP:-/bin/sleep}
CHECKS=${CLEVER_BOOT_CHECKS:-18}
DELAY=${CLEVER_BOOT_DELAY:-10}

cd "$PROJECT_DIR"

compose() {
    "$DOCKER" compose -f docker-compose.yml -f monitoring/docker-compose.monitoring.prod.yml "$@"
}

healthy() {
    response=$("$CURL" --noproxy '*' -fsS --connect-timeout 2 --max-time 5 \
        --resolve app.clevercrm.pro:443:127.0.0.1 \
        https://app.clevercrm.pro/up 2>/dev/null) || return 1
    [ "$response" = OK ] || return 1

    for service in postgresql redis supervisor app; do
        container=$(compose ps -q "$service" 2>/dev/null) || return 1
        [ -n "$container" ] || return 1
        [ "$("$DOCKER" inspect -f '{{.State.Running}}' "$container" 2>/dev/null)" = true ] || return 1
    done
}

if healthy; then
    echo 'Clever stack is already healthy; no recovery needed.'
    exit 0
fi

echo 'Clever stack is unavailable; starting application services.'
compose up -d --no-build postgresql redis supervisor app

attempt=0
while [ "$attempt" -lt "$CHECKS" ]; do
    if healthy; then
        echo 'Clever stack recovered.'
        exit 0
    fi
    attempt=$((attempt + 1))
    [ "$attempt" -lt "$CHECKS" ] || break
    "$SLEEP" "$DELAY"
done

echo 'Clever stack did not become healthy after Compose startup.' >&2
exit 1
