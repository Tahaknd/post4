#!/usr/bin/env bash
# Runs the opt-in end-to-end test of the identity consumer (group `kafka-identity`, tests/Feature/IdentityKafkaTest.php)
# against the RUNNING compose stack: real /batch/ endpoint, real Kafka, real Postgres, real resolver.
#
# What it does, and undoes on exit (success, failure, INT or TERM):
#   1. stops the live `identity` service for the duration, so the consumers the test starts are the only ones that
#      act (it is started again at the end if it was running; what it missed meanwhile is consumed then, and the
#      scratch project is gone by that time, so those events are skipped as `project_gone`);
#   2. creates a throwaway project (found and deleted again by its unique api_key, never by name) and hands its id and
#      key to the test through the environment;
#   3. runs `php artisan test --group=kafka-identity` in the app container;
#   4. deletes the project (its persons and distinct ids go with it through the foreign keys, its ClickHouse events
#      are purged by the queued job) and the consumer groups named identity-e2e-* that the test created.
# What stays: the messages the test produced on the Kafka topics (person_distinct_ids, events_dlq, events). They
# carry the scratch project's id and expire with the topics' retention.
#
# Usage: scripts/test-identity.sh [pest arguments...]     e.g. --filter="idempotent"
# Environment: HOST (http://localhost:8000; only a local address is accepted)
# Exit codes: 0 passed, 1 a test failed (pest's own code) or the script aborted, 2 REFUSED (bad HOST, docker is not
# local, the stack is not up).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
# shellcheck source=scripts/lib.sh
source "$ROOT/scripts/lib.sh"

case "${1:-}" in
  -h | --help)
    sed -n '2,22p' "$0" | sed 's/^# \{0,1\}//'
    exit 0
    ;;
esac

HOST="${HOST:-http://localhost:8000}"
lib_require_local_host
lib_docker_guard
lib_require_app_up

TMP="$(mktemp -d)"
KEY=""
PROJECT_ID=""
IDENTITY_WAS_RUNNING=0

cleanup() {
  local rc=$?
  trap '' INT TERM
  trap - EXIT
  set +e
  delete_project
  local group
  for group in $(docker compose exec -T kafka /opt/kafka/bin/kafka-consumer-groups.sh --bootstrap-server localhost:9092 --list 2>/dev/null | tr -d '\r' | grep '^identity-e2e-'); do
    docker compose exec -T kafka /opt/kafka/bin/kafka-consumer-groups.sh --bootstrap-server localhost:9092 --delete --group "$group" >/dev/null 2>&1
  done
  if [[ "$IDENTITY_WAS_RUNNING" == 1 ]]; then
    docker compose start identity >/dev/null 2>&1 || echo "WARN: could not start the identity service again: docker compose up -d identity" >&2
  fi
  rm -rf "$TMP"
  exit "$rc"
}
lib_install_traps cleanup

if [[ -n "$(docker compose ps --status running -q identity 2>/dev/null || true)" ]]; then
  IDENTITY_WAS_RUNNING=1
  docker compose stop identity >/dev/null
fi

lib_create_project "identity_e2e_$(date +%s)_$$"

# By name, never on an argv: the key is a credential of the (throwaway) project.
export IDENTITY_E2E_PROJECT_ID="$PROJECT_ID" IDENTITY_E2E_API_KEY="$KEY"
docker compose exec -T -e IDENTITY_E2E_PROJECT_ID -e IDENTITY_E2E_API_KEY -e IDENTITY_E2E_HOST=http://localhost:8000 \
  app php artisan test --group=kafka-identity "$@"
