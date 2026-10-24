#!/usr/bin/env bash
# Verifies the guarantees of the ClickHouse read-only user `posthog_ro` (profile ro_insights,
# docker/clickhouse/users.d/20-readonly-user.xml) against a running ClickHouse.
#
# Only talks to a local Docker engine. Every probe runs as posthog_ro and is either a SELECT, a statement
# that must be refused before it runs (and would be harmless with zero rows if it were not), or a setting the
# server must reject. The ONLY writes are made by the ADMIN user (credentials read inside the container) to
# seed five throwaway rows for the two projects 990901 and 990902, so that the tenant isolation checks cannot
# pass on an empty table; an EXIT trap deletes exactly those rows again.
#
# Run `scripts/check-readonly-user.sh --help` for usage, environment variables and exit codes.
set -euo pipefail

cd "$(dirname "$0")/.."

usage() {
  cat <<'EOF_USAGE'
Usage: scripts/check-readonly-user.sh [-h|--help]

Seeds five rows for the throwaway projects 990901 (3 rows) and 990902 (2 rows) as the admin user, and
deletes them again on exit (also on failure). Then checks that the ClickHouse read-only user posthog_ro can
log in and SELECT from the DEFINER view posthog.project_events_v (every read must carry the SQL_project_id
setting of the view filter, which hides all other projects), and that everything else is refused with the
RIGHT error code (not merely "some error"):
  497 ACCESS_DENIED     posthog.events itself (direct, and through mergeTreeIndex / view(), which skip row
                        policies but need a grant), other tables (events_errors,
                        schema_migrations, kafka_events), INSERT, ALTER, CREATE, and the url() / file() /
                        remote() table functions
  636 CANNOT_EXTRACT_TABLE_STRUCTURE
                        merge() over events (it only lists tables the user may see: nothing matches)
  36  BAD_ARGUMENTS     mergeTreeIndex on the view (a view is not a MergeTree)
  115 UNKNOWN_SETTING   a read of the view WITHOUT SQL_project_id (fails closed)
  6   CANNOT_PARSE_TEXT SQL_project_id that is not a number
  511 UNKNOWN_ROLE      SET ROLE of a role that does not exist
  164 READONLY          SET readonly = 0 and SET profile = 'readonly'
  452 SETTING_CONSTRAINT_VIOLATION
                        raising or removing a limit (max_execution_time 25 and 0, max_memory_usage,
                        max_result_rows, max_concurrent_queries_for_user = 0) and changing a pinned
                        setting (join_algorithm, use_query_cache, log_queries,
                        stream_like_engine_allow_direct_select, session_timezone,
                        output_format_json_quote_64bit_integers, serialize_query_plan, enable_analyzer)
and that a benign setting (max_execution_time = 5) is accepted, SET profile = 'default' and SET ROLE ALL
change no limit, and the grants are EXACTLY `GRANT SELECT ON posthog.project_events_v TO posthog_ro`. For
EVERY relation found in SHOW GRANTS a bare SELECT (no SQL_project_id) must fail with 115, so a relation
granted without a filter is caught. One PASS or FAIL line per check.

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
  OUT="$(rox --multiquery --query "$1" 2>&1)" || RC=$?
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

# clickhouse-client as the ADMIN user of the container (CLICKHOUSE_USER / CLICKHOUSE_PASSWORD are in the
# container's own environment, so no secret is on any argv). Used only to seed and delete the throwaway rows.
adx() {
  if [[ -n "$CH_CONTAINER" ]]; then
    docker exec "$CH_CONTAINER" sh -c 'exec clickhouse-client "$@"' sh "$@"
  else
    docker compose exec -T clickhouse sh -c 'exec clickhouse-client "$@"' sh "$@"
  fi
}

SEED_A=990901
SEED_B=990902
SEEDED=0

# delete_seed: deletes the seeded rows and waits (at most 30 s) until they are gone; 0 when they are gone.
delete_seed() {
  local tries=0 left=1
  adx --lightweight_deletes_sync=0 --query "DELETE FROM posthog.events WHERE project_id IN ($SEED_A, $SEED_B)" >/dev/null 2>&1 || true
  while [[ $tries -lt 30 ]]; do
    left="$(adx --query "SELECT count() FROM posthog.events WHERE project_id IN ($SEED_A, $SEED_B)" 2>/dev/null || echo 1)"
    [[ "$left" == "0" ]] && return 0
    tries=$((tries + 1))
    sleep 1
  done
  return 1
}

# Removes the seeded rows on exit, keeping the exit status of the script.
cleanup_seed() {
  local rc=$?
  trap - EXIT
  if [[ $SEEDED -eq 1 ]] && ! delete_seed; then
    echo "WARN: seeded rows of projects $SEED_A/$SEED_B are still in posthog.events: delete them by hand" >&2
  fi
  exit "$rc"
}
trap cleanup_seed EXIT

# seed_rows: five rows (3 for SEED_A, 2 for SEED_B) written by the admin; starts from a clean slate.
seed_rows() {
  SEEDED=1
  delete_seed || return 1
  adx --query "INSERT INTO posthog.events (uuid, project_id, event, distinct_id, properties, timestamp) SELECT generateUUIDv4(), if(number < 3, $SEED_A, $SEED_B), 'ro_check', 'ro_check', '{}', now64(3) FROM numbers(5)" >/dev/null 2>&1
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
check_ok "SELECT count() FROM posthog.project_events_v works (with SQL_project_id)" 'SELECT count() FROM posthog.project_events_v SETTINGS SQL_project_id = 1'

# Five throwaway rows (admin) so the isolation checks below cannot pass on an empty table.
if seed_rows; then
  pass "seeded 3 rows for project $SEED_A and 2 rows for project $SEED_B (removed again on exit)"
else
  fail "could not seed the throwaway rows as the admin user (is CLICKHOUSE_USER / CLICKHOUSE_PASSWORD set in the container?)"
fi

# --- tenant isolation: the DEFINER view posthog.project_events_v (fail-closed) ---------------------------------
# The view filter is project_id = toUInt32(getSetting('SQL_project_id')). A query without the setting
# must FAIL (never return every project's rows): on 25.8 that is Code 115 UNKNOWN_SETTING, raised while
# the filter expression is evaluated. A value that is not a UInt32 fails with 6 (CANNOT_PARSE_TEXT).
check_code "SELECT without SQL_project_id is rejected (fail-closed)" 115 'SELECT count() FROM posthog.project_events_v'
check_code "SQL_project_id that is not a number is rejected" 6 "SELECT count() FROM posthog.project_events_v SETTINGS SQL_project_id = 'abc'"
# Non vacuous: the seeded rows prove that the table is not empty and that each project is readable.
check_out "project $SEED_A sees exactly its 3 rows" "SELECT count() FROM posthog.project_events_v SETTINGS SQL_project_id = $SEED_A" 3
check_out "project $SEED_B sees exactly its 2 rows" "SELECT count() FROM posthog.project_events_v SETTINGS SQL_project_id = $SEED_B" 2
check_out "project $SEED_B sees only its own project id" "SELECT toString(groupUniqArray(project_id)) FROM posthog.project_events_v SETTINGS SQL_project_id = $SEED_B" "[$SEED_B]"
check_out "the view hides every other project (WHERE project_id != $SEED_A is empty)" "SELECT count() FROM posthog.project_events_v WHERE project_id != $SEED_A SETTINGS SQL_project_id = $SEED_A" 0
check_out "the filter applies inside a UNION ALL subquery too" "SELECT count() FROM (SELECT project_id FROM posthog.project_events_v UNION ALL SELECT project_id FROM posthog.project_events_v) WHERE project_id != $SEED_A SETTINGS SQL_project_id = $SEED_A" 0
check_out "a project id nobody owns (0) sees no rows although the table has some" 'SELECT count() FROM posthog.project_events_v SETTINGS SQL_project_id = 0' 0

# Every relation the user may SELECT from needs a filter: derive them from SHOW GRANTS (not from this
# script) and require that a bare read FAILS with 115 for each. A relation granted without a filter would
# answer for every project and fail this check.
run 'SHOW GRANTS FOR posthog_ro'
GRANT_LINES="$OUT"
GRANTED_TABLES=0
while IFS= read -r line; do
  [[ -z "$line" ]] && continue
  if [[ "$line" =~ ^GRANT\ SELECT\ ON\ posthog\.([A-Za-z0-9_]+)\ TO\ posthog_ro$ ]]; then
    GRANTED_TABLES=$((GRANTED_TABLES + 1))
    check_code "bare SELECT from granted relation posthog.${BASH_REMATCH[1]} fails closed (filter present)" 115 "SELECT count() FROM posthog.${BASH_REMATCH[1]}"
  else
    fail "unexpected grant line, add a row policy and extend this check: $line"
  fi
done <<< "$GRANT_LINES"
if [[ $GRANTED_TABLES -eq 0 ]]; then fail "SHOW GRANTS listed no SELECT grant on posthog.<table>"; fi

# --- posthog.events itself and its table functions: no grant, so no read at all ----------------------------
# mergeTreeIndex / merge / view() skip row policies; they are closed by the ABSENCE of any grant on
# posthog.events (the view reads it with the definer's rights). SQL_project_id is passed to prove that the
# denial is the grant and not the missing setting.
check_code "direct SELECT from posthog.events is denied" 497 'SELECT count() FROM posthog.events SETTINGS SQL_project_id = 1'
check_code "mergeTreeIndex on posthog.events is denied" 497 "SELECT DISTINCT project_id FROM mergeTreeIndex('posthog', 'events') SETTINGS SQL_project_id = 1"
check_code "mergeTreeIndex with an assembled table name is denied" 497 "SELECT DISTINCT project_id FROM mergeTreeIndex('posthog', concat('ev', 'ents')) SETTINGS SQL_project_id = 1"
# merge() only lists tables the user may see, so the regex matches nothing (636) instead of 497: still no read.
check_code "merge() over posthog.events finds no readable table" 636 "SELECT count() FROM merge('posthog', '^events\$') SETTINGS SQL_project_id = 1"
check_code "view() over posthog.events is denied" 497 'SELECT count() FROM view(SELECT * FROM posthog.events) SETTINGS SQL_project_id = 1'
check_code "mergeTreeIndex on the view itself fails (a view is not a MergeTree)" 36 "SELECT * FROM mergeTreeIndex('posthog', 'project_events_v') SETTINGS SQL_project_id = 1"

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
check_code "session_timezone is pinned" 452 "SELECT 1 SETTINGS session_timezone = 'Europe/Istanbul'"
check_code "output_format_json_quote_64bit_integers is pinned" 452 'SELECT 1 SETTINGS output_format_json_quote_64bit_integers = 1'
check_code "serialize_query_plan is pinned" 452 'SELECT 1 SETTINGS serialize_query_plan = 1'
check_code "enable_analyzer (old analyzer) is pinned" 452 'SELECT 1 SETTINGS enable_analyzer = 0'
check_out "the pinned values are in effect" "SELECT getSetting('session_timezone'), getSetting('output_format_json_quote_64bit_integers'), getSetting('serialize_query_plan'), getSetting('enable_analyzer')" $'UTC\tfalse\tfalse\ttrue'
check_ok "a benign setting (max_execution_time = 5) is accepted" 'SELECT 1 SETTINGS max_execution_time = 5'

# --- profile and role switches do not loosen anything (outcome recorded on 25.8, not a hole) -----------
# `SET profile = 'default'` is ACCEPTED (it is a setting) but changes nothing that matters: the profile
# constraints still apply and the values stay as they are. The stock profile `readonly` is refused (164).
# No role exists, so SET ROLE ALL enables nothing; an unknown role is refused (511).
check_out "SET profile = 'default' keeps readonly and max_execution_time" "SET profile = 'default'; SELECT getSetting('readonly'), getSetting('max_execution_time')" $'2\t20'
check_out "SET profile = 'default' keeps max_memory_usage at the cap" "SET profile = 'default'; SELECT getSetting('max_memory_usage')" 400000000
check_code "a limit still cannot be raised after SET profile = 'default'" 452 "SET profile = 'default'; SELECT 1 SETTINGS max_execution_time = 0"
check_code "SET profile = 'readonly' (stock profile) is refused" 164 "SET profile = 'readonly'"
check_out "SET ROLE ALL enables no role" 'SET ROLE ALL; SELECT length(enabledRoles())' 0
check_code "SET ROLE of an unknown role is refused" 511 'SET ROLE nosuchrole'

# --- grants are exactly one line (the view, never posthog.events) ---------------------------------------------------------------------
check_out "grants are exactly SELECT on posthog.project_events_v" 'SHOW GRANTS FOR posthog_ro' 'GRANT SELECT ON posthog.project_events_v TO posthog_ro'

echo "----"
echo "check-readonly-user: $PASSED passed, $FAILED failed"
[[ $FAILED -eq 0 ]]
