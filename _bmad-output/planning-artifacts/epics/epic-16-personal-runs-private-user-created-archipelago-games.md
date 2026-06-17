# Epic 16: Personal Runs - Private User-Created Archipelago Games

Authenticated users can create private Archipelago runs outside of any ArchiLAN event, configure game worlds, invite friends via an opaque shareable link, and start the server - reusing the runner infrastructure from Epic 9.

**FRs covered:** FR-PR1, FR-PR2, FR-PR3, FR-PR4, FR-PR5, FR-PR6, FR-PR7, NFR-PR1, NFR-PR2, NFR-PR3.

## Story 16.1: Personal Run Domain Model and API

As an authenticated user,
I want to create and manage personal Archipelago runs via the API,
So that I can start private games independently of association events.

**Acceptance Criteria:**

**Given** an authenticated user calls `POST /api/v1/runs` with a valid title
**When** the request is processed
**Then** a new `PersonalRun` record is created with status `draft`, a unique 32-hex-char id, and the caller set as owner
**And** the response is `201 Created` with `{ "data": { "id", "title", "status", "inviteToken", "ownerId", "createdAt", "updatedAt" } }`
**And** the `invite_token` is a 32-byte random hex string (64 chars), unique and non-sequential

**Given** an authenticated user calls `GET /api/v1/runs/mine`
**When** the request is processed
**Then** the response lists all PersonalRuns owned by the caller, ordered by `created_at` descending
**And** runs belonging to other owners are not included

**Given** an authenticated user calls `GET /api/v1/runs/{runId}`
**When** the run exists and the caller is the owner or a participant
**Then** the response includes full run details including game config, status, and participants list
**And** a caller who is neither owner nor participant receives 403

**Given** an authenticated user calls `DELETE /api/v1/runs/{runId}`
**When** the run exists, the caller is the owner, and the run status is `draft` or `idle`
**Then** the run is soft-deleted (status `cancelled`) and the response is 204
**And** attempting to delete a run with status `active` returns 422 with code `run_active` - the run must be stopped first

**And** unauthenticated requests to all `/runs` endpoints return 401
**And** the `PersonalRun` entity lives in a new bounded context `App\PersonalRuns\Domain`
**And** a Doctrine migration creates table `personal_runs` (id, owner_id FK users, title, status, invite_token UNIQUE, game_selection_config JSON, created_at, updated_at)
**And** functional tests cover: create, list mine, get as owner, get as participant (403), delete draft, delete active (422)

## Story 16.2: Invite Link Generation and Join Flow

As a run owner,
I want to share a private invite link,
So that friends can join my personal run without it being publicly discoverable.

**Acceptance Criteria:**

**Given** a run owner calls `POST /api/v1/runs/{runId}/invite/regenerate`
**When** the run exists and the caller is the owner
**Then** a new `invite_token` is generated (old token invalidated) and the response returns the new token and the full invite URL

**Given** an authenticated user follows `GET /api/v1/runs/join/{inviteToken}`
**When** the token matches a non-cancelled run and the caller is not already a participant
**Then** a `PersonalRunParticipant` record is created (`personal_run_id`, `user_id`, `joined_at`)
**And** the response is `200 OK` with the run payload (same shape as Story 16.1 GET)
**And** the user is now visible in the participants list of the run

**Given** an authenticated user follows the same invite link a second time
**When** the caller is already a participant
**Then** the response is `200 OK` (idempotent - no duplicate participant created)

**Given** an unauthenticated visitor follows `GET /api/v1/runs/join/{inviteToken}`
**When** the token is valid
**Then** the response is 401 with code `auth_required` - an account is required to join

**Given** a token does not match any run, or the matched run is `cancelled`
**When** the join endpoint is called
**Then** the response is 404

**And** a Doctrine migration creates table `personal_run_participants` (personal_run_id FK, user_id FK, joined_at; PK: personal_run_id + user_id)
**And** functional tests cover: join as new participant, join idempotent, join unauthenticated (401), invalid token (404), cancelled run (404), regenerate token

## Story 16.3: Game Configuration for Personal Run

As a run owner,
I want to configure which Archipelago games are included in my run,
So that the multiworld generation has the correct game list when I start the server.

**Acceptance Criteria:**

**Given** a run owner calls `PATCH /api/v1/runs/{runId}/games` with `{ "games": [{ "gameId": "..." }, ...] }`
**When** the run is in `draft` or `idle` status and the caller is the owner
**Then** the `game_selection_config` JSON column is updated with the provided game list
**And** each `gameId` must match an existing game in the Archipelago game library (`App\GameSelection` domain)
**And** unknown `gameId` values return 422 with code `unknown_game` listing the invalid IDs

**Given** the owner updates games on an `active` run
**When** the request is processed
**Then** the response is 422 with code `run_active` - configuration changes require stopping the run first

**Given** a non-owner authenticated user calls the game config endpoint
**When** the request is processed
**Then** the response is 403

**And** a minimum of 1 game is required; an empty `games` array returns 422 with code `games_required`
**And** functional tests cover: valid config update (draft/idle), update on active run (422), unknown gameId (422), non-owner (403), empty games (422)

## Story 16.4: Server Launch and Connection Details

As a run owner,
I want to start the Archipelago server for my personal run and see connection details,
So that participants can connect and the game can begin.

**Acceptance Criteria:**

**Given** a run owner calls `POST /api/v1/runs/{runId}/start`
**When** the run is in `draft` status, has at least one game configured, and the owner has a runner available
**Then** the API dispatches a `LaunchPersonalRunJob` via Symfony Messenger (same runner infrastructure as Epic 9)
**And** the run status transitions to `active` once the container is running
**And** connection details (host, port) are stored on the run record once the server reports ready

**Given** the run is active and the caller is the owner or a participant
**When** `GET /api/v1/runs/{runId}` is called
**Then** the response includes `connectionHost`, `connectionPort`, and `connectionPassword` fields (null until active)

**Given** a run owner calls `POST /api/v1/runs/{runId}/start` when the run is already `active`
**When** the request is processed
**Then** the response is 422 with code `run_already_active`

**Given** a run owner calls `POST /api/v1/runs/{runId}/stop`
**When** the run is `active`
**Then** the API dispatches a `StopPersonalRunJob`, the container is stopped gracefully, the run transitions to `idle`, and connection details are cleared
**And** the response is 200 with the updated run payload

**And** a Doctrine migration adds `session_id` (nullable FK to sessions), `connection_host`, `connection_port`, `connection_password` columns to `personal_runs`
**And** functional tests cover: start (draft → active dispatch), start already active (422), stop (active → idle), get connection details when active, get connection details when idle (null)

## Story 16.5: Frontend - Run Creation and Dashboard

As an authenticated user,
I want a dashboard to create and manage my personal runs,
So that I can organize my private games from the site.

**Acceptance Criteria:**

**Given** an authenticated user navigates to `/runs`
**When** the page loads
**Then** they see a list of their personal runs with status badge, title, date created, and action buttons (View, Delete for draft/idle)
**And** a prominent "Créer une partie" button is visible
**And** runs are grouped: active first, then idle, then draft, then cancelled (collapsed by default)
**And** unauthenticated visitors are redirected to `/login?redirect=/runs`

**Given** the user clicks "Créer une partie"
**When** the creation form is shown
**Then** the form asks for a title (required, max 80 chars) and submits to `POST /api/v1/runs`
**And** on success, the user is redirected to `/runs/{runId}`

**Given** the user navigates to `/runs/{runId}` as the owner
**When** the page loads
**Then** they see the run title, status, list of participants, and game configuration section
**And** they see a "Copier le lien d'invitation" button that copies `{siteUrl}/runs/join/{inviteToken}` to clipboard with a toast confirmation
**And** if status is `draft`, a "Configurer les jeux" section and "Démarrer la partie" button are visible
**And** if status is `active`, connection details (host:port, password) are displayed prominently for all participants
**And** if status is `idle`, a "Reprendre" button is visible (links to Epic 17 restart flow)

**And** frontend lives in `src/features/personal-runs/`
**And** routes: `src/app/runs/page.tsx` (list), `src/app/runs/[runId]/page.tsx` (detail)

## Story 16.6: Frontend - Join via Invite Link and Participant View

As an invited user,
I want to join a personal run by following the invite link,
So that I can participate in the game and see connection details.

**Acceptance Criteria:**

**Given** an authenticated user navigates to `/runs/join/{inviteToken}`
**When** the page loads
**Then** the frontend calls `GET /api/v1/runs/join/{inviteToken}` which registers the user as a participant
**And** on success, the user is redirected to `/runs/{runId}` (participant view)
**And** if the token is invalid or the run is cancelled, a clear error message is shown with a link back to the homepage

**Given** an unauthenticated visitor navigates to `/runs/join/{inviteToken}`
**When** the page loads
**Then** they see a "Rejoindre la partie" page with the run title, a brief description, and a "Se connecter / créer un compte" CTA
**And** after authentication they are automatically redirected back to `/runs/join/{inviteToken}` to complete the join

**Given** the user is now a participant and the run is `active`
**When** they view `/runs/{runId}`
**Then** they see connection details (host, port, password) and the participant list (owner highlighted)
**And** they do NOT see configuration controls or the start/stop buttons (owner-only)

**And** `src/app/runs/join/[inviteToken]/page.tsx` handles the join route
**And** the join API call is triggered client-side on page load (not server-side, to avoid join on crawler visits)

## Story 16.11: Named YAML Templates for Personal Run Slots

As a member who configures Archipelago YAMLs for personal runs,
I want to save my YAML configurations as named, reusable templates and apply them to a slot in one click,
So that I stop re-entering the same settings every run and can keep several presets per game.

> Status: planned (not started). Date: 2026-06-17. Moved here from Epic 28 (28.9) - it completes the
> personal-run slot-config flow and belongs to this context, not to Steam Library Coupling. The minimum
> viable ask is "at least be able to name them". Sharing / import-export / cross-user templates are out.
>
> **Numbering note.** Stories 16.7-16.10 already exist as implementation stories (patch/spoiler download,
> participant patch, joined-runs listing) under `_bmad-output/implementation-artifacts/` but were never
> back-documented in this planning epic; this story takes the next free number, **16.11**.

**Acceptance Criteria:**

**Given** a member is editing a slot YAML in the personal-run slot editor and calls `POST /api/v1/yaml-templates` with `{ "gameId", "name", "yaml" }`
**When** the YAML is valid, the game is `apworldReady`, and no template with that name exists for this `(user, game)`
**Then** a `YamlTemplate` record is created (`id`, `user_id`, `game_id`, `name`, `yaml`, `created_at`, `updated_at`) and returned
**And** the template appears in the member's template list for that game

**Given** the member saves a template with a name that already exists for the same `(user, game)`
**When** the request is processed
**Then** the response is 422 (code `template_name_taken`) - no silent overwrite

**Given** invalid/malformed YAML is submitted as a template
**When** the request is processed
**Then** the response is 422 (same validation as the slot-save path) - the template is not created

**Given** a member calls `GET /api/v1/yaml-templates?gameId=...`
**When** the request is processed
**Then** the response lists only that member's templates for the given game
**And** another member's templates are never returned or applicable (privacy enforced server-side by `user_id`)

**Given** a member applies a template in the slot editor
**When** the template is selected
**Then** the editor loads the template's `yaml` into the form
**And** the slot is persisted only when the member saves (applying does not auto-save - consistent with the current flow)

**Given** a member calls `PUT /api/v1/yaml-templates/{id}` to rename or update its stored YAML
**When** the caller owns the template and the new name/YAML is valid
**Then** the template is updated; a foreign `id` returns 404 and a duplicate name returns 422

**Given** a member calls `DELETE /api/v1/yaml-templates/{id}`
**When** the caller owns the template
**Then** the template is deleted (204) and can no longer be listed or applied; a foreign `id` returns 404

**Given** a member's account is erased (Story 2.4 path)
**When** erasure runs
**Then** all of that member's `YamlTemplate` records are removed (personal data cascade)

**And** the `YamlTemplate` aggregate lives in `App\PersonalRuns\Domain` (`final` class, no public setters - `rename()` / `updateYaml()` business methods) with a `YamlTemplateRepositoryInterface`
**And** a Doctrine migration creates table `yaml_template` (id, user_id FK users, game_id, name, yaml TEXT, created_at, updated_at) with a UNIQUE index on `(user_id, game_id, name)`; reversible `down()`
**And** Application provides command services `SaveYamlTemplate` / `RenameYamlTemplate` / `UpdateYamlTemplate` / `DeleteYamlTemplate` (return `void`) and a `YamlTemplateListQuery` (read), reusing the existing YAML validation - no new engine
**And** endpoints are member-gated via `ApiAccessGuard`
**And** the frontend layers template controls onto the shared `YamlOptionEditor` via additive props (no editor fork; the event-registration caller is untouched): a template picker (apply on click), an "Enregistrer comme template" action with a name prompt, plus rename/delete affordances, wired through `features/personal-runs/yaml-templates-api.ts` (typed `is*` guards, TanStack Query with explicit `staleTime`)
**And** functional tests cover: create, duplicate-name (422), invalid YAML (422), list scoped to owner, privacy isolation, apply, rename, delete, foreign id (404), erasure cascade

**Open questions (resolve before dev):**
- Context placement: keep in `PersonalRuns` (MVP, only consumer today) or introduce a neutral `Presets`/`Library` context now to pre-empt event-registration reuse? Recommendation: `PersonalRuns`, lift later.
- "Update applied template" (overwrite) vs "Save as new": MVP ships "save as new" + explicit rename/update; defer an inline "overwrite current template" button.
- Surface the built-in `Game::defaultYaml` as a "Défaut du jeu" pseudo-template in the same picker? Cheap nice-to-have - flag, don't block.
- Future "reprendre ce jeu avec ma dernière config" from the Epic 28.8 recently-played surface builds on this aggregate - keep it clean/context-neutral.

---
