# shellcheck shell=bash
# Shared helpers of the operator scripts: scripts/smoke-capture.sh, scripts/check-readonly-user.sh,
# scripts/e2e.sh and tests/compat/run.sh. SOURCE it (never execute it), after `cd` to the repository root:
#
#   ROOT="$(cd "$(dirname "$0")/.." && pwd)"
#   cd "$ROOT"
#   source "$ROOT/scripts/lib.sh"
#
# It only defines functions and the two result counters, and it runs nothing. The caller owns `set -euo
# pipefail`, its own usage text, its cleanup function and its exit codes. The contract of the callers is the
# same in all of them: exit 0 passed, 1 a check failed (or the script aborted), 2 REFUSED (bad input, not a
# local Docker engine, the stack is not up).
#
# Variables the helpers read (set by the caller before it uses them):
#   TMP               scratch directory (ch_err / delete_err files)
#   KEY               api_key of the throwaway project (set by lib_create_project, cleared by delete_project)
#   CH_FALLBACK_PASSWORD
#                     exported by the caller: used inside the clickhouse container only when it has no
#                     CLICKHOUSE_PASSWORD of its own (passed by name, never on an argv)
# Variables the helpers set: PASSED, FAILED, CH_OUT, KEY, PROJECT_ID, REFUSED, APP_ID.
#
# Compose project: every `docker compose` call below runs in the current directory, so the project is the one
# of that directory, or COMPOSE_PROJECT_NAME / COMPOSE_FILE / COMPOSE_ENV_FILES of the environment: the same
# scripts therefore work against a second checkout with its own project name.

PASSED=0
FAILED=0
CH_OUT=""
REFUSED=0
APP_ID=""

pass() { PASSED=$((PASSED + 1)); echo "PASS: $1"; }
fail() { FAILED=$((FAILED + 1)); echo "FAIL: $1"; }

# --- refusals (exit code 2) ---------------------------------------------------------------------------

# lib_refuse <message>: prints REFUSED to stderr and exits 2.
lib_refuse() {
  echo "REFUSED: $*" >&2
  exit 2
}

# lib_require_local_host: HOST must be http://localhost[:port] or http://127.0.0.1[:port] (the scripts create
# and delete throwaway projects and send events: never against anything that is not this machine).
lib_require_local_host() {
  if [[ ! "$HOST" =~ ^http://(localhost|127\.0\.0\.1)(:[0-9]+)?$ ]]; then
    lib_refuse "HOST must be http://localhost[:port] or http://127.0.0.1[:port], got '$HOST'"
  fi
}

# lib_require_positive_int <variable name>: the variable must hold an integer >= 1.
lib_require_positive_int() {
  local name="$1" value="${!1}"
  if [[ ! "$value" =~ ^[0-9]+$ || "$value" -lt 1 ]]; then
    lib_refuse "$name must be a positive integer, got '$value'"
  fi
}

# lib_require_nonneg_int <variable name>: the variable must hold an integer >= 0.
lib_require_nonneg_int() {
  local name="$1" value="${!1}"
  if [[ ! "$value" =~ ^[0-9]+$ ]]; then
    lib_refuse "$name must be a non-negative integer, got '$value'"
  fi
}

# lib_docker_guard: docker must exist and talk to a LOCAL engine (a unix socket or a named pipe), never a
# remote DOCKER_HOST / context: these scripts delete rows and stop services.
lib_docker_guard() {
  local endpoint
  command -v docker >/dev/null 2>&1 || lib_refuse "docker is not installed"
  endpoint="${DOCKER_HOST:-$(docker context inspect --format '{{.Endpoints.docker.Host}}' 2>/dev/null || true)}"
  case "$endpoint" in
    unix://* | npipe://* | "") ;;
    *) lib_refuse "docker endpoint '$endpoint' is not local" ;;
  esac
}

# lib_require_app_up: the compose stack must be running (the app service has a container); sets APP_ID.
lib_require_app_up() {
  APP_ID="$(docker compose ps -q app 2>/dev/null || true)"
  if [[ -z "$APP_ID" ]]; then
    lib_refuse "stack is not up (docker compose up -d)"
  fi
}

# lib_compose_network <container id>: prints the compose network of that container (the one ending in _default
# when there are several, else the first); nothing when there is none. Derived from the running container, never
# from the directory name, so it is right for any COMPOSE_PROJECT_NAME.
lib_compose_network() {
  local nets n
  nets="$(docker inspect "$1" --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}' 2>/dev/null || true)"
  for n in $nets; do
    if [[ "$n" == *_default ]]; then
      echo "$n"
      return 0
    fi
  done
  echo "${nets%% *}"
}

# lib_install_traps <cleanup function>: the cleanup runs on every exit, INT and TERM (130 / 143).
lib_install_traps() {
  # shellcheck disable=SC2064  # the function name is expanded now on purpose
  trap "$1" EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM
}

# --- ClickHouse (the admin user of the container, no password on any argv) ----------------------------------

# ch_run <clickhouse-client args...>: clickhouse-client inside the container with the container's own
# CLICKHOUSE_USER / CLICKHOUSE_PASSWORD (the client reads them from its environment), so no password appears on
# any argv. ch <query> is the 30 s-limited shortcut.
# SC2016 is intended: the single-quoted script is for the container's shell, not for this one.
# shellcheck disable=SC2016
ch_run() {
  docker compose exec -T -e CH_FALLBACK_PASSWORD clickhouse sh -c '
    [ -n "${CLICKHOUSE_USER:-}" ] || CLICKHOUSE_USER=posthog
    [ -n "${CLICKHOUSE_PASSWORD:-}" ] || CLICKHOUSE_PASSWORD="$CH_FALLBACK_PASSWORD"
    export CLICKHOUSE_USER CLICKHOUSE_PASSWORD
    exec clickhouse-client "$@"' sh "$@"
}
ch() { ch_run --max_execution_time=30 --query "$1"; }

# chv <query>: result in CH_OUT; a failing query is reported through fail and returns 1.
chv() {
  if CH_OUT="$(ch "$1" 2>"$TMP/ch_err")"; then
    return 0
  fi
  CH_OUT=""
  fail "ClickHouse query failed: ${1:0:90} ($(head -c 200 "$TMP/ch_err" 2>/dev/null | tr '\n' ' '))"
  return 1
}

# --- the throwaway project -------------------------------------------------------------------------------------

# lib_create_project <name>: creates the project through the app and sets KEY (from then on a cleanup can delete
# it by this key) and PROJECT_ID. One JSON line carries id and key. Only a line of exactly that shape counts, so
# a stray warning cannot hide a project that was created; the key is validated before it is used to delete
# anything and the id must be exactly a number before it may reach SQL. Exits 1 (after a FAIL line) otherwise.
lib_create_project() {
  local created_json created id_raw
  created_json="$(docker compose exec -T app php artisan project:create "$1" --json 2>"$TMP/create_err" | tr -d '\r' | grep -E '^\{"id":[0-9]+,"api_key":"phc_[A-Za-z0-9]{43}"\}$' | head -n 1 || true)"
  created="$(printf '%s\n' "$created_json" | sed -n 's/^.*"api_key":"\(phc_[A-Za-z0-9]\{43\}\)".*$/\1/p')"
  id_raw="$(printf '%s\n' "$created_json" | sed -n 's/^{"id":\([0-9][0-9]*\),.*$/\1/p')"
  if [[ "$created" =~ ^phc_[A-Za-z0-9]{43}$ ]]; then
    KEY="$created"   # from here on cleanup deletes the project by this key
  else
    fail "could not create throwaway project ($(head -c 200 "$TMP/create_err" | tr '\n' ' '))"
    exit 1
  fi

  if [[ "$id_raw" =~ ^[0-9]+$ ]]; then
    PROJECT_ID="$id_raw"
    echo "INFO: project #$PROJECT_ID $1"
  else
    fail "could not read throwaway project id (got '${id_raw:0:100}')"
    exit 1
  fi
}

# lib_preflight_project_clean: Postgres ids and ClickHouse rows must agree: a fresh project id has no events yet
# and no person_distinct_ids rows (the identity mapping, Milestone 4). Otherwise the project is deleted again,
# PROJECT_ID is cleared (those rows are not ours: the cleanup must not touch them) and the script exits 2.
lib_preflight_project_clean() {
  local pre
  if ! pre="$(ch "SELECT (SELECT count() FROM posthog.events WHERE project_id = $PROJECT_ID) + (SELECT count() FROM posthog.person_distinct_ids WHERE project_id = $PROJECT_ID)" 2>"$TMP/ch_err")" || [[ ! "$pre" =~ ^[0-9]+$ ]]; then
    fail "cannot query ClickHouse: $(head -c 200 "$TMP/ch_err" | tr '\n' ' ')"
    exit 1
  fi
  if [[ "$pre" != "0" ]]; then
    REFUSED=1
    PROJECT_ID=""
    delete_project || true
    lib_refuse "project id collides with existing ClickHouse rows; Postgres and ClickHouse are out of sync"
  fi
}

# Removes the throwaway project by its unique api_key. project:delete goes through the model, so the
# observer also forgets the cached key lookups, and it queues the purge of the project's events by default
# (the scripts also delete their own rows, so the two cannot disagree). The decision rests on the
# documented exit codes only: 0 deleted and 3 unknown key (nothing left to delete) are both success; 4 (deleted,
# purge not queued) is a WARN, the rows are removed by the caller anyway. On exit 0 only the cache-clearing warning
# ('Could not clear the cached key') is echoed as WARN: stderr also carries the structured audit log line
# (LOG_CHANNEL=stderr under Compose, see LOG_AUDIT_CHANNEL) and the 'KEPT'/purge notes, which are not warnings.
# Scripts must call the app with `docker compose exec -T` (no TTY): without -T the output is not a clean stream.
delete_project() {
  [[ -n "$KEY" ]] || return 0
  local out rc=0
  out="$(docker compose exec -T app php artisan project:delete "$KEY" --force 2>"$TMP/delete_err")" || rc=$?
  case "$rc" in
    0)
      if grep -q 'Could not clear the cached key' "$TMP/delete_err"; then
        echo "WARN: project:delete reported: $(grep 'Could not clear the cached key' "$TMP/delete_err" | head -c 300 | tr '\n' ' ')" >&2
      fi
      KEY=""
      return 0
      ;;
    4)
      echo "WARN: project deleted but its events purge could not be queued (the rows are removed below)" >&2
      KEY=""
      return 0
      ;;
    3)
      KEY=""
      return 0
      ;;
  esac
  echo "WARN: could not delete throwaway project (key ${KEY:0:8}..., exit $rc): $(printf '%s %s' "$out" "$(head -c 200 "$TMP/delete_err" 2>/dev/null)" | head -c 200 | tr '\n' ' ')" >&2
  return 1
}
