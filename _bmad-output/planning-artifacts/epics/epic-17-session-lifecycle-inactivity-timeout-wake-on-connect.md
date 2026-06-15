# Epic 17: Session Lifecycle - Inactivity Timeout & Wake-on-Connect

Sessions (event-based and personal) auto-stop their Archipelago process after 1 hour of inactivity. The container stays alive; the bridge enters a wake-on-connect mode (TCP listener on the AP port). The first player connection attempt automatically restarts the AP process - no admin action required. An explicit "Reprendre" UI trigger is also supported. MinIO backup provides a safety net if the container is ever fully stopped.

**FRs covered:** FR-IT1, FR-IT2, FR-IT3, FR-IT4, FR-IT5, FR-IT6, NFR-IT1, NFR-IT2, NFR-IT3, NFR-IT4.

## Story 17.1: Activity Tracking on Sessions

As a system operator,
I want sessions to track when they last had Archipelago activity,
So that the inactivity watchdog has a reliable signal for when to pause a run.

**Acceptance Criteria:**

**Given** the Bridge.py service receives an Archipelago event (ItemSent, LocationChecked, or any game-state event)
**When** the event is processed
**Then** Bridge.py calls `PATCH /api/v1/sessions/{sessionId}/activity` (internal endpoint, bearer-token protected) with `{ "activityType": "check"|"item"|"hint", "occurredAt": "<ISO8601>" }`
**And** the Symfony API updates `sessions.last_activity_at` to the provided `occurredAt` timestamp (or server time if not provided)

**Given** a session is created (status `running`)
**When** no activity event has been received yet
**Then** `last_activity_at` defaults to the session `started_at` timestamp

**Given** the activity endpoint is called with an invalid or unknown `sessionId`
**When** the request is processed
**Then** the response is 404

**And** a Doctrine migration adds column `last_activity_at datetimetz_immutable DEFAULT NULL` to the `sessions` table
**And** the endpoint requires a machine-to-machine bearer token configured via `BRIDGE_INTERNAL_TOKEN` env var (not a user JWT)
**And** functional tests cover: activity update on existing session, default value equals started_at, unknown session (404), unauthenticated request (401)

## Story 17.2: Inactivity Watchdog - AP Process Stop & Wake-on-Connect Activation

As a system operator,
I want idle sessions to have their Archipelago process stopped automatically after 1 hour without activity,
So that CPU and game-server RAM are freed while the container stays alive for instant wake-on-connect.

**Acceptance Criteria:**

**Given** a Symfony Messenger scheduled message fires every 5 minutes
**When** the `InactivityWatchdogMessage` handler runs
**Then** it queries all sessions with status `running` where `last_activity_at < NOW() - INTERVAL ARCHIPELAGO_INACTIVITY_TIMEOUT_SECONDS`
**And** for each such session, it dispatches a `PauseRunJob` to the owning runner

**Given** the runner receives a `PauseRunJob`
**When** the job executes
**Then** it calls Bridge.py's internal `POST /pause` endpoint
**And** Bridge.py triggers an Archipelago `/save` command and waits for the `.apsave` file to be written (timeout: 30s)
**And** Bridge.py uploads the `.apsave` file to MinIO at key `sessions/{sessionId}/saves/{timestamp}.apsave` as a safety-net backup
**And** Bridge.py kills the Archipelago process (SIGTERM, then SIGKILL after 5s if still alive)
**And** Bridge.py starts a TCP listener on the AP port (wake-on-connect mode)
**And** Bridge.py calls `POST /api/v1/sessions/{sessionId}/paused` (internal, bearer-token protected) with `{ "lastSaveKey": "<minio-key>", "pausedWithoutSave": false }`
**And** the Symfony API transitions the session status from `running` to `idle` and stores `last_save_key`
**And** the container is NOT stopped - it remains running with only the bridge alive

**Given** `ARCHIPELAGO_INACTIVITY_TIMEOUT_SECONDS` is not set
**When** the watchdog evaluates sessions
**Then** it defaults to 3600 seconds (1 hour)

**Given** Bridge.py's `/save` call times out (AP process unresponsive)
**When** the PauseRunJob handles the timeout
**Then** Bridge.py kills the AP process anyway, enters wake-on-connect mode, and calls the paused callback with `{ "pausedWithoutSave": true }`
**And** the session is marked `idle` with `paused_without_save = true`
**And** an admin notification is dispatched via Messenger

**And** a Doctrine migration adds columns `last_save_key VARCHAR(500) DEFAULT NULL` and `paused_without_save BOOLEAN NOT NULL DEFAULT FALSE` to `sessions`
**And** the `InactivityWatchdogMessage` is configured as a Symfony Scheduler recurring message (every 5 minutes)
**And** functional tests cover: session below threshold (not paused), session above threshold (PauseRunJob dispatched), save timeout path (paused_without_save=true), default timeout value

## Story 17.3: Explicit Session Restart from UI ("Reprendre")

As a run owner or admin,
I want to explicitly restart a paused session from the UI,
So that I can resume a game without waiting for a player connection.

**Acceptance Criteria:**

**Given** an authenticated user calls `POST /api/v1/sessions/{sessionId}/restart`
**When** the session exists with status `idle`, and the caller is either an admin or the owner of the personal run
**Then** the API calls Bridge.py's internal `POST /resume` endpoint (bearer-token protected) directly on the container
**And** the Symfony API transitions the session status to `restarting`
**And** the response is 202 with `{ "data": { "sessionId", "status": "restarting" } }`

**Given** Bridge.py receives `POST /resume`
**When** the bridge is in wake-on-connect mode (TCP listener active)
**Then** the bridge closes the TCP listener
**And** launches the Archipelago process using the most recent `.apsave` file on disk in the container (falling back to MinIO `last_save_key` if no local file exists)
**And** once the AP server reports ready (health check), Bridge.py calls `POST /api/v1/sessions/{sessionId}/restarted` (internal)
**And** the Symfony API transitions the session status from `restarting` to `running` and resets `last_activity_at` to NOW()

**Given** the session has `paused_without_save = true` and no local `.apsave` file exists
**When** the restart is attempted
**Then** the response is 422 with code `no_save_available`

**Given** a session with status `running` or `completed` is targeted
**When** the restart endpoint is called
**Then** the response is 422 with code `invalid_session_status`

**Given** a non-admin, non-owner authenticated user calls the restart endpoint
**When** the request is processed
**Then** the response is 403

**And** functional tests cover: successful restart dispatch (idle → restarting), bridge callback (restarting → running), no save available (422), already running (422), non-owner non-admin (403)

## Story 17.4: Frontend - Idle Session Status and Restart UI

As a run owner or admin,
I want to see clearly when a session is paused and have the option to restart it from the UI,
So that I can resume a game without waiting for a player connection.

**Acceptance Criteria:**

**Given** a personal run or event session has status `idle`
**When** the owner views `/runs/{runId}` or the admin views the session management page
**Then** the run card displays an "En pause" amber status badge
**And** a subtitle shows the time since last activity (e.g. "Inactif depuis 1h 23min")
**And** an info callout explains: "La partie redémarre automatiquement dès qu'un joueur tente de se connecter. Vous pouvez aussi la relancer manuellement."
**And** a "Reprendre manuellement" secondary button is visible
**And** if `paused_without_save` is true, the button is disabled with tooltip "Reprise impossible : aucune sauvegarde disponible"

**Given** the user clicks "Reprendre manuellement"
**When** the `POST .../restart` request is in flight
**Then** the button shows a loading spinner and is disabled to prevent double-submit
**And** the status badge updates to "Redémarrage en cours..."

**Given** the session transitions to `restarting` (triggered either by UI or by wake-on-connect)
**When** the frontend detects the status change (polling `GET .../` every 5s while restarting)
**Then** the status badge shows "Redémarrage en cours..." with a spinner

**Given** the session transitions back to `running`
**When** the frontend detects the status change
**Then** the "En pause" badge is replaced by the "En cours" active badge
**And** connection details are shown again
**And** a success toast "Partie reprise avec succès" appears

**Given** an admin views the backoffice session list
**When** one or more sessions have status `idle`
**Then** idle sessions appear in a dedicated "Sessions en pause" section above completed sessions
**And** the "Reprendre" action is available inline in the admin list

**And** all status polling uses TanStack Query with 5-second refetch interval while status is `restarting`
**And** polling stops once status returns to `running` or reaches a terminal state

## Story 17.5: Bridge - Wake-on-Connect TCP Listener

As a player,
I want the Archipelago server to restart automatically when I attempt to connect to a paused game,
So that I can resume playing without having to ask anyone to restart it.

**Acceptance Criteria:**

**Given** Bridge.py has killed the AP process due to inactivity (Story 17.2)
**When** the bridge enters idle mode
**Then** it opens a TCP server socket on `config.ap_port` (same port the AP server normally uses)
**And** the socket accepts one connection at a time in a non-blocking loop (does not block the bridge's main heartbeat or REST loops - runs in a dedicated thread or asyncio task)

**Given** the TCP listener is active and a client connects on the AP port
**When** the connection is accepted
**Then** Bridge.py immediately closes the accepted socket (the client receives a connection reset or empty response - this is expected and acceptable UX)
**And** Bridge.py closes the TCP listener socket (no longer listening)
**And** Bridge.py calls `POST /api/v1/sessions/{sessionId}/restarting` (internal, bearer-token protected) to notify Symfony
**And** Symfony transitions the session status from `idle` to `restarting`
**And** Bridge.py launches the AP process using the most recent `.apsave` file on disk (falling back to MinIO download if none found)
**And** once the AP server passes a health check (TCP connect succeeds on port), Bridge.py calls `POST /api/v1/sessions/{sessionId}/restarted`
**And** Symfony transitions the session status from `restarting` to `running` and resets `last_activity_at`

**Given** the AP process fails to start (crash at launch)
**When** the restart attempt fails
**Then** Bridge.py calls `POST /api/v1/sessions/{sessionId}/restart-failed` (internal)
**And** Symfony transitions the session to `idle` again and sets an error flag
**And** an admin notification is dispatched

**Given** Bridge.py itself crashes while in wake-on-connect mode (unexpected)
**When** the container is still alive but the bridge process is dead
**Then** the runner's health check detects a dead bridge (no heartbeat) and marks the session as `idle` with a `bridge_crashed` flag; no automatic recovery attempted - admin action required

**And** unit tests for the TCP listener: listener starts on correct port, connection triggers restart sequence, failed AP launch triggers error callback
**And** the TCP listener port is derived from `config.ap_port` (no new config required)
