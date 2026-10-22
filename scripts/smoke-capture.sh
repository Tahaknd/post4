#!/usr/bin/env bash
# End-to-end capture smoke test: HTTP -> Kafka -> ClickHouse, against the running compose stack.
#
# Safe by construction: only talks to a local HOST, uses a throwaway project and `smoke_`-prefixed
# events, and on exit (success, failure or interrupt) removes exactly what it created.
set -euo pipefail

cd "$(dirname "$0")/.."

HOST="${HOST:-http://localhost:8000}"
CH_PASSWORD="${CLICKHOUSE_PASSWORD:-posthog}"

if [[ ! "$HOST" =~ ^http://(localhost|127\.0\.0\.1)(:[0-9]+)?$ ]]; then
  echo "REFUSED: HOST must be http://localhost[:port] or http://127.0.0.1[:port], got '$HOST'" >&2
  exit 2
fi

EPOCH="$(date +%s)"
NAME="smoke_${EPOCH}"
MARK="smoke_${EPOCH}"
SENT_URL='http://example.test/'
KEY=""
PROJECT_ID=""
TMP="$(mktemp -d)"
PASSED=0
FAILED=0

ch() {
  docker compose exec -T clickhouse clickhouse-client --user posthog --password "$CH_PASSWORD" --query "$1"
}

pass() { PASSED=$((PASSED + 1)); echo "PASS: $1"; }
fail() { FAILED=$((FAILED + 1)); echo "FAIL: $1" >&2; }

cleanup() {
  local rc=$?
  set +e
  trap - EXIT
  # Delete only this run's own rows (event prefix + our throwaway project), never TRUNCATE.
  if [[ -n "$PROJECT_ID" ]]; then
    ch "ALTER TABLE posthog.events DELETE WHERE event LIKE 'smoke\\\\_%' AND project_id = ${PROJECT_ID} SETTINGS mutations_sync = 2" >/dev/null 2>&1 \
      || echo "WARN: could not delete smoke rows from posthog.events" >&2
  fi
  # Always remove the throwaway project (and its cached key lookup).
  docker compose exec -T app php artisan tinker --execute="\$k = App\\Models\\Project::where('name','${NAME}')->value('api_key'); if (\$k) { app(App\\Domain\\Capture\\ProjectResolver::class)->forget(\$k); } App\\Models\\Project::where('name','${NAME}')->delete();" >/dev/null 2>&1 \
    || echo "WARN: could not delete project ${NAME}" >&2
  rm -rf "$TMP"
  if [[ $rc -ne 0 && $FAILED -eq 0 ]]; then
    echo "FAIL: script aborted unexpectedly (exit $rc)" >&2
  fi
  exit "$rc"
}
trap cleanup EXIT

echo "smoke-capture: HOST=$HOST project=$NAME"

# Reference time from ClickHouse itself, so host/container clock skew cannot matter.
T0="$(ch 'SELECT toUnixTimestamp(now())')"

KEY="$(docker compose exec -T app php artisan project:create "$NAME" --key-only | tr -d '\r\n')"
PROJECT_ID="$(docker compose exec -T app php artisan tinker --execute="echo App\\Models\\Project::where('name','${NAME}')->value('id');" | tr -d '\r\n')"
[[ "$KEY" =~ ^phc_[A-Za-z0-9]{43}$ ]] || { echo "FAIL: could not create throwaway project" >&2; exit 1; }
[[ "$PROJECT_ID" =~ ^[0-9]+$ ]] || { echo "FAIL: could not read throwaway project id" >&2; exit 1; }

# post <body-or-@file> -> sets HTTP_CODE and HTTP_BODY
post() {
  HTTP_CODE="$(curl -sS --max-time 15 -o "$TMP/body" -w '%{http_code}' -X POST "$HOST/e/" \
    -H 'Content-Type: application/json' --data-binary "$1" 2>"$TMP/curl_err")" || HTTP_CODE="000"
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
if [[ $SENT_OK -eq 1 ]]; then
  for i in $(seq 1 30); do
    COUNT="$(ch "SELECT count() FROM posthog.events WHERE event = '$MARK' AND project_id = $PROJECT_ID")"
    if [[ "$COUNT" -ge 1 ]]; then ARRIVED=1; break; fi
    sleep 1
  done
  if [[ $ARRIVED -eq 1 ]]; then
    GOT_URL="$(ch "SELECT current_url FROM posthog.events WHERE event = '$MARK' AND project_id = $PROJECT_ID LIMIT 1")"
    if [[ "$GOT_URL" == "$SENT_URL" ]]; then
      pass "event in posthog.events after ${i}s, current_url='$GOT_URL'"
    else
      fail "event in posthog.events but current_url='$GOT_URL', expected '$SENT_URL'"
    fi
  else
    fail "event did not reach posthog.events within 30s"
  fi
else
  fail "event not checked in ClickHouse: it was never accepted"
fi

# --- (c) no recent pipeline errors -----------------------------------------------------------
ERRS="$(ch "SELECT count() FROM posthog.events_errors WHERE received_at > now() - INTERVAL 5 MINUTE")"
if [[ "$ERRS" == "0" ]]; then
  pass "events_errors has no rows from the last 5 minutes"
else
  fail "events_errors has $ERRS rows from the last 5 minutes"
fi

# --- (d) Kafka consumer healthy --------------------------------------------------------------
# system.kafka_consumers keeps its last 10 exceptions in a ring buffer that survives until the
# consumer is recreated, so only exceptions newer than this script's start count. The commit
# must also be newer than the start (a commit happens after our event was flushed), so this is
# only meaningful when our event actually arrived.
CONSUMER_OK=0
CONSUMER_MSG="no row for posthog.kafka_events in system.kafka_consumers"
for i in $(seq 1 20); do
  [[ $ARRIVED -eq 1 ]] || { CONSUMER_MSG="our event never arrived, no commit to verify"; break; }
  ROW="$(ch "SELECT count(), sum(arrayCount(t -> toUnixTimestamp(t) >= $T0, \`exceptions.time\`)), max(toUnixTimestamp(last_commit_time)) FROM system.kafka_consumers WHERE database = 'posthog' AND \`table\` = 'kafka_events' FORMAT TSV")"
  read -r ROWS EXC COMMIT <<<"$ROW"
  if [[ "$ROWS" -ge 1 ]]; then
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

{
  printf '{"api_key":"%s","event":"%s_big","distinct_id":"smoke-user","properties":{"pad":"' "$KEY" "$MARK"
  head -c 1200000 /dev/zero | tr '\0' 'a'
  printf '"}}'
} >"$TMP/big.json"
post "@$TMP/big.json"
if [[ "$HTTP_CODE" == "413" ]]; then pass "~1.2 MB body -> 413"; else fail "oversized body: expected 413, got $HTTP_CODE"; fi

post '{"api_key": not json'
if [[ "$HTTP_CODE" == "400" ]]; then pass "invalid JSON -> 400"; else fail "invalid JSON: expected 400, got $HTTP_CODE"; fi

echo "----"
echo "smoke-capture: $PASSED passed, $FAILED failed"
[[ $FAILED -eq 0 ]]
