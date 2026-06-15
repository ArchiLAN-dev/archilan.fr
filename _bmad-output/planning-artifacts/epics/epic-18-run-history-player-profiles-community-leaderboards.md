# Epic 18: Run History, Player Profiles & Community Leaderboards

Players and visitors can explore completed run results, personal history across all runs, and community-wide leaderboards. Slot stats are automatically invalidated when a player forfeits and their slot is released/collected, ensuring leaderboard integrity. The data layer already exists (`checksDone`, `itemsReceived`, `goalReachedAt` on `SessionSlot`, timestamps on `Session`) - this epic exposes it engagingly.

## New Requirements

### Functional Requirements

FR-HC1: Visitors can view a public run results page (`/runs/{id}/resultats`) showing per-slot stats (checks done, items received, goal reached, playtime), grouped by slot outcome: "Objectif atteint" / "Incomplet" / "Forfait".
FR-HC2: A slot is invalidated when an admin (backoffice) or the personal-run owner triggers the release/collect action AND the slot has no `goal_reached_at`. Slots where `goal_reached_at IS NOT NULL` are immune - a completed player can never be invalidated.
FR-HC3: A `was_released` boolean column is added to `session_slots` and set to `true` atomically within the same transaction as the release/collect action.
FR-HC4: Invalidated slots display a distinct "Forfait" badge on the results page and are excluded from all leaderboard aggregations (goal count, checks count, completion rate). Their run attendance is counted in `runsParticipated` - showing up counts regardless of outcome.
FR-HC5: Any visitor (no login required) can view a public player profile page at `/joueurs/{slug}`. A new unique `slug` column is introduced on `identity_users` as part of this epic and generated from the player's `displayName`.
FR-HC6: The player profile page displays aggregated personal stats: total runs participated in, total checks done, total items received, goal completion rate, and number of goals reached - all metrics excluding invalidated slots from numerators; `runsParticipated` counts all slots.
FR-HC7: A public community leaderboard at `/classements` ranks players on three axes: most goal completions, most checks done (all-time), and fastest single-run completion (goal reached, shortest elapsed time). Each axis is paginated, limit clamped to [1, 100], secondary tie-breaker is `displayName ASC`. Optional filter by event.
FR-HC8: A global community stats widget (embeddable on the landing page) shows: total sessions with status `finished`, total checks done across all non-invalidated slots, and total goals reached.
FR-HC9: Run results and leaderboards are only computed from sessions with status `finished`. Sessions in any other status (generating, running, idle, stopped, failed, crashed) expose no stats publicly.

### Non-Functional Requirements

NFR-HC1: All API endpoints under this epic (`GET /api/v1/runs/{id}/results`, `GET /api/v1/players/{slug}`, `GET /api/v1/community/stats`, `GET /api/v1/leaderboard`) require no authentication. Frontend routes (`/runs/{id}/resultats`, `/joueurs/{slug}`, `/classements`) are public Next.js pages accessible without login.
NFR-HC2: Leaderboard queries must use indexed columns (`goal_reached_at`, `checks_done`, `session_id`, `was_released`) and paginate; a full table scan on `session_slots` is not acceptable at scale.
NFR-HC3: Setting `was_released = true` must be atomic with the parent release/collect transaction - no separate async job, no eventual consistency gap.
NFR-HC4: Leaderboard and global-stats endpoints must be cacheable; a 60-second server-side cache is acceptable (ETag or `Cache-Control: max-age=60`).
NFR-CQ1: Each test entity factory (`createUser`, `createEvent`, `createGame`, `createRegistration`) is defined exactly once in `FunctionalTestCase`; no functional test file retains its own copy.
NFR-CQ2: Controller auth guard logic is implemented in a single shared location; no controller retains an inline copy of the guard pattern.
NFR-CQ3: Null-check + not-found throw for entity lookups is encapsulated in one place; individual application services do not repeat the pattern inline.
NFR-CQ4: DBAL pagination (`setFirstResult` / `setMaxResults`) is encapsulated in one helper; query services do not inline the calculation.
NFR-CQ5: All four quality gates (PHPStan level max, CS Fixer, `phpunit`, DDD validator) pass green after every story in this epic.

### FR Coverage Map Additions

FR-HC1: Epic 18 - Public run results page (grouped by outcome).
FR-HC2: Epic 18 - Slot invalidation rule (release/collect + no goal reached).
FR-HC3: Epic 18 - `was_released` column on `session_slots`.
FR-HC4: Epic 18 - Forfait badge + leaderboard exclusion + participation still counted.
FR-HC5: Epic 18 - Public player profile page + `slug` column on `identity_users`.
FR-HC6: Epic 18 - Aggregated personal stats on profile (excluding invalidated from numerators).
FR-HC7: Epic 18 - Community leaderboard page (3 axes, deterministic tie-breaker).
FR-HC8: Epic 18 - Global stats widget (`finished` sessions only).
FR-HC9: Epic 18 - Stats gated on `finished` status only.

---

## Story 18.1: Domain & Migration - `was_released` Flag on SessionSlot

As a developer,
I want a `was_released` flag on `SessionSlot` that is set when an admin releases/collects a forfeiting player's slot,
So that the system can distinguish intentionally invalidated participation from normal activity when computing stats.

**Acceptance Criteria:**

**Given** the existing `session_slots` table
**When** the migration is applied
**Then** a `was_released BOOLEAN NOT NULL DEFAULT FALSE` column is added to `session_slots`
**And** all existing rows default to `false` (no back-fill needed)

**Given** a `SessionSlot` domain entity
**When** `SessionSlot::markAsReleased()` is called
**Then** `wasReleased` is set to `true` only if `goalReachedAt` is `null`; the call is silently ignored for a slot where the goal was already reached (a completed player cannot be forfeited)

**Given** the release/collect action in `SessionOrchestrator` (or the handler that currently processes the slot release command for event sessions, or the personal-run owner triggering the equivalent action for a personal run)
**When** the action targets a slot whose registration is in a cancelled/abandoned state (or the personal-run participant has left)
**Then** `SessionSlot::markAsReleased()` is called inside the same DB transaction that persists the release action
**And** the transaction commits both changes atomically
**And** if the slot has `goalReachedAt` set, `markAsReleased()` is a silent no-op - the transaction still commits, the flag stays `false`

**Given** a slot with `was_released = true`
**When** it is serialized to the API response
**Then** the `wasReleased` boolean is included in the slot DTO (camelCase, as per project conventions)

**And** unit tests cover: `markAsReleased()` sets flag when goal not reached, `markAsReleased()` is a no-op when goal already reached
**And** a Doctrine migration file is generated under `api/migrations/`

---

## Story 18.2: API - Public Run Results Endpoint

As a visitor or player,
I want to fetch the results of a completed run via a public API endpoint,
So that the frontend can display per-slot stats without requiring authentication.

**Acceptance Criteria:**

**Prerequisite:** Story 18.1 (adds `was_released` column and `markAsReleased()`)

**Given** a `GET /api/v1/runs/{id}/results` request for a session with status `finished`
**When** the request is received (no authentication required)
**Then** the response is 200 with:
```json
{
  "data": {
    "sessionId": "uuid",
    "eventName": "ArchiLAN #12",
    "startedAt": "ISO8601",
    "finishedAt": "ISO8601",
    "durationSeconds": 14400,
    "slots": [
      {
        "slotId": "uuid",
        "playerName": "string",
        "game": "Hollow Knight",
        "checksDone": 42,
        "itemsReceived": 35,
        "goalReachedAt": "ISO8601 | null",
        "completionSeconds": 3600,
        "wasReleased": false,
        "isInvalidated": false
      }
    ]
  }
}
```
**And** `isInvalidated` is `true` when `wasReleased = true` and `goalReachedAt IS NULL`
**And** `completionSeconds` is `goalReachedAt - session.startedAt` in seconds, or `null` if goal not reached
**And** slots are ordered: goal-reached first (by `completionSeconds` asc), then incomplete (no goal, not released), then invalidated (was_released = true)

**Given** a `GET /api/v1/runs/{id}/results` request for a session that does not have status `finished` (e.g. status: `generating`, `running`, `idle`, `stopped`, etc.)
**When** the request is received
**Then** the response is 404 with code `run_not_found_or_not_finished`

**Given** a non-existent session ID
**When** the request is received
**Then** the response is 404

**And** a new `RunResultsController` (or equivalent) is created in the `Sessions` bounded context
**And** no authentication guard is applied to this endpoint
**And** functional tests cover: `finished` session (200 + correct payload + correct slot ordering), non-finished session (404 with `run_not_found_or_not_finished`), non-existent session (404), invalidated slot appears with `isInvalidated: true`

---

## Story 18.3: API - Player Profile and History Endpoints

As a visitor,
I want to fetch a player's public profile and run history,
So that the frontend can render their page without requiring authentication.

**Prerequisite:** Story 18.1 (adds `was_released` column)

**Acceptance Criteria:**

**Given** the existing `identity_users` table
**When** the Story 18.3 migration is applied
**Then** a `slug VARCHAR(80) NOT NULL UNIQUE` column is added to `identity_users`
**And** existing rows are back-filled: slug generated by lowercasing `display_name`, stripping accents, replacing spaces and special chars with hyphens, and appending a numeric suffix (`-2`, `-3`, …) to resolve collisions; if `display_name` is null, use the local part of `email_canonical`
**And** new user creation (`User::registerLambda`) sets `slug` at registration time using the same normalization logic (a `SlugGenerator` service handles both the normalization and collision check)

**Given** a `GET /api/v1/players/{slug}` request
**When** the player exists
**Then** the response is 200 with:
```json
{
  "data": {
    "slug": "jean",
    "displayName": "Jean",
    "joinedAt": "ISO8601",
    "stats": {
      "runsParticipated": 8,
      "goalCompletions": 5,
      "goalCompletionRate": 0.625,
      "totalChecksDone": 312,
      "totalItemsReceived": 287
    }
  }
}
```
**And** `runsParticipated` counts distinct `finished` sessions the player has a slot in, regardless of invalidation
**And** all other stats (`goalCompletions`, `goalCompletionRate`, `totalChecksDone`, `totalItemsReceived`) exclude slots where `was_released = true` AND `goal_reached_at IS NULL`

**Given** a `GET /api/v1/players/{slug}/history?page=1&limit=10` request
**When** the player exists
**Then** the response is 200 with a paginated list of runs, each containing:
  - `sessionId`, `eventName`, `finishedAt`, `game`, `checksDone`, `itemsReceived`, `goalReachedAt`, `wasReleased`, `isInvalidated`
**And** only runs from sessions with status `finished` are returned
**And** the list is ordered by `finishedAt` descending (most recent first)

**Given** a slug that matches no user
**When** either endpoint is called
**Then** the response is 404

**And** no authentication guard is applied to either endpoint
**And** functional tests cover: existing player with stats (200 + correct stat computation), player with no finished runs (200 + empty history), invalidated slot excluded from goal rate, non-existent slug (404)
**And** unit tests for `SlugGenerator`: normalization, accent stripping, collision suffix

---

## Story 18.4: API - Community Leaderboard and Global Stats Endpoints

As a visitor,
I want to query community leaderboards and aggregate stats,
So that the frontend can render the `/classements` page and the landing-page stats widget.

**Acceptance Criteria:**

**Prerequisite:** Story 18.1 (adds `was_released` column)

**Given** a `GET /api/v1/community/stats` request
**When** the request is received (no auth)
**Then** the response is 200 with:
```json
{
  "data": {
    "totalFinishedSessions": 42,
    "totalChecksDone": 18432,
    "totalGoalsReached": 156
  }
}
```
**And** `totalFinishedSessions` counts sessions with status `finished`
**And** `totalChecksDone` and `totalGoalsReached` exclude invalidated slots (`was_released = true` AND `goal_reached_at IS NULL`)
**And** the response includes `Cache-Control: public, max-age=60` header

**Given** a `GET /api/v1/leaderboard?axis=goals&page=1&limit=20` request (axis: `goals` | `checks` | `speed`)
**When** the request is received (no auth)
**Then** the response is 200 with a paginated ranking:
```json
{
  "data": [
    { "rank": 1, "slug": "jean", "displayName": "Jean", "value": 12, "unit": "goals" }
  ],
  "meta": { "axis": "goals", "page": 1, "total": 34 }
}
```
**And** for `axis=goals`: `value` = count of `goal_reached_at IS NOT NULL` across non-invalidated slots from `finished` sessions
**And** for `axis=checks`: `value` = sum of `checks_done` across non-invalidated slots from `finished` sessions
**And** for `axis=speed`: `value` = minimum `(goal_reached_at - session.started_at)` in seconds across the player's non-invalidated slots from `finished` sessions; players with no goal completion are excluded entirely
**And** `limit` is clamped server-side to [1, 100]; values outside this range are normalized without error
**And** an empty page (no results for the given page offset) returns `{ "data": [], "meta": { … "total": N } }` with status 200
**And** primary sort is by `value DESC`; secondary tie-breaker is `displayName ASC` (deterministic, case-insensitive)
**And** an optional `eventId` query param filters all three axes to sessions associated with that event
**And** the response includes `Cache-Control: public, max-age=60`

**Given** an invalid `axis` value
**When** the request is received
**Then** the response is 422 with a descriptive error

**And** database indexes are added on `session_slots(was_released, goal_reached_at)` and `session_slots(session_id)` if not already present
**And** functional tests cover: all three axes return correct ranking with correct tie-breaker order, eventId filter narrows results, empty page returns 200 + empty array, limit clamping, invalid axis → 422

---

## Story 18.5: Frontend - Public Run Results Page (`/runs/{id}/resultats`)

As a visitor or player,
I want to view a richly formatted results page for a finished run,
So that I can celebrate achievements, identify who completed what, and understand the run's outcome.

**Prerequisite:** Story 18.2 (run results API endpoint)

**Acceptance Criteria:**

**Given** the user navigates to `/runs/{id}/resultats`
**When** the API returns a session with status `finished`
**Then** the page renders a header with: event name, run date, total duration (formatted as `Xh Ym`)
**And** a results grid displays one card per slot with: player name, game, checks done, items received, goal status badge ("Objectif atteint" or "Incomplet"), and completion time if goal was reached
**And** slots are grouped in three labelled sections: "Objectifs atteints" (sorted by completion time asc), "Incomplets" (no goal, not invalidated), "Forfaits" (invalidated - `isInvalidated: true`)
**And** "Forfait" slot cards display an amber "Forfait" badge, checks and items are shown in muted style, and a tooltip explains "Statistiques exclues des classements (slot relâché)"
**And** the page is publicly accessible with no login requirement
**And** the page has proper `<title>` and `og:title` meta for social sharing (e.g. "Résultats de la run ArchiLAN #12")

**Given** the API returns 404 (session not finished or not found)
**When** the user navigates to `/runs/{id}/resultats`
**Then** a "Résultats non disponibles" placeholder is shown with a back link to the run page

**Given** the results page is loaded on mobile
**When** the viewport is below 768px
**Then** the grid collapses to a single-column list of cards with no horizontal scroll

**And** the page uses Next.js SSR (`getServerSideProps` or equivalent) to render results data on the server for SEO
**And** a "Voir le classement communautaire" link points to `/classements`

---

## Story 18.6: Frontend - Public Player Profile Page (`/joueurs/{slug}`)

As a visitor,
I want to view a player's profile with their run history and personal stats,
So that I can understand their involvement in ArchiLAN runs.

**Prerequisite:** Story 18.3 (player profile API endpoints)

**Acceptance Criteria:**

**Given** the user navigates to `/joueurs/{slug}` for an existing player
**When** the page loads
**Then** a profile header shows: display name, join date ("Membre depuis…"), and aggregated stats row: total runs, total checks done, goal completions, goal completion rate (as a percentage)
**And** below the stats, a "Historique des runs" section lists the player's finished runs in reverse-chronological order, each showing: event name, date, game played, checks done, items received, and a "Objectif atteint" or "Forfait" or "Incomplet" status badge
**And** each run row links to `/runs/{id}/resultats`
**And** invalidated (`isInvalidated: true`) runs display a "Forfait" amber badge and muted stats

**Given** there is no player matching the slug
**When** the page loads
**Then** a 404 page is rendered

**Given** the player has no finished run history
**When** the page loads
**Then** an empty state is shown: "Aucune run terminée pour l'instant"

**And** the page uses Next.js SSR for SEO
**And** `og:title` is set to the player's display name (e.g. "Jean - Profil ArchiLAN")

---

## Story 18.7: Frontend - Community Leaderboard Page (`/classements`) and Stats Widget

As a visitor,
I want to browse community leaderboards and discover top players,
So that I feel part of a competitive and engaged community.

**Prerequisite:** Story 18.4 (leaderboard and community stats API endpoints)

**Acceptance Criteria:**

**Given** the user navigates to `/classements`
**When** the page loads
**Then** three leaderboard tabs are displayed: "Objectifs", "Checks", "Vitesse"
**And** the active tab shows a ranked list of up to 20 players with: rank number, avatar placeholder (initials), display name (linking to their profile), and their value (e.g. "12 objectifs", "3 412 checks", "1h 23min")
**And** an "Événement" dropdown allows filtering all three leaderboards to a specific event (fetched from the events list)
**And** a "Voir plus" pagination link or button loads the next 20 entries within the current tab

**Given** the user lands on the tab for axis `speed`
**When** there are players with no goal completion
**Then** those players do not appear in the speed leaderboard (only players who reached at least one goal appear)

**Given** the community stats widget is embedded on the landing page
**When** the page loads
**Then** the widget shows three counters with animated number transitions on first viewport entry: "X runs terminées", "X checks complétés", "X objectifs atteints"
**And** the counters use data from `GET /api/v1/community/stats` fetched client-side (not blocking SSR)
**And** the widget degrades gracefully if the API call fails (counters show "-")

**Given** the user views the leaderboard on mobile
**When** the viewport is below 768px
**Then** the tabs are horizontally scrollable, the table collapses to a stacked card format, and rank numbers and values remain legible

**And** the leaderboard page uses Next.js SSR for initial data (axis=goals, no event filter) for SEO
**And** tab switching and event filter changes use client-side fetches (TanStack Query) without full page reload

---
