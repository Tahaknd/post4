#!/usr/bin/env bash
# Runs the opt-in Postgres integration suite (tests/Integration, phpunit.pgsql.xml) against a throwaway database.
#
# Starts the `postgres-test` compose service (profile `test`: tmpfs storage, its own host, database and
# credentials, no published port; see docker-compose.yml), runs
#   vendor/bin/pest -c phpunit.pgsql.xml
# in the running app container, and removes the service again on exit, also on failure and on Ctrl-C. Nothing is
# written to the live `postgres` service: the suite refuses to start on any host but `postgres-test`
# (tests/Support/TestDatabaseGuard.php) and phpunit.pgsql.xml pins the connection.
#
# Usage: scripts/test-postgres.sh [pest arguments...]     e.g. --filter="merge"
# Exit codes: 0 passed, 1 a test failed (pest's own code), 2 REFUSED (docker is not local, or the stack is not up).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
# shellcheck source=scripts/lib.sh
source "$ROOT/scripts/lib.sh"

case "${1:-}" in
  -h | --help)
    sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'
    exit 0
    ;;
esac

lib_docker_guard
lib_require_app_up

cleanup() {
  # -s stop, -f no prompt, -v drop anonymous volumes (there are none: the data directory is tmpfs).
  docker compose --profile test rm -sfv postgres-test >/dev/null 2>&1 || true
}
lib_install_traps cleanup

docker compose --profile test up -d --wait postgres-test

docker compose exec -T app vendor/bin/pest -c phpunit.pgsql.xml "$@"
