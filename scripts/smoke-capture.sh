#!/usr/bin/env bash
# End-to-end capture smoke test: HTTP -> Kafka -> ClickHouse, against the running compose stack.
#
# Safe by construction: only talks to a local HOST and a local Docker engine, uses a throwaway project
# (found and deleted by its unique api_key, never by name) and `smoke_`-prefixed events, and on exit
# (success, failure, INT or TERM) removes exactly what it created.
#
# Concurrency: running two instances at once is NOT a problem (every check is scoped to this run's own
# project, event name and start time), but do not run it alongside tests/compat/run.sh --with-kafka-outage
# (a stopped Kafka makes the capture checks fail by design).
#
# Run `scripts/smoke-capture.sh --help` for usage, environment variables and exit codes.
set -euo pipefail

cd "$(dirname "$0")/.."

usage() {
  cat <<'EOF'
Usage: scripts/smoke-capture.sh [-h|--help]

End-to-end capture smoke test against the running docker compose stack.

Environment:
  HOST              capture endpoint, http://localhost[:port] or http://127.0.0.1[:port]
                    (default http://localhost:8000; anything else is refused)
  WAIT_SECONDS      how long to poll ClickHouse for the event / consumer commit (default 60)
  MAX_BODY_BYTES    the server's request body limit; the 413 check sends 120% of it (default 1000000)
  CLICKHOUSE_PASSWORD
                    only used if the clickhouse container has no CLICKHOUSE_PASSWORD of its own

Exit codes:
  0  all checks passed
  1  at least one check failed (or the script aborted)
  2  refused: bad HOST / non-local Docker endpoint / stack not up / unknown argument /
     project id collides with existing ClickHouse rows
EOF
}

for arg in "$@"; do
  case "$arg" in
    -h | --help) usage; exit 0 ;;
    *) echo "unknown argument: $arg (try --help)" >&2; exit 2 ;;
  esac
done

HOST="${HOST:-http://localhost:8000}"
WAIT_SECONDS="${WAIT_SECONDS:-60}"
MAX_BODY_BYTES="${MAX_BODY_BYTES:-1000000}"
# Only used inside the container when it has no CLICKHOUSE_PASSWORD of its own (passed by name, never on argv).
export CH_FALLBACK_PASSWORD="${CLICKHOUSE_PASSWORD:-posthog}"

if [[ ! "$HOST" =~ ^http://(localhost|127\.0\.0\.1)(:[0-9]+)?$ ]]; then
  echo "REFUSED: HOST must be http://localhost[:port] or http://127.0.0.1[:port], got '$HOST'" >&2
  exit 2
fi
if [[ ! "$WAIT_SECONDS" =~ ^[0-9]+$ || "$WAIT_SECONDS" -lt 1 ]]; then
  echo "REFUSED: WAIT_SECONDS must be a positive integer, got '$WAIT_SECONDS'" >&2
  exit 2
fi
if [[ ! "$MAX_BODY_BYTES" =~ ^[0-9]+$ || "$MAX_BODY_BYTES" -lt 1 ]]; then
  echo "REFUSED: MAX_BODY_BYTES must be a positive integer, got '$MAX_BODY_BYTES'" >&2
  exit 2
fi

# --- Docker target guard: only a local engine, and only if the stack is up -------------------------
command -v docker >/dev/null 2>&1 || { echo "REFUSED: docker is not installed" >&2; exit 2; }
endpoint="${DOCKER_HOST:-$(docker context inspect --format '{{.Endpoints.docker.Host}}' 2>/dev/null || true)}"
case "$endpoint" in
  unix://* | npipe://* | "") ;;
  *) echo "REFUSED: docker endpoint '$endpoint' is not local" >&2; exit 2 ;;
esac
if [[ -z "$(docker compose ps -q app 2>/dev/null || true)" ]]; then
  echo "REFUSED: stack is not up (docker compose up -d)" >&2
  exit 2
fi

EPOCH="$(date +%s)"
NAME="smoke_${EPOCH}_$$_${RANDOM}"
MARK="$NAME"
SENT_URL='http://example.test/'
KEY=""
PROJECT_ID=""
REFUSED=0
TMP="$(mktemp -d)"
PASSED=0
FAILED=0
HTTP_CODE="000"
HTTP_BODY=""
CH_OUT=""

pass() { PASSED=$((PASSED + 1)); echo "PASS: $1"; }
fail() { FAILED=$((FAILED + 1)); echo "FAIL: $1"; }

# ClickHouse client run inside the container with the container's own CLICKHOUSE_USER / CLICKHOUSE_PASSWORD
# (the client reads them from its environment), so no password appears on any argv.
# SC2016 is intended: the single-quoted script is for the container's shell, not for this one.
# shellcheck disable=SC2016
ch() {
  docker compose exec -T -e CH_FALLBACK_PASSWORD clickhouse sh -c '
    [ -n "${CLICKHOUSE_USER:-}" ] || CLICKHOUSE_USER=posthog
    [ -n "${CLICKHOUSE_PASSWORD:-}" ] || CLICKHOUSE_PASSWORD="$CH_FALLBACK_PASSWORD"
    export CLICKHOUSE_USER CLICKHOUSE_PASSWORD
    exec clickhouse-client --max_execution_time=30 "$@"' sh --query "$1"
}

# chv <query>: result in CH_OUT; a failing query is reported through fail and returns 1.
chv() {
  if CH_OUT="$(ch "$1" 2>"$TMP/ch_err")"; then
    return 0
  fi
  CH_OUT=""
  fail "ClickHouse query failed: ${1:0:90} ($(head -c 200 "$TMP/ch_err" 2>/dev/null | tr '\n' ' '))"
  return 1
}

# Removes the throwaway project by its unique api_key (and its cached key lookups).
delete_project() {
  [[ -n "$KEY" ]] || return 0
  local out
  out="$(docker compose exec -T app php artisan tinker --execute="\$k = '${KEY}'; app(App\\Domain\\Capture\\ProjectResolver::class)->forget(\$k); \$n = App\\Models\\Project::where('api_key', \$k)->delete(); app(App\\Domain\\Capture\\ProjectResolver::class)->forget(\$k); echo 'deleted='.\$n;" 2>&1)" \
    && [[ "$out" == *"deleted="* ]] || { echo "WARN: could not delete throwaway project (key ${KEY:0:8}...): ${out:0:200}" >&2; return 1; }
  KEY=""
}

cleanup() {
  local rc=$?
  trap '' INT TERM
  trap - EXIT
  set +e
  # Project first, so no new events are accepted for it; then give Kafka a moment to drain what is in
  # flight, then delete only this run's rows (event prefix AND our throwaway project), never TRUNCATE.
  local had_project=0 left
  [[ -n "$KEY" ]] && had_project=1
  delete_project
  if [[ -n "$PROJECT_ID" ]]; then
    sleep 3
    ch "DELETE FROM posthog.events WHERE event LIKE 'smoke\\\\_%' AND project_id = ${PROJECT_ID}" >/dev/null 2>&1 \
      || echo "WARN: could not delete smoke rows from posthog.events" >&2
    left="$(ch "SELECT count() FROM posthog.events WHERE event LIKE 'smoke\\\\_%' AND project_id = ${PROJECT_ID}" 2>/dev/null)"
    [[ "$left" == "0" ]] || echo "WARN: ${left:-?} smoke rows still in posthog.events for project ${PROJECT_ID}" >&2
  elif [[ $had_project -eq 1 ]]; then
    echo "WARN: project id unknown, no ClickHouse rows deleted (event prefix ${MARK})" >&2
  fi
  rm -rf "$TMP"
  if [[ $rc -ne 0 && $FAILED -eq 0 && $REFUSED -eq 0 ]]; then
    echo "FAIL: script aborted unexpectedly (exit $rc)"
  fi
  exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

echo "smoke-capture: HOST=$HOST project=$NAME wait=${WAIT_SECONDS}s"

# Reference time from ClickHouse itself, so host/container clock skew cannot matter.
if ! T0="$(ch 'SELECT toUnixTimestamp(now())' 2>"$TMP/ch_err")" || [[ ! "$T0" =~ ^[0-9]+$ ]]; then
  fail "cannot query ClickHouse: $(head -c 200 "$TMP/ch_err" | tr '\n' ' ')"
  exit 1
fi

# Take only the line that is a well-formed key, so a stray warning cannot hide a project that was created.
CREATED="$(docker compose exec -T app php artisan project:create "$NAME" --key-only 2>"$TMP/create_err" | tr -d '\r' | grep -E '^phc_[A-Za-z0-9]{43}$' | head -n 1 || true)"
if [[ -n "$CREATED" ]]; then
  KEY="$CREATED"   # from here on cleanup deletes the project by this key
else
  fail "could not create throwaway project ($(head -c 200 "$TMP/create_err" | tr '\n' ' '))"
  exit 1
fi

# The tinker output must be exactly a number before it may reach SQL (a PHP warning on stdout would not be).
ID_RAW="$(docker compose exec -T app php artisan tinker --execute="echo App\\Models\\Project::where('api_key','${KEY}')->value('id');" 2>/dev/null | tr -d '\r\n' || true)"
if [[ "$ID_RAW" =~ ^[0-9]+$ ]]; then
  PROJECT_ID="$ID_RAW"
else
  fail "could not read throwaway project id (got '${ID_RAW:0:100}')"
  exit 1
fi

# Pre-flight: Postgres ids and ClickHouse rows must agree; a fresh project id must have no events yet.
if ! PRE="$(ch "SELECT count() FROM posthog.events WHERE project_id = $PROJECT_ID" 2>"$TMP/ch_err")" || [[ ! "$PRE" =~ ^[0-9]+$ ]]; then
  fail "cannot query ClickHouse: $(head -c 200 "$TMP/ch_err" | tr '\n' ' ')"
  exit 1
fi
if [[ "$PRE" != "0" ]]; then
  REFUSED=1
  PROJECT_ID=""   # these rows are not ours: cleanup must not touch them
  delete_project || true
  echo "REFUSED: project id collides with existing ClickHouse rows; Postgres and ClickHouse are out of sync" >&2
  exit 2
fi

# post <body-or-@file> -> sets HTTP_CODE and HTTP_BODY
post() {
  local code
  rm -f "$TMP/body"
  # curl may exit non-zero (55/56) after the server answered early; the printed status is still the answer.
  code="$(curl -sS --max-time 15 -o "$TMP/body" -w '%{http_code}' -X POST "$HOST/e/" \
    -H 'Content-Type: application/json' --data-binary "$1" 2>"$TMP/curl_err" || true)"
  HTTP_CODE="${code:-000}"
  HTTP_BODY="$(cat "$TMP/body" 2>/dev/null || true)"
}

# --- (a) valid event: 200 and {"status":1} ---------------------------------------------------
SENT_OK=0
post "{\"api_key\":\"$KEY\",\"event\":\"$MARK\",\"distinct_id\":\"smoke-user\",\"properties\":{\"\$current_url\":\"$SENT_URL\"}}"
if [[ "$HTTP_CODE" == "200" && "$HTTP_BODY" == '{"status":1}' ]]; then
  SENT_OK=1
  pass "valid event accepted (HTTP 200, body {\"status\":1})"
else
  fail "valid event: expected 200 {\"status\":1}, got HTTP $HTTP_CODE body '${HTTP_BODY:0:200}' $(cat "$TMP/curl_err" 2>/dev/null)"
fi

# --- (b) event reaches ClickHouse with the materialized current_url --------------------------
ARRIVED=0
WAITED=0
if [[ $SENT_OK -eq 1 ]]; then
  for i in $(seq 1 "$WAIT_SECONDS"); do
    WAITED=$i
    # One transient ClickHouse error must not abort the run: count it as "not there yet".
    COUNT="$(ch "SELECT count() FROM posthog.events WHERE event = '$MARK' AND project_id = $PROJECT_ID" 2>/dev/null || echo 0)"
    [[ "$COUNT" =~ ^[0-9]+$ ]] || COUNT=0
    if [[ "$COUNT" -ge 1 ]]; then ARRIVED=1; break; fi
    sleep 1
  done
  if [[ $ARRIVED -eq 1 ]]; then
    if chv "SELECT current_url FROM posthog.events WHERE event = '$MARK' AND project_id = $PROJECT_ID LIMIT 1"; then
      GOT_URL="$CH_OUT"
      if [[ "$GOT_URL" == "$SENT_URL" ]]; then
        pass "event in posthog.events after ${WAITED}s, current_url='$GOT_URL'"
      else
        fail "event in posthog.events but current_url='$GOT_URL', expected '$SENT_URL'"
      fi
    fi
  else
    fail "event did not reach posthog.events within ${WAIT_SECONDS}s"
  fi
else
  fail "event not checked in ClickHouse: it was never accepted"
fi

# --- (c) no pipeline errors from this run ----------------------------------------------------
# Fatal check: errors received since this script started that mention our unique event name.
if chv "SELECT count() FROM posthog.events_errors WHERE received_at >= toDateTime($T0) AND raw LIKE '%${MARK}%'"; then
  if [[ "$CH_OUT" == "0" ]]; then
    pass "events_errors has no rows for this run (${MARK})"
  else
    fail "events_errors has $CH_OUT rows for this run (${MARK})"
  fi
fi
# Informational only: anything else in the last 5 minutes (other tests, other instances) is a warning.
GLOBAL_ERRS="$(ch "SELECT count() FROM posthog.events_errors WHERE received_at > now() - INTERVAL 5 MINUTE" 2>/dev/null || echo '?')"
if [[ "$GLOBAL_ERRS" != "0" ]]; then
  echo "WARN: events_errors has ${GLOBAL_ERRS} rows from the last 5 minutes (not necessarily from this run)"
fi

# --- (d) Kafka consumer healthy --------------------------------------------------------------
# system.kafka_consumers keeps its last 10 exceptions in a ring buffer that survives until the
# consumer is recreated, so only exceptions newer than this script's start count. The commit
# must also be newer than the start (a commit happens after our event was flushed), so this is
# only meaningful when our event actually arrived.
CONSUMER_OK=0
CONSUMER_MSG="no row for posthog.kafka_events in system.kafka_consumers"
for i in $(seq 1 "$WAIT_SECONDS"); do
  [[ $ARRIVED -eq 1 ]] || { CONSUMER_MSG="our event never arrived, no commit to verify"; break; }
  ROW="$(ch "SELECT count(), sum(arrayCount(t -> toUnixTimestamp(t) >= $T0, \`exceptions.time\`)), max(toUnixTimestamp(last_commit_time)) FROM system.kafka_consumers WHERE database = 'posthog' AND \`table\` = 'kafka_events' FORMAT TSV" 2>/dev/null || true)"
  ROWS=""; EXC=""; COMMIT=""
  read -r ROWS EXC COMMIT <<<"$ROW" || true
  if [[ "$ROWS" =~ ^[0-9]+$ && "$EXC" =~ ^[0-9]+$ && "$COMMIT" =~ ^[0-9]+$ && "$ROWS" -ge 1 ]]; then
    CONSUMER_MSG="consumers=$ROWS new_exceptions=$EXC last_commit=$COMMIT start=$T0"
    if [[ "$EXC" -gt 0 ]]; then break; fi
    if [[ "$COMMIT" -ge "$T0" ]]; then CONSUMER_OK=1; break; fi
  fi
  sleep 1
done
if [[ $CONSUMER_OK -eq 1 ]]; then
  pass "kafka_events consumer healthy ($CONSUMER_MSG)"
else
  fail "kafka_events consumer unhealthy ($CONSUMER_MSG)"
fi

# --- negative checks (each wrong-key/invalid-JSON request counts toward the per-IP limiter: 2 hits) ----
WRONG_KEY="phc_$(printf 'x%.0s' $(seq 1 43))"
post "{\"api_key\":\"$WRONG_KEY\",\"event\":\"${MARK}_wrongkey\",\"distinct_id\":\"smoke-user\"}"
if [[ "$HTTP_CODE" == "401" ]]; then pass "wrong well-formed api key -> 401"; else fail "wrong api key: expected 401, got $HTTP_CODE"; fi

BIG_BYTES=$((MAX_BODY_BYTES + MAX_BODY_BYTES / 5))
{
  printf '{"api_key":"%s","event":"%s_big","distinct_id":"smoke-user","properties":{"pad":"' "$KEY" "$MARK"
  head -c "$BIG_BYTES" /dev/zero | tr '\0' 'a'
  printf '"}}'
} >"$TMP/big.json"
post "@$TMP/big.json"
if [[ "$HTTP_CODE" == "413" ]]; then pass "~${BIG_BYTES} byte body -> 413"; else fail "oversized body (~${BIG_BYTES} bytes): expected 413, got $HTTP_CODE"; fi

post '{"api_key": not json'
if [[ "$HTTP_CODE" == "400" ]]; then pass "invalid JSON -> 400"; else fail "invalid JSON: expected 400, got $HTTP_CODE"; fi

echo "----"
echo "smoke-capture: $PASSED passed, $FAILED failed"
[[ $FAILED -eq 0 ]]
