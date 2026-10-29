#!/usr/bin/env bash
# posthog-node wire-compatibility test against the running compose stack.
#
# The SDK version is pinned by tests/compat/package.json + package-lock.json (supply chain). It is installed
# with `npm ci --ignore-scripts` in a throwaway container on the DEFAULT bridge network (it needs the
# internet, not our stack), and then run by a SECOND throwaway container on the compose network that is
# read-only, capability-less and has the code mounted read-only.
#
# Safe by construction: only talks to a local HOST and a local Docker engine, uses a throwaway project
# (found and deleted by its unique api_key, never by name), and on exit (success, failure, INT or TERM)
# removes exactly what it created and restarts Kafka if it was stopped.
#
# Concurrency: two instances at once are fine (every check is scoped to this run's own project and start
# time), but nothing may run alongside --with-kafka-outage: it stops the shared Kafka service.
#
# Run `tests/compat/run.sh --help` for usage, environment variables and exit codes.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
# shellcheck source=scripts/lib.sh
source "$ROOT/scripts/lib.sh"
DIR="$PWD/tests/compat"

usage() {
  cat <<'EOF'
Usage: tests/compat/run.sh [--with-kafka-outage] [-h|--help]

posthog-node wire-compatibility test against the running docker compose stack.

  --with-kafka-outage  additionally stops the kafka service, checks the 503 behaviour and the recovery
                       after `start` (OFF by default: it interrupts the pipeline for about a minute;
                       do not run anything else against the stack meanwhile)

Environment:
  HOST                    capture endpoint for the host-side checks, http://localhost[:port] or
                          http://127.0.0.1[:port] (default http://localhost:8000; anything else is refused)
  WAIT_SECONDS            how long to poll ClickHouse / Kafka for arrival and recovery (default 60)
  TS_TOLERANCE_MS         allowed drift between SDK timestamp and stored timestamp (default 1000)
  POSTHOG_NODE_VERSION    opt-in: install this posthog-node version (e.g. latest, 5.60.0) instead of the
                          locked one, to answer "does the newest SDK still work?" (^[A-Za-z0-9._-]+$)
  CLICKHOUSE_PASSWORD     only used if the clickhouse container has no CLICKHOUSE_PASSWORD of its own

Exit codes:
  0  all checks passed
  1  at least one check failed (or the script aborted)
  2  refused: bad HOST / non-local Docker endpoint / stack not up / bad argument or variable /
     project id collides with existing ClickHouse rows
EOF
}

WITH_OUTAGE=0
for arg in "$@"; do
  case "$arg" in
    --with-kafka-outage) WITH_OUTAGE=1 ;;
    -h | --help) usage; exit 0 ;;
    *) echo "unknown argument: $arg (try --help)" >&2; exit 2 ;;
  esac
done

HOST="${HOST:-http://localhost:8000}"
WAIT_SECONDS="${WAIT_SECONDS:-60}"
# Timestamps are corrected by the server with sent_at (clock skew + latency), so allow some drift.
TS_TOLERANCE_MS="${TS_TOLERANCE_MS:-1000}"
POSTHOG_NODE_VERSION="${POSTHOG_NODE_VERSION:-}"
# Only used inside the container when it has no CLICKHOUSE_PASSWORD of its own (passed by name, never on argv).
export CH_FALLBACK_PASSWORD="${CLICKHOUSE_PASSWORD:-posthog}"

lib_require_local_host
lib_require_positive_int WAIT_SECONDS
lib_require_nonneg_int TS_TOLERANCE_MS
if [[ -n "$POSTHOG_NODE_VERSION" && ! "$POSTHOG_NODE_VERSION" =~ ^[A-Za-z0-9._-]+$ ]]; then
  lib_refuse "POSTHOG_NODE_VERSION must match ^[A-Za-z0-9._-]+\$, got '$POSTHOG_NODE_VERSION'"
fi

lib_docker_guard
lib_require_app_up

EPOCH="$(date +%s)"
NAME="compat_${EPOCH}_$$_${RANDOM}"
PREFIX="${NAME}_"
NODE_URL="http://app:8000"   # what the node container uses: HOST's localhost is the container itself there
NODE_IMAGE="node:22-alpine"
KEY=""
PROJECT_ID=""
KAFKA_STOPPED=0
TMP="$(mktemp -d)"
HTTP_CODE="000"
HTTP_TIME="0"
RETRY_AFTER=""

# The rows this script owns: its own project AND a name only the SDK/this script produces (this includes the
# SDK's fixed-name $identify / $create_alias events). $1 = project id.
own_rows_where() {
  printf '%s' "project_id = ${1} AND (event LIKE 'compat\\\\_%' OR event IN ('\$identify','\$create_alias')) AND distinct_id LIKE 'compat-%'"
}

# Pipeline errors that belong to this run: received since the start AND mentioning our project or name.
run_errors_where() {
  printf '%s' "received_at >= toDateTime(${T0}) AND (raw LIKE '%${NAME}%' OR match(raw, '\"project_id\":${PROJECT_ID}[^0-9]'))"
}

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
  trap '' INT TERM
  trap - EXIT
  set +e
  local had_project=0 left
  # Never leave kafka stopped, whatever happened.
  if [[ $KAFKA_STOPPED -eq 1 ]]; then
    echo "cleanup: restarting kafka" >&2
    docker compose start kafka >/dev/null 2>&1
    wait_kafka_healthy 90 || echo "WARN: kafka not healthy after restart" >&2
  fi
  # Project first, so no new events are accepted for it; then give Kafka a moment to drain what is in
  # flight, then delete only this run's rows, never TRUNCATE.
  [[ -n "$KEY" ]] && had_project=1
  delete_project
  if [[ -n "$PROJECT_ID" ]]; then
    sleep 3
    ch "DELETE FROM posthog.events WHERE $(own_rows_where "$PROJECT_ID")" >/dev/null 2>&1 \
      || echo "WARN: could not delete compat rows from posthog.events" >&2
    left="$(ch "SELECT count() FROM posthog.events WHERE $(own_rows_where "$PROJECT_ID")" 2>/dev/null)"
    [[ "$left" == "0" ]] || echo "WARN: ${left:-?} compat rows still in posthog.events for project ${PROJECT_ID}" >&2
  elif [[ $had_project -eq 1 ]]; then
    echo "WARN: project id unknown, no ClickHouse rows deleted (event prefix ${PREFIX})" >&2
  fi
  rm -rf "$TMP"
  if [[ $rc -ne 0 && $FAILED -eq 0 && $REFUSED -eq 0 ]]; then
    echo "FAIL: script aborted unexpectedly (exit $rc)"
  fi
  exit "$rc"
}
lib_install_traps cleanup

echo "compat: HOST=$HOST node-url=$NODE_URL project=$NAME kafka-outage=$WITH_OUTAGE wait=${WAIT_SECONDS}s"

# Compose network of the app service: prefer the one ending in _default.
NETWORK="$(lib_compose_network "$APP_ID")"
if [[ -z "$NETWORK" ]]; then
  fail "cannot find the compose network of the app service"
  exit 1
fi

# Reference time from ClickHouse itself, so host/container clock skew cannot matter.
if ! T0="$(ch 'SELECT toUnixTimestamp(now())' 2>"$TMP/ch_err")" || [[ ! "$T0" =~ ^[0-9]+$ ]]; then
  fail "cannot query ClickHouse: $(head -c 200 "$TMP/ch_err" | tr '\n' ' ')"
  exit 1
fi

# --- Step A: install the pinned SDK (default bridge network, no lifecycle scripts) -----------------
# `npm ci` every time: it wipes node_modules and reinstalls exactly what the lockfile says, so a previous
# POSTHOG_NODE_VERSION run can never leak into a pinned run. It costs a few seconds.
if [[ -n "$POSTHOG_NODE_VERSION" ]]; then
  echo "step A: installing posthog-node@${POSTHOG_NODE_VERSION} (override, --no-save; package.json and lockfile untouched) on the default bridge network"
  INSTALL_CMD="npm install --no-save --ignore-scripts --no-audit --no-fund --loglevel=error posthog-node@${POSTHOG_NODE_VERSION}"
else
  echo "step A: npm ci --ignore-scripts from package-lock.json on the default bridge network"
  INSTALL_CMD="npm ci --ignore-scripts --no-audit --no-fund --loglevel=error"
fi
# shellcheck disable=SC2086  # INSTALL_CMD is a fixed command line with a validated version, split on purpose
if ! docker run --rm --network bridge --user "$(id -u):$(id -g)" \
  -e HOME=/tmp -e npm_config_cache=/tmp/.npm \
  -v "$DIR":/w -w /w "$NODE_IMAGE" $INSTALL_CMD >&2; then
  fail "dependency install (step A) failed"
  exit 1
fi

# --- throwaway project --------------------------------------------------------------------------
lib_create_project "$NAME"
lib_preflight_project_clean

# --- Step B: run the real SDK in a locked-down throwaway container on the compose network ---------------
NODE_OK=1
PH_API_KEY="$KEY" PH_HOST="$NODE_URL" PH_PREFIX="$PREFIX" \
  docker run --rm --init --network "$NETWORK" \
  --read-only --tmpfs /tmp --cap-drop ALL --security-opt no-new-privileges \
  --user "$(id -u):$(id -g)" -e HOME=/tmp \
  -v "$DIR":/w:ro -w /w \
  -e PH_API_KEY -e PH_HOST -e PH_PREFIX \
  "$NODE_IMAGE" node smoke-node.mjs \
  >"$TMP/manifest.tsv" || NODE_OK=0

SDK_VERSION="$(awk -F'\t' '$1 == "SDK" { print $3; exit }' "$TMP/manifest.tsv")"
if [[ -n "$POSTHOG_NODE_VERSION" ]]; then
  echo "posthog-node ${SDK_VERSION:-unknown} (override POSTHOG_NODE_VERSION=${POSTHOG_NODE_VERSION})"
else
  echo "posthog-node ${SDK_VERSION:-unknown} (pinned by package-lock.json)"
fi

if [[ $NODE_OK -eq 1 ]]; then pass "posthog-node script ran and the SDK reported no errors"; else fail "posthog-node script failed"; fi

# --- what we expect: every call the script makes, by exact name, once each ----------------------------
{
  printf '%s\t1\n' "${PREFIX}capture"
  printf '%s\t1\n' "${PREFIX}custom_timestamp_uuid"
  for i in $(seq 1 12); do printf '%s\t1\n' "${PREFIX}batch_${i}"; done
  printf '%s\t1\n' "\$identify"
  printf '%s\t1\n' "\$create_alias"
} | LC_ALL=C sort >"$TMP/expected_names"
EXPECTED="$(wc -l <"$TMP/expected_names" | tr -d ' ')"

SENT="$(awk -F'\t' '$1 == "EVENT" { n++ } END { print n + 0 }' "$TMP/manifest.tsv")"
NOTHING_SENT=0
if [[ "$SENT" == "$EXPECTED" ]]; then
  pass "SDK flushed $SENT events (expected $EXPECTED)"
else
  fail "SDK flushed $SENT events, expected $EXPECTED"
  if [[ "$SENT" == "0" ]]; then NOTHING_SENT=1; fi
fi

# --- batching really happened: some flush carried more than one event -------------------------------
if [[ $NOTHING_SENT -eq 0 ]]; then
  BATCH_INFO="$(awk -F'\t' '$1 == "FLUSH" { s[$2] = s[$2] (s[$2] == "" ? "" : ",") $3; if ($3 + 0 > max) max = $3 + 0 }
    END { printf "A=[%s] B=[%s] max=%d", s["A"], s["B"], max + 0 }' "$TMP/manifest.tsv")"
  BATCH_MAX="${BATCH_INFO##*max=}"
  if [[ "$BATCH_MAX" =~ ^[0-9]+$ && "$BATCH_MAX" -gt 1 ]]; then
    pass "SDK batching: flush sizes $BATCH_INFO"
  else
    fail "SDK never batched (every flush had at most one event): $BATCH_INFO"
  fi
fi

# check_delivery: arrival, uuid, timestamps, identify/alias (needs events to have been sent)
check_delivery() {
  # --- (1) all events arrive, exactly the expected ones ------------------------------------------
  local i waited=0 u
  ARRIVED=0
  for i in $(seq 1 "$WAIT_SECONDS"); do
    waited=$i
    # One transient ClickHouse error must not abort the run: count it as "not there yet".
    u="$(ch "SELECT uniqExact(uuid) FROM posthog.events WHERE project_id = $PROJECT_ID" 2>/dev/null || echo 0)"
    [[ "$u" =~ ^[0-9]+$ ]] || u=0
    if [[ "$u" -ge "$EXPECTED" ]]; then ARRIVED=1; break; fi
    sleep 1
  done
  sleep 1   # let a surplus event show up, if there is one
  # Compare by event name with uniqExact(uuid): at-least-once redelivery of the same event is not a failure.
  if chv "SELECT event, uniqExact(uuid) FROM posthog.events WHERE project_id = $PROJECT_ID GROUP BY event FORMAT TSVRaw"; then
    printf '%s\n' "$CH_OUT" | LC_ALL=C sort | awk 'NF' >"$TMP/got_names"
    if diff -q "$TMP/expected_names" "$TMP/got_names" >/dev/null; then
      pass "ClickHouse has exactly the $EXPECTED expected events by name, one uuid each (after ${waited}s)"
    else
      fail "ClickHouse events differ from expected (name, unique uuids): $(diff "$TMP/expected_names" "$TMP/got_names" | tr '\t\n' ' :')"
    fi
  fi
  if chv "SELECT count() - uniqExact(uuid) FROM posthog.events WHERE project_id = $PROJECT_ID"; then
    if [[ "$CH_OUT" =~ ^[0-9]+$ && "$CH_OUT" -gt 0 ]]; then
      echo "NOTE: $CH_OUT duplicate rows (same uuid delivered more than once: at-least-once redelivery)"
    fi
  fi

  # --- (2) uuid and timestamp preserved ----------------------------------------------------------
  if ! ch "SELECT toString(uuid), toUnixTimestamp64Milli(timestamp), event FROM posthog.events WHERE project_id = $PROJECT_ID FORMAT TSV" >"$TMP/ch.tsv" 2>"$TMP/ch_err"; then
    fail "ClickHouse query failed (uuid/timestamp rows): $(head -c 200 "$TMP/ch_err" | tr '\n' ' ')"
    : >"$TMP/ch.tsv"
  fi
  local result n missing wrongname badts maxdelta
  result="$(awk -F'\t' -v tol="$TS_TOLERANCE_MS" '
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
  read -r n missing wrongname badts maxdelta <<<"$result"
  if [[ "$n" -gt 0 && "$missing" -eq 0 && "$wrongname" -eq 0 ]]; then
    pass "all $n SDK-generated uuids are stored unchanged (same uuid -> same event)"
  else
    fail "uuid preservation: $n sent, $missing uuids missing in ClickHouse, $wrongname attached to another event"
  fi
  if [[ "$n" -gt 0 && "$badts" -eq 0 ]]; then
    pass "all timestamps within ${TS_TOLERANCE_MS} ms of what the SDK sent (max drift ${maxdelta} ms; sent_at skew correction)"
  else
    fail "timestamps: $badts of $n differ by more than ${TS_TOLERANCE_MS} ms (max drift ${maxdelta} ms)"
  fi

  local custom_line c_name c_uuid c_iso c_ms g_uuid g_ms d
  custom_line="$(awk -F'\t' '$1 == "CUSTOM" { print; exit }' "$TMP/manifest.tsv")"
  c_name=""; c_uuid=""; c_iso=""; c_ms=""
  if [[ -n "$custom_line" ]]; then
    IFS=$'\t' read -r _ c_name c_uuid c_iso c_ms <<<"$custom_line"
  fi
  if [[ -n "$c_uuid" ]]; then
    g_uuid=""; g_ms=""
    if chv "SELECT toString(uuid), toUnixTimestamp64Milli(timestamp) FROM posthog.events WHERE project_id = $PROJECT_ID AND event = '$c_name' LIMIT 1 FORMAT TSV"; then
      read -r g_uuid g_ms <<<"$CH_OUT" || true
    fi
    if [[ "$g_uuid" == "$c_uuid" ]]; then pass "caller-chosen uuid preserved ($c_uuid)"; else fail "caller-chosen uuid: sent $c_uuid, stored '${g_uuid:-none}'"; fi
    if [[ "$g_ms" =~ ^[0-9]+$ ]]; then
      d=$(( g_ms - c_ms )); (( d < 0 )) && d=$(( -d ))
      if (( d <= TS_TOLERANCE_MS )); then pass "custom timestamp $c_iso preserved (stored drift ${d} ms: sent_at correction)"; else fail "custom timestamp: sent $c_iso, stored drift ${d} ms"; fi
    else
      fail "custom timestamp event not found in ClickHouse"
    fi
  else
    fail "custom timestamp/uuid event missing from the SDK output (the node script probably crashed before the CUSTOM line)"
  fi

  # --- (3) identify: $set inside properties ------------------------------------------------------
  if chv "SELECT JSONExtractString(properties, '\$set', 'email') || '|' || JSONExtractString(properties, '\$set', 'plan') FROM posthog.events WHERE project_id = $PROJECT_ID AND event = '\$identify' LIMIT 1 FORMAT TSVRaw"; then
    if [[ "$CH_OUT" == "compat@example.test|free" ]]; then pass "\$identify keeps traits under properties.\$set"; else fail "\$identify properties.\$set unexpected: '$CH_OUT'"; fi
  fi
  if chv "SELECT JSONExtractString(properties, 'alias') FROM posthog.events WHERE project_id = $PROJECT_ID AND event = '\$create_alias' LIMIT 1 FORMAT TSVRaw"; then
    if [[ "$CH_OUT" == compat-alias-* ]]; then pass "\$create_alias keeps properties.alias"; else fail "\$create_alias properties.alias unexpected: '$CH_OUT'"; fi
  fi

  # --- (4) enrichment leaves a server SDK alone ----------------------------------------------------
  # posthog-node sends `posthog-node/<version>` as its User-Agent: not a browser, so the Enricher must not
  # invent a browser, os or device (and it drops the raw ip by default, CAPTURE_STORE_IP=false).
  if chv "SELECT countIf(browser != '' OR os != '' OR device_type != '') FROM posthog.events WHERE project_id = $PROJECT_ID"; then
    if [[ "$CH_OUT" == "0" ]]; then pass "no browser/os/device invented for the server SDK's User-Agent"; else fail "$CH_OUT SDK events got a browser, os or device_type from the Enricher"; fi
  fi
}

ARRIVED=0
if [[ $NOTHING_SENT -eq 0 ]]; then
  check_delivery
else
  fail "nothing was sent by the SDK: skipping the ClickHouse delivery checks (arrival, uuid, timestamps, identify/alias)"
fi

# --- (4) pipeline health -----------------------------------------------------------------------
# errors_report <label>: fatal check on errors from this run, informational WARN for everything else.
errors_report() {
  if chv "SELECT count() FROM posthog.events_errors WHERE $(run_errors_where)"; then
    if [[ "$CH_OUT" == "0" ]]; then pass "events_errors has no rows for this run ($1)"; else fail "events_errors has $CH_OUT rows for this run ($1)"; fi
  fi
  local global
  global="$(ch "SELECT count() FROM posthog.events_errors WHERE received_at > now() - INTERVAL 5 MINUTE" 2>/dev/null || echo '?')"
  if [[ "$global" != "0" ]]; then
    echo "WARN: events_errors has ${global} rows from the last 5 minutes (not necessarily from this run)"
  fi
}
errors_report "$NAME"

# Only exceptions / commits newer than this script's start count (the exception ring buffer outlives restarts).
CONSUMER_OK=0
CONSUMER_MSG="no row for posthog.kafka_events in system.kafka_consumers"
for i in $(seq 1 "$WAIT_SECONDS"); do
  [[ $ARRIVED -eq 1 ]] || { CONSUMER_MSG="events never arrived, no commit to verify"; break; }
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
if [[ $CONSUMER_OK -eq 1 ]]; then pass "kafka_events consumer healthy ($CONSUMER_MSG)"; else fail "kafka_events consumer unhealthy ($CONSUMER_MSG)"; fi

# --- optional: kafka outage and recovery ----------------------------------------------------------
if [[ $WITH_OUTAGE -eq 1 ]]; then
  echo "---- kafka outage ----"
  body() { printf '{"api_key":"%s","event":"%s","distinct_id":"compat-outage","properties":{}}' "$KEY" "$1"; }
  # send <event> -> HTTP_CODE, HTTP_TIME (seconds), RETRY_AFTER (header value or empty)
  send() {
    local out
    rm -f "$TMP/hdr"
    # curl may exit non-zero after the server answered; whatever it printed is still the answer.
    out="$(curl -sS --max-time 20 -D "$TMP/hdr" -o /dev/null -w '%{http_code} %{time_total}' -X POST "$HOST/e/" \
      -H 'Content-Type: application/json' --data-binary "$(body "$1")" 2>/dev/null || true)"
    HTTP_CODE="000"; HTTP_TIME="0"
    read -r HTTP_CODE HTTP_TIME <<<"${out:-000 0}" || true
    [[ "$HTTP_CODE" =~ ^[0-9]{3}$ ]] || HTTP_CODE="000"
    [[ "$HTTP_TIME" =~ ^[0-9.]+$ ]] || HTTP_TIME="0"
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

  docker compose start kafka >/dev/null 2>&1 || true
  KAFKA_STOPPED=0
  START=$SECONDS
  if wait_kafka_healthy "$WAIT_SECONDS"; then
    pass "kafka healthy again after $((SECONDS - START))s"
  else
    fail "kafka not healthy within ${WAIT_SECONDS}s of start"
  fi
  echo "waiting 11s for the breaker cooldown"
  sleep 11

  RECOVERED=0
  attempt=0
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
  waited=0
  if [[ $RECOVERED -eq 1 ]]; then
    for i in $(seq 1 "$WAIT_SECONDS"); do
      waited=$i
      C="$(ch "SELECT count() FROM posthog.events WHERE project_id = $PROJECT_ID AND event = '${PREFIX}outage_recovery'" 2>/dev/null || echo 0)"
      [[ "$C" =~ ^[0-9]+$ ]] || C=0
      if [[ "$C" -ge 1 ]]; then GOT=1; break; fi
      sleep 1
    done
  fi
  if [[ $GOT -eq 1 ]]; then pass "recovery event reached ClickHouse after ${waited}s"; else fail "recovery event did not reach ClickHouse within ${WAIT_SECONDS}s"; fi

  if chv "SELECT count() FROM posthog.events WHERE project_id = $PROJECT_ID AND event IN ('${PREFIX}outage_1','${PREFIX}outage_2')"; then
    if [[ "$CH_OUT" == "0" ]]; then pass "rejected (503) events were not stored"; else fail "$CH_OUT events that got 503 were stored anyway"; fi
  fi

  errors_report "after the outage"
fi

echo "----"
echo "compat: $PASSED passed, $FAILED failed"
[[ $FAILED -eq 0 ]]
