#!/bin/sh
# Runs the Playwright smoke test (tests/e2e) in a THROWAWAY container next to the running app container: the browser lives in the
# official Playwright image, nothing is installed on the host or in the app image, and the repository is mounted
# read-only (the container works on a copy, so no root-owned files appear in the checkout).
#
#   E2E_EMAIL=... E2E_PASSWORD=... scripts/e2e.sh          (E2E_OUTPUT=<dir for traces and screenshots> is optional)
#
# The credentials must belong to a THROWAWAY administrator (the test creates a project and deletes it again); they are
# forwarded from the environment by name, never written to a file or printed. The image tag must match the pinned
# @playwright/test in package.json (1.64.1). First use pulls the image (about 2 GB); remove it with
# `docker rmi mcr.microsoft.com/playwright:v1.64.1-noble` when you are done.
set -eu

: "${E2E_EMAIL:?set E2E_EMAIL to a throwaway administrator}"
: "${E2E_PASSWORD:?set E2E_PASSWORD}"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(sed -n 's/.*"@playwright\/test": "\([0-9.]*\)".*/\1/p' "$ROOT/package.json")"
OUT="${E2E_OUTPUT:-}"

# The browser shares the network namespace of the app container, so the app is `http://localhost:8000` for it. That is
# needed, not a shortcut: pages are served with Inertia's encrypted history, which uses crypto.subtle, and browsers only
# expose that in a secure context (https or localhost); on a plain-http address such as http://172.x.x.x:8000 a sign-in
# never finishes. (A host called `app` fails differently: `.app` is an HSTS-preloaded top level domain.)
CONTAINER="$(cd "$ROOT" && docker compose ps -q app)"
[ -n "$CONTAINER" ] || { echo "the app container is not running" >&2; exit 1; }
E2E_BASE_URL="${E2E_BASE_URL:-http://localhost:8000}"

set -- --rm --init --ipc=host --network "container:$CONTAINER" \
    -v "$ROOT:/src:ro" \
    -e E2E_EMAIL -e E2E_PASSWORD \
    -e "E2E_BASE_URL=$E2E_BASE_URL"
if [ -n "$OUT" ]; then
    mkdir -p "$OUT"
    set -- "$@" -v "$OUT:/out" -e E2E_OUTPUT_DIR=/out
fi

exec docker run "$@" "mcr.microsoft.com/playwright:v${VERSION}-noble" sh -c '
    set -e
    mkdir /work && cd /work
    cp /src/package.json /src/package-lock.json /src/.npmrc /src/playwright.config.js ./
    cp -r /src/tests ./tests
    npm ci --ignore-scripts --no-audit --no-fund --omit=optional >/dev/null
    npx playwright test
'
