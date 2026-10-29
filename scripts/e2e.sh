#!/usr/bin/env bash
# Runs the Playwright smoke test (tests/e2e) in a THROWAWAY container on the compose network: the browser lives in the
# official Playwright image, nothing is installed on the host or in the app image, and only the few files the test needs
# are mounted, read-only (the container works on copies, so no root-owned files appear in the checkout).
#
#   E2E_EMAIL=... E2E_PASSWORD=... scripts/e2e.sh        (E2E_OUTPUT=<dir> keeps traces and screenshots of a failure)
#
# The credentials must belong to a THROWAWAY administrator (the test creates a project and deletes it again); they are
# forwarded from the environment by name, never written to a file or printed.
#
# The browser reaches the app by the container's IP on the compose network, over PLAIN http: that is not a secure
# context (not localhost, not https), exactly how a LAN or server install is opened, so every run also proves that sign-in
# and the signed-in pages work there (Inertia's encrypted history needs crypto.subtle and is only switched on in secure
# contexts, App\Http\HistoryEncryption). It is by IP and not by the service name because a host called `app` is forced to
# https by Chromium (`.app` is an HSTS-preloaded top level domain). Set E2E_BASE_URL to test another address.
#
# The specs: trends.spec.js (the Trends page), funnel.spec.js (the Funnel page: the step builder by keyboard, run, cancel,
# numbers, URL, axe) and demo.spec.js (the real posthog-js on the demo page: remote config, gzip / JSON / base64 bodies,
# sendBeacon on page close, UTM, no cookies from the SDK endpoints, a clean console, axe). Each creates and deletes its own project.
#
# The tests refuse an E2E_BASE_URL that is not localhost, a private address or a *.test / *.localhost name (they create and delete
# projects as an administrator) unless E2E_ALLOW_REMOTE=1 is set.
#
# Without the image (it is about 2 GB) the same specs run from the host against an installed Chrome, see playwright.config.js:
#   E2E_BROWSER_CHANNEL=chrome E2E_HOST_MAP=app.test E2E_BASE_URL=http://app.test:8000 E2E_EMAIL=... E2E_PASSWORD=... npx playwright test
# (E2E_HOST_MAP makes the browser resolve that name to 127.0.0.1: a plain-http, non-localhost origin like a LAN install.)
#
# PLAYWRIGHT_VERSION is the image tag and MUST equal the pinned @playwright/test of package.json (checked below). The first
# run pulls the image (about 2 GB); remove it with `docker rmi mcr.microsoft.com/playwright:v1.64.1-noble` when you are done.
set -eu

PLAYWRIGHT_VERSION=1.64.1

: "${E2E_EMAIL:?set E2E_EMAIL to a throwaway administrator}"
: "${E2E_PASSWORD:?set E2E_PASSWORD}"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck source=scripts/lib.sh
source "$ROOT/scripts/lib.sh"
lib_docker_guard
PINNED="$(sed -n 's/.*"@playwright\/test": "\([0-9.]*\)".*/\1/p' "$ROOT/package.json")"
if [ "$PINNED" != "$PLAYWRIGHT_VERSION" ]; then
    echo "PLAYWRIGHT_VERSION ($PLAYWRIGHT_VERSION) in scripts/e2e.sh must equal @playwright/test ($PINNED) in package.json" >&2
    exit 1
fi

CONTAINER="$(cd "$ROOT" && docker compose ps -q app)"
[ -n "$CONTAINER" ] || { echo "the app container is not running" >&2; exit 1; }
# The compose network of the running app container (any COMPOSE_PROJECT_NAME), unless E2E_NETWORK names one.
NETWORK="${E2E_NETWORK:-$(lib_compose_network "$CONTAINER")}"
[ -n "$NETWORK" ] || { echo "the app container has no compose network (set E2E_NETWORK)" >&2; exit 1; }
if [ -z "${E2E_BASE_URL:-}" ]; then
    IP="$(docker inspect -f "{{(index .NetworkSettings.Networks \"$NETWORK\").IPAddress}}" "$CONTAINER")"
    [ -n "$IP" ] || { echo "the app container has no address on the network $NETWORK (set E2E_NETWORK)" >&2; exit 1; }
    E2E_BASE_URL="http://$IP:8000"
fi

set -- --rm --init --network "$NETWORK" --shm-size=1g \
    -v "$ROOT/package.json:/src/package.json:ro" \
    -v "$ROOT/package-lock.json:/src/package-lock.json:ro" \
    -v "$ROOT/.npmrc:/src/.npmrc:ro" \
    -v "$ROOT/playwright.config.js:/src/playwright.config.js:ro" \
    -v "$ROOT/tests/e2e:/src/tests/e2e:ro" \
    -e E2E_EMAIL -e E2E_PASSWORD \
    -e "E2E_BASE_URL=$E2E_BASE_URL"
if [ -n "${E2E_OUTPUT:-}" ]; then
    case "$E2E_OUTPUT" in /*) OUT="$E2E_OUTPUT" ;; *) OUT="$PWD/$E2E_OUTPUT" ;; esac
    mkdir -p "$OUT"
    set -- "$@" -v "$OUT:/out" -e E2E_OUTPUT_DIR=/out
fi

exec docker run "$@" "mcr.microsoft.com/playwright:v${PLAYWRIGHT_VERSION}-noble" sh -c '
    set -e
    mkdir /work && cd /work
    cp /src/package.json /src/package-lock.json /src/.npmrc /src/playwright.config.js ./
    cp -r /src/tests ./tests
    npm ci --ignore-scripts --no-audit --no-fund --omit=optional >/dev/null
    npx playwright test
'
