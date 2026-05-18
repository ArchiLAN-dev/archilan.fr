#!/bin/sh
set -e

# Find the .archipelago game file from the output directory.
# ARCHIPELAGO_OUTPUT_DIR can override the default when the workspace is mounted
# as a named volume (the runner passes the session-specific subpath via this var).
GAME_DIR="${ARCHIPELAGO_OUTPUT_DIR:-/archipelago/output}"
GAME_FILE=$(ls "$GAME_DIR"/*.archipelago 2>/dev/null | head -1)

if [ -z "$GAME_FILE" ]; then
    echo '{"event":"no .archipelago file found in '"$GAME_DIR"'","severity":"ERROR","run_id":"","timestamp":""}' >&2
    exit 1
fi

# Start Archipelago server in background
ArchipelagoServer "$GAME_FILE" \
    --host 0.0.0.0 \
    --port 38281 \
    --password "${PASSWORD:-}" \
    --server_password "${SERVER_PASSWORD:-}" \
    &
echo "$!" > "${AP_PID_FILE:-/tmp/ap.pid}"

# Wait briefly for the server to initialize before Bridge.py connects
sleep 3

# Start Bridge.py - stdout+stderr go to container logs (docker logs)
python -u /bridge/bridge.py &

# Wait for any child to exit (restart policy handled by Docker)
wait
