#!/usr/bin/env bash
# Verifies the guarantees of the ClickHouse read-only user `posthog_ro` (profile ro_insights,
# docker/clickhouse/users.d/20-readonly-user.xml) against a running ClickHouse.
#
# Safe by construction: only talks to a local Docker engine, logs in as posthog_ro (never as the admin)
# and every probe is either a SELECT, a statement that must be refused before it runs (and would be
# harmless with zero rows if it were not), or a setting the server must reject.
#
# Run `scripts/check-readonly-user.sh --help` for usage, environment variables and exit codes.
set -euo pipefail

cd "$(dirname "$0")/.."

usage() {
  cat <<'EOF_USAGE'
Usage: scripts/check-readonly-user.sh [-h|--help]

Checks that the ClickHouse read-only user posthog_ro can log in and SELECT from posthog.events (every
read must carry the SQL_project_id setting of the row policy, which hides all other projects), and that
everything else is refused with the RIGHT error code (not merely "some error"):
  497 ACCESS_DENIED     other tables (events_errors, schema_migrations, kafka_events), INSERT, ALTER,
                        CREATE, and the url() / file() / remote() table functions
  115 UNKNOWN_SETTING   a read of posthog.events WITHOUT SQL_project_id (the row policy fails closed)
  6   CANNOT_PARSE_TEXT SQL_project_id that is not a number
  164 READONLY          SET readonly = 0
  452 SETTING_CONSTRAINT_VIOLATION
                        raising or removing a limit (max_execution_time 25 and 0, max_memory_usage,
                        max_result_rows, max_concurrent_queries_for_user = 0) and changing a pinned
                        setting (join_algorithm, use_query_cache, log_queries,
                        stream_like_engine_allow_direct_select)
and that a benign setting (max_execution_time = 5) is accepted and the grants are EXACTLY
`GRANT SELECT ON posthog.events TO posthog_ro`. One PASS or FAIL line per check.

The password never appears on a command line: it is read inside the container from
CLICKHOUSE_RO_PASSWORD.

Environment:
  CH_CONTAINER      run against `docker exec <name>` (for example a scratch ClickHouse container started
                    in CI with the XML mounted and -e CLICKHOUSE_RO_PASSWORD=...) instead of
                    `docker compose exec clickhouse`

Exit codes:
  0  all checks passed
  1  at least one check failed
  2  refused: Docker is missing or not local, the target container is not running, unknown argument
EOF_USAGE
}

for arg in "$@"; do
  case "$arg" in
    -h | --help) usage; exit 0 ;;
    *) echo "unknown argument: $arg (try --help)" >&2; exit 2 ;;
  esac
done

CH_CONTAINER="${CH_CONTAINER:-}"
if [[ -n "$CH_CONTAINER" && ! "$CH_CONTAINER" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]]; then
  echo "REFUSED: CH_CONTAINER must be a plain container name, got '$CH_CONTAINER'" >&2
  exit 2
fi

# --- Docker target guard: only a local engine, and only if the target is up -------------------------
command -v docker >/dev/null 2>&1 || { echo "REFUSED: docker is not installed" >&2; exit 2; }
endpoint="${DOCKER_HOST:-$(docker context inspect --format '{{.Endpoints.docker.Host}}' 2>/dev/null || true)}"
case "$endpoint" in
  unix://* | npipe://* | "") ;;
  *) echo "REFUSED: docker endpoint '$endpoint' is not local" >&2; exit 2 ;;
esac
if [[ -n "$CH_CONTAINER" ]]; then
  if [[ "$(docker inspect --format '{{.State.Running}}' "$CH_CONTAINER" 2>/dev/null || true)" != "true" ]]; then
    echo "REFUSED: container '$CH_CONTAINER' is not running" >&2
    exit 2
  fi
elif [[ -z "$(docker compose ps -q clickhouse 2>/dev/null || true)" ]]; then
  echo "REFUSED: the clickhouse service is not up (docker compose up -d)" >&2
  exit 2
fi

PASSED=0
FAILED=0
OUT=""
RC=0

pass() { PASSED=$((PASSED + 1)); echo "PASS: $1"; }
fail() { FAILED=$((FAILED + 1)); echo "FAIL: $1"; }

# clickhouse-client as posthog_ro inside the container. The password is read from the container's own
# CLICKHOUSE_RO_PASSWORD by the inner shell, so it is never on any argv. The single-quoted script is for
# the container's shell, hence SC2016.
# shellcheck disable=SC2016
rox() {
  if [[ -n "$CH_CONTAINER" ]]; then
    docker exec "$CH_CONTAINER" sh -c 'CLICKHOUSE_USER=posthog_ro CLICKHOUSE_PASSWORD="$CLICKHOUSE_RO_PASSWORD" exec clickhouse-client "$@"' sh "$@"
  else
    docker compose exec -T clickhouse sh -c 'CLICKHOUSE_USER=posthog_ro CLICKHOUSE_PASSWORD="$CLICKHOUSE_RO_PASSWORD" exec clickhouse-client "$@"' sh "$@"
  fi
}

# run <sql>: result text (stdout + stderr) in OUT, exit status in RC; never aborts the script.
run() {
  RC=0
  OUT="$(rox --query "$1" 2>&1)" || RC=$?
}

short() { printf '%s' "$1" | tr '\n' ' ' | head -c 170; }

# check_ok <name> <sql>: the statement must succeed.
check_ok() {
  run "$2"
  if [[ $RC -eq 0 ]]; then pass "$1"; else fail "$1: expected success, got: $(short "$OUT")"; fi
}

# check_out <name> <sql> <expected output>: the statement must succeed and print exactly this.
check_out() {
  run "$2"
  if [[ $RC -eq 0 && "$OUT" == "$3" ]]; then pass "$1"; else fail "$1: expected '$3', got (exit $RC): $(short "$OUT")"; fi
}

# check_code <name> <error code> <sql>: the statement must FAIL with exactly this ClickHouse error code
# (an unrelated failure, such as a dropped connection or a syntax error, must not count as a pass).
check_code() {
  run "$3"
  if [[ $RC -ne 0 && "$OUT" == *"Code: $2. DB::Exception"* ]]; then
    pass "$1 -> $2"
  elif [[ $RC -eq 0 ]]; then
    fail "$1: expected error $2 but the statement SUCCEEDED (check the grants and profile in 20-readonly-user.xml)"
  else
    fail "$1: expected error $2, got: $(short "$OUT")"
  fi
}

echo "check-readonly-user: target=${CH_CONTAINER:-compose service clickhouse}"

# --- login and the one allowed read ------------------------------------------------------------------
run 'SELECT currentUser()'
if [[ $RC -eq 0 && "$OUT" == "posthog_ro" ]]; then
  pass "login works (currentUser() = posthog_ro)"
else
  fail "login as posthog_ro failed: $(short "$OUT") (is 20-readonly-user.xml mounted and CLICKHOUSE_RO_PASSWORD set in the container?)"
  echo "----"
  echo "check-readonly-user: $PASSED passed, $FAILED failed"
  exit 1
fi
# Every read needs the SQL_project_id setting (row policy, see below); without it the query fails.
check_ok "SELECT count() FROM posthog.events works (with SQL_project_id)" 'SELECT count() FROM posthog.events SETTINGS SQL_project_id = 1'

# --- tenant isolation: the row policy on posthog.events (fail-closed) ---------------------------------
# The policy filter is project_id = toUInt32(getSetting('SQL_project_id')). A query without the setting
# must FAIL (never return every project's rows): on 25.8 that is Code 115 UNKNOWN_SETTING, raised while
# the policy expression is evaluated. A value that is not a UInt32 fails with 6 (CANNOT_PARSE_TEXT).
check_code "SELECT without SQL_project_id is rejected (fail-closed)" 115 'SELECT count() FROM posthog.events'
check_code "SQL_project_id that is not a number is rejected" 6 "SELECT count() FROM posthog.events SETTINGS SQL_project_id = 'abc'"
# These two hold on ANY data: with the setting at 1 no row of another project may be visible.
check_out "the policy hides every other project (WHERE project_id != 1 is empty)" 'SELECT count() FROM posthog.events WHERE project_id != 1 SETTINGS SQL_project_id = 1' 0
check_out "the policy applies inside a UNION ALL subquery too" 'SELECT count() FROM (SELECT project_id FROM posthog.events UNION ALL SELECT project_id FROM posthog.events) WHERE project_id != 1 SETTINGS SQL_project_id = 1' 0
check_out "a project id nobody owns (0) sees no rows" 'SELECT count() FROM posthog.events SETTINGS SQL_project_id = 0' 0

# --- no other table ----------------------------------------------------------------------------------
check_code "SELECT from posthog.events_errors is denied" 497 'SELECT count() FROM posthog.events_errors'
check_code "SELECT from posthog.schema_migrations is denied" 497 'SELECT count() FROM posthog.schema_migrations'
check_code "SELECT from posthog.kafka_events is denied" 497 'SELECT count() FROM posthog.kafka_events'

# --- no writes (each statement is refused before it runs; the INSERT and ALTER would touch no row anyway)
check_code "INSERT into posthog.events is denied" 497 "INSERT INTO posthog.events (uuid, project_id, event, distinct_id, properties, timestamp) SELECT toUUID('00000000-0000-0000-0000-000000000000'), 0, '', '', '', now64(3) WHERE 0"
check_code "ALTER TABLE posthog.events is denied" 497 'ALTER TABLE posthog.events DELETE WHERE 0'
check_code "CREATE TABLE is denied" 497 'CREATE TABLE posthog.ro_check_probe (a Int8) ENGINE = Memory'

# --- no files, URLs or remote servers (the ABSENCE of the SOURCES grant, not readonly, blocks these) ---
check_code "url() is denied" 497 "SELECT * FROM url('http://127.0.0.1:1/x', 'CSV', 'a String')"
check_code "file() is denied" 497 "SELECT * FROM file('/etc/hostname', 'CSV', 'a String')"
check_code "remote() is denied" 497 "SELECT * FROM remote('127.0.0.1', system.one)"

# --- the profile cannot be loosened ------------------------------------------------------------------
check_code "SET readonly = 0 is refused" 164 'SET readonly = 0'
check_code "max_execution_time = 25 exceeds the cap" 452 'SELECT 1 SETTINGS max_execution_time = 25'
check_code "max_execution_time = 0 (unlimited) is refused" 452 'SELECT 1 SETTINGS max_execution_time = 0'
check_code "max_memory_usage = 500000000 exceeds the cap" 452 'SELECT 1 SETTINGS max_memory_usage = 500000000'
check_code "max_result_rows = 30000 exceeds the cap" 452 'SELECT 1 SETTINGS max_result_rows = 30000'
check_code "max_concurrent_queries_for_user = 0 (unlimited) is refused" 452 'SELECT 1 SETTINGS max_concurrent_queries_for_user = 0'
check_code "join_algorithm = grace_hash is pinned" 452 "SELECT 1 SETTINGS join_algorithm = 'grace_hash'"
check_code "use_query_cache = 1 is pinned" 452 'SELECT 1 SETTINGS use_query_cache = 1'
check_code "log_queries = 0 is pinned" 452 'SELECT 1 SETTINGS log_queries = 0'
check_code "stream_like_engine_allow_direct_select = 1 is pinned" 452 'SELECT 1 SETTINGS stream_like_engine_allow_direct_select = 1'
check_ok "a benign setting (max_execution_time = 5) is accepted" 'SELECT 1 SETTINGS max_execution_time = 5'

# --- grants are exactly one line ---------------------------------------------------------------------
check_out "grants are exactly SELECT on posthog.events" 'SHOW GRANTS FOR posthog_ro' 'GRANT SELECT ON posthog.events TO posthog_ro'

echo "----"
echo "check-readonly-user: $PASSED passed, $FAILED failed"
[[ $FAILED -eq 0 ]]
