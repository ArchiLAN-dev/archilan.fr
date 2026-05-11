#!/bin/sh
set -e

# Find the .archipelago game file from the mounted output directory
GAME_FILE=$(ls /archipelago/output/*.archipelago 2>/dev/null | head -1)

if [ -z "$GAME_FILE" ]; then
    echo '{"event":"no .archipelago file found in /archipelago/output/","severity":"ERROR","run_id":"","timestamp":""}' >&2
    exit 1
fi

# Start Archipelago server in background
ArchipelagoServer "$GAME_FILE" \
    --host 0.0.0.0 \
    --port 38281 \
    --password "${PASSWORD:-}" \
    --server_password "${SERVER_PASSWORD:-}" \
    &

# Wait briefly for the server to initialize before Bridge.py connects
sleep 3

# Start Bridge.py - stdout+stderr go to container logs (docker logs)
python -u /bridge/bridge.py &

# Wait for any child to exit (restart policy handled by Docker)
wait
