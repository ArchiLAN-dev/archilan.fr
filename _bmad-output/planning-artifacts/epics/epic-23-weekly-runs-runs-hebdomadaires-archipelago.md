# Epic 23: Weekly Runs - Runs Hebdomadaires Archipelago

Members can join an admin-scheduled weekly Archipelago challenge that resets every Monday. Each participant generates their own individual Archipelago game on demand using a shared deterministic seed - everyone plays the same randomized world independently. Three leaderboards rank participants by fastest goal, fewest checks, and fewest items. Admins configure run templates using the existing YAML editor; the weekly scheduling is fully automated.

## Requirements: FR-WR1–FR-WR10, NFR-WR1–NFR-WR4

---

## Story 23.1: Weekly Run Domain Model & Bounded Context

*(Amended 2026-05-17: `WeeklyTemplate.game` replaced by `gameId` UUID; `WeeklyEntry.playerYaml` removed - admin YAML fixed for all players)*
*(Amended 2026-05-17: individual on-demand runs per player - no shared multiworld. WeeklyRun has no externalSessionId; WeeklyEntry gains externalSessionId + launchedAt; status simplified to `'active'|'finished'`.)*

As a developer,
I want a `WeeklyRuns` bounded context with `WeeklyTemplate`, `WeeklyRun`, and `WeeklyEntry` domain entities,
So that the weekly run lifecycle is cleanly encapsulated with no coupling to other bounded contexts.

**Context:**
A new `WeeklyRuns` bounded context is created. `WeeklyTemplate` defines the game, YAML config, and rules for a weekly challenge. `WeeklyRun` is one instantiation per ISO week. `WeeklyEntry` links one member attempt to a run and owns the runner session for that player's individual Archipelago game. Each player generates their own session on demand using the week's seed - there is no shared multiworld.

Cross-context coupling: `WeeklyRuns` never imports from `PersonalRuns`, `Sessions`, or `Events`. It references `GameSelection` only by UUID string (`gameId`). The runner is accessed through `WeeklyRunnerGatewayInterface` in `WeeklyRuns/Application/`.

**Domain entities:**

`WeeklyTemplate` (`final`, ORM-mapped):
- `id`: UUID varchar 36
- `name`: varchar 100, nullable (falls back to game name for display)
- `gameId`: varchar 36 (UUID of a `GameSelection\Domain\Game` - plain string, no Doctrine cross-context relation)
- `yamlConfig`: text (admin-configured YAML; placeholder name "ArchiLAN" substituted with player's displayName at launch time)
- `maxAttempts`: int nullable (`null` = unlimited attempts per member per week)
- `isActive`: bool default true
- `createdAt`, `updatedAt`: datetime_immutable

`WeeklyRun` (`final`, ORM-mapped):
- `id`: UUID varchar 36
- `templateId`: varchar 36 (plain string)
- `weekYear`: int (e.g. 2026) - ISO year via `format('o')`
- `weekNumber`: int (ISO 8601, 1–53)
- `seed`: varchar 100 (format: `archilan-weekly-{weekYear}-{weekNumber:02d}`)
- `status`: varchar 10 (`'active'|'finished'` - no `pending` phase)
- `startedAt`: datetime_immutable (set Monday 00:00 at creation - serves as leaderboard time baseline)
- `finishedAt`: datetime_immutable nullable
- `createdAt`: datetime_immutable

`WeeklyEntry` (`final`, ORM-mapped):
- `id`: UUID varchar 36
- `weeklyRunId`: varchar 36 (plain string)
- `userId`: varchar 36 (plain string)
- `attemptNumber`: int (1-based per user per `WeeklyRun`)
- `externalSessionId`: varchar 36 nullable (runner session ID for this player's individual game, set at launch)
- `launchedAt`: datetime_immutable nullable (set when player starts their session)
- `goalReachedAt`: datetime_immutable nullable
- `completionTimeSeconds`: int nullable (`goalReachedAt - run.startedAt` in seconds)
- `checksTotal`: int nullable
- `itemsTotal`: int nullable
- `createdAt`, `updatedAt`: datetime_immutable

**Acceptance Criteria:**

**Given** the monorepo has existing bounded contexts
**When** story 23.1 begins
**Then** `src/WeeklyRuns/{Domain,Application,Infrastructure,Presentation}/` directories are created
**And** `DddArchitectureValidator::CONTEXTS` includes `'WeeklyRuns'`
**And** `services.yaml` excludes `App\WeeklyRuns\Domain\` from autowiring
**And** Doctrine mapping is configured for `src/WeeklyRuns/Domain/`

**Given** the bounded context is set up
**When** the migration runs
**Then** a `weekly_templates` table is created with all fields, an index on `is_active`, and an index on `game_id`
**And** a `weekly_runs` table is created with all fields, a unique index on `(template_id, week_year, week_number)`, and an index on `status`
**And** a `weekly_entries` table is created with all fields, a unique index on `(weekly_run_id, user_id, attempt_number)`, an index on `(weekly_run_id, goal_reached_at)`, and an index on `(user_id)`
**And** no foreign key constraint is created between `weekly_templates.game_id` and `game.id` - the reference is intentionally loose (cross-context coupling via DB constraint is forbidden)

**Given** `WeeklyRunnerGatewayInterface` is defined in `WeeklyRuns/Application/`
**When** story 23.1 is complete
**Then** the interface declares:
- `launchEntry(string $weeklyEntryId, string $seed, string $apworldStorageKey, string $playerName, string $yaml): array` - returns `['externalSessionId' => string, 'connectionInfo' => ['host' => string, 'port' => int, 'password' => string|null]]`
- `terminate(string $externalSessionId): void`
- `getStats(string $externalSessionId): array` - returns `['checksTotal' => int, 'itemsTotal' => int, 'goalReachedAt' => ?string]`
**And** `NullWeeklyRunnerGateway` (stub, `when@test:`) is registered in `services.yaml`
**And** `HttpWeeklyRunnerGateway` (real implementation) is registered as default

**Given** domain methods are called on `WeeklyRun` and `WeeklyEntry`
**When** story 23.1 is complete
**Then** `WeeklyRun::finish(\DateTimeImmutable $finishedAt): void` sets `status = 'finished'` and `finishedAt`
**And** `WeeklyEntry::launch(string $externalSessionId, \DateTimeImmutable $launchedAt): void` sets `externalSessionId` and `launchedAt`
**And** `WeeklyEntry::recordGoal(\DateTimeImmutable $goalReachedAt, int $completionTimeSeconds, int $checksTotal, int $itemsTotal): void` sets all goal fields
**And** `WeeklyTemplate::deactivate(): void` sets `isActive = false`
**And** no method accepts a Symfony or infrastructure dependency (AC-D3)

**And** all four quality gates pass

---

## Story 23.2: Bridge/Runner Extension - Seed Parameter Support

As the system,
I want to pass a deterministic `--seed` argument to the Archipelago CLI during weekly run generation,
So that the same seed string always produces the same game world for a given week.

**Context:**
The existing generation pipeline (dispatched by `PersonalRuns` via a Messenger job to the Python bridge) does not pass a seed. Three layers need extension: (1) the PHP job message gains a nullable `seed` field; (2) the PHP handler includes `seed` in the job payload sent to the bridge; (3) the Python `runner/app/generator.py` appends `--seed <value>` to the Archipelago CLI invocation when the field is present and non-null. A `null` seed preserves current behaviour - random generation, PersonalRuns are unaffected.

To avoid coupling `WeeklyRuns/Application/` to `PersonalRuns/Application/Message/`, `GenerateRunJob` is moved from `PersonalRuns/Application/Message/` to `Shared/Application/Message/`. `PersonalRuns/Application/Handler/GenerateRunJobHandler` is updated to import from `Shared`. `DddArchitectureValidator` and `messenger.yaml` routing are updated accordingly. **This Messenger bus path is for PersonalRuns only.** The `WeeklyRuns` context does NOT dispatch `GenerateRunJob` through the bus - it launches player sessions directly via `HttpWeeklyRunnerGateway::launchEntry()` (an HTTP call to the runner, fully implemented in this story as AC4).

**Acceptance Criteria:**

**Given** `Shared/Application/Message/GenerateRunJob.php` exists (moved from PersonalRuns)
**When** story 23.2 is complete
**Then** `GenerateRunJob` has a new `seed: ?string` constructor parameter (default `null`)
**And** `PersonalRuns/Application/Handler/GenerateRunJobHandler.php` imports `GenerateRunJob` from `Shared\Application\Message\` - not from `PersonalRuns\Application\Message\`
**And** the old `PersonalRuns/Application/Message/GenerateRunJob.php` file is deleted
**And** `DddArchitectureValidator` allows `Shared\Application\Message\` imports from any Application layer
**And** all existing PersonalRuns functional tests pass unchanged (seed is `null` → same behaviour)

**Given** `GenerateRunJobHandler` serializes the job for the bridge
**When** `seed` is non-null
**Then** the JSON payload sent to the bridge includes `"seed": "<value>"`
**And** when `seed` is null the `seed` field is omitted from the payload

**Given** `runner/app/generator.py` receives a generation job payload
**When** the payload contains a `seed` field with a non-empty string value
**Then** the Archipelago CLI command includes `--seed <value>` as additional flags
**And** when the field is absent or null the command is unchanged

**Given** the same seed string is provided twice in separate weeks
**When** Archipelago generates both worlds
**Then** both produce identical game worlds (determinism is guaranteed by Archipelago's `--seed` flag - this is a smoke-test assertion in Dev Notes, not an automated test)

**Given** `runner/app/main.py` is updated
**When** `POST /sessions/{sessionId}/generate-and-launch` is called with `{ seed?, slots: [{ slotName, apworldStorageKey, playerYaml }] }`
**Then** the runner writes the YAML, runs Archipelago generation synchronously (awaited), launches the Docker container, and returns `{ sessionId, containerHost, containerPort, serverPassword }`
**And** `HttpWeeklyRunnerGateway::launchEntry()` calls this single endpoint with timeout ≥120s and maps the response to `['externalSessionId', 'connectionInfo']`

**Given** story 23.2 is complete
**When** `php bin/console app:architecture:ddd` runs
**Then** no layer violations are reported for the moved message type

**And** all four quality gates pass

---

## Story 23.3: Scheduler - Weekly Lifecycle Automation

*(Amended 2026-05-17: no LaunchWeeklyRunJob - runs start as `'active'` immediately; stop handler terminates per-entry sessions)*

As the system,
I want a fully automated weekly run lifecycle driven by Symfony Scheduler,
So that `WeeklyRun` records are created every Monday at 00:00 UTC and all still-running player sessions are stopped every Sunday at 23:59 UTC.

**Context:**
Two recurring entries in `src/Schedule.php`: (1) `cron('0 0 * * 1', new GenerateWeeklyRunsMessage())` - creates the weekly run record; (2) `cron('59 23 * * 0', new StopWeeklyRunsMessage())` - stops player sessions. No batch launch: player sessions are created on demand when a member clicks "Lancer ma partie" (Story 23.4).

`GenerateWeeklyRunsMessageHandler`: for each active template with no existing run this ISO week, creates `WeeklyRun` with `status = 'active'`, `startedAt = now`, `seed = 'archilan-weekly-{year}-{week:02d}'`. No runner call.

`StopWeeklyRunsMessageHandler`: for each active `WeeklyRun`, finds all `WeeklyEntry` with `externalSessionId IS NOT NULL AND goalReachedAt IS NULL`, calls `terminate(externalSessionId)` for each (best-effort, continues on error), then calls `WeeklyRun::finish(now)` and flushes.

**Acceptance Criteria:**

**Given** `src/Schedule.php` is the central scheduler
**When** story 23.3 is complete
**Then** a `RecurringMessage::cron('0 0 * * 1', new GenerateWeeklyRunsMessage())` entry exists
**And** a `RecurringMessage::cron('59 23 * * 0', new StopWeeklyRunsMessage())` entry exists

**Given** `GenerateWeeklyRunsMessageHandler` runs
**When** an active template has no existing run for the current ISO week
**Then** a `WeeklyRun` is created with `status = 'active'`, `startedAt = now`, correct `weekYear`, `weekNumber`, `seed`
**And** it is persisted and flushed with no runner call and no async job dispatched

**Given** `GenerateWeeklyRunsMessageHandler` runs again for the same week
**When** a run already exists
**Then** no duplicate is created (idempotent)

**Given** `StopWeeklyRunsMessageHandler` runs on Sunday at 23:59
**When** active runs exist with launched entries
**Then** `terminate(externalSessionId)` is called for each entry with non-null `externalSessionId` and null `goalReachedAt`
**And** on `terminate()` exception the error is logged at `error` level and the handler continues (best-effort)
**And** `WeeklyRun::finish(now)` is called and flushed for each run

**And** all four quality gates pass

---

## Story 23.4: Member Opt-In Flow & Goal Detection

*(Amended 2026-05-17: on-demand per-player launch endpoint added; goal callback uses `externalSessionId` not `slotIndex`; secret is `CENTRAL_API_SECRET`/`$centralApiSecret` matching existing pattern)*

As a member,
I want to opt into the weekly run and launch my personal Archipelago session on demand, with my progress tracked automatically,
So that I appear on the leaderboard when I reach the goal.

**Context:**
Three steps: (1) opt-in creates a `WeeklyEntry`; (2) player launches their individual session via `POST .../launch` which substitutes their `displayName` into the template YAML and calls `WeeklyRunnerGatewayInterface::launchEntry()`; (3) the bridge POSTs a goal callback to `POST /api/v1/internal/weekly-runs/goal-callback` (header: `X-Internal-Secret: {CENTRAL_API_SECRET}`) when the player reaches the goal. Internal auth follows the same pattern as `Sessions/Presentation/RunnerCallbackController`.

**Acceptance Criteria:**

**Given** an authenticated member (`ROLE_MEMBER`)
**When** `POST /api/v1/weekly-runs/{weeklyRunId}/entries` is called
**Then** if run `status ≠ 'active'` → `422 { error: 'run_not_active' }`
**And** if entry count ≥ `maxAttempts` (skip when null) → `422 { error: 'max_attempts_reached' }`
**And** otherwise `WeeklyEntry` created, flushed, response `201 { data: { id, weeklyRunId, userId, attemptNumber } }`

**Given** `POST /api/v1/weekly-runs/{weeklyRunId}/entries/{entryId}/launch` (ROLE_MEMBER, own entry)
**When** entry has no `externalSessionId` yet
**Then** service fetches `apworldStorageKey` from `game` table via DBAL, fetches `displayName` from `user` table via DBAL, substitutes `displayName` as YAML `name` field, calls `WeeklyRunnerGatewayInterface::launchEntry()`, calls `WeeklyEntry::launch(externalSessionId, now)`, flushes
**And** response is `201 { data: { entryId, externalSessionId, connectionInfo: { host, port, password } } }`
**And** if `externalSessionId` already set → `422 { error: 'session_already_started' }`

**Given** `POST /api/v1/internal/weekly-runs/goal-callback`
**When** `X-Internal-Secret` header ≠ `CENTRAL_API_SECRET` env → `401 Unauthorized`

**Given** goal callback with `{ externalSessionId, checksTotal, itemsTotal, goalReachedAt }`
**When** `RecordWeeklyGoal::record()` is called
**Then** entry found by `externalSessionId`; if `goalReachedAt` already set → no-op
**And** `WeeklyEntry::recordGoal(goalReachedAt, completionTimeSeconds, checksTotal, itemsTotal)` called where `completionTimeSeconds = max(0, goalReachedAt - run.startedAt)`
**And** flushed; Mercure published on `weekly-runs/{weeklyRunId}/leaderboard` via `HubInterface::publish(new Update(...))` after flush

**Given** `GET /api/v1/weekly-runs/current` (public)
**When** authenticated caller's `myEntry` is included
**Then** `200 { data: [{ weeklyRunId, templateName, gameName, weekNumber, weekYear, status, startedAt, finishedAt, leaderboard: { fastest, fewestChecks, fewestItems }, participants, myEntry: null | { entryId, launchedAt, goalReachedAt, connectionInfo } }] }`

**And** all four quality gates pass

---

## Story 23.5: Admin - Template CRUD & Weekly Run Monitoring

*(Amended 2026-05-17: template creation uses game picker + yaml-option-editor instead of raw textarea; API accepts gameId not free-text game name)*

As an admin,
I want to create and edit weekly run templates using the existing game library and YAML editor,
So that I can configure which games run each week with the same visual tool players already know.

**Context:**
Five endpoints under `WeeklyRuns/Presentation/Admin/`. Template CRUD follows the same pattern as admin membership endpoints: controllers call Application services, no DB access in controllers (AC-P1/P2). Soft-delete: `WeeklyTemplate::deactivate()` sets `isActive = false`; the template is never physically removed.

**Template creation UX - two-step flow:**
The "Nouveau template" action opens a dedicated page (or full-screen dialog) with two sequential steps:
1. **Sélection du jeu** - a game picker card grid (reusing `GET /api/v1/admin/games?apworldReady=true`) showing game name and cover image. The admin clicks a game to proceed. Only games with `isApworldReady = true` are shown.
2. **Configuration YAML** - the `yaml-option-editor.tsx` component is mounted with the selected game's `defaultYaml` as schema seed. The admin uses the visual editor (toggles, dropdowns, sliders) to set the options for the week. Below the editor, fields for `name` (optional override) and `maxAttempts` (number or "Illimité" toggle). The "Créer" button serializes the YAML and submits `POST /api/v1/admin/weekly-templates`.

**Template edit UX:**
Edit re-opens the same two-step page. Step 1 is skipped (game cannot be changed once created - it would invalidate the YAML). Step 2 mounts `yaml-option-editor.tsx` with the stored `yamlConfig` merged over `defaultYaml` via `mergePlayerValues()` from `src/lib/archipelago-yaml.ts`, so previous admin choices are restored in the UI.

**API contract for template endpoints:** `gameId` is a UUID referencing `GameSelection.Game`. The backend validates that the referenced game exists and has `isApworldReady = true`; if not, returns `422 { error: 'game_not_ready' }`.

**Acceptance Criteria:**

**Given** an admin is authenticated
**When** `GET /api/v1/admin/weekly-templates` is called
**Then** the response is `200 { data: [...], meta: { total } }` listing all templates (active and inactive)
**And** each entry contains `id, name, gameId, gameName, maxAttempts, isActive, createdAt`
**And** `gameName` is joined from the `game` table via DBAL (not from `WeeklyTemplate` - avoids denormalization)

**Given** an admin is authenticated
**When** `POST /api/v1/admin/weekly-templates` is called with `{ gameId, yamlConfig, name?, maxAttempts? }`
**Then** the application service validates `gameId` references an existing game with `apworld_storage_key IS NOT NULL` (DBAL check - no `is_apworld_ready` column exists)
**And** a new `WeeklyTemplate` is created with `isActive = true`, storing `gameId` and `yamlConfig`
**And** the response is `201 { data: { id, name, gameId, gameName, yamlConfig, maxAttempts, isActive } }`
**And** missing `gameId` or `yamlConfig` returns `422`
**And** a `gameId` referencing a non-existent or non-ready game returns `422 { error: 'game_not_ready' }`

**Given** an admin is authenticated
**When** `GET /api/v1/admin/weekly-templates/{id}` is called
**Then** the response is `200 { data: { id, name, gameId, gameName, yamlConfig, maxAttempts, isActive } }`
**And** `yamlConfig` is included in full so the frontend can seed `yaml-option-editor.tsx` for editing

**Given** an admin is authenticated
**When** `PATCH /api/v1/admin/weekly-templates/{id}` is called with partial fields
**Then** only provided fields are updated (`name`, `yamlConfig`, `maxAttempts`, `isActive`) - `gameId` is immutable after creation
**And** the response is `200 { data: { ... updated template ... } }`

**Given** an admin is authenticated
**When** `DELETE /api/v1/admin/weekly-templates/{id}` is called
**Then** `WeeklyTemplate::deactivate()` is called (sets `isActive = false`)
**And** any active `WeeklyRun` for this template is not stopped - it runs to its natural end
**And** the response is `204 No Content`

**Given** an admin is authenticated
**When** `GET /api/v1/admin/weekly-runs/current` is called
**Then** the response is `200 { data: [{ weeklyRunId, templateName, gameName, status, seed, startedAt, finishedAt, entryCount, entries: [{ userId, displayName, attemptNumber, externalSessionId, launchedAt, goalReachedAt, completionTimeSeconds, checksTotal, itemsTotal }] }] }` where `status IN ('active', 'finished')`
**And** unauthenticated requests receive `401`, non-admin `403`

**Given** the admin visits `/admin/weekly-runs`
**When** the page loads
**Then** two sections are visible: "Templates" and "Run de la semaine"
**And** the Templates section lists all templates with columns: game cover thumbnail, game name, template name, maxAttempts badge ("Illimité" or "N tentative(s)"), active/inactive toggle, and "Éditer" / "Désactiver" row actions
**And** clicking "Nouveau template" navigates to the two-step creation page described in Context
**And** clicking "Éditer" navigates to the same page pre-filled with the template's current values
**And** the "Run de la semaine" section shows a card per `active` or `finished` run with: seed pill, status badge (active=green, finished=muted), participant count, and an expandable table of entries with `externalSessionId` (truncated), `launchedAt`, `goalReachedAt`, and metric values

**Given** the admin is on the YAML configuration step (step 2)
**When** the `yaml-option-editor.tsx` component renders
**Then** it receives the game's `defaultYaml` (fetched from `GET /api/v1/admin/games/{gameId}`) as its base schema
**And** in edit mode, `mergePlayerValues(defaultYaml, storedYamlConfig)` pre-populates the editor with the previous admin choices
**And** the live YAML preview panel updates as the admin changes options (same behaviour as player-facing editor)
**And** clicking "Créer" or "Enregistrer" serializes the editor state via `serializeToYaml()` and POSTs or PATCHes the endpoint

**And** `pnpm typecheck`, `pnpm lint`, and `pnpm build` are clean
**And** all four API quality gates pass

---

## Story 23.6: Member-Facing Weekly Run Page & Live Leaderboard

As a member,
I want a dedicated weekly runs page where I can see the current run, opt in with one click, and watch the leaderboard update live as other players reach their goal,
So that participating in the weekly Archipelago challenge feels engaging and competitive.

**Context:**
`/runs-hebdo` is a new Next.js page under `app/(public)/runs-hebdo/` (accessible to authenticated members only - auth enforced in the Server Component, same pattern as `compte/`; no middleware). It uses TanStack Query with `staleTime: 30_000` as the baseline and a Mercure EventSource for real-time pushes on the `weekly-runs/{weeklyRunId}/leaderboard` topic. The opt-in button calls `POST /api/v1/weekly-runs/{weeklyRunId}/entries`; on `201` the query is invalidated, and the member sees a "Lancer ma partie" button. On launch, `POST .../launch` returns `connectionInfo: { host, port, password }`. The three leaderboard columns (fastest, fewest checks, fewest items) are rendered as tabs; players without a goal appear in a "Participants en cours" section. Env vars go through `src/lib/env.ts` (AC-ENV1). All keys use stable IDs (AC-KEY1). No `useEffect` for initial data (AC-NX1).

**Acceptance Criteria:**

**Given** an unauthenticated visitor
**When** `/runs-hebdo` is accessed
**Then** they are redirected to the login page (auth check in Server Component - no middleware)

**Given** an authenticated member
**When** `/runs-hebdo` loads
**Then** the page fetches `GET /api/v1/weekly-runs/current` and renders each active run in a card
**And** each card shows: `gameName` (from the joined Game record), optional `templateName` if set, week number (`Semaine {N}`), time remaining until Sunday 23:59, and the three leaderboard tabs

**Given** no run is active this week
**When** the page loads
**Then** a placeholder "Aucun run cette semaine - revenez lundi !" is displayed

**Given** the member has not yet opted in and `maxAttempts` allows it
**When** they click "Rejoindre ce run"
**Then** `POST /api/v1/weekly-runs/{weeklyRunId}/entries` is called
**And** on `201` the button changes to "Vous participez (tentative #N)" and the participants list updates
**And** on `422 max_attempts_reached` a toast "Vous avez atteint le nombre maximum de tentatives" is shown
**And** on `422 run_not_active` a toast "Ce run n'est plus actif" is shown

**Given** a Mercure EventSource is subscribed to `weekly-runs/{weeklyRunId}/leaderboard`
**When** a goal event arrives
**Then** the leaderboard entries update without a full page reload
**And** the EventSource listener is cleaned up in the `useEffect` return function (no memory leak)

**Given** the three leaderboard tabs
**When** entries with goals exist
**Then** "Meilleur temps" shows `displayName` and time formatted as `HH:mm:ss`, ordered fastest first
**And** "Moins de checks" shows `displayName` and `checksTotal` integer, ordered lowest first
**And** "Moins d'items" shows `displayName` and `itemsTotal` integer, ordered lowest first
**And** each tab shows the member's own entry highlighted (if present)

**Given** `GET /api/v1/weekly-runs/current` is called (public endpoint, optional authentication)
**When** runs exist this week
**Then** the response is `200 { data: [{ weeklyRunId, templateName, gameName, weekNumber, weekYear, status, startedAt, finishedAt, leaderboard: { fastest, fewestChecks, fewestItems }, participants, myEntry }] }`
**And** `gameName` is joined from the `game` table (DBAL query - no cross-context import)
**And** `myEntry` is `null` for unauthenticated callers; for authenticated callers it is `{ entryId, launchedAt, goalReachedAt, connectionInfo: { host, port, password } | null }` or `null` if the member has not opted in

**And** `pnpm typecheck`, `pnpm lint`, and `pnpm build` are clean
**And** all four API quality gates pass
