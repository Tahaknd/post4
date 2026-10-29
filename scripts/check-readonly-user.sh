#!/usr/bin/env bash
# Verifies the guarantees of the ClickHouse read-only user `posthog_ro` (profile ro_insights,
# docker/clickhouse/users.d/20-readonly-user.xml) against a running ClickHouse.
#
# Only talks to a local Docker engine. Every probe runs as posthog_ro and is either a SELECT, a statement
# that must be refused before it runs (and would be harmless with zero rows if it were not), or a setting the
# server must reject. The ONLY writes are made by the ADMIN user (credentials read inside the container) to
# seed throwaway rows for the two projects 990901 and 990902 (five events and eight person_distinct_ids rows), so that
# the tenant isolation checks cannot pass on an empty table; an EXIT trap deletes exactly those rows again.
#
# Run `scripts/check-readonly-user.sh --help` for usage, environment variables and exit codes.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
# shellcheck source=scripts/lib.sh
source "$ROOT/scripts/lib.sh"

usage() {
  cat <<'EOF_USAGE'
Usage: scripts/check-readonly-user.sh [-h|--help]

Seeds five events for the throwaway projects 990901 (3 rows) and 990902 (2 rows) and six person_distinct_ids
rows for them (990901: ids d1 moved once = two unmerged versions, d2, ro_check, and d3 with a newest tombstone;
990902: e1, e2) as the admin user, and deletes them again on exit (also on failure). Then checks that the ClickHouse
read-only user posthog_ro can log in and SELECT from the two DEFINER views posthog.project_events_v and
posthog.project_person_distinct_ids_v (the deduplicated distinct id -> person mapping): every read must carry
the SQL_project_id setting of the view filter, which hides all other projects; the person view shows the newest
version of an id and hides a tombstoned one. Everything else is refused with the RIGHT error code (not merely
"some error"):
  497 ACCESS_DENIED     posthog.events and posthog.person_distinct_ids themselves (direct, and through
                        mergeTreeIndex / view(), which skip row policies but need a grant), other tables
                        (events_errors, schema_migrations, kafka_events, kafka_person_distinct_ids), INSERT,
                        ALTER, CREATE, and the url() / file() / remote() table functions
  636 CANNOT_EXTRACT_TABLE_STRUCTURE
                        merge() over events or person_distinct_ids (it only lists tables the user may see:
                        nothing matches)
  36  BAD_ARGUMENTS     mergeTreeIndex on either view (a view is not a MergeTree)
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
change no limit, and the grants are EXACTLY `GRANT SELECT ON posthog.project_events_v TO posthog_ro` and
`GRANT SELECT ON posthog.project_person_distinct_ids_v TO posthog_ro`. For
EVERY relation found in SHOW GRANTS a bare SELECT (no SQL_project_id) must fail with 115, so a relation
granted without a filter is caught. One PASS or FAIL line per check.

The same guarantees are then probed over the HTTP interface (the path the application really uses: URL
arguments instead of SET statements, run from inside the ClickHouse container with bash and /dev/tcp as
posthog_ro, authenticated with X-ClickHouse-* headers): the views read with SQL_project_id as URL argument,
fail-closed without it (115) and with a non-number (6), `readonly=0` (164) and raised limits
(max_execution_time=1000 and 0, max_memory_usage, max_result_rows: 452) as URL arguments, `profile=default`
accepted but loosening nothing (readonly stays 2, max_execution_time stays 20) while `profile=readonly` is
refused (164), `role=` of an unknown role (511), `user=` / `password=` URL arguments next to the header
credentials (516), a `session_id` session in which SET readonly = 0 (164) and SET max_execution_time = 0 (452)
fail and SET profile = 'default' loosens nothing, direct reads of posthog.events, system.users and
system.query_log (497), direct reads of posthog.person_distinct_ids and kafka_person_distinct_ids (497), INSERT
(497), and that system.tables shows only the two views. A last group runs inside the
app container (docker compose only, skipped with CH_CONTAINER): the application's own
ReadOnlyClickHouseClient::select() reads each view with SQL_project_id, fails closed without it (115) and is
denied on posthog.events, posthog.person_distinct_ids, system.users and system.query_log (497). One documented
limitation is printed as an INFO line (twice, once per view), not a check: SQL text `SETTINGS SQL_project_id =
<other id>` overrides the URL argument (the filter
protects against builder bugs, not against SQL injection; see 20-readonly-user.xml and the reader lint).

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
  lib_refuse "CH_CONTAINER must be a plain container name, got '$CH_CONTAINER'"
fi

# --- Docker target guard: only a local engine, and only if the target is up -------------------------
lib_docker_guard
if [[ -n "$CH_CONTAINER" ]]; then
  if [[ "$(docker inspect --format '{{.State.Running}}' "$CH_CONTAINER" 2>/dev/null || true)" != "true" ]]; then
    lib_refuse "container '$CH_CONTAINER' is not running"
  fi
elif [[ -z "$(docker compose ps -q clickhouse 2>/dev/null || true)" ]]; then
  lib_refuse "the clickhouse service is not up (docker compose up -d)"
fi

OUT=""
RC=0

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

# hx_raw <url arguments> <sql>: POST <sql> to the ClickHouse HTTP interface (127.0.0.1:8123 inside the
# container) as posthog_ro, with the URL arguments exactly as the application sends them. Only bash and
# /dev/tcp are used (the image has neither curl nor Python): the password is expanded by the container's bash
# from CLICKHOUSE_RO_PASSWORD into a socket write, so it is on no argv. Prints the raw HTTP response.
# shellcheck disable=SC2016
hx_raw() {
  local script='export LC_ALL=C; qs=$1; sql=$2
exec 3<>/dev/tcp/127.0.0.1/8123 || exit 7
printf "POST /?%s HTTP/1.0\r\nHost: localhost\r\nX-ClickHouse-User: posthog_ro\r\nX-ClickHouse-Key: %s\r\nContent-Length: %d\r\n\r\n%s" "$qs" "$CLICKHOUSE_RO_PASSWORD" "${#sql}" "$sql" >&3
head -c 20000 <&3 | tr -d "\r"'
  if [[ -n "$CH_CONTAINER" ]]; then
    docker exec "$CH_CONTAINER" bash -c "$script" bash "$1" "$2"
  else
    docker compose exec -T clickhouse bash -c "$script" bash "$1" "$2"
  fi
}

# hrun <url arguments> <sql>: HTTP status in HTTP_STATUS, response body in OUT, transport status in RC.
hrun() {
  local raw
  RC=0
  raw="$(hx_raw "$1" "$2" 2>&1)" || RC=$?
  HTTP_STATUS="$(printf '%s\n' "$raw" | sed -n '1s/^HTTP\/[0-9.]* \([0-9]\{3\}\).*/\1/p')"
  OUT="$(printf '%s\n' "$raw" | sed '1,/^$/d')"
}

# check_http_out <name> <url arguments> <sql> <expected body>: HTTP 200 and exactly this body.
check_http_out() {
  hrun "$2" "$3"
  if [[ $RC -eq 0 && "$HTTP_STATUS" == "200" && "$OUT" == "$4" ]]; then pass "$1"; else fail "$1: expected '$4', got (HTTP ${HTTP_STATUS:-none}, exit $RC): $(short "$OUT")"; fi
}

# check_http_code <name> <error code> <url arguments> <sql>: not HTTP 200 and this exact ClickHouse error code.
check_http_code() {
  hrun "$3" "$4"
  if [[ "$HTTP_STATUS" != "200" && "$OUT" == *"Code: $2. DB::Exception"* ]]; then
    pass "$1 -> $2 (HTTP $HTTP_STATUS)"
  elif [[ "$HTTP_STATUS" == "200" ]]; then
    fail "$1: expected error $2 but the request SUCCEEDED over HTTP (check the grants and profile in 20-readonly-user.xml)"
  else
    fail "$1: expected error $2, got (HTTP ${HTTP_STATUS:-none}, exit $RC): $(short "$OUT")"
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

# delete_seed: deletes the seeded rows of both tables and waits (at most 30 s) until they are gone; 0 when they are gone.
delete_seed() {
  local tries=0 left=1
  adx --lightweight_deletes_sync=0 --query "DELETE FROM posthog.events WHERE project_id IN ($SEED_A, $SEED_B)" >/dev/null 2>&1 || true
  adx --lightweight_deletes_sync=0 --query "DELETE FROM posthog.person_distinct_ids WHERE project_id IN ($SEED_A, $SEED_B)" >/dev/null 2>&1 || true
  while [[ $tries -lt 30 ]]; do
    left="$(adx --query "SELECT (SELECT count() FROM posthog.events WHERE project_id IN ($SEED_A, $SEED_B)) + (SELECT count() FROM posthog.person_distinct_ids WHERE project_id IN ($SEED_A, $SEED_B))" 2>/dev/null || echo 1)"
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
    echo "WARN: seeded rows of projects $SEED_A/$SEED_B are still in posthog.events or posthog.person_distinct_ids: delete them by hand" >&2
  fi
  exit "$rc"
}
trap cleanup_seed EXIT

# Fixed person uuids of the seeded mapping rows (they only need to be distinguishable).
PERSON_A1='00000000-0000-4000-8000-0000000a0001'
PERSON_A2='00000000-0000-4000-8000-0000000a0002'
PERSON_B1='00000000-0000-4000-8000-0000000b0001'

# seed_rows: five events (3 for SEED_A, 2 for SEED_B) and eight person_distinct_ids rows, written by the admin; starts
# from a clean slate. Mapping rows of SEED_A: d1 version 0 -> PERSON_A1 and version 1 -> PERSON_A2 (a merge moved it;
# two separate inserts = two parts, unmerged for a while), d2 and ro_check (the distinct id of the seeded events) ->
# PERSON_A1, d3 version 0 -> PERSON_A1 and a newest tombstone (version 1, is_deleted 1). SEED_B: e1 and e2 -> PERSON_B1.
seed_rows() {
  SEEDED=1
  delete_seed || return 1
  adx --query "INSERT INTO posthog.events (uuid, project_id, event, distinct_id, properties, timestamp) SELECT generateUUIDv4(), if(number < 3, $SEED_A, $SEED_B), 'ro_check', 'ro_check', '{\"\$os\":\"ro_os\",\"\$device_type\":\"ro_device\",\"utm_source\":\"ro_utm\"}', now64(3) FROM numbers(5)" >/dev/null 2>&1 || return 1
  adx --query "INSERT INTO posthog.person_distinct_ids (project_id, distinct_id, person_id, version, is_deleted, created_at) VALUES ($SEED_A, 'd1', '$PERSON_A1', 0, 0, now64(3)), ($SEED_A, 'd2', '$PERSON_A1', 0, 0, now64(3)), ($SEED_A, 'ro_check', '$PERSON_A1', 0, 0, now64(3)), ($SEED_A, 'd3', '$PERSON_A1', 0, 0, now64(3)), ($SEED_B, 'e1', '$PERSON_B1', 0, 0, now64(3)), ($SEED_B, 'e2', '$PERSON_B1', 0, 0, now64(3))" >/dev/null 2>&1 || return 1
  adx --query "INSERT INTO posthog.person_distinct_ids (project_id, distinct_id, person_id, version, is_deleted, created_at) VALUES ($SEED_A, 'd1', '$PERSON_A2', 1, 0, now64(3)), ($SEED_A, 'd3', '$PERSON_A1', 1, 1, now64(3))" >/dev/null 2>&1
}

echo "check-readonly-user: target=${CH_CONTAINER:-compose service clickhouse}"

# --- login and the one allowed read ------------------------------------------------------------------
run 'SELECT currentUser()'
if [[ $RC -eq 0 && "$OUT" == "posthog_ro" ]]; then
  pass "login works (currentUser() = posthog_ro)"
else
  fail "login as posthog_ro failed: $(short "$OUT") (is 20-readonly-user.xml mounted and CLICKHOUSE_RO_PASSWORD set in the container? Is ClickHouse crash-looping after a config edit? Check \`docker compose ps clickhouse\`: exit code 91 or 36 in a restart loop means an invalid or renamed setting in docker/clickhouse/config.d or users.d, see \`docker compose logs clickhouse\`)"
  echo "----"
  echo "check-readonly-user: $PASSED passed, $FAILED failed"
  exit 1
fi
# Every read needs the SQL_project_id setting (row policy, see below); without it the query fails.
check_ok "SELECT count() FROM posthog.project_events_v works (with SQL_project_id)" 'SELECT count() FROM posthog.project_events_v SETTINGS SQL_project_id = 1'

# Five throwaway rows (admin) so the isolation checks below cannot pass on an empty table.
if seed_rows; then
  pass "seeded 3 events and 6 mapping rows for project $SEED_A, 2 events and 2 mapping rows for project $SEED_B (removed again on exit)"
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

# --- tenant isolation: the DEFINER view posthog.project_person_distinct_ids_v (migration 004, the same pattern) ----------
# Deduplicated mapping: one row per distinct id with the NEWEST version, none for an id whose newest row is a tombstone.
# SEED_A has 5 physical rows (d1 twice, d3 twice) but sees 2 mappings (d1, d2); SEED_B sees e1, e2.
check_code "person view: SELECT without SQL_project_id is rejected (fail-closed)" 115 'SELECT count() FROM posthog.project_person_distinct_ids_v'
check_code "person view: SQL_project_id that is not a number is rejected" 6 "SELECT count() FROM posthog.project_person_distinct_ids_v SETTINGS SQL_project_id = 'abc'"
check_out "person view: project $SEED_A sees exactly its 3 live mappings (d1 deduplicated, d3 tombstoned)" "SELECT count() FROM posthog.project_person_distinct_ids_v SETTINGS SQL_project_id = $SEED_A" 3
check_out "person view: project $SEED_B sees exactly its 2 mappings" "SELECT count() FROM posthog.project_person_distinct_ids_v SETTINGS SQL_project_id = $SEED_B" 2
check_out "person view: d1 maps to the NEWEST version (the person it was merged into), d2 to the first one" \
  "SELECT concat(distinct_id, '=', toString(person_id), '@', toString(version)) FROM posthog.project_person_distinct_ids_v ORDER BY distinct_id SETTINGS SQL_project_id = $SEED_A" \
  $'d1='"$PERSON_A2"$'@1\nd2='"$PERSON_A1"$'@0\nro_check='"$PERSON_A1"$'@0'
check_out "person view: a tombstoned id (d3) is not visible" "SELECT count() FROM posthog.project_person_distinct_ids_v WHERE distinct_id = 'd3' SETTINGS SQL_project_id = $SEED_A" 0
check_out "person view: the superseded person of d1 is not visible through a person_id filter either" "SELECT count() FROM posthog.project_person_distinct_ids_v WHERE distinct_id = 'd1' AND person_id = '$PERSON_A1' SETTINGS SQL_project_id = $SEED_A" 0
check_out "person view: project $SEED_B sees only its own project id" "SELECT toString(groupUniqArray(project_id)) FROM posthog.project_person_distinct_ids_v SETTINGS SQL_project_id = $SEED_B" "[$SEED_B]"
check_out "person view: it hides every other project (WHERE project_id != $SEED_A is empty)" "SELECT count() FROM posthog.project_person_distinct_ids_v WHERE project_id != $SEED_A SETTINGS SQL_project_id = $SEED_A" 0
check_out "person view: the filter applies inside a UNION ALL subquery too" "SELECT count() FROM (SELECT project_id FROM posthog.project_person_distinct_ids_v UNION ALL SELECT project_id FROM posthog.project_person_distinct_ids_v) WHERE project_id != $SEED_A SETTINGS SQL_project_id = $SEED_A" 0
check_out "person view: a project id nobody owns (0) sees no rows although the table has some" 'SELECT count() FROM posthog.project_person_distinct_ids_v SETTINGS SQL_project_id = 0' 0
check_out "the two views join inside one project: the 3 events of $SEED_A meet its ro_check mapping" \
  "SELECT count() FROM posthog.project_events_v AS e INNER JOIN posthog.project_person_distinct_ids_v AS p ON e.distinct_id = p.distinct_id SETTINGS SQL_project_id = $SEED_A" 3
check_out "... and the 2 events of $SEED_B meet nothing: project $SEED_A's ro_check mapping is invisible to it" \
  "SELECT count() FROM posthog.project_events_v AS e INNER JOIN posthog.project_person_distinct_ids_v AS p ON e.distinct_id = p.distinct_id SETTINGS SQL_project_id = $SEED_B" 0

# --- the view after a CREATE OR REPLACE (migration 003 recreated it): columns, grant, policy, definer ---------
# The columns materialized by 003 are readable through the replaced view, from the seeded properties.
check_out "the columns added by migration 003 (os, device_type, utm_source) are readable through the view" \
  "SELECT concat(os, '|', device_type, '|', utm_source, '|', toString(count())) FROM posthog.project_events_v WHERE project_id = $SEED_A GROUP BY os, device_type, utm_source SETTINGS SQL_project_id = $SEED_A" \
  "ro_os|ro_device|ro_utm|3"
check_out "the other columns of the view are still readable (browser, country_code, current_url are empty here)" \
  "SELECT concat(browser, country_code, current_url, utm_medium, utm_campaign, toString(count())) FROM posthog.project_events_v WHERE project_id = $SEED_B GROUP BY browser, country_code, current_url, utm_medium, utm_campaign SETTINGS SQL_project_id = $SEED_B" \
  "2"
# The grant and the row policy are declared by NAME in 20-readonly-user.xml, so they apply to the replaced
# view; the admin looks at the server's own catalogue (the read-only user cannot read system tables).
POLICIES="$(adx --query "SELECT count() FROM system.row_policies WHERE database = 'posthog' AND table = 'project_events_v'" 2>/dev/null || echo ERR)"
if [[ "$POLICIES" =~ ^[0-9]+$ && "$POLICIES" -ge 1 ]]; then pass "a row policy exists on posthog.project_events_v ($POLICIES)"; else fail "no row policy on posthog.project_events_v (got '$POLICIES'): it must survive CREATE OR REPLACE VIEW"; fi
DEFINER_OK="$(adx --query "SELECT count() FROM system.tables WHERE database = 'posthog' AND name = 'project_events_v' AND create_table_query LIKE '%SQL SECURITY DEFINER%' AND create_table_query LIKE '%getSetting(\\'SQL_project_id\\')%'" 2>/dev/null || echo ERR)"
if [[ "$DEFINER_OK" == "1" ]]; then pass "the current view is SQL SECURITY DEFINER and keeps the SQL_project_id filter"; else fail "the current view lost SQL SECURITY DEFINER or its SQL_project_id filter (got '$DEFINER_OK')"; fi

POLICIES="$(adx --query "SELECT count() FROM system.row_policies WHERE database = 'posthog' AND table = 'project_person_distinct_ids_v'" 2>/dev/null || echo ERR)"
if [[ "$POLICIES" =~ ^[0-9]+$ && "$POLICIES" -ge 1 ]]; then pass "a row policy exists on posthog.project_person_distinct_ids_v ($POLICIES)"; else fail "no row policy on posthog.project_person_distinct_ids_v (got '$POLICIES')"; fi
DEFINER_OK="$(adx --query "SELECT count() FROM system.tables WHERE database = 'posthog' AND name = 'project_person_distinct_ids_v' AND create_table_query LIKE '%SQL SECURITY DEFINER%' AND create_table_query LIKE '%getSetting(\\'SQL_project_id\\')%'" 2>/dev/null || echo ERR)"
if [[ "$DEFINER_OK" == "1" ]]; then pass "the person view is SQL SECURITY DEFINER and keeps the SQL_project_id filter"; else fail "the person view lost SQL SECURITY DEFINER or its SQL_project_id filter (got '$DEFINER_OK')"; fi

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

# The same for the person mapping: the base table has no grant at all, so every road to it is closed on the server.
check_code "direct SELECT from posthog.person_distinct_ids is denied" 497 'SELECT count() FROM posthog.person_distinct_ids SETTINGS SQL_project_id = 1'
check_code "mergeTreeIndex on posthog.person_distinct_ids is denied" 497 "SELECT DISTINCT project_id FROM mergeTreeIndex('posthog', 'person_distinct_ids') SETTINGS SQL_project_id = 1"
check_code "mergeTreeIndex with an assembled person table name is denied" 497 "SELECT DISTINCT project_id FROM mergeTreeIndex('posthog', concat('person_distinct', '_ids')) SETTINGS SQL_project_id = 1"
check_code "merge() over posthog.person_distinct_ids finds no readable table" 636 "SELECT count() FROM merge('posthog', '^person_distinct_ids\$') SETTINGS SQL_project_id = 1"
check_code "view() over posthog.person_distinct_ids is denied" 497 'SELECT count() FROM view(SELECT * FROM posthog.person_distinct_ids) SETTINGS SQL_project_id = 1'
check_code "mergeTreeIndex on the person view itself fails (a view is not a MergeTree)" 36 "SELECT * FROM mergeTreeIndex('posthog', 'project_person_distinct_ids_v') SETTINGS SQL_project_id = 1"

# --- no other table ----------------------------------------------------------------------------------
check_code "SELECT from posthog.events_errors is denied" 497 'SELECT count() FROM posthog.events_errors'
check_code "SELECT from posthog.schema_migrations is denied" 497 'SELECT count() FROM posthog.schema_migrations'
check_code "SELECT from posthog.kafka_events is denied" 497 'SELECT count() FROM posthog.kafka_events'
check_code "SELECT from posthog.kafka_person_distinct_ids is denied" 497 'SELECT count() FROM posthog.kafka_person_distinct_ids'

# --- no writes (each statement is refused before it runs; the INSERT and ALTER would touch no row anyway)
check_code "INSERT into posthog.events is denied" 497 "INSERT INTO posthog.events (uuid, project_id, event, distinct_id, properties, timestamp) SELECT toUUID('00000000-0000-0000-0000-000000000000'), 0, '', '', '', now64(3) WHERE 0"
check_code "INSERT into posthog.person_distinct_ids is denied" 497 "INSERT INTO posthog.person_distinct_ids (project_id, distinct_id, person_id, version, is_deleted, created_at) SELECT 0, '', toUUID('00000000-0000-0000-0000-000000000000'), 0, 0, now64(3) WHERE 0"
check_code "ALTER TABLE posthog.events is denied" 497 'ALTER TABLE posthog.events DELETE WHERE 0'
check_code "ALTER TABLE posthog.person_distinct_ids is denied" 497 'ALTER TABLE posthog.person_distinct_ids DELETE WHERE 0'
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

# --- the same guarantees over the HTTP interface (what ReadOnlyClickHouseClient really uses) ---------------------
# The application never speaks the native protocol: it sends SQL_project_id, default_format, wait_end_of_query
# and database as URL arguments, which is why the profile is readonly=2. The probes below put the settings the
# native checks above change with SET into URL arguments (and into a session) and expect the same outcomes.
check_http_out "HTTP: login as posthog_ro with readonly = 2" "SQL_project_id=1" "SELECT currentUser(), getSetting('readonly')" $'posthog_ro\t2'
check_http_out "HTTP: project $SEED_A sees exactly its 3 rows (SQL_project_id as URL argument)" "SQL_project_id=$SEED_A" 'SELECT count() FROM posthog.project_events_v' 3
check_http_out "HTTP: project $SEED_B sees exactly its 2 rows" "SQL_project_id=$SEED_B" 'SELECT count() FROM posthog.project_events_v' 2
check_http_out "HTTP: the view hides every other project" "SQL_project_id=$SEED_A" "SELECT count() FROM posthog.project_events_v WHERE project_id != $SEED_A" 0
check_http_out "HTTP: person view: project $SEED_A sees exactly its 3 live mappings (SQL_project_id as URL argument)" "SQL_project_id=$SEED_A" 'SELECT count() FROM posthog.project_person_distinct_ids_v' 3
check_http_out "HTTP: person view: project $SEED_B sees exactly its 2 mappings" "SQL_project_id=$SEED_B" 'SELECT count() FROM posthog.project_person_distinct_ids_v' 2
check_http_out "HTTP: person view: d1 maps to the newest version" "SQL_project_id=$SEED_A" "SELECT toString(person_id) FROM posthog.project_person_distinct_ids_v WHERE distinct_id = 'd1'" "$PERSON_A2"
check_http_out "HTTP: person view: it hides every other project" "SQL_project_id=$SEED_A" "SELECT count() FROM posthog.project_person_distinct_ids_v WHERE project_id != $SEED_A" 0
check_http_code "HTTP: person view: a read without SQL_project_id is rejected (fail-closed)" 115 "default_format=TSV" 'SELECT count() FROM posthog.project_person_distinct_ids_v'
check_http_code "HTTP: person view: SQL_project_id that is not a number is rejected" 6 "SQL_project_id=abc" 'SELECT count() FROM posthog.project_person_distinct_ids_v'
check_http_code "HTTP: a read without SQL_project_id is rejected (fail-closed)" 115 "default_format=TSV" 'SELECT count() FROM posthog.project_events_v'
check_http_code "HTTP: SQL_project_id that is not a number is rejected" 6 "SQL_project_id=abc" 'SELECT count() FROM posthog.project_events_v'
check_http_code "HTTP: readonly=0 as URL argument is refused" 164 "SQL_project_id=1&readonly=0" 'SELECT 1'
check_http_code "HTTP: readonly=1 as URL argument is refused (readonly cannot be touched at all)" 164 "SQL_project_id=1&readonly=1" 'SELECT 1'
check_http_code "HTTP: max_execution_time=1000 as URL argument exceeds the cap" 452 "SQL_project_id=1&max_execution_time=1000" 'SELECT 1'
check_http_code "HTTP: max_execution_time=0 (unlimited) as URL argument is refused" 452 "SQL_project_id=1&max_execution_time=0" 'SELECT 1'
check_http_code "HTTP: max_memory_usage=500000000 as URL argument exceeds the cap" 452 "SQL_project_id=1&max_memory_usage=500000000" 'SELECT 1'
check_http_code "HTTP: max_result_rows=30000 as URL argument exceeds the cap" 452 "SQL_project_id=1&max_result_rows=30000" 'SELECT 1'
check_http_code "HTTP: allow_introspection_functions=1 as URL argument is pinned" 452 "SQL_project_id=1&allow_introspection_functions=1" 'SELECT 1'
check_http_out "HTTP: a benign URL argument (max_execution_time=5) is accepted and applied" "SQL_project_id=1&max_execution_time=5" "SELECT getSetting('max_execution_time')" 5
check_http_out "HTTP: profile=default is accepted but keeps readonly and max_execution_time" "SQL_project_id=1&profile=default" "SELECT getSetting('readonly'), getSetting('max_execution_time')" $'2\t20'
check_http_code "HTTP: a limit still cannot be raised together with profile=default" 452 "SQL_project_id=1&profile=default&max_execution_time=0" 'SELECT 1'
check_http_code "HTTP: profile=readonly (stock profile) is refused" 164 "SQL_project_id=1&profile=readonly" 'SELECT 1'
check_http_code "HTTP: role= of an unknown role is refused" 511 "SQL_project_id=1&role=nosuchrole" 'SELECT 1'
check_http_code "HTTP: user= next to the header credentials is refused (no user switch)" 516 "SQL_project_id=1&user=default" 'SELECT 1'
check_http_code "HTTP: user= and password= next to the header credentials are refused" 516 "SQL_project_id=1&user=posthog&password=x" 'SELECT 1'
check_http_out "HTTP: a quota_key changes nothing" "SQL_project_id=$SEED_A&quota_key=other" 'SELECT count() FROM posthog.project_events_v' 3

# A session (session_id) keeps SET statements between requests: none of them may loosen the profile.
HTTP_SESSION="ro_check_$$_${RANDOM}"
check_http_code "HTTP session: SET readonly = 0 is refused" 164 "SQL_project_id=1&session_id=$HTTP_SESSION" 'SET readonly = 0'
check_http_code "HTTP session: SET max_execution_time = 0 is refused" 452 "SQL_project_id=1&session_id=$HTTP_SESSION" 'SET max_execution_time = 0'
check_http_code "HTTP session: SET max_memory_usage above the cap is refused" 452 "SQL_project_id=1&session_id=$HTTP_SESSION" 'SET max_memory_usage = 500000000'
check_http_out "HTTP session: SET profile = 'default' is accepted" "SQL_project_id=1&session_id=$HTTP_SESSION" "SET profile = 'default'" ""
check_http_out "HTTP session: the session still has readonly = 2 and max_execution_time = 20" "SQL_project_id=1&session_id=$HTTP_SESSION" "SELECT getSetting('readonly'), getSetting('max_execution_time')" $'2\t20'
check_http_code "HTTP session: a limit still cannot be raised afterwards" 452 "SQL_project_id=1&session_id=$HTTP_SESSION" 'SELECT 1 SETTINGS max_execution_time = 0'

# Grants over HTTP: no read of events, no system tables with data, no write.
check_http_code "HTTP: direct SELECT from posthog.events is denied" 497 "SQL_project_id=1" 'SELECT count() FROM posthog.events'
check_http_code "HTTP: direct SELECT from posthog.person_distinct_ids is denied" 497 "SQL_project_id=1" 'SELECT count() FROM posthog.person_distinct_ids'
check_http_code "HTTP: SELECT from posthog.kafka_person_distinct_ids is denied" 497 "SQL_project_id=1" 'SELECT count() FROM posthog.kafka_person_distinct_ids'
check_http_code "HTTP: SELECT from system.users is denied" 497 "SQL_project_id=1" 'SELECT count() FROM system.users'
check_http_code "HTTP: SELECT from system.query_log is denied" 497 "SQL_project_id=1" 'SELECT count() FROM system.query_log'
check_http_code "HTTP: INSERT into posthog.events is denied" 497 "SQL_project_id=1" "INSERT INTO posthog.events (uuid, project_id, event, distinct_id, properties, timestamp) SELECT toUUID('00000000-0000-0000-0000-000000000000'), 0, '', '', '', now64(3) WHERE 0"
check_http_code "HTTP: CREATE TABLE is denied" 497 "SQL_project_id=1" 'CREATE TABLE posthog.ro_check_probe (a Int8) ENGINE = Memory'
check_http_out "HTTP: database=system shows only the two granted views in system.tables" "SQL_project_id=1&database=system" "SELECT concat(database, '.', name) FROM tables ORDER BY name" $'posthog.project_events_v\nposthog.project_person_distinct_ids_v'
hrun "SQL_project_id=$SEED_A" "SELECT count() FROM posthog.project_events_v SETTINGS SQL_project_id = $SEED_B"
echo "INFO: documented limitation, not a check: SQL text 'SETTINGS SQL_project_id = <other id>' overrides the URL argument (URL argument said project $SEED_A, the SQL text said project $SEED_B, the server counted $(short "$OUT") row(s)): the filter guards against query builder bugs, injection is stopped by typed parameters and the ProjectScopedReader lint"
hrun "SQL_project_id=$SEED_A" "SELECT count() FROM posthog.project_person_distinct_ids_v SETTINGS SQL_project_id = $SEED_B"
echo "INFO: documented limitation, not a check (person view, same as the events view): SQL text 'SETTINGS SQL_project_id = <other id>' overrides the URL argument (URL argument said project $SEED_A, the SQL text said project $SEED_B, the server counted $(short "$OUT") mapping(s)): closed only by the ProjectScopedReader lint"

# --- the application's own client in the app container (compose only) --------------------------------------------------------
if [[ -z "$CH_CONTAINER" ]]; then
  if [[ -z "$(docker compose ps -q app 2>/dev/null || true)" ]]; then
    echo "INFO: the app service is not up: the ReadOnlyClickHouseClient probes are skipped"
  else
    # shellcheck disable=SC2016
    APP_PROBE='$c = app(App\Infrastructure\ClickHouse\ReadOnlyClickHouseClient::class);
$p = function (string $name, string $sql, array $s = []) use ($c) {
    try { $r = $c->select($sql, [], $s); echo "PROBE|$name|ok|".($r[0]["c"] ?? "")."\n"; }
    catch (Throwable $e) { preg_match("/Code: (\\d+)\\./", $e->getMessage(), $m); echo "PROBE|$name|error|".($m[1] ?? "none")."\n"; }
};
$id = [App\Infrastructure\ClickHouse\RoSettings::SQL_PROJECT_ID => '"$SEED_A"'];
$p("view", "SELECT count() AS c FROM posthog.project_events_v", $id);
$p("bare", "SELECT count() AS c FROM posthog.project_events_v");
$p("events", "SELECT count() AS c FROM posthog.events", $id);
$p("users", "SELECT count() AS c FROM system.users", $id);
$p("query_log", "SELECT count() AS c FROM system.query_log", $id);
$p("pview", "SELECT count() AS c FROM posthog.project_person_distinct_ids_v", $id);
$p("pbare", "SELECT count() AS c FROM posthog.project_person_distinct_ids_v");
$p("ptable", "SELECT count() AS c FROM posthog.person_distinct_ids", $id);'
    APP_OUT="$(docker compose exec -T app php artisan tinker --execute="$APP_PROBE" 2>&1 || true)"
    probe_line() { printf '%s\n' "$APP_OUT" | grep -m1 "^PROBE|$1|" || true; }
    [[ "$(probe_line view)" == "PROBE|view|ok|3" ]] && pass "app ReadOnlyClickHouseClient: the view with SQL_project_id returns project $SEED_A's 3 rows" || fail "app ReadOnlyClickHouseClient view read: expected 3 rows, got: $(short "$(probe_line view)") $(short "$APP_OUT")"
    [[ "$(probe_line bare)" == "PROBE|bare|error|115" ]] && pass "app ReadOnlyClickHouseClient: a read without SQL_project_id fails closed -> 115" || fail "app ReadOnlyClickHouseClient bare read: expected error 115, got: $(short "$(probe_line bare)")"
    [[ "$(probe_line events)" == "PROBE|events|error|497" ]] && pass "app ReadOnlyClickHouseClient: posthog.events is denied -> 497" || fail "app ReadOnlyClickHouseClient events read: expected error 497, got: $(short "$(probe_line events)")"
    [[ "$(probe_line users)" == "PROBE|users|error|497" ]] && pass "app ReadOnlyClickHouseClient: system.users is denied -> 497" || fail "app ReadOnlyClickHouseClient system.users read: expected error 497, got: $(short "$(probe_line users)")"
    [[ "$(probe_line pview)" == "PROBE|pview|ok|3" ]] && pass "app ReadOnlyClickHouseClient: the person view with SQL_project_id returns project $SEED_A's 3 mappings" || fail "app ReadOnlyClickHouseClient person view read: expected 3 rows, got: $(short "$(probe_line pview)") $(short "$APP_OUT")"
    [[ "$(probe_line pbare)" == "PROBE|pbare|error|115" ]] && pass "app ReadOnlyClickHouseClient: a person view read without SQL_project_id fails closed -> 115" || fail "app ReadOnlyClickHouseClient person view bare read: expected error 115, got: $(short "$(probe_line pbare)")"
    [[ "$(probe_line ptable)" == "PROBE|ptable|error|497" ]] && pass "app ReadOnlyClickHouseClient: posthog.person_distinct_ids is denied -> 497" || fail "app ReadOnlyClickHouseClient person table read: expected error 497, got: $(short "$(probe_line ptable)")"
    [[ "$(probe_line query_log)" == "PROBE|query_log|error|497" ]] && pass "app ReadOnlyClickHouseClient: system.query_log is denied -> 497" || fail "app ReadOnlyClickHouseClient system.query_log read: expected error 497, got: $(short "$(probe_line query_log)")"
  fi
fi

# --- grants are exactly two lines (the two views, never a base table) ---------------------------------------------------------------------
check_out "grants are exactly SELECT on posthog.project_events_v and posthog.project_person_distinct_ids_v" 'SHOW GRANTS FOR posthog_ro' $'GRANT SELECT ON posthog.project_events_v TO posthog_ro\nGRANT SELECT ON posthog.project_person_distinct_ids_v TO posthog_ro'

echo "----"
echo "check-readonly-user: $PASSED passed, $FAILED failed"
[[ $FAILED -eq 0 ]]
