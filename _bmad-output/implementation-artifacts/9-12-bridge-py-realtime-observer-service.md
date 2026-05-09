# Story 9.12: Bridge.py - Real-Time Observer Service

Status: review

## Story

As the system,
I want a Bridge.py service running inside each Archipelago server container,
So that game events are published to Mercure in real time and admin commands can be forwarded to the server.

## Acceptance Criteria

1. Container env vars available at runtime: `MERCURE_HUB_URL`, `CENTRAL_API_SECRET`, `SYMFONY_INTERNAL_URL`, `RUN_ID` (injected by `StartRunJobHandler` - see Story 9.6).
2. Bridge.py starts alongside MultiServer.py via the container entrypoint script (`/entrypoint.sh` or `CMD` in Dockerfile).
3. Bridge.py connects to `ws://localhost:38281` as a TextOnly Archipelago client (slot type `TextOnly`, no game slot, no password required for observer).
4. On receiving `RoomInfo` packet: store `locations` count per slot index → `checks_total` for each slot.
5. On receiving `PrintJSON` packet: POST to Mercure hub topic `runs/{RUN_ID}/feed` - payload `{"type": "...", "text": "...", "color": "...", "timestamp": "<ISO8601>"}`.
6. On receiving `StatusUpdate`, `ReceivedItems`, or `LocationChecks` packet: update in-memory player state per slot: `checks_done`, `checks_total`, `items_received`, `client_status`; POST updated aggregate to topic `runs/{RUN_ID}/players`.
7. On `client_status` transition to 30 (GOAL): record `goal_reached_at` as current UTC ISO8601 timestamp.
8. On startup (before WS connect): read `/archipelago/saves/*.apsave` - decompress with `zlib.decompress`, unpickle; extract `location_checks` (dict slot→set of checked locations) and `client_game_state` (dict slot→ClientStatus int) to init `checks_done` and `client_status` per slot. If file absent or unreadable: init empty.
9. Publisher JWT: on startup, call `GET {SYMFONY_INTERNAL_URL}/api/v1/internal/sessions/{RUN_ID}/publisher-token` with header `X-Internal-Secret: {CENTRAL_API_SECRET}`; use returned `token` as `Authorization: Bearer {token}` on all Mercure POST requests. Schedule refresh every 50 minutes (or immediately on 401 from Mercure).
10. WS reconnect: exponential backoff 1s→2s→4s→8s→max 30s; on reconnection re-fetch `RoomInfo` to restore `checks_total` (in-memory player state preserved, `.apsave` not re-read).
11. REST API on `0.0.0.0:5000`: `POST /commands` accepts `{"command": "..."}` → sends WS `Say` packet; `GET /state` returns current player aggregate JSON; `GET /health` returns `{"status": "ok", "ws_connected": true|false}`.
12. Structured JSON logs to stdout: each log line is a JSON object with `event`, `run_id`, `timestamp`, `severity`.
13. Unit tests cover: `.apsave` parsing, RoomInfo→checks_total, PrintJSON→Mercure topic, GOAL→goal_reached_at, state aggregation, command forwarding, `/state` output, publisher token fetch+refresh.

## Tasks / Subtasks

- [x] Task 1: Create `bridge/` directory in repo root (AC: all)
  - [x] `bridge/bridge.py` - main service
  - [x] `bridge/requirements.txt` - `websockets`, `aiohttp`, `pytest`, `pytest-asyncio`
  - [x] Included in `archipelago-server` image (build context: repo root, `dockerfile: archipelago/Dockerfile`)
  - [x] `archipelago/entrypoint.sh` starts both ArchipelagoServer and bridge.py; `CMD ["/entrypoint.sh"]` in Dockerfile

- [x] Task 2: Implement publisher token lifecycle (AC: #9)
  - [x] `TokenManager` class: `fetch_token()` → GET `{SYMFONY_INTERNAL_URL}/api/v1/internal/sessions/{RUN_ID}/publisher-token` with `X-Internal-Secret` header
  - [x] Store `token`; schedule async refresh via `asyncio.create_task` loop every 3000s
  - [x] On 401 from Mercure: immediate `fetch_token()` retry before re-posting

- [x] Task 3: Implement `.apsave` parsing (AC: #8)
  - [x] `load_save_state(save_dir: str) -> dict` - glob `{save_dir}/*.apsave`, pick latest by mtime, `zlib.decompress`, `pickle.loads`
  - [x] Extract `location_checks` and `client_game_state` per slot
  - [x] Map to `PlayerState` dataclass per slot
  - [x] Wrapped in try/except - any error → init empty state (log warning)

- [x] Task 4: Archipelago WebSocket client (AC: #3, #4, #5, #6, #7, #10)
  - [x] `websockets` library; Connect with `TextOnly` tag, no game, no slot
  - [x] Handle `RoomInfo`, `PrintJSON`, `StatusUpdate`, `ReceivedItems`, `LocationChecks`
  - [x] GOAL (status=30): record `goal_reached_at` UTC ISO8601
  - [x] After each state update: POST aggregate to `runs/{RUN_ID}/players`
  - [x] Exponential backoff reconnect: 1→2→4→8→16→30s

- [x] Task 5: Mercure publish helper (AC: #5, #6, #9)
  - [x] `MercurePublisher.publish()` via `aiohttp` POST with `Authorization: Bearer`
  - [x] On 401: re-fetch token, retry once

- [x] Task 6: REST API with aiohttp (AC: #11)
  - [x] `POST /commands` - validates `command` field, sends WS `Say` packet
  - [x] `GET /state` - returns player aggregate JSON
  - [x] `GET /health` - returns `{"status": "ok", "ws_connected": bool}`
  - [x] Bound to `0.0.0.0:5000`, runs in same asyncio loop

- [x] Task 7: Structured logging (AC: #12)
  - [x] `JsonFormatter` emits `{"event", "run_id", "timestamp", "severity"}` per line
  - [x] All non-JSON handlers removed

- [x] Task 8: Unit tests (AC: #13)
  - [x] `bridge/tests/test_save_parser.py` - 10 tests covering edge cases
  - [x] `bridge/tests/test_state.py` - 16 tests: RoomInfo, StatusUpdate, LocationChecks, ReceivedItems, GOAL, to_api_dict
  - [x] `bridge/tests/test_api.py` - 9 tests via `aiohttp.test_utils`
  - [x] `bridge/tests/test_token.py` - 5 tests: fetch, URL, header, refresh, schedule
  - [x] 40 tests total - all pass (`pytest bridge/`)
  - [x] `bridge/pyproject.toml` with `asyncio_mode = "auto"`

## Dev Notes

### Archipelago Protocol Details

- **Connection**: JSON arrays, each element is a packet object `{"cmd": "Connect", "name": "...", "game": "", "uuid": "...", "version": {"major":0,"minor":5,"build":0,"class":"Version"}, "tags": ["TextOnly"], "items_handling": 0}`
- **RoomInfo response**: `{"cmd": "RoomInfo", "games": [...], "locations": [<total_locs_per_game>], ...}` - `locations[slot_index]` is total checks for that slot
- **StatusUpdate**: `{"cmd": "StatusUpdate", "status": <int>}` - ClientStatus: UNKNOWN=0, CONNECTED=5, READY=10, PLAYING=20, GOAL=30
- **ReceivedItems**: `{"cmd": "ReceivedItems", "index": N, "items": [...]}`  - items is a list; `items_received += len(items)`
- **LocationChecks**: `{"cmd": "LocationChecks", "locations": [<loc_id>, ...]}` - `checks_done = len(checked_locations_set)`
- **PrintJSON**: `{"cmd": "PrintJSON", "data": [...], "type": "...", "color": "..."}` - reconstruct `text` by joining `data[*].text`
- **Compression**: Archipelago uses per-message deflate on WS; `websockets` library supports this via `compress=None` on server side but client should negotiate it. Use `websockets.connect(..., compression=None)` and handle manually, or use the library's built-in deflate support.

### `.apsave` File Format

- Format: `zlib.decompress(file_bytes)` → `pickle.loads(decompressed)` → Python dict
- Key fields: `location_checks: dict[int, set[int]]` (slot index → set of checked loc IDs), `client_game_state: dict[int, int]` (slot index → ClientStatus)
- File location inside container: `/archipelago/saves/*.apsave` (volume mounted by runner)
- Use `max(glob.glob(...))` or pick the latest by mtime if multiple files

### Mercure POST Format

```python
await session.post(
    MERCURE_HUB_URL,
    data={"topic": f"runs/{RUN_ID}/feed", "data": json.dumps(event)},
    headers={"Authorization": f"Bearer {token}"}
)
```

### Player State JSON (GET /state)

```json
{
  "slots": {
    "1": {"slot_name": "Alice_HK1", "checks_done": 12, "checks_total": 47, "items_received": 8, "client_status": 20, "goal_reached_at": null},
    "2": {"slot_name": "Bob_LttP", "checks_done": 47, "checks_total": 47, "items_received": 15, "client_status": 30, "goal_reached_at": "2026-05-05T14:32:00Z"}
  }
}
```

### Entrypoint Script

The `archipelago-server` Docker image entrypoint (to be added to the image Dockerfile):
```sh
#!/bin/sh
python /archipelago/MultiServer.py --host 0.0.0.0 --port 38281 --server_password "$SERVER_PASSWORD" &
python /bridge/bridge.py &
wait
```

### References

- `src/Sessions/Presentation/PublisherTokenController.php` - the publisher-token endpoint Bridge.py calls (Story 9.11, already implemented)
- `src/Sessions/Application/Handler/StartRunJobHandler.php` - injects env vars into container (Story 9.6)
- `.env.test`: `CENTRAL_API_SECRET=test-runner-secret` for test mocking

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

N/A - no blockers during implementation.

### Completion Notes List

- Build context must be repo root (`.`) so `COPY bridge/bridge.py` and `COPY archipelago/entrypoint.sh` are both in scope; `docker-compose.yml` updated accordingly with `dockerfile: archipelago/Dockerfile`.
- The `archilan-archipelago` compose service retains `command: ["tail", "-f", "/dev/null"]` (used as toolbox for generate/template CLI invocations); server containers spawned per-run by the runner use the Dockerfile's default `CMD ["/entrypoint.sh"]`.
- `StateManager` is stateful and holds `PlayerState` objects; all handler methods are synchronous and called from the async WS loop.
- `TokenManager.schedule_refresh` uses `asyncio.create_task` with a sleep loop (not `call_later`) for compatibility with `pytest-asyncio`.
- 40 Python tests across 4 files; run with `pytest bridge/` from repo root.

### File List

- `bridge/bridge.py` (new)
- `bridge/requirements.txt` (new)
- `bridge/pyproject.toml` (new)
- `bridge/__init__.py` (new)
- `bridge/tests/__init__.py` (new)
- `bridge/tests/test_save_parser.py` (new)
- `bridge/tests/test_state.py` (new)
- `bridge/tests/test_api.py` (new)
- `bridge/tests/test_token.py` (new)
- `archipelago/Dockerfile` (modified - added `aiohttp`, `COPY bridge/bridge.py`, `COPY archipelago/entrypoint.sh`, changed CMD)
- `archipelago/entrypoint.sh` (new)
- `docker-compose.yml` (modified - `archipelago` service: context `.`, `dockerfile: archipelago/Dockerfile`)
