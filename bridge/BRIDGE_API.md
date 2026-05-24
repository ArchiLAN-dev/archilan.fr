# Bridge API Specification

## Principles

The bridge is a **generic Archipelago runtime**. It knows nothing about
Symfony, Mercure, or any specific application. Any client that speaks HTTP or
WebSocket can use it.

Three surfaces:
- **Operator API** — prepare and operate an Archipelago session (apworld
  upload, world generation, server start/stop). Admin only.
- **WebSocket** — real-time event stream. Any number of clients can connect
  simultaneously and receive the same events.
- **Session API** — query session state and send commands (REST). Public +
  admin tiers.

---

## Configuration

All environment variables are application-agnostic.

### Session identity

| Variable | Required | Default | Description |
|---|---|---|---|
| `SESSION_ID` | yes | — | Opaque identifier passed back in all events |
| `INTERNAL_TOKEN` | yes | — | Bearer token for privileged REST endpoints and WS connection |

### AP server connection

| Variable | Required | Default | Description |
|---|---|---|---|
| `AP_WS_URL` | no | `ws://localhost:38281` | Archipelago server WebSocket URL |
| `AP_SERVER_PASSWORD` | no | `""` | Player password for the AP room |
| `AP_ADMIN_PASSWORD` | no | `""` | Admin password (`!admin login`) |
| `SLOT_NAMES` | no | `[]` | JSON array of `{"name": str, "game": str}` — slots the bridge connects as |

### AP process management

| Variable | Required | Default | Description |
|---|---|---|---|
| `AP_PID_FILE` | no | `/tmp/ap.pid` | Path written by the AP process containing its PID |
| `AP_START_CMD` | no | `""` | Shell command to start the AP server; seed file path appended as first argument |
| `AP_LAUNCH_CMD` | no | `""` | Shell command to resume the AP server; `--savefile=<path>` appended |

`AP_START_CMD` is for first launch from a generated seed.
`AP_LAUNCH_CMD` is for resume after pause.

### Directories

| Variable | Required | Default | Description |
|---|---|---|---|
| `SAVE_DIR` | no | `/archipelago/output` | Directory containing `.apsave` save files |
| `AP_WORLDS_DIR` | no | `/archipelago/worlds` | Directory where `.apworld` files are installed |
| `AP_YAMLS_DIR` | no | `/archipelago/yamls` | Staging directory for player YAML files before generation |
| `AP_OUTPUT_DIR` | no | `/archipelago/output` | Directory where generated `.archipelago` seed files land |
| `AP_GENERATE_CMD` | no | `""` | Shell command to run Archipelago world generation |

### Object storage (saves)

| Variable | Required | Default | Description |
|---|---|---|---|
| `STORAGE_ENDPOINT` | no | `""` | S3-compatible endpoint for save upload/download |
| `STORAGE_ACCESS_KEY` | no | `""` | S3 access key |
| `STORAGE_SECRET_KEY` | no | `""` | S3 secret key |
| `STORAGE_BUCKET` | no | `archipelago-saves` | S3 bucket name |

### Network

| Variable | Required | Default | Description |
|---|---|---|---|
| `REST_PORT` | no | `5000` | HTTP + WebSocket listen port |

---

## Authentication

| Tier | Mechanism | Scope |
|---|---|---|
| **Public** | None | Read-only session state queries |
| **Admin** | `Authorization: Bearer {INTERNAL_TOKEN}` | All operator endpoints, privileged session endpoints |

WebSocket connections authenticate via query parameter:
```
ws://host:5000/ws?token={INTERNAL_TOKEN}
```

---

## Data Models

All AP protocol integers are mapped to named strings at the bridge boundary.
Raw integer values never appear in the public API.

### `SlotStatus`
```
"idle"          AP client_status 5 or 10 — connected, not yet active
"playing"       AP client_status 20
"goal_reached"  AP client_status 30
"done"          AP client_status 40
```

### `SlotType`
```
"player"        AP slot type 1 — standard player slot
"spectator"     AP slot type 2 — spectator, no game
"group"         AP slot type 3 — group slot (multiple games in one slot)
```

### `ItemClassification`
Array of strings derived from AP item flags bitmask.
```
[]                       flags 0 — filler
["progression"]          flags 1
["useful"]               flags 2
["trap"]                 flags 4
["progression","useful"] flags 3
```

### `HintStatus`
```
"unspecified"   AP hint status 0
"no_priority"   AP hint status 10
"avoid"         AP hint status 20
"priority"      AP hint status 30
"found"         AP hint status 40
```

### `ForfeitMode`
```
"disabled"       command never available
"goal"           available after goal reached
"enabled"        always available
"auto"           AP executes automatically on goal, command not available
"auto_enabled"   AP executes automatically on goal, command also available
```
Applies identically to `forfeitMode`, `releaseMode`, and `collectMode`.

### `FeedEventType`
```
"item_sent"     PrintJSON/ItemSend
"goal"          PrintJSON/Goal
"hint"          PrintJSON/Hint
"chat"          PrintJSON/Chat
"join"          PrintJSON/Join    — player connected to the room
"part"          PrintJSON/Part    — player disconnected from the room
"release"       PrintJSON/Release
"collect"       PrintJSON/Collect
"forfeit"       PrintJSON/Forfeit (release + collect)
"countdown"     PrintJSON/Countdown
"system"        PrintJSON/ServerChat, Tutorial, TagsChanged, CounterMeasure
"death_link"    Bounce/DeathLink received
```

### `SlotSummary`
```jsonc
{
  "slot": 1,
  "name": "Jean",
  "game": "Hollow Knight",
  "type": "player",              // SlotType
  "status": "playing",           // SlotStatus
  "connected": true,             // is an AP client currently connected to this slot?
  "checksDone": 12,
  "checksTotal": 40,
  "itemsReceived": 8,
  "goalReachedAt": null,         // ISO-8601 or null
  "reachableNow": 14             // null until first reachability computation
}
```

### `CheckDetail`
```jsonc
{ "id": 12345, "name": "Forgotten Crossroads - Grubfather" }
```

### `ItemDetail`
```jsonc
{
  "id": 67890,
  "name": "Mothwing Cloak",
  "classification": ["progression"],
  "senderSlot": 2,
  "senderName": "Marie",
  "locationId": 11111,
  "locationName": "Greenpath - Shrubbery"
}
```

### `HintDetail`
```jsonc
{
  "itemId": 67890,
  "itemName": "Mothwing Cloak",
  "itemClassification": ["progression"],
  "locationId": 11111,
  "locationName": "Greenpath - Shrubbery",
  "receivingSlot": 1,
  "receivingPlayerName": "Jean",
  "findingSlot": 2,
  "findingPlayerName": "Marie",
  "entrance": "",                // entrance rando name, or ""
  "status": "priority",          // HintStatus
  "found": false
}
```

### `HintBudget`
```jsonc
{
  "hintsUsed": 2,
  "pointsAvailable": 10,
  "costPerHint": 5,
  "pointsPerCheck": 1
}
```

### `LocationPlacement`
```jsonc
{
  "locationId": 11111,
  "locationName": "Greenpath - Shrubbery",
  "itemId": 67890,
  "itemName": "Mothwing Cloak",
  "itemClassification": ["progression"],
  "receivingSlot": 1,
  "receivingPlayerName": "Jean"
}
```

---

## Operator API

All operator endpoints require `Authorization: Bearer {INTERNAL_TOKEN}`.

These endpoints cover the full lifecycle of an Archipelago session before and
after it is running: installing game worlds, generating the multiworld seed,
and managing the AP server process.

---

### Apworlds

#### `GET /operator/apworlds`
List all installed apworld files.
```jsonc
{
  "apworlds": [
    { "filename": "hollow_knight.apworld", "game": "Hollow Knight", "version": "0.1.3" }
  ]
}
```
`game` and `version` are extracted from the apworld manifest; `null` if absent.

---

#### `POST /operator/apworlds`
Upload and install an apworld file. Placed in `AP_WORLDS_DIR`.
Multipart `form-data`, field name `file`.

Response `201 Created`:
```jsonc
{ "filename": "hollow_knight.apworld", "game": "Hollow Knight", "version": "0.1.3" }
```

Errors:
- `400 invalid_apworld` — not a valid apworld archive
- `409 already_exists` — use `PUT` to overwrite

---

#### `PUT /operator/apworlds/{filename}`
Overwrite an existing apworld. Same multipart body as `POST`.

Response `200 OK`: same shape as `POST`.

---

#### `DELETE /operator/apworlds/{filename}`
Remove an installed apworld.

Response `204 No Content`.

Errors: `404 not_found`

---

#### `GET /operator/apworlds/{filename}/yaml`
Generate the default YAML configuration template for a game. Invokes AP's
template generator; synchronous, typically < 2 s.

Response `200 OK`:
```jsonc
{
  "game": "Hollow Knight",
  "filename": "hollow_knight.apworld",
  "yaml": "# Archipelago Hollow Knight player options\nname: Player\ngame: Hollow Knight\n..."
}
```

Errors:
- `404 not_found`
- `503 generation_failed`

---

### Generation

World generation is async. `POST /operator/generate` starts a background job
and returns a job ID immediately. Poll `GET /operator/jobs/{jobId}` until
terminal.

Only one generation job runs at a time; a second `POST` returns
`409 job_already_running`.

---

#### `POST /operator/generate`
Start world generation. Multipart `form-data`.

| Field | Type | Required | Description |
|---|---|---|---|
| `yamls` | file (multiple) | yes | One `.yaml` file per player slot |
| `seed` | text | no | Integer seed; AP generates a random one if absent |
| `race` | text | no | `"true"` to force race mode (no spoiler, no hints) |
| `spoilerLevel` | text | no | `0`–`3`; default `3` (full spoiler). Ignored when `race=true` |

Response `202 Accepted`:
```jsonc
{ "jobId": "gen-a1b2c3" }
```

Errors:
- `409 job_already_running`
- `422 no_yamls`
- `503 generate_cmd_not_configured`

---

#### `GET /operator/jobs/{jobId}`
Poll a generation job.

| Status | Terminal | Description |
|---|---|---|
| `pending` | no | Queued, not yet started |
| `running` | no | AP generation subprocess active |
| `done` | yes | Seed file generated successfully |
| `failed` | yes | Generation process exited with an error |

Response while running:
```jsonc
{
  "jobId": "gen-a1b2c3",
  "status": "running",
  "startedAt": "2026-05-19T14:30:00Z",
  "progress": "Filling multiworld..."
}
```

Response on success:
```jsonc
{
  "jobId": "gen-a1b2c3",
  "status": "done",
  "startedAt": "2026-05-19T14:30:00Z",
  "finishedAt": "2026-05-19T14:30:45Z",
  "seed": 12345678,
  "seedFile": "AP_12345678.archipelago",
  "spoilerFile": "AP_12345678_Spoiler.txt",  // null in race mode or spoilerLevel 0
  "raceMode": false
}
```

Response on failure:
```jsonc
{
  "jobId": "gen-a1b2c3",
  "status": "failed",
  "startedAt": "2026-05-19T14:30:00Z",
  "finishedAt": "2026-05-19T14:30:12Z",
  "error": "yaml_parse_error",
  "message": "jean.yaml: unknown option 'skip_quake' for game Hollow Knight"
}
```

Errors: `404 not_found`

---

### Server

At most one AP server process runs per bridge instance.

---

#### `GET /operator/server`
```jsonc
{
  "running": true,
  "pid": 1234,
  "port": 38281,
  "seedFile": "AP_12345678.archipelago",
  "startedAt": "2026-05-19T14:31:00Z"
}
```

When not running: all fields except `running` are `null`.

---

#### `POST /operator/server/start`
Launch the AP server. Runs `AP_START_CMD <seedFile>`, writes PID to
`AP_PID_FILE`. Waits up to **30 s** for the server to accept TCP connections
before returning.

```jsonc
// Request
{ "seedFile": "AP_12345678.archipelago" }

// Response
{ "ok": true, "pid": 1234, "port": 38281 }
```

Errors:
- `409 already_running`
- `404 seed_not_found`
- `503 start_cmd_not_configured`
- `504 server_health_timeout`

---

#### `POST /operator/server/stop`
SIGTERM → wait 5 s → SIGKILL if needed.

```jsonc
{ "ok": true }
```

Errors: `409 not_running`

---

### Operator WebSocket events

#### `generation_progress`
```jsonc
{ "type": "generation_progress", "jobId": "gen-a1b2c3", "progress": "Filling multiworld..." }
```

#### `generation_done`
```jsonc
{
  "type": "generation_done",
  "jobId": "gen-a1b2c3",
  "seed": 12345678,
  "seedFile": "AP_12345678.archipelago",
  "spoilerFile": "AP_12345678_Spoiler.txt",
  "raceMode": false
}
```

#### `generation_failed`
```jsonc
{ "type": "generation_failed", "jobId": "gen-a1b2c3", "error": "yaml_parse_error", "message": "..." }
```

#### `server_started`
```jsonc
{ "type": "server_started", "pid": 1234, "port": 38281, "seedFile": "AP_12345678.archipelago" }
```

#### `server_stopped`
```jsonc
{ "type": "server_stopped", "seedFile": "AP_12345678.archipelago" }
```

---

## Session REST API

Base URL: `http://host:5000`

All responses are `application/json`. Error shape:
```jsonc
{ "error": "error_code", "message": "Human-readable description" }
```

---

### Public endpoints

#### `GET /health`
```jsonc
{ "status": "ok", "wsConnected": true, "sessionId": "abc123" }
```

---

#### `GET /room`
Room-level information, populated from AP `RoomInfo` and `RoomUpdate` packets.
```jsonc
{
  "sessionId": "abc123",
  "slotCount": 4,
  "hintCostPercent": 10,
  "locationCheckPoints": 1,
  "forfeitMode": "goal",         // ForfeitMode
  "releaseMode": "auto",         // ForfeitMode
  "collectMode": "disabled",     // ForfeitMode
  "deathLinkActive": false,      // true if any connected slot has the DeathLink tag
  "raceMode": false,             // true if generated with race=true
  "wsConnected": true
}
```

---

#### `GET /slots`
```jsonc
{ "slots": [ SlotSummary, ... ] }
```

Player, spectator, and group slots are all included. Clients should filter by
`type` if they only want to display player slots.

---

#### `GET /slots/{slot}`
```jsonc
{
  "slot": 1,
  "name": "Jean",
  "game": "Hollow Knight",
  "type": "player",
  "status": "playing",
  "connected": true,
  "checksDone": 12,
  "checksTotal": 40,
  "itemsReceived": 8,
  "goalReachedAt": null,
  "reachableNow": 14,
  "budget": HintBudget
}
```

---

#### `GET /slots/{slot}/checks`
Categorised check state. `reachable` and `blocked` are empty until the first
reachability computation completes.
```jsonc
{
  "slot": 1,
  "checked":   [ CheckDetail, ... ],
  "reachable": [ CheckDetail, ... ],
  "blocked":   [ CheckDetail, ... ],
  "total": 40
}
```

---

#### `GET /slots/{slot}/items`
```jsonc
{
  "slot": 1,
  "received": [ ItemDetail, ... ],
  "counts": { "received": 8, "total": null },   // total=null until spoiler computed
  "byClassification": { "progression": 3, "useful": 2, "trap": 1, "filler": 2 }
}
```

---

#### `GET /slots/{slot}/hints`
```jsonc
{
  "slot": 1,
  "hints": [ HintDetail, ... ],
  "budget": HintBudget
}
```

---

#### `GET /slots/{slot}/reachable`
```jsonc
{
  "slot": 1,
  "player": "Jean",
  "counts": {
    "reachableNow": 14,
    "reachableUnchecked": 6,
    "reachableChecked": 8,
    "unreachableUnchecked": 10,
    "total": 40
  },
  "reachableUnchecked":   [ CheckDetail, ... ],
  "reachableChecked":     [ CheckDetail, ... ],
  "unreachableUnchecked": [ CheckDetail, ... ],
  "cached": false
}
```

---

#### `GET /slots/{slot}/item-locations`
Where this slot's items are located across all other worlds.
```jsonc
{
  "slot": 1,
  "locations": [
    {
      "itemId": 67890,
      "itemName": "Mothwing Cloak",
      "itemClassification": ["progression"],
      "locationId": 11111,
      "locationName": "Greenpath - Shrubbery",
      "findingSlot": 2,
      "findingPlayerName": "Marie",
      "checkStatus": "reachable"    // "reachable" | "checked" | "blocked"
    }
  ]
}
```

---

### Admin endpoints

All require `Authorization: Bearer {INTERNAL_TOKEN}`.

---

#### `POST /commands`
```jsonc
// Request
{ "command": "!admin /collect Jean" }

// Response
{ "ok": true }
```

Errors: `503 ws_disconnected`

---

#### `POST /pause`
Async. Returns immediately; flow runs in background.
```jsonc
{ "ok": true }
```

---

#### `POST /resume`
Async. Returns immediately; flow runs in background.
```jsonc
// Request
{ "saveKey": "20260519T143000.apsave" }   // optional — bridge tries local save first

// Response
{ "ok": true }
```

---

#### `POST /deathlink`
Broadcast a DeathLink event to the AP server. All connected players whose
games support DeathLink will die.
```jsonc
// Request
{ "source": "Jean", "cause": "Fell into void" }

// Response
{ "ok": true }
```

Errors: `503 ws_disconnected`

---

#### `GET /slots/{slot}/items/missing`
Items destined for this slot not yet received. Queries AP via `LocationScouts`.
Response may take up to 5 s.
```jsonc
{
  "slot": 1,
  "missing": [
    {
      "itemId": 67890,
      "itemName": "Mothwing Cloak",
      "itemClassification": ["progression"],
      "findingSlot": 2,
      "findingPlayerName": "Marie",
      "locationId": 11111,
      "locationName": "Greenpath - Shrubbery",
      "reachable": true
    }
  ]
}
```

Errors: `503 ws_disconnected`

---

#### `GET /slots/{slot}/spoiler`
Full item placement for one slot via `LocationScouts` (no hint cost).
```jsonc
{ "slot": 1, "placements": [ LocationPlacement, ... ] }
```

Errors:
- `503 ws_disconnected`
- `422 race_mode_active`

---

#### `GET /spoiler`
Full item placement for all slots.
```jsonc
{ "placements": [ LocationPlacement, ... ] }
```

Errors:
- `503 ws_disconnected`
- `422 race_mode_active`

---

#### `GET /spheres`
Static sphere breakdown from empty inventory. Expensive; result cached until
bridge restart.
```jsonc
{
  "cached": false,
  "spheres": [
    {
      "index": 0,
      "checks": [ CheckDetail, ... ],
      "items": [
        {
          "itemId": 67890,
          "itemName": "Mothwing Cloak",
          "itemClassification": ["progression"],
          "receivingSlot": 1,
          "receivingPlayerName": "Jean"
        }
      ]
    }
  ]
}
```

Errors: `422 race_mode_active`

---

## WebSocket Protocol

### Connection

```
ws://host:5000/ws?token={INTERNAL_TOKEN}
```

On connection the bridge immediately sends a `snapshot` before any other
messages.

---

### Message envelope

**Notification** — bridge → clients, no response expected:
```jsonc
{ "type": "...", ...payload }
```

**Request** — bridge → clients, response expected, has `id`:
```jsonc
{ "id": "req-a1b2", "type": "request", "action": "...", ...payload }
```

**Response** — clients → bridge, correlated by `id`:
```jsonc
{ "id": "req-a1b2", "type": "response", ...payload }
```

**Command** — clients → bridge, optional `id` for ack:
```jsonc
{ "id": "cmd-c3d4", "type": "command", "text": "!admin /release Jean" }
```

**Ack** — bridge → client, only when `id` was provided:
```jsonc
{ "id": "cmd-c3d4", "type": "ack", "queued": true }
```

---

### Notifications (bridge → all clients)

#### `snapshot`
Sent immediately on connection.
```jsonc
{
  "type": "snapshot",
  "sessionId": "abc123",
  "room": { ...same shape as GET /room... },
  "slots": [ SlotSummary, ... ],
  "wsConnected": true
}
```

#### `state_changed`
Any progression change (check, item, goal, status, connection).
```jsonc
{ "type": "state_changed", "sessionId": "abc123", "slots": [ SlotSummary, ... ] }
```

#### `room_updated`
Room properties changed (e.g. a client connected and activated DeathLink).
```jsonc
{ "type": "room_updated", "sessionId": "abc123", "room": { ...same shape as GET /room... } }
```

#### `feed`
One AP feed event.
```jsonc
{
  "type": "feed",
  "sessionId": "abc123",
  "event": {
    "type": "item_sent",      // FeedEventType
    "text": "Marie found Mothwing Cloak for Jean",
    "color": "cyan",
    "timestamp": "2026-05-19T14:30:00Z"
  }
}
```

DeathLink feed event:
```jsonc
{
  "type": "feed",
  "sessionId": "abc123",
  "event": {
    "type": "death_link",
    "source": "Jean",
    "cause": "Fell into void",   // null if not provided
    "timestamp": "2026-05-19T14:30:00Z"
  }
}
```

#### `hints_changed`
```jsonc
{
  "type": "hints_changed",
  "sessionId": "abc123",
  "slot": 1,
  "hints": [ HintDetail, ... ],
  "budget": HintBudget
}
```

#### `reachable_changed`
```jsonc
{ "type": "reachable_changed", "sessionId": "abc123", "slot": 1, "reachableNow": 14 }
```

#### `lifecycle`
```jsonc
// Pause completed
{ "type": "lifecycle", "sessionId": "abc123", "event": "paused", "saveKey": "20260519T143000.apsave", "failedSave": false }

// AP is back up
{ "type": "lifecycle", "sessionId": "abc123", "event": "restarted" }

// AP failed to restart
{ "type": "lifecycle", "sessionId": "abc123", "event": "restart_failed" }
```

#### `heartbeat`
```jsonc
{ "type": "heartbeat", "sessionId": "abc123", "wsConnected": true }
```

---

### Requests (bridge → clients, response required)

#### `approve_restart`
Wake-on-connect: a player TCP-connected while the session is paused. The bridge
waits up to **5 s** for a response. No response or `approved: false` aborts the
restart.

```jsonc
// Bridge sends
{ "id": "req-a1b2", "type": "request", "action": "approve_restart" }

// Client responds
{ "id": "req-a1b2", "type": "response", "approved": true }
```

---

### Commands (clients → bridge)

#### AP command
```jsonc
{ "id": "cmd-c3d4", "type": "command", "text": "!admin /collect Jean" }

// Ack
{ "id": "cmd-c3d4", "type": "ack", "queued": true }

// Error (if ws_disconnected)
{ "type": "error", "id": "cmd-c3d4", "code": "ws_disconnected" }
```

---

## Save Key Convention

The bridge uploads saves with a bare filename:
```
{timestamp}.apsave      e.g. 20260519T143000.apsave
```
`timestamp` = UTC datetime as `YYYYMMDDTHHmmss`.

The calling application is responsible for namespacing within its own storage
hierarchy. The bridge returns and accepts only the bare filename.

---

## Error Codes

| Code | Meaning |
|---|---|
| `unauthorized` | Missing or invalid `INTERNAL_TOKEN` |
| `ws_disconnected` | Bridge is not connected to the AP server |
| `slot_not_found` | Requested slot index does not exist |
| `ap_timeout` | AP server did not respond in time (LocationScouts) |
| `race_mode_active` | Endpoint not available in race mode |
| `storage_unavailable` | S3 storage not configured or unreachable |
| `ap_launch_not_configured` | `AP_LAUNCH_CMD` is empty, cannot resume |
| `invalid_apworld` | Uploaded file is not a valid apworld archive |
| `already_exists` | An apworld for this game is already installed |
| `job_already_running` | A generation job is already in progress |
| `no_yamls` | No YAML files provided to `/operator/generate` |
| `generate_cmd_not_configured` | `AP_GENERATE_CMD` is empty |
| `start_cmd_not_configured` | `AP_START_CMD` is empty |
| `yaml_parse_error` | One or more player YAML files failed to parse |
| `generation_failed` | AP generation subprocess exited with an error |
| `already_running` | AP server is already running |
| `not_running` | AP server is not running |
| `seed_not_found` | Requested seed file does not exist in `AP_OUTPUT_DIR` |
| `server_health_timeout` | AP server did not become reachable within 30 s |
