#!/usr/bin/env bash
# Quality gates, both sides at once - the fast local loop.
#
#   ./scripts/gates.sh            api + frontend in parallel, phpunit on ParaTest (8 workers)
#   ./scripts/gates.sh --serial   api + frontend in parallel, phpunit serial (byte-identical to CI)
#   ./scripts/gates.sh --api      api only (combine with --serial)
#   ./scripts/gates.sh --front    frontend only
#
# Same legs as CI on each side (composer gates / pnpm gates); only phpunit's runner differs by default, and
# ParaTest is stricter than serial (story 33.26), so a green fast run is a safe signal before a PR. Use --serial
# when a doubt remains about an order-dependent test.
#
# The api suite runs on its own databases: archilan_test_gates1..8 with ParaTest, archilan_test_gates serial.
# Override the namespace with GATES_DB_PREFIX (e.g. one per worktree) so two runs never share a schema.
# Logs land in a temp folder, printed at the end; a failing side shows its last lines.

set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SERIAL=0
RUN_API=1
RUN_FRONT=1
for arg in "$@"; do
    case "$arg" in
        --serial) SERIAL=1 ;;
        --api) RUN_FRONT=0 ;;
        --front) RUN_API=0 ;;
        -h|--help) sed -n '2,15p' "$0"; exit 0 ;;
        *) echo "Unknown option: $arg (see --help)" >&2; exit 2 ;;
    esac
done

PREFIX="${GATES_DB_PREFIX:-_gates}"
LOGS="$(mktemp -d "${TMPDIR:-/tmp}/archilan-gates.XXXXXX")"

run_api() {
    local start=$SECONDS
    if [ "$SERIAL" -eq 1 ]; then
        # Serial: one database, named by TEST_TOKEN as an isolated run would be.
        TEST_TOKEN="$PREFIX" composer --working-dir="$ROOT/api" gates >"$LOGS/api.log" 2>&1
    else
        # ParaTest sets TEST_TOKEN=1..N per worker; the prefix keeps its databases apart from other runs.
        TEST_DB_PREFIX="$PREFIX" composer --working-dir="$ROOT/api" gates:fast >"$LOGS/api.log" 2>&1
    fi
    local status=$?
    echo "$status $((SECONDS - start))" >"$LOGS/api.status"
}

run_front() {
    local start=$SECONDS
    pnpm --dir "$ROOT/frontend" gates >"$LOGS/front.log" 2>&1
    local status=$?
    echo "$status $((SECONDS - start))" >"$LOGS/front.status"
}

mode="ParaTest"
[ "$SERIAL" -eq 1 ] && mode="serial"
echo "==> Gates ($( [ "$RUN_API" -eq 1 ] && echo "api/$mode ")$( [ "$RUN_FRONT" -eq 1 ] && echo "frontend")) - logs: $LOGS"

[ "$RUN_API" -eq 1 ] && run_api &
[ "$RUN_FRONT" -eq 1 ] && run_front &
wait

failed=0
report() {
    local side="$1"
    [ -f "$LOGS/$side.status" ] || return 0
    read -r status seconds <"$LOGS/$side.status"
    local duration
    duration="$((seconds / 60))m$(printf '%02d' $((seconds % 60)))s"
    if [ "$status" -eq 0 ]; then
        echo "OK    $side ($duration)"
    else
        failed=1
        echo "FAIL  $side ($duration) - last lines of $LOGS/$side.log:"
        tail -25 "$LOGS/$side.log" | sed 's/^/      /'
    fi
}
report api
report front

exit "$failed"
