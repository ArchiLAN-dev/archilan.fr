# Epic 9: Archipelago Session Management

Admins can generate Archipelago multiworld sessions from confirmed event registrations, launch dedicated server containers automatically, and players receive their connection details in real time - end to end, without manual file uploads.

## Story 9.1: Multi-Slot Registration Model

As a registered player,
I want to select the same game multiple times with different configurations,
So that I can participate with multiple independent slots in the same multiworld session.

**Acceptance Criteria:**

**Given** a player is configuring their game selection for an event
**When** they add a game that is already in their selection
**Then** a new independent slot is created for that game with its own option configuration
**And** each slot for the same game can have different option values
**And** slot order is preserved and displayed consistently
**And** existing registrations are migrated without data loss - each selected game becomes slot index 1
**And** the admin registration detail view shows the full slot breakdown including index and per-slot options
**And** the export includes one row per slot rather than one row per game
**And** functional tests cover multi-slot selection for the same game by a single player

## Story 9.2: Game YAML Template Management

As an admin,
I want to define a default YAML template for each game in the library,
So that session generation can produce valid Archipelago YAML without requiring manual file uploads.

**Acceptance Criteria:**

**Given** a game exists in the Archipelago game library
**When** an admin edits the game
**Then** they can set the exact Archipelago game name (the string the generator expects, e.g. "Hollow Knight")
**And** they can define default YAML option values as key-value pairs matching the game's Archipelago option schema
**And** the system shows a live preview of the YAML that would be generated for a slot using those defaults
**And** a game with no Archipelago game name set is flagged as "not ready for session generation"
**And** saving an empty Archipelago game name on a previously configured game is rejected with a validation error
**And** functional tests cover template persistence and preview YAML output

## Story 9.3: Runner Message Consumer Foundation

As the system,
I want Symfony Messenger consumers deployable on runner servers that can manage Docker containers,
So that Archipelago run operations are distributed across multiple runners without a separate microservice.

**Acceptance Criteria:**

**Given** a runner server has the Symfony API codebase deployed and RabbitMQ credentials configured
**When** `php bin/console messenger:consume run_generation run_server` is executed on the runner
**Then** the worker connects to the central RabbitMQ and processes jobs from the assigned queues
**And** the runner has access to the Docker daemon via the Docker CLI (`docker` installed and socket accessible)
**And** the `GenerateRunJob`, `StartRunJob`, `StopRunJob`, and `RestartRunJob` message classes are defined in `src/Sessions/Application/Message/`
**And** each message carries a `sessionId` and relevant parameters for the operation
**And** upon job completion or failure, the handler POSTs a callback to the central API at `POST /api/v1/internal/sessions/{id}/runner-callback` authenticated by shared secret (`CENTRAL_API_SECRET` env var)
**And** the runner's identity is configured via `RUNNER_ID` env var and included in every callback payload
**And** Docker port allocation uses a pool derived from `PORT_RANGE_START` and `PORT_RANGE_END` env vars, with in-process tracking of currently allocated ports
**And** on worker startup, the port pool is reconstructed by running `docker ps --filter name=archipelago-run- --format '{{.Ports}}'` to identify ports already bound to existing run containers
**And** only one Messenger worker consuming from the `run_server` queue must run per runner - running multiple workers on the same runner would cause port pool conflicts; this is documented as an operational constraint
**And** all handler log output uses `LoggerInterface` with structured context: `session_id`, `runner_id`, `action`
**And** functional tests cover message dispatch from the central API and handler invocation with a mocked Docker client

## Story 9.4: Pre-flight Validation and YAML Generation

As an admin,
I want to validate all player slots and generate their YAML files before committing to a run,
So that configuration errors are caught early and generation never fails silently.

**Acceptance Criteria:**

**Given** confirmed registrations exist for an event, each with at least one slot
**When** an admin clicks "Valider et préparer la génération" in the admin run UI
**Then** the API creates a `Run` entity in status `validating`, associates it with the event, and dispatches a `GenerateRunJob{phase: validate}` via Messenger
**And** the Messenger handler on the runner validates every slot: game has an Archipelago game name, all required options have a value or default, slot name is ≤ 16 characters and unique within the session
**And** if any slot fails validation, the handler POSTs an error callback containing a structured list of errors per slot; the API transitions the Run to `draft` and stores the errors
**And** the admin sees the error list per slot in the backoffice and can correct slot names (within the 16-character limit) before retrying
**And** if all slots pass, the handler writes one YAML file per slot to `/workspace/{runId}/yamls/` on the runner, then POSTs a success callback
**And** the API transitions the Run to `ready` on a success callback
**And** slot names are auto-generated from player display name and a game abbreviation derived from the first letter of each word in the Archipelago game name, truncated to 3 characters (e.g. "Hollow Knight" → "HK", "A Link to the Past" → "ALT"), with collision resolution by appending an incrementing index (e.g. `Alice_HK1`, `Alice_HK2`)
**And** functional tests cover: all-valid slots, one invalid slot blocking validation, slot name collision resolution, and well-formed YAML content structure

## Story 9.5: Multiworld Generation Pipeline

As the system,
I want the runner to execute the Archipelago generator on the prepared YAML files and notify Symfony of the outcome,
So that the multiworld seed file is ready for server launch.

**Acceptance Criteria:**

**Given** a Run is in status `ready` with YAML files present on the runner workspace
**When** an admin confirms generation in the backoffice
**Then** the API transitions the Run to `generating` and dispatches a `GenerateRunJob{phase: generate}` via Messenger
**And** the Messenger handler on the runner executes `docker run --rm -v {workspace}/yamls:/yamls -v {workspace}/output:/output archipelago-generate --player_files_path /yamls --outputpath /output` as a subprocess
**And** before dispatching, Symfony generates a random integer seed and stores it on the Run entity; the handler passes `--seed {seed}` to the docker run command so the generation is reproducible
**And** generation is bounded to a 5-minute timeout; exceeding the timeout sends a `failed` callback with a timeout reason
**And** on success: the `.archipelago` output file exists in `/workspace/{runId}/output/`, and the handler POSTs a success callback with the file path
**And** on failure: the full stderr output (containing all Archipelago-accumulated errors) is captured and included in the error callback
**And** the API transitions the Run to `generated` on success, or to `failed` with stored error details on failure
**And** the admin can view the full error output in the backoffice when generation fails
**And** the `archipelago-generate` Docker image must be pre-built from the official Archipelago repository and tagged as `archipelago-generate` on each runner (documented as a deployment prerequisite)
**And** functional tests cover: success path with correct output file, error capture on generate failure, timeout handling

## Story 9.6: Server Lifecycle - Launch, Health Monitoring and Auto-recovery

As the system,
I want the runner to launch a persistent Archipelago server container, monitor its health, and support restart on crash,
So that a session stays available to players and recovers automatically from transient failures.

**Acceptance Criteria:**

**Given** a Run is in status `generated` with the `.archipelago` file on the runner workspace
**When** an admin triggers server launch in the backoffice
**Then** the API transitions the Run to `launching` and dispatches a `StartRunJob` via Messenger to the runner that owns the session
**And** the handler allocates two ports from the pool: `port` (game server, mapped to container 38281) and `bridge_port` (Bridge.py REST API, mapped to container 5000)
**And** the handler executes `docker run -d --name archipelago-run-{runId} -p {port}:38281 -p {bridge_port}:5000 -v {workspace}/output:/archipelago/output:ro -v {workspace}/saves:/archipelago/saves -e SERVER_PASSWORD={password} -e RUN_ID={runId} -e CENTRAL_API_SECRET={CENTRAL_API_SECRET} -e SYMFONY_INTERNAL_URL={SYMFONY_INTERNAL_URL} -e MERCURE_HUB_URL={MERCURE_HUB_URL} archipelago-server`
**And** the password is auto-generated (32 random alphanumeric characters) and stored encrypted on the Run entity
**And** the handler POSTs a success callback to the central API containing: `runner_id`, `host` (runner hostname/IP), `port`, `bridge_port`, `container_id`, `password`
**And** the API transitions the Run to `running` on successful callback and stores `runner_id`, `host`, `port`, `bridge_port`, `password`
**And** the `archipelago-server` Docker image must be pre-built with MultiServer.py and Bridge.py included, tagged as `archipelago-server` on each runner (documented as a deployment prerequisite)
**And** the handler schedules periodic health checks (every 30 seconds) by re-dispatching a `RunHealthCheckJob` to its own queue with the session ID
**And** the health check handler attempts a TCP connection to `localhost:{port}`; three consecutive failures dispatch a `RunCrashedCallback` to the central API
**And** the API transitions the Run to `crashed` on a crash callback and returns the port to the available pool
**And** `RestartRunJob` stops the container, re-launches it using the same workspace files, and assigns a new port from the pool
**And** `StopRunJob` stops and removes the container (`docker stop` then `docker rm`) and returns the port to the pool
**And** functional tests cover: launch with port selection, health check failure sequence (3 consecutive → crashed), restart, and stop with port pool verification

## Story 9.7: Session Lifecycle API and Realtime Status

As the system,
I want ArchiLAN's Symfony API to own the session state machine and broadcast status changes via Mercure,
So that admins and players receive live updates without polling.

**Acceptance Criteria:**

**Given** a `Sessions` bounded context exists in the Symfony API
**When** a session changes state
**Then** the transition is persisted in a `sessions` table with `id`, `event_id`, `status`, `host`, `port`, `bridge_port`, `password`, `seed`, `created_at`, `started_at`, `stopped_at`, `finished_at`
**And** associated slots are persisted in `session_slots` with `session_id`, `registration_id`, `game_id`, `slot_name`, `slot_order`
**And** the runner notifies the API of status changes via `POST /api/v1/internal/sessions/{id}/runner-callback` authenticated by `CENTRAL_API_SECRET` shared secret header
**And** on each status change the API publishes a Mercure event on topic `/sessions/{id}`
**And** the full status machine is enforced: `draft → validating → ready → generating → generated → launching → running → stopped | failed | crashed | finished`
**And** the `finished` status is reachable only from `running` (via admin force-end or all-GOAL detection) and triggers archival (Story 9.16); `finished_at` is set when the Run transitions to `finished`
**And** session records and slot assignments are preserved in the database after the container is destroyed
**And** functional tests cover the full status machine including `finished`, callback authentication, and Mercure event publication

## Story 9.8: Admin UI - Session Creation, Monitoring and Controls

As an admin,
I want a dedicated session management page per event in the backoffice,
So that I can create, monitor, control, and audit Archipelago sessions without leaving the ERP.

**Acceptance Criteria:**

**Given** an event has confirmed registrations
**When** an admin opens `/admin/evenements/{eventId}/session`
**Then** they can initiate session creation from confirmed registrations
**And** pre-flight validation results are displayed per slot before the admin confirms generation
**And** the slot review table shows player name, game, and slot name - slot names are editable inline within the 16-character limit
**And** generation and launch progress are shown in real time via SSE without page refresh
**And** a running session displays host, port, and password with copy buttons and a "Stopper" control (graceful stop - container preserved, run can be restarted); force-end and commands are in Story 9.15
**And** a crashed session displays the error and a "Redémarrer" control
**And** a ZIP download of generated YAMLs is available after generation for debugging
**And** a session history section lists all past sessions for the event with status, date, and duration
**And** functional tests cover session creation, pre-flight error display, slot name editing, and stop/restart flows

## Story 9.9: Player Notifications - Email and Realtime Dashboard Alert

As a confirmed registrant,
I want to be notified automatically when my Archipelago session goes live,
So that I know exactly when and how to connect without checking the site manually.

**Acceptance Criteria:**

**Given** a session transitions to the `running` state
**When** the status change is processed
**Then** an email is sent to every confirmed registrant containing the event name, their slot name(s), host, port, and password
**And** the email includes a brief guide on how to connect with an Archipelago client
**And** a realtime dashboard notification is published via Mercure on the player's private topic
**And** failed email delivery is retried up to 3 times with exponential backoff before marking delivery as failed
**And** no notification is sent for `stopped`, `failed`, or `crashed` state transitions
**And** the notification is not re-sent if the session is restarted after a crash
**And** functional tests cover email content per slot, retry logic, and the no-duplicate-notification rule

## Story 9.10: Player Connection View

As a confirmed registrant,
I want to see my Archipelago connection details from my account area,
So that I can retrieve my slot name, server address, and password at any time during the event.

**Acceptance Criteria:**

**Given** an authenticated player has a confirmed registration for an event with an active or past session
**When** they view the event page or their account session tab
**Then** they see each of their slots with game name, slot name, host, port, and password
**And** each field has a copy-to-clipboard button
**And** if the session is in `generating` or `launching` state a live status indicator is shown via SSE - no page refresh needed
**And** if no session exists for the event a neutral "Aucune session active" message is shown
**And** if the session is `stopped` or `completed` the connection info remains visible as read-only history
**And** a player with a pending or cancelled registration cannot access session connection details
**And** functional tests cover slot isolation (player sees only their own slots), live state display, and access control

## Story 9.11: Traefik HTTP Provider - Dynamic WS Routing

As the system,
I want Symfony to expose a Traefik-compatible HTTP provider endpoint for Archipelago sessions,
So that WebSocket connections are routed automatically to the correct runner without manual Traefik configuration.

**Acceptance Criteria:**

**Given** Traefik is configured to poll `GET /internal/traefik` on the central Symfony API
**When** Traefik polls the endpoint
**Then** the response is a JSON object in Traefik HTTP provider format containing one router and one service entry per Run in `running` status
**And** each router matches the host rule `Host(\`{runId}.ws.archilan.fr\`)`, uses the `websecure` entrypoint (port 443, TLS), and routes to a backend service pointing to `{runner_host}:{port}`
**And** the endpoint is protected by a shared secret header (`X-Traefik-Token`); requests with an incorrect or missing token return 401
**And** the endpoint returns a valid empty Traefik config (not an error response) when no runs are in `running` status
**And** runs in any status other than `running` are excluded from the generated config
**And** the wildcard TLS certificate for `*.ws.archilan.fr` and Traefik's `websecure` entrypoint are configured in Traefik static config (outside the scope of this story - documented as deployment prerequisites); the recommended polling interval is 5 seconds
**And** Symfony exposes `GET /api/v1/internal/sessions/{runId}/publisher-token` authenticated by `X-Internal-Secret: {CENTRAL_API_SECRET}`; it generates and returns a short-lived Mercure publisher JWT (TTL 1h) signed with `MERCURE_JWT_SECRET`, scoped to publish on topics `runs/{runId}/*` only, as `{"token": "...", "expires_at": "..."}`
**And** functional tests cover: single active run generates correct router/service entry, multiple concurrent runs generate multiple entries, non-running runs are excluded, publisher-token endpoint returns correctly scoped JWT, invalid secret returns 401

## Story 9.12: Bridge.py - Real-Time Observer Service

As the system,
I want a Bridge.py service running inside each Archipelago server container,
So that game events are published to Mercure in real time and admin commands can be forwarded to the server.

**Acceptance Criteria:**

**Given** an Archipelago server container is running and `MERCURE_HUB_URL`, `CENTRAL_API_SECRET`, `SYMFONY_INTERNAL_URL`, and `RUN_ID` are set as container environment variables (injected by the runner at launch - no long-lived Mercure secret in the container)
**When** Bridge.py starts alongside MultiServer.py via the container entrypoint script
**Then** Bridge.py connects to `ws://localhost:38281` as a TextOnly Archipelago client with no game slot
**And** on receiving the initial `RoomInfo` packet, Bridge.py stores the total location count per slot (`RoomInfo.locations` array) so that checks can be displayed as "X / Total"
**And** for each `PrintJSON` packet received, Bridge.py POSTs to the Mercure hub on topic `runs/{runId}/feed` with a JSON payload: `{type, text, color, timestamp}`
**And** for each `StatusUpdate`, `ReceivedItems`, or `LocationChecks` packet, Bridge.py updates its in-memory player state aggregate per slot: `checks_done`, `checks_total`, `items_received`, `client_status`
**And** when a slot's `client_status` transitions to 30 (GOAL), Bridge.py records `goal_reached_at` as the current UTC timestamp on that slot's state
**And** on startup, before connecting to MultiServer.py, Bridge.py reads the `.apsave` file from the shared Docker volume (mounted at `/archipelago/saves/`) using `zlib.decompress` + `pickle.loads`, extracting `location_checks` and `client_game_state` to rebuild in-memory state (`checks_done`, `client_status`, `goal_reached_at` per slot); if the file is absent (first start) or unreadable, state initializes empty
**And** after each state update, Bridge.py POSTs the full aggregate to Mercure topic `runs/{runId}/players`; no persistent write to Symfony DB is needed - initial page load state is served via Bridge.py's own `/state` endpoint
**And** on startup, Bridge.py fetches a short-lived Mercure publisher JWT (TTL 1h) from Symfony by calling `GET /api/v1/internal/sessions/{runId}/publisher-token` (authenticated with `X-Internal-Secret: {CENTRAL_API_SECRET}`); Symfony generates the JWT server-side signed with `MERCURE_JWT_SECRET`, scoped to publish on topics `runs/{runId}/*` only
**And** Bridge.py schedules a token refresh every 50 minutes (before expiry), or immediately on receiving a 401 response from the Mercure hub, by re-calling the same endpoint; all Mercure POST requests include the current token as `Authorization: Bearer {token}`
**And** the `MERCURE_PUBLISHER_JWT` env var is NOT injected into the container - the container only needs `CENTRAL_API_SECRET`, `SYMFONY_INTERNAL_URL`, and `RUN_ID` to bootstrap its token lifecycle
**And** if the WebSocket connection to MultiServer.py drops, Bridge.py retries with exponential backoff (1s → 2s → 4s → 8s → max 30s) until reconnected; on reconnection it re-fetches `RoomInfo` to restore the location totals (in-memory player state is preserved across WS reconnects - `.apsave` is only read at process startup)
**And** Bridge.py exposes an internal REST API bound to `0.0.0.0:5000`: `POST /commands` accepts `{"command": "..."}` and forwards it as a WS `Say` packet to MultiServer.py; `GET /state` returns the current in-memory player aggregate as JSON (Symfony proxies this to frontend callers for initial page load)
**And** `GET /health` on port 5000 returns `{"status": "ok", "ws_connected": true|false}`
**And** all Bridge.py output is structured JSON logs to stdout with fields: `event`, `run_id`, `timestamp`, `severity`
**And** unit tests cover: `.apsave` parsing and in-memory state restoration, RoomInfo parsing for checks_total, PrintJSON → Mercure topic mapping, goal_reached_at timestamp on GOAL transition, player state aggregation, command forwarding via REST API, `/state` endpoint returns correct aggregate, publisher token fetch on startup, token refresh on 401 and on 50-minute schedule

## Story 9.13: Real-Time Event Feed

As a confirmed player or admin,
I want to see a real-time feed of Archipelago game events on the session page,
So that I can follow the session's progress without connecting an Archipelago client.

**Acceptance Criteria:**

**Given** a Run is in `running` status and Bridge.py is publishing events to Mercure topic `runs/{runId}/feed`
**When** an authenticated confirmed registrant or admin opens the session feed page
**Then** the Next.js page calls `GET /api/v1/sessions/{runId}/feed-token`; Symfony verifies that the requester is a confirmed registrant for this event or an admin, then generates and returns a short-lived Mercure subscriber JWT (TTL 1h) scoped to subscribe on topic `runs/{runId}/feed` only
**And** the page subscribes to `runs/{runId}/feed` via EventSource using the obtained JWT
**And** each incoming event is prepended to a scrollable feed list showing: formatted timestamp, message type badge, and text content
**And** message types are styled distinctly: hint → amber, item-received → teal, location-checked → blue, system → muted gray, chat → foreground white
**And** the feed displays the 100 most recent messages; older messages are removed from the DOM as new ones arrive
**And** on first page load, the feed starts empty - Mercure is real-time only (no history replay by default); a static notice "Les messages apparaîtront en direct" is shown until the first event arrives
**And** if the EventSource connection closes, a subtle disconnected indicator appears and automatic reconnection is attempted after 5 seconds
**And** the Mercure subscriber JWT is passed as a query parameter `?authorization=...` in the EventSource URL (EventSource does not support custom headers in browsers)
**And** unauthenticated users or non-registrants receive 403 from the feed-token endpoint and are shown an access denied state
**And** the feed is accessible both from the player view at `/evenements/{slug}/session` and from the admin session management page
**And** functional tests cover: feed-token JWT generation (correct topic scope, TTL, registrant ✅, non-registrant ❌, admin ✅), message delivery end-to-end from Bridge.py POST to EventSource client

## Story 9.14: Player Progress Dashboard

As a confirmed player or admin,
I want a real-time player progress dashboard showing each slot's checks, items, and connection status,
So that I can monitor the overall session at a glance without reading the event feed.

**Acceptance Criteria:**

**Given** a Run is in `running` status and Bridge.py is publishing state to Mercure topic `runs/{runId}/players`
**When** an authenticated confirmed registrant or admin opens the session dashboard
**Then** the page first calls `GET /api/v1/sessions/{runId}/players` (requires auth; Symfony proxies the request to `GET http://{runner_host}:{bridge_port}/state` on Bridge.py and returns the current in-memory player aggregate) to render the initial grid without waiting for a Mercure event
**And** the page calls `GET /api/v1/sessions/{runId}/players-token`; Symfony applies the same authorization as for feed-token (confirmed registrant or admin) and returns a short-lived subscriber JWT (TTL 1h) scoped to topic `runs/{runId}/players` only; the page subscribes via EventSource for live updates
**And** each slot card shows: player display name, game name, slot name, checks completed (X / Total where Total comes from `checks_total` provided by Bridge.py), items received count, and a client status badge
**And** ClientStatus values map to labels: UNKNOWN → "Hors ligne", CONNECTED → "Connecté", READY → "Prêt", PLAYING → "En jeu", GOAL → "Objectif atteint !"
**And** slots with ClientStatus GOAL (30) display a distinct visual indicator (accent color border and checkmark icon)
**And** the grid updates in real time as Bridge.py publishes state changes - no page refresh needed
**And** slots are sorted: GOAL slots first (ordered by `goal_reached_at` ascending), then by checks_done descending
**And** a disconnected indicator is shown if the EventSource closes, with automatic reconnection
**And** functional tests cover: initial state load via Bridge.py `/state` proxy, players-token JWT generation (correct topic scope, TTL, same auth rules as feed-token), real-time update on StatusUpdate, GOAL detection and sorting

## Story 9.15: Admin Server Commands and Log Viewer

As an admin,
I want to send commands to the Archipelago server and view container logs in real time,
So that I can manage the session without SSH access to the runner.

**Acceptance Criteria:**

**Given** a Run is in `running` status
**When** an admin opens the admin session management page
**Then** they see a command input form where they can type Archipelago server commands (e.g. `!hint PlayerName game`, `/release PlayerName`, `/collect PlayerName`, free-text broadcast)
**And** submitting a command calls `POST /api/v1/admin/sessions/{id}/commands` with `{"command": "..."}`, which Symfony validates and forwards to Bridge.py's internal REST API `POST /commands`, which Bridge.py sends as a WS `Say` packet to MultiServer.py
**And** the command endpoint requires admin authentication; non-admin requests return 403
**And** a "Voir les logs" action opens a log viewer panel and immediately dispatches a `FetchLogsJob` via Messenger to the owning runner; the runner executes `docker logs --tail 200 --timestamps archipelago-run-{runId}` and POSTs the output back via callback; the API stores the result and returns it to the admin page
**And** while the log panel is open, the frontend automatically re-polls `GET /api/v1/admin/sessions/{id}/logs` every 10 seconds, triggering a new `FetchLogsJob` dispatch; each refresh replaces the panel content with the latest 200 lines
**And** the log viewer panel displays the 200 most recent log lines in a fixed-height monospace scrollable area; polling stops automatically when the panel is closed
**And** the `admin/sessions/{id}/logs` topic Mercure subscriber JWT is scoped to admins only
**And** a "Forcer la fin de la run" button (distinct from the "Stopper" control in Story 9.8 which only pauses the run) requires confirmation via AlertDialog, then POSTs to `POST /api/v1/admin/sessions/{id}/force-end`, which transitions the Run to `finished`, triggers archival (Story 9.16), and dispatches a `StopRunJob` to the runner
**And** all command and force-end actions are recorded in a run audit log with admin user ID and timestamp
**And** functional tests cover: command routing chain (API → Bridge REST → assertion on WS send), log fetch dispatch and delivery via Mercure, force-end with confirmation and StopRunJob dispatch

## Story 9.16: Run Archival and Statistics

As an admin or player,
I want the session's spoiler log, save file, and statistics archived when the run ends,
So that results are preserved and publicly accessible as history.

**Acceptance Criteria:**

**Given** a Run transitions to status `finished` (via all GOAL or admin force-end)
**When** the Run status is persisted as `finished`
**Then** the API dispatches an `ArchiveRunJob` via Messenger to the runner that owns the run
**And** the runner handler copies the `.apsave` file from the save volume to a permanent archive directory (local path or S3-compatible storage configured via env); the spoiler log (if present in the output volume) is also copied
**And** before stopping the container, the handler calls `GET http://{runner_host}:{bridge_port}/state` to capture the final player aggregate from Bridge.py's in-memory state (this is authoritative - the `.apsave` on disk may be up to 60s behind); if Bridge.py is unreachable, the handler falls back to parsing the `.apsave` file directly
**And** the handler POSTs an archive callback to the central API containing: archive file paths, final per-slot stats, and confirmation of archival success
**And** the API stores on the `sessions` record: `archived_spoiler_path`, `archived_save_path` (the `finished_at` field is already set when the Run transitions to `finished` in Story 9.7); per-slot stats (`checks_done`, `items_received`, `goal_reached_at`) are stored on `session_slots` from the final state snapshot received in the archive callback
**And** a public results page is available at `/evenements/{slug}/session/resultats` showing the most recent `finished` session: run duration (`started_at` → `finished_at`), ranked list of slots (GOAL slots by `goal_reached_at` ascending, non-GOAL slots below), checks and items per slot
**And** if an event has multiple past sessions (test runs, reruns), an admin-visible session history selector allows navigating between them; the public page always shows the most recent `finished` session
**And** the results page is publicly accessible (no authentication required) once the Run is `finished`
**And** admins can download the spoiler log and `.apsave` file from the admin session page via authenticated download endpoints
**And** `GET /api/v1/admin/sessions/{id}/export?format=json` and `?format=csv` return per-slot stats (slot name, player, game, checks_done, items_received, goal_reached_at)
**And** functional tests cover: ArchiveRunJob dispatch on finish, stats persistence per slot, public results page accessibility by unauthenticated user, export JSON/CSV format correctness
