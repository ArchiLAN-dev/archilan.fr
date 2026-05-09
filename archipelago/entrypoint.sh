#!/bin/sh
set -e

# Find the .archipelago game file from the mounted output directory
GAME_FILE=$(ls /archipelago/output/*.archipelago 2>/dev/null | head -1)

if [ -z "$GAME_FILE" ]; then
    echo '{"event":"no .archipelago file found in /archipelago/output/","severity":"ERROR","run_id":"","timestamp":""}' >&2
    exit 1
fi

mkdir -p /archipelago/saves

# Start Archipelago server in background
SAVE_FILE="/archipelago/saves/$(basename "$GAME_FILE" .archipelago).apsave"

ArchipelagoServer "$GAME_FILE" \
    --host 0.0.0.0 \
    --port 38281 \
    --password "${PASSWORD:-}" \
    --server_password "${SERVER_PASSWORD:-}" \
    --savefile "$SAVE_FILE" \
    &

# Wait briefly for the server to initialize before Bridge.py connects
sleep 3

# Start Bridge.py in background, stdout+stderr vers fichier de log
python -u /bridge/bridge.py > /tmp/bridge.log 2>&1 &

# Wait for any child to exit (restart policy handled by Docker)
wait
