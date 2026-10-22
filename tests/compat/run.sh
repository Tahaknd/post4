#!/usr/bin/env bash
# posthog-node wire-compatibility test against the running compose stack.
#
# Usage: tests/compat/run.sh [--with-kafka-outage]
#
# --with-kafka-outage additionally stops the kafka service, checks the 503 behaviour and the
# recovery after `start` (OFF by default: it interrupts the pipeline for about a minute).
set -euo pipefail

cd "$(dirname "$0")/../.."
DIR="$PWD/tests/compat"

WITH_OUTAGE=0
for arg in "$@"; do
  case "$arg" in
    --with-kafka-outage) WITH_OUTAGE=1 ;;
    *) echo "unknown argument: $arg" >&2; exit 2 ;;
  esac
done

HOST="${HOST:-http://localhost:8000}"
CH_PASSWORD="${CLICKHOUSE_PASSWORD:-posthog}"
# Timestamps are corrected by the server with sent_at (clock skew + latency), so allow some drift.
TS_TOLERANCE_MS="${TS_TOLERANCE_MS:-1000}"

if [[ ! "$HOST" =~ ^http://(localhost|127\.0\.0\.1)(:[0-9]+)?$ ]]; then
  echo "REFUSED: HOST must be http://localhost[:port] or http://127.0.0.1[:port], got '$HOST'" >&2
  exit 2
fi

EPOCH="$(date +%s)"
NAME="compat_${EPOCH}"
PREFIX="compat_${EPOCH}_"
NODE_URL="http://app:8000"   # what the node container uses: HOST's localhost is the container itself there
KEY=""
PROJECT_ID=""
KAFKA_STOPPED=0
TMP="$(mktemp -d)"
PASSED=0
FAILED=0

ch() {
  docker compose exec -T clickhouse clickhouse-client --user posthog --password "$CH_PASSWORD" --query "$1"
}
pass() { PASSED=$((PASSED + 1)); echo "PASS: $1"; }
fail() { FAILED=$((FAILED + 1)); echo "FAIL: $1" >&2; }

kafka_health() {
  local id
  id="$(docker compose ps -q kafka 2>/dev/null || true)"
  [[ -n "$id" ]] || { echo "missing"; return; }
  docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$id" 2>/dev/null || echo "unknown"
}

wait_kafka_healthy() {
  local deadline=$((SECONDS + ${1:-60}))
  while (( SECONDS < deadline )); do
    [[ "$(kafka_health)" == "healthy" ]] && return 0
    sleep 1
  done
  return 1
}

cleanup() {
  local rc=$?
  set +e
  trap - EXIT INT TERM
  # Never leave kafka stopped, whatever happened.
  if [[ $KAFKA_STOPPED -eq 1 ]]; then
    echo "cleanup: restarting kafka" >&2
    docker compose start kafka >/dev/null 2>&1
    wait_kafka_healthy 90 || echo "WARN: kafka not healthy after restart" >&2
  fi
  # Only rows of our own throwaway project (this includes the SDK's fixed-name $identify / $create_alias).
  if [[ -n "$PROJECT_ID" ]]; then
    ch "ALTER TABLE posthog.events DELETE WHERE project_id = ${PROJECT_ID} SETTINGS mutations_sync = 2" >/dev/null 2>&1 \
      || echo "WARN: could not delete compat rows from posthog.events" >&2
  fi
  docker compose exec -T app php artisan tinker --execute="\$k = App\\Models\\Project::where('name','${NAME}')->value('api_key'); if (\$k) { app(App\\Domain\\Capture\\ProjectResolver::class)->forget(\$k); } App\\Models\\Project::where('name','${NAME}')->delete();" >/dev/null 2>&1 \
    || echo "WARN: could not delete project ${NAME}" >&2
  rm -rf "$TMP"
  if [[ $rc -ne 0 && $FAILED -eq 0 ]]; then
    echo "FAIL: script aborted unexpectedly (exit $rc)" >&2
  fi
  exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

echo "compat: HOST=$HOST node-url=$NODE_URL project=$NAME kafka-outage=$WITH_OUTAGE"

NETWORK="$(docker inspect "$(docker compose ps -q app)" --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}' | awk '{print $1}')"
[[ -n "$NETWORK" ]] || { echo "FAIL: cannot find the compose network of the app service" >&2; exit 1; }

T0="$(ch 'SELECT toUnixTimestamp(now())')"

KEY="$(docker compose exec -T app php artisan project:create "$NAME" --key-only | tr -d '\r\n')"
PROJECT_ID="$(docker compose exec -T app php artisan tinker --execute="echo App\\Models\\Project::where('name','${NAME}')->value('id');" | tr -d '\r\n')"
[[ "$KEY" =~ ^phc_[A-Za-z0-9]{43}$ ]] || { echo "FAIL: could not create throwaway project" >&2; exit 1; }
[[ "$PROJECT_ID" =~ ^[0-9]+$ ]] || { echo "FAIL: could not read throwaway project id" >&2; exit 1; }

# --- run the real SDK in a throwaway container on the compose network -----------------------------
NODE_OK=1
docker run --rm --network "$NETWORK" --user "$(id -u):$(id -g)" \
  -e HOME=/tmp -e npm_config_cache=/tmp/.npm \
  -v "$DIR":/w -w /w \
  -e PH_API_KEY="$KEY" -e PH_HOST="$NODE_URL" -e PH_PREFIX="$PREFIX" \
  node:22-alpine sh -c "npm install --no-audit --no-fund --loglevel=error posthog-node >&2 && echo \"posthog-node \$(node -p \"JSON.parse(require('fs').readFileSync('node_modules/posthog-node/package.json','utf8')).version\")\" >&2 && node smoke-node.mjs" \
  >"$TMP/manifest.tsv" || NODE_OK=0

if [[ $NODE_OK -eq 1 ]]; then pass "posthog-node script ran and the SDK reported no errors"; else fail "posthog-node script failed"; fi

# --- what we expect: every call the script makes, by exact name -----------------------------------
{
  echo "${PREFIX}capture"
  echo "${PREFIX}custom_timestamp_uuid"
  for i in $(seq 1 12); do echo "${PREFIX}batch_${i}"; done
  echo '$identify'
  echo '$create_alias'
} | LC_ALL=C sort >"$TMP/expected_names"
EXPECTED="$(wc -l <"$TMP/expected_names" | tr -d ' ')"

SENT="$(grep -c '^EVENT	' "$TMP/manifest.tsv" || true)"
if [[ "$SENT" == "$EXPECTED" ]]; then pass "SDK flushed $SENT events (expected $EXPECTED)"; else fail "SDK flushed $SENT events, expected $EXPECTED"; fi

# --- (1) all events arrive, exactly the expected ones ---------------------------------------------
ARRIVED=0
for i in $(seq 1 30); do
  COUNT="$(ch "SELECT count() FROM posthog.events WHERE project_id = $PROJECT_ID")"
  if [[ "$COUNT" -ge "$EXPECTED" ]]; then ARRIVED=1; break; fi
  sleep 1
done
sleep 1   # let a surplus event show up, if there is one
ch "SELECT event FROM posthog.events WHERE project_id = $PROJECT_ID ORDER BY event FORMAT TSVRaw" | LC_ALL=C sort >"$TMP/got_names"
if diff -q "$TMP/expected_names" "$TMP/got_names" >/dev/null; then
  pass "ClickHouse has exactly the $EXPECTED expected events by name (after ${i}s)"
else
  fail "ClickHouse events differ from expected: $(diff "$TMP/expected_names" "$TMP/got_names" | tr '\n' ' ')"
fi

# --- (2) uuid and timestamp preserved ------------------------------------------------------------
ch "SELECT toString(uuid), toUnixTimestamp64Milli(timestamp), event FROM posthog.events WHERE project_id = $PROJECT_ID FORMAT TSV" >"$TMP/ch.tsv"
RESULT="$(awk -F'\t' -v tol="$TS_TOLERANCE_MS" '
  NR == FNR { ts[$1] = $2; ev[$1] = $3; next }
  $1 == "EVENT" {
    n++
    if (!($3 in ts)) { missing++; next }
    if (ev[$3] != $2) wrongname++
    d = ts[$3] - $5; if (d < 0) d = -d
    if (d > max) max = d
    if (d > tol) badts++
  }
  END { printf "%d %d %d %d %d", n, missing + 0, wrongname + 0, badts + 0, max + 0 }
' "$TMP/ch.tsv" "$TMP/manifest.tsv")"
read -r N MISSING WRONGNAME BADTS MAXDELTA <<<"$RESULT"
if [[ "$N" -gt 0 && "$MISSING" -eq 0 && "$WRONGNAME" -eq 0 ]]; then
  pass "all $N SDK-generated uuids are stored unchanged (same uuid -> same event)"
else
  fail "uuid preservation: $N sent, $MISSING uuids missing in ClickHouse, $WRONGNAME attached to another event"
fi
if [[ "$N" -gt 0 && "$BADTS" -eq 0 ]]; then
  pass "all timestamps within ${TS_TOLERANCE_MS} ms of what the SDK sent (max drift ${MAXDELTA} ms; sent_at skew correction)"
else
  fail "timestamps: $BADTS of $N differ by more than ${TS_TOLERANCE_MS} ms (max drift ${MAXDELTA} ms)"
fi

IFS=$'\t' read -r _ C_NAME C_UUID C_ISO C_MS < <(grep '^CUSTOM	' "$TMP/manifest.tsv" || true)
if [[ -n "${C_UUID:-}" ]]; then
  ROW="$(ch "SELECT toString(uuid), toUnixTimestamp64Milli(timestamp) FROM posthog.events WHERE project_id = $PROJECT_ID AND event = '$C_NAME' FORMAT TSV")"
  read -r G_UUID G_MS <<<"$ROW"
  if [[ "${G_UUID:-}" == "$C_UUID" ]]; then pass "caller-chosen uuid preserved ($C_UUID)"; else fail "caller-chosen uuid: sent $C_UUID, stored '${G_UUID:-none}'"; fi
  if [[ -n "${G_MS:-}" ]]; then
    D=$(( G_MS - C_MS )); (( D < 0 )) && D=$(( -D ))
    if (( D <= TS_TOLERANCE_MS )); then pass "custom timestamp $C_ISO preserved (stored drift ${D} ms: sent_at correction)"; else fail "custom timestamp: sent $C_ISO, stored drift ${D} ms"; fi
  else
    fail "custom timestamp event not found in ClickHouse"
  fi
else
  fail "custom timestamp/uuid event missing from the SDK output"
fi

# --- (3) identify: $set inside properties --------------------------------------------------------
IDENT="$(ch "SELECT JSONExtractString(properties, '\$set', 'email') || '|' || JSONExtractString(properties, '\$set', 'plan') FROM posthog.events WHERE project_id = $PROJECT_ID AND event = '\$identify' FORMAT TSVRaw")"
if [[ "$IDENT" == "compat@example.test|free" ]]; then pass "\$identify keeps traits under properties.\$set"; else fail "\$identify properties.\$set unexpected: '$IDENT'"; fi
ALIAS="$(ch "SELECT JSONExtractString(properties, 'alias') FROM posthog.events WHERE project_id = $PROJECT_ID AND event = '\$create_alias' FORMAT TSVRaw")"
if [[ "$ALIAS" == "compat-alias-"* ]]; then pass "\$create_alias keeps properties.alias"; else fail "\$create_alias properties.alias unexpected: '$ALIAS'"; fi

# --- (4) pipeline health -----------------------------------------------------------------------
ERRS="$(ch "SELECT count() FROM posthog.events_errors WHERE received_at > now() - INTERVAL 5 MINUTE")"
if [[ "$ERRS" == "0" ]]; then pass "events_errors has no rows from the last 5 minutes"; else fail "events_errors has $ERRS rows from the last 5 minutes"; fi

# Only exceptions / commits newer than this script's start count (the exception ring buffer outlives restarts).
CONSUMER_OK=0
CONSUMER_MSG="no row for posthog.kafka_events in system.kafka_consumers"
for i in $(seq 1 20); do
  [[ $ARRIVED -eq 1 ]] || { CONSUMER_MSG="events never arrived, no commit to verify"; break; }
  ROW="$(ch "SELECT count(), sum(arrayCount(t -> toUnixTimestamp(t) >= $T0, \`exceptions.time\`)), max(toUnixTimestamp(last_commit_time)) FROM system.kafka_consumers WHERE database = 'posthog' AND \`table\` = 'kafka_events' FORMAT TSV")"
  read -r ROWS EXC COMMIT <<<"$ROW"
  if [[ "$ROWS" -ge 1 ]]; then
    CONSUMER_MSG="consumers=$ROWS new_exceptions=$EXC last_commit=$COMMIT start=$T0"
    if [[ "$EXC" -gt 0 ]]; then break; fi
    if [[ "$COMMIT" -ge "$T0" ]]; then CONSUMER_OK=1; break; fi
  fi
  sleep 1
done
if [[ $CONSUMER_OK -eq 1 ]]; then pass "kafka_events consumer healthy ($CONSUMER_MSG)"; else fail "kafka_events consumer unhealthy ($CONSUMER_MSG)"; fi

# --- optional: kafka outage and recovery ----------------------------------------------------------
if [[ $WITH_OUTAGE -eq 1 ]]; then
  echo "---- kafka outage ----"
  body() { printf '{"api_key":"%s","event":"%s","distinct_id":"compat-outage","properties":{}}' "$KEY" "$1"; }
  # send <event> -> HTTP_CODE, HTTP_TIME (seconds), RETRY_AFTER (header value or empty)
  send() {
    local out
    out="$(curl -sS --max-time 20 -D "$TMP/hdr" -o /dev/null -w '%{http_code} %{time_total}' -X POST "$HOST/e/" \
      -H 'Content-Type: application/json' --data-binary "$(body "$1")" 2>/dev/null)" || out="000 0"
    read -r HTTP_CODE HTTP_TIME <<<"$out"
    RETRY_AFTER="$(tr -d '\r' <"$TMP/hdr" 2>/dev/null | awk -F': ' 'tolower($1)=="retry-after"{print $2}')"
  }

  KAFKA_STOPPED=1
  docker compose stop kafka >/dev/null 2>&1
  echo "kafka stopped"

  send "${PREFIX}outage_1"
  if [[ "$HTTP_CODE" == "503" && -n "$RETRY_AFTER" ]] && awk "BEGIN{exit !($HTTP_TIME <= 5.0)}"; then
    pass "first event while kafka is down -> 503 Retry-After: $RETRY_AFTER in ${HTTP_TIME}s"
  else
    fail "first event while kafka is down: got HTTP $HTTP_CODE Retry-After='$RETRY_AFTER' in ${HTTP_TIME}s (want 503 + header within 5s)"
  fi

  send "${PREFIX}outage_2"
  if [[ "$HTTP_CODE" == "503" ]] && awk "BEGIN{exit !($HTTP_TIME <= 1.0)}"; then
    pass "second event with the breaker open -> fast 503 in ${HTTP_TIME}s"
  else
    fail "second event: got HTTP $HTTP_CODE in ${HTTP_TIME}s (want a fast 503, <= 1s)"
  fi

  docker compose start kafka >/dev/null 2>&1
  KAFKA_STOPPED=0
  START=$SECONDS
  if wait_kafka_healthy 60; then
    pass "kafka healthy again after $((SECONDS - START))s"
  else
    fail "kafka not healthy within 60s of start"
  fi
  echo "waiting 11s for the breaker cooldown"
  sleep 11

  RECOVERED=0
  for attempt in 1 2 3 4 5 6; do
    send "${PREFIX}outage_recovery"
    if [[ "$HTTP_CODE" == "200" ]]; then RECOVERED=1; break; fi
    sleep 3
  done
  if [[ $RECOVERED -eq 1 ]]; then
    pass "event after recovery -> 200 (attempt $attempt, ${HTTP_TIME}s)"
  else
    fail "event after recovery still HTTP $HTTP_CODE after $attempt attempts"
  fi

  GOT=0
  if [[ $RECOVERED -eq 1 ]]; then
    for i in $(seq 1 60); do
      C="$(ch "SELECT count() FROM posthog.events WHERE project_id = $PROJECT_ID AND event = '${PREFIX}outage_recovery'")"
      if [[ "$C" -ge 1 ]]; then GOT=1; break; fi
      sleep 1
    done
  fi
  if [[ $GOT -eq 1 ]]; then pass "recovery event reached ClickHouse after ${i}s"; else fail "recovery event did not reach ClickHouse within 60s"; fi

  LOST="$(ch "SELECT count() FROM posthog.events WHERE project_id = $PROJECT_ID AND event IN ('${PREFIX}outage_1','${PREFIX}outage_2')")"
  if [[ "$LOST" == "0" ]]; then pass "rejected (503) events were not stored"; else fail "$LOST events that got 503 were stored anyway"; fi

  ERRS="$(ch "SELECT count() FROM posthog.events_errors WHERE received_at > now() - INTERVAL 5 MINUTE")"
  if [[ "$ERRS" == "0" ]]; then pass "events_errors still empty after the outage"; else fail "events_errors has $ERRS rows after the outage"; fi
fi

echo "----"
echo "compat: $PASSED passed, $FAILED failed"
[[ $FAILED -eq 0 ]]
