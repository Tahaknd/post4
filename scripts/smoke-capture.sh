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
Usage: scripts/smoke-capture.sh [--with-wedge-check] [--with-slow-post-check] [-h|--help]

End-to-end capture smoke test against the running docker compose stack.

Options:
  --with-wedge-check
                    also send one event for EVERY month from 2000-01 through the current month
                    (about 322 today), in /batch/ requests of 24 months each (the per-request cap),
                    back to back, and assert that ClickHouse ingests all of them without a
                    TOO_MANY_PARTS stall of the Kafka consumer (opt-in; the default run is unchanged
                    except for the always-on settings check). Timestamps are the first day of each
                    month at 12:00Z; the server clamp allows 2000-01-01 .. now+366d, we only use
                    months up to the current one
  --with-slow-post-check
                    open (Octane workers + 2) raw TCP connections to HOST, each announcing a POST /e/
                    with Content-Length 1000000 and then sending ONE byte per second, poll GET /up for
                    ~12 s meanwhile, and assert that the server closes every slow connection after 25
                    to 40 s (the Caddy read timeout is 30 s) with no response or a 400/408. It FAILS as
                    vacuous unless at least min(K, workers) worker threads were busy at ~2 s and ~8-14 s
                    (FrankenPHP admin API) and all K connections were still open when the /up poll
                    ended. The /up responsiveness while the connections are open is reported as an
                    informational INFO line, not asserted: the timeout bounds the starvation to ~30 s,
                    it does not remove it. Also asserts /up answers again once the connections are
                    gone. Each timed-out connection counts as one failure against the per-IP capture
                    limiter (K of 120 per window). Takes ~45 s, uses no project and sends no events
                    (opt-in; the default run is unchanged)

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

WITH_WEDGE=0
WITH_SLOW=0
for arg in "$@"; do
  case "$arg" in
    -h | --help) usage; exit 0 ;;
    --with-wedge-check) WITH_WEDGE=1 ;;
    --with-slow-post-check) WITH_SLOW=1 ;;
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
SLOW_PIDS=()

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
  local had_project=0 left pid
  # Slow-POST connection holders (background subshells): never leave one running.
  for pid in ${SLOW_PIDS[@]+"${SLOW_PIDS[@]}"}; do
    kill "$pid" 2>/dev/null
  done
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

# --- (0) static: the ingest wedge guard setting is active in the running server -----------------
# Cheap and always on; placed before anything that can abort the run, so it is always reached.
if chv "SELECT value FROM system.settings WHERE name = 'max_partitions_per_insert_block'"; then
  if [[ "$CH_OUT" =~ ^[0-9]+$ && "$CH_OUT" -ge 400 ]]; then
    pass "max_partitions_per_insert_block=$CH_OUT (ingest wedge guard active)"
  else
    fail "max_partitions_per_insert_block='${CH_OUT:0:40}', expected >= 400: ingest wedge guard missing or stale: docker compose up -d --force-recreate clickhouse, see docker/clickhouse/users.d/10-ingest-settings.xml"
  fi
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

# post <body-or-@file> -> sets HTTP_CODE and HTTP_BODY (POST_PATH selects the endpoint, default /e/)
POST_PATH="/e/"
post() {
  local code
  rm -f "$TMP/body"
  # curl may exit non-zero (55/56) after the server answered early; the printed status is still the answer.
  code="$(curl -sS --max-time 15 -o "$TMP/body" -w '%{http_code}' -X POST "$HOST$POST_PATH" \
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
# consumer is recreated, so only exceptions newer than the given start count. The commit
# must also be newer than the given start (a commit happens after our event was flushed), so this is
# only meaningful when our events actually arrived.
# consumer_health <arrived 0|1> <exceptions-since epoch> <commit-since epoch>
# -> sets CONSUMER_OK and CONSUMER_MSG
CONSUMER_OK=0
CONSUMER_MSG=""
consumer_health() {
  local arrived="$1" exc_since="$2" commit_since="$3" i row rows exc commit
  CONSUMER_OK=0
  CONSUMER_MSG="no row for posthog.kafka_events in system.kafka_consumers"
  for i in $(seq 1 "$WAIT_SECONDS"); do
    [[ "$arrived" -eq 1 ]] || { CONSUMER_MSG="our event never arrived, no commit to verify"; break; }
    row="$(ch "SELECT count(), sum(arrayCount(t -> toUnixTimestamp(t) >= $exc_since, \`exceptions.time\`)), max(toUnixTimestamp(last_commit_time)) FROM system.kafka_consumers WHERE database = 'posthog' AND \`table\` = 'kafka_events' FORMAT TSV" 2>/dev/null || true)"
    rows=""; exc=""; commit=""
    read -r rows exc commit <<<"$row" || true
    if [[ "$rows" =~ ^[0-9]+$ && "$exc" =~ ^[0-9]+$ && "$commit" =~ ^[0-9]+$ && "$rows" -ge 1 ]]; then
      CONSUMER_MSG="consumers=$rows new_exceptions=$exc last_commit=$commit start=$commit_since"
      if [[ "$exc" -gt 0 ]]; then break; fi
      if [[ "$commit" -ge "$commit_since" ]]; then CONSUMER_OK=1; break; fi
    fi
    sleep 1
  done
}
consumer_health "$ARRIVED" "$T0" "$T0"
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

# --- (e) opt-in: wide-timestamp batches must not stall ingest ---------------------------------
# `events` is partitioned by month; one Kafka block touching more than max_partitions_per_insert_block
# months used to fail with TOO_MANY_PARTS and wedge the consumer. The controller caps a request at
# capture.max_months_per_request (24) distinct months, so we send EVERY month from 2000-01 through the
# current month (the clamp allows up to now+366d, we stay at the current month), 24 per request, back to
# back. With ~322 distinct months even a burst split by a poll boundary leaves a block far above 100
# months, so a limit of 100 fails this check deterministically.
if [[ $WITH_WEDGE -eq 1 ]]; then
  WEDGE_NAME="${MARK}_wedge"
  WEDGE_CHUNK=24
  FAILED_BEFORE_WEDGE=$FAILED
  if ! T1="$(ch 'SELECT toUnixTimestamp(now())' 2>/dev/null)" || [[ ! "$T1" =~ ^[0-9]+$ ]]; then
    T1="$(date +%s)"
  fi
  # Months 2000-01 .. current month (UTC) with bash arithmetic only (10# avoids octal parsing of 08/09).
  NOW_Y="$(date -u +%Y)"
  NOW_M="$(date -u +%m)"
  WEDGE_EVENTS=$(( (10#$NOW_Y - 2000) * 12 + 10#$NOW_M ))
  WEDGE_REQS=$(( (WEDGE_EVENTS + WEDGE_CHUNK - 1) / WEDGE_CHUNK ))
  w_y=2000
  w_m=1
  w_idx=0
  w_req=0
  w_in_chunk=0
  while [[ $w_idx -lt $WEDGE_EVENTS ]]; do
    if [[ $w_in_chunk -eq 0 ]]; then
      printf '{"api_key":"%s","batch":[' "$KEY" >"$TMP/wedge_$w_req.json"
    else
      printf ',' >>"$TMP/wedge_$w_req.json"
    fi
    printf '{"event":"%s","distinct_id":"smoke-wedge","timestamp":"%04d-%02d-01T12:00:00Z"}' \
      "$WEDGE_NAME" "$w_y" "$w_m" >>"$TMP/wedge_$w_req.json"
    w_in_chunk=$((w_in_chunk + 1))
    w_idx=$((w_idx + 1))
    if [[ $w_in_chunk -eq $WEDGE_CHUNK || $w_idx -eq $WEDGE_EVENTS ]]; then
      printf ']}' >>"$TMP/wedge_$w_req.json"
      w_req=$((w_req + 1))
      w_in_chunk=0
    fi
    w_m=$((w_m + 1))
    if [[ $w_m -gt 12 ]]; then w_m=1; w_y=$((w_y + 1)); fi
  done

  WEDGE_SENT=0
  POST_PATH="/batch/"
  for ((r = 0; r < WEDGE_REQS; r++)); do
    post "@$TMP/wedge_$r.json"
    if [[ "$HTTP_CODE" == "200" ]]; then
      WEDGE_SENT=$((WEDGE_SENT + 1))
    else
      fail "wedge request $((r + 1))/${WEDGE_REQS}: expected 200, got HTTP $HTTP_CODE body '${HTTP_BODY:0:200}'"
    fi
  done
  POST_PATH="/e/"
  if [[ $WEDGE_SENT -eq $WEDGE_REQS ]]; then
    pass "${WEDGE_REQS} wide-timestamp batch requests accepted (HTTP 200, ${WEDGE_EVENTS} months 2000-01..${NOW_Y}-${NOW_M})"
  fi

  WEDGE_ARRIVED=0
  WEDGE_COUNT=0
  WEDGE_MONTHS=0
  if [[ $WEDGE_SENT -eq $WEDGE_REQS ]]; then
    for i in $(seq 1 "$WAIT_SECONDS"); do
      ROW="$(ch "SELECT count(), uniqExact(toYYYYMM(timestamp)) FROM posthog.events WHERE event = '$WEDGE_NAME' AND project_id = $PROJECT_ID FORMAT TSV" 2>/dev/null || true)"
      WEDGE_COUNT=""; WEDGE_MONTHS=""
      read -r WEDGE_COUNT WEDGE_MONTHS <<<"$ROW" || true
      [[ "$WEDGE_COUNT" =~ ^[0-9]+$ ]] || WEDGE_COUNT=0
      [[ "$WEDGE_MONTHS" =~ ^[0-9]+$ ]] || WEDGE_MONTHS=0
      if [[ "$WEDGE_COUNT" -ge "$WEDGE_EVENTS" ]]; then WEDGE_ARRIVED=1; break; fi
      sleep 1
    done
    if [[ $WEDGE_ARRIVED -eq 1 && "$WEDGE_COUNT" -eq "$WEDGE_EVENTS" ]]; then
      pass "all ${WEDGE_EVENTS} wedge events in posthog.events"
    else
      fail "wedge events in posthog.events: ${WEDGE_COUNT}/${WEDGE_EVENTS} after ${WAIT_SECONDS}s"
    fi
    if [[ "$WEDGE_MONTHS" -eq "$WEDGE_EVENTS" ]]; then
      pass "wedge events span ${WEDGE_MONTHS} distinct months"
    else
      fail "wedge events span ${WEDGE_MONTHS} distinct months, expected ${WEDGE_EVENTS}"
    fi
  else
    fail "wedge events not checked in ClickHouse: not all requests were accepted"
  fi

  consumer_health "$WEDGE_ARRIVED" "$T0" "$T1"
  if [[ $CONSUMER_OK -eq 1 ]]; then
    pass "kafka_events consumer healthy after wedge batches ($CONSUMER_MSG)"
  else
    fail "kafka_events consumer unhealthy after wedge batches ($CONSUMER_MSG)"
  fi
  if chv "SELECT count() FROM posthog.events_errors WHERE received_at >= toDateTime($T0) AND raw LIKE '%${MARK}%'"; then
    if [[ "$CH_OUT" == "0" ]]; then
      pass "events_errors still has no rows for this run after wedge batches"
    else
      fail "events_errors has $CH_OUT rows for this run after wedge batches"
    fi
  fi
  if [[ $FAILED -gt $FAILED_BEFORE_WEDGE ]]; then
    echo "HINT: ingest may be wedged. Check the exceptions in system.kafka_consumers (WHERE database='posthog' AND table='kafka_events');"
    echo "      see the header of docker/clickhouse/users.d/10-ingest-settings.xml for how to recognize it and the reset steps."
  fi
fi

# --- (f) opt-in: slow POST bodies must be closed by the Caddy read timeout --------------------------
# K = Octane workers + 2 connections each announce a 1 MB body (Content-Length 1000000, the largest the
# request_body cap allows) and then send one byte per second. Every such request holds an Octane worker
# thread while PHP waits for the body. Caddy's read_body timeout (30 s, config/octane.php) must close them.
# The check must not pass vacuously, so it asserts that the holders really were holding:
#   - at t~2 s and again at the first /up poll after t=8 s, at least min(K, workers) FrankenPHP worker
#     threads are busy (admin API);
#   - all K holders are still open when the /up poll ends (~t=14 s);
#   - each holder lives 25..40 s (the 30 s read timeout is anchored at accept, so even a holder queued
#     for a worker is closed at ~30-31 s): shorter was not the timeout, longer means no timeout;
#   - the first bytes the server sends before closing are either nothing or a 400/408 status line.
# What /up does meanwhile is only reported: with K >= workers the pool can be fully tied up until the
# timeout fires.
# NOTE: a 400 at ~30 s (PHP saw a truncated body) counts as one failure per connection against the per-IP
# capture limiter (capture.auth_fail_limit, 120 per window): one run spends K of them from this host.

# worker_threads: prints "<busy> <total>" for the FrankenPHP worker threads (admin API on :2019 inside the
# app container). The pretty-printed JSON is flattened (`tr -d ' \n'`), so the thread name reads
# "WorkerPHPThread-..." and the field order Name, State, IsWaiting, IsBusy is matched literally.
worker_threads() {
  local flat busy total
  flat="$(docker compose exec -T app sh -c 'curl -s localhost:2019/frankenphp/threads' 2>/dev/null | tr -d ' \n\r' || true)"
  total="$(printf '%s' "$flat" | { grep -o '"Name":"WorkerPHPThread[^"]*"' || true; } | wc -l | tr -d ' ')"
  busy="$(printf '%s' "$flat" | { grep -o '"Name":"WorkerPHPThread[^"]*","State":"[^"]*","IsWaiting":[a-z]*,"IsBusy":true' || true; } | wc -l | tr -d ' ')"
  echo "${busy:-0} ${total:-0}"
}

slow_holder() {
  # slow_holder <index> <host> <port>: writes the first response line the server sends (if any) to
  # $TMP/slow_resp_<index>, then "<seconds> <how>" to $TMP/slow_<index> when the server hangs up
  # (that second file is the "holder finished" marker, so it is written last).
  local idx="$1" h="$2" p="$3" start how rc ch rest
  trap '' PIPE
  if ! exec 3<>"/dev/tcp/$h/$p"; then
    echo "-1 connect_failed" >"$TMP/slow_$idx"
    return 1
  fi
  start=$SECONDS
  printf 'POST /e/ HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\nContent-Length: 1000000\r\nConnection: close\r\n\r\n' >&3 \
    || { echo "-1 send_failed" >"$TMP/slow_$idx"; return 1; }
  while [[ $((SECONDS - start)) -lt 70 ]]; do
    # Read first (it doubles as the one-second pacer), so a response that arrived is consumed before the
    # next write can provoke an RST. bash 3.2 returns 1 both on timeout and on EOF, so only "0 = the
    # server sent bytes (its answer)" is told apart here; EOF shows up as a failing write.
    rc=0
    # shellcheck disable=SC2034
    read -r -t 1 -n 1 -u 3 ch || rc=$?
    if [[ $rc -eq 0 ]]; then
      rest=""
      read -r -t 1 -u 3 rest || true
      printf '%s%s' "$ch" "$rest" | tr -d '\r' >"$TMP/slow_resp_$idx"
      how="server_response"
      break
    fi
    # A write fails (EPIPE) once the server has hung up; the first write after its FIN only provokes an RST.
    printf 'a' >&3 2>/dev/null || { how="hung_up"; break; }
  done
  echo "$((SECONDS - start)) ${how:-gave_up_70s}" >"$TMP/slow_$idx"
}

SLOW_NEED=0
SLOW_BUSY_OK=1
SLOW_BUSY_REPORT=""
# slow_check_busy <label>: asserts >= SLOW_NEED busy worker threads right now.
slow_check_busy() {
  local b t
  read -r b t <<<"$(worker_threads)"
  SLOW_BUSY_REPORT="${SLOW_BUSY_REPORT}${1}: ${b}/${t} workers busy; "
  [[ "$b" =~ ^[0-9]+$ && "$b" -ge "$SLOW_NEED" ]] || SLOW_BUSY_OK=0
}

if [[ $WITH_SLOW -eq 1 ]]; then
  FAILED_BEFORE_SLOW=$FAILED
  SLOW_HOST="${HOST#http://}"
  SLOW_PORT=80
  if [[ "$SLOW_HOST" == *:* ]]; then SLOW_PORT="${SLOW_HOST##*:}"; fi
  SLOW_HOST="${SLOW_HOST%%:*}"
  [[ "$SLOW_HOST" == "localhost" ]] && SLOW_HOST="127.0.0.1"   # the published port is bound to IPv4 loopback
  SLOW_K=0
  read -r _ SLOW_WORKERS <<<"$(worker_threads)"
  [[ "$SLOW_WORKERS" =~ ^[0-9]+$ && "$SLOW_WORKERS" -ge 1 ]] || SLOW_WORKERS=4
  SLOW_K=$((SLOW_WORKERS + 2))
  SLOW_NEED=$(( SLOW_K < SLOW_WORKERS ? SLOW_K : SLOW_WORKERS ))

  UP_BEFORE="$(curl -s -o /dev/null --max-time 5 -w '%{http_code}' "$HOST/up" 2>/dev/null || true)"
  if [[ "$UP_BEFORE" != "200" ]]; then
    fail "slow-POST check needs a healthy /up first, got HTTP ${UP_BEFORE:-000}"
  else
    echo "slow-POST check: ${SLOW_K} connections (${SLOW_WORKERS} Octane workers + 2) to ${SLOW_HOST}:${SLOW_PORT}, 1 byte/s, announced body 1000000 bytes"
    echo "INFO: a 400 at ~30 s counts as one failure per connection against the per-IP capture limiter (${SLOW_K} of the 120 per window for this run)"
    slow_start=$SECONDS
    for ((c = 0; c < SLOW_K; c++)); do
      slow_holder "$c" "$SLOW_HOST" "$SLOW_PORT" &
      SLOW_PIDS+=("$!")
      disown "$!" 2>/dev/null || true
    done
    sleep 2   # let the connections establish and take their workers
    slow_check_busy "t~$((SECONDS - slow_start))s"

    up_n=0; up_fail=0; up_max_ms=0; up_slow=0
    busy10_done=0
    poll_start=$SECONDS
    while [[ $((SECONDS - poll_start)) -lt 12 ]]; do
      if [[ $busy10_done -eq 0 && $((SECONDS - slow_start)) -ge 8 ]]; then
        slow_check_busy "t~$((SECONDS - slow_start))s"
        busy10_done=1
      fi
      up_out="$(curl -s -o /dev/null --max-time 5 -w '%{http_code} %{time_total}' "$HOST/up" 2>/dev/null || true)"
      up_code="${up_out%% *}"
      up_time="${up_out#* }"
      up_n=$((up_n + 1))
      if [[ "$up_code" != "200" ]]; then up_fail=$((up_fail + 1)); fi
      if [[ "$up_time" =~ ^[0-9]+\.[0-9]+$ ]]; then
        up_ms=$((10#${up_time%.*} * 1000 + 10#${up_time#*.} / 1000))
        [[ $up_ms -gt $up_max_ms ]] && up_max_ms=$up_ms
        [[ $up_ms -gt 1000 ]] && up_slow=$((up_slow + 1))
      fi
      sleep 1
    done
    if [[ $busy10_done -eq 0 ]]; then slow_check_busy "t~$((SECONDS - slow_start))s"; fi

    # Vacuousness guards: the holders must have occupied the workers, and still be open now.
    echo "INFO: busy FrankenPHP worker threads: ${SLOW_BUSY_REPORT%; } (need >= ${SLOW_NEED} each time)"
    if [[ $SLOW_BUSY_OK -eq 1 ]]; then
      pass "slow connections occupied the Octane workers (>= ${SLOW_NEED} of ${SLOW_WORKERS} busy at every sample)"
    else
      fail "holders did not occupy workers: check is vacuous (${SLOW_BUSY_REPORT%; })"
    fi
    open_early=0
    for ((c = 0; c < SLOW_K; c++)); do [[ -e "$TMP/slow_$c" ]] && open_early=$((open_early + 1)); done
    if [[ $open_early -eq 0 ]]; then
      if [[ $up_fail -eq 0 && $up_max_ms -le 1000 ]]; then
        echo "INFO: /up stayed responsive while ${SLOW_K} slow POSTs were open: ${up_n} polls, max ${up_max_ms} ms, 0 failures"
      else
        echo "INFO: /up WAS AFFECTED while ${SLOW_K} slow POSTs were open (${SLOW_WORKERS} workers): ${up_n} polls, ${up_fail} failed/timed out (5 s limit), ${up_slow} slower than 1 s, max ${up_max_ms} ms. The read timeout bounds this to ~30 s per connection, it does not remove it"
      fi
    else
      fail "holders were not open during the /up poll: ${open_early}/${SLOW_K} had already closed after ~$((SECONDS - slow_start)) s, check is vacuous"
    fi

    # Wait for every holder to finish (a holder gives up after 70 s, so 78 s since the start covers all).
    closed=0
    while [[ $((SECONDS - slow_start)) -lt 78 ]]; do
      closed=0
      for ((c = 0; c < SLOW_K; c++)); do [[ -s "$TMP/slow_$c" ]] && closed=$((closed + 1)); done
      [[ $closed -eq SLOW_K ]] && break
      sleep 1
    done
    max_closed=0; early_n=0; late_n=0; resp_bad=0; closed_list=""; resp_list=""
    for ((c = 0; c < SLOW_K; c++)); do
      secs=-1; how="no_result"
      read -r secs how <"$TMP/slow_$c" 2>/dev/null || { secs=-1; how="no_result"; }
      resp=""
      [[ -s "$TMP/slow_resp_$c" ]] && resp="$(head -n 1 "$TMP/slow_resp_$c" 2>/dev/null | head -c 80 || true)"
      closed_list="${closed_list}${secs}s(${how}) "
      if [[ "$secs" =~ ^[0-9]+$ && "$how" != "gave_up_70s" ]]; then
        [[ $secs -gt $max_closed ]] && max_closed=$secs
        if [[ $secs -lt 25 ]]; then early_n=$((early_n + 1)); fi
        if [[ $secs -gt 40 ]]; then late_n=$((late_n + 1)); fi
      else
        late_n=$((late_n + 1))
      fi
      if [[ -z "$resp" ]]; then
        resp_list="${resp_list}none "
      else
        status="${resp#HTTP/* }"; status="${status%% *}"
        resp_list="${resp_list}${status:-?} "
        [[ "$status" == "400" || "$status" == "408" ]] || resp_bad=$((resp_bad + 1))
      fi
    done
    echo "INFO: connection lifetimes until the server hung up: ${closed_list}"
    echo "INFO: first response from the server before it hung up (none = closed without a status): ${resp_list}"
    if [[ $early_n -gt 0 ]]; then
      fail "${early_n}/${SLOW_K} slow POST connections closed early (< 25 s), not by the read timeout: ${closed_list}"
    fi
    if [[ $late_n -gt 0 ]]; then
      fail "${late_n}/${SLOW_K} slow POST connections not closed within 40 s (${closed}/${SLOW_K} finished): no read timeout is active (check CADDY_GLOBAL_OPTIONS in config/octane.php, docker compose restart app): ${closed_list}"
    fi
    if [[ $resp_bad -gt 0 ]]; then
      fail "unexpected response while holding the connection (${resp_bad}/${SLOW_K}, only none/400/408 allowed): ${resp_list}"
    fi
    if [[ $early_n -eq 0 && $late_n -eq 0 && $resp_bad -eq 0 ]]; then
      pass "all ${SLOW_K} slow POST connections were closed by the read timeout (lifetimes 25-40 s, slowest ${max_closed}s, responses: ${resp_list% })"
    fi

    # The workers must be free again.
    UP_AFTER="000"
    for _ in 1 2 3 4 5; do
      UP_AFTER="$(curl -s -o /dev/null --max-time 5 -w '%{http_code}' "$HOST/up" 2>/dev/null || true)"
      [[ "$UP_AFTER" == "200" ]] && break
      sleep 1
    done
    if [[ "$UP_AFTER" == "200" ]]; then
      pass "/up answers again after the slow connections were closed"
    else
      fail "/up still answers HTTP ${UP_AFTER:-000} after the slow connections were closed"
    fi
  fi
  if [[ $FAILED -gt $FAILED_BEFORE_SLOW ]]; then
    echo "HINT: see config/octane.php (caddy.env CADDY_GLOBAL_OPTIONS) and: docker compose exec -T app sh -c 'curl -s localhost:2019/config/apps/http/servers/'"
  fi
fi


echo "----"
echo "smoke-capture: $PASSED passed, $FAILED failed"
[[ $FAILED -eq 0 ]]
