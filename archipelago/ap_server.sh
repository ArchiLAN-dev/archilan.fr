#!/bin/sh
set -e
GAME_DIR="${ARCHIPELAGO_OUTPUT_DIR:-/data/output}"
GAME_FILE=$(ls "$GAME_DIR"/*.zip "$GAME_DIR"/*.archipelago 2>/dev/null | head -1)
if [ -z "$GAME_FILE" ]; then
    echo "ERROR: no game file found in $GAME_DIR" >&2
    exit 1
fi
exec ArchipelagoServer "$GAME_FILE" \
    --host 0.0.0.0 \
    --port 38281 \
    --password "${PASSWORD:-}" \
    --server_password "${SERVER_PASSWORD:-}"
