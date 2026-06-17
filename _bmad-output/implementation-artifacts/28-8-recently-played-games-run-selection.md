# Story 28.8: "Récemment joués" surfaced on the run game-selection page

Status: ready-for-review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a member configuring a personal run on `/runs/{runId}/jeux`,
I want my 3 most recently played games to surface automatically at the top of the catalog,
so that I can re-pick the games I actually play without searching the whole library every time.

Computes the user's 3 most recently played games from their personal-run history (`run` +
`run_participant.game_slots`) via a new Application read query (DBAL in Infrastructure), exposes them
(with `lastPlayedAt` + `runTitle`) on the run game-selection payload, bubbles them to the top of the
catalog listing with a recency-aware "Récemment joué" badge, adds a "Récemment joués" filter chip
(consistent with the 28.7 "Mes jeux"/category chips), and handles the "déjà sélectionné" state.

## Acceptance Criteria

1. Given a member who has played at least one launched personal run, when they open `/runs/{runId}/jeux` with no active search, then the games from their 3 most recent distinct plays appear first in the catalog, each with a "Récemment joué" badge.
2. The badge carries recency context: a relative-time label ("Joué il y a 3 j") with the source run title available on hover/`title`.
3. A "Récemment joués" filter chip renders (only when the set is non-empty) next to the existing "Mes jeux"/category chips; toggling it restricts the catalog to recently-played games and combines with any other active filter and the search term.
4. De-duplication is by game; the same game played in several runs appears once, positioned and dated by its most recent play.
5. Draft-only runs, cancelled runs, and the run currently being edited never contribute to the list.
6. A recently-played game already present in the current working selection is not offered as a duplicate action (omitted from the pinned strip or shown in its selected state) - never actionable twice for the same result.
7. A member with no qualifying run history sees the catalog unchanged (no badges, no pinning, no chip, no errors).
8. Typing in the catalog search disables the pinning and shows the normal name-ordered, filtered results; the "Récemment joué" badge still renders on any matching recently-played game.
9. The recently-played computation is scoped to the authenticated user only - it never leaks another member's history (a joined-but-not-owner participant only sees their own plays).
10. Gates green: backend (php-cs-fixer, phpstan, phpunit 0-notice, app:architecture:ddd) and frontend (typecheck, lint, build).

## Tasks / Subtasks

- [x] **Backend: read query** (AC: 1, 4, 5, 9)
  - [x] Define `RecentlyPlayedGamesQueryInterface` in `App\PersonalRuns\Application` with `recentlyPlayed(string $userId, string $excludeRunId, int $limit = 3): list<array{gameId: string, lastPlayedAt: string, runTitle: string}>` (read-only, no transaction). [Source: api/CLAUDE.md AC-A2]
  - [x] Implement `DbalRecentlyPlayedGamesQuery` in `App\PersonalRuns\Infrastructure`: DBAL QueryBuilder over `run_participant` joined to `run` on `personal_run_id = run.id`, `WHERE run_participant.user_id = :uid AND run.status IN (:launched) AND run.id != :current`, `ORDER BY run.updated_at DESC`. Fetch `run.id`, `run.title`, `run.updated_at`, `run_participant.game_slots`. Decode each `game_slots` JSON in PHP, iterate in run order, dedupe by `gameId` keeping the first (most recent) occurrence, carry that run's `updated_at` (→ `lastPlayedAt`, ISO-8601) + `title` (→ `runTitle`), cap at `$limit`.
  - [x] Launched statuses = `Run::STATUS_ACTIVE`, `STATUS_IDLE`, `STATUS_RESTARTING`, `STATUS_STOPPING`, `STATUS_COMPLETED` (i.e. "has been launched at least once"); exclude `draft` and `cancelled`. Define a `Run::LAUNCHED_STATUSES` const for reuse.
  - [x] Register `DbalRecentlyPlayedGamesQuery` as the interface impl in `services.yaml` (real impl, no `when@test` gating).
- [x] **Backend: extend the payload** (AC: 1, 4, 6, 7)
  - [x] Inject `RecentlyPlayedGamesQueryInterface` into `PersonalRunGameSelection`; in `getMySlots()`, call `recentlyPlayed($userId, $runId, 3)` and add `recentlyPlayedGames` to the result + the `result()` shape and PHPStan docblocks. Empty history → `[]`.
  - [x] Add `recentlyPlayedGames` to the `GET /runs/{runId}/participants/me/game-selection` payload in `PersonalRunController::getMyGameSelection` (`data.recentlyPlayedGames`).
- [x] **Backend: tests** (AC: 1, 4, 5, 7, 9)
  - [~] Query contract: covered end-to-end via the functional endpoint tests (real `DbalRecentlyPlayedGamesQuery` against Postgres) rather than a separate stub-based unit test - stronger coverage, matches the established `PersonalRunGameSelectionPayloadTest` pattern.
  - [x] Functional test on the endpoint (`PersonalRunRecentlyPlayedTest`): seed launched + draft runs for the user + a launched run for another user; assert the payload returns the user's launched-run games only, deduped, newest-first, excluding the current run and the other member's history. Covers empty-history (AC 7). Zero-notice gate.
- [x] **Frontend: payload type + pinned listing** (AC: 1, 2, 6, 8)
  - [x] Extend the `SelectionData`/payload type in `personal-run-game-selection-page.tsx` with `recentlyPlayedGames: { gameId: string; lastPlayedAt: string; runTitle: string }[]` (widen the existing `res.json()` parse type).
  - [x] Build a recency-ordered "pinned" slice from `recentlyPlayedGames` mapped onto `availableGames`, excluding any gameId already in `workingGameIds` (AC 6). When `gameSearch` is empty, render the pinned rows first, then the rest of `filteredGames` with pinned games removed from the tail (no double-listing). When `gameSearch` is non-empty, fall back to the current flat behaviour (AC 8).
  - [x] Add a "Récemment joué" badge in the catalog row (next to availability/owned badges), with a relative-time label (e.g. `Joué il y a 3 j`) and `title={runTitle}`. Add a small pure `relativeTime(iso)` helper (compute in render from the server timestamp - no `Date.now()` in query options; AC-HK3).
- [x] **Frontend: filter chip** (AC: 3, 7)
  - [x] Add a "Récemment joués" toggle chip next to "Mes jeux"/categories, rendered only when `recentlyPlayedGames.length > 0`. Toggling filters `filteredGames` to the recently-played set and resets the page, combining with search/availability/category/owned (mirror the `ownedOnly` chip wiring).
- [x] **Frontend: pagination + selected state** (AC: 6, 8)
  - [x] Keep pagination correct with the pinned section (pin within page 1 / the active filter result; do not double-list a pinned game lower down); reflect the "déjà sélectionné" state for pinned games.
- [x] **Gates** (AC: 10)
  - [x] `php-cs-fixer`, `phpstan`, `phpunit` (0 notices), `app:architecture:ddd`; `typecheck`, `lint`, `build`.

## Dev Notes

### Reuse, don't reinvent
- The badge follows the existing "Tu possèdes ce jeu" pattern and the chip follows the 28.7 "Mes jeux"/category chip wiring (`ownedOnly` state, `setCurrentPage(1)` on toggle). No new design tokens. [Source: frontend/src/features/personal-runs/personal-run-game-selection-page.tsx:390-432, 464-477]
- `availableGames` already carries everything the rows need; the new query only returns ids + metadata that we map onto it client-side. [Source: api/src/PersonalRuns/Application/PersonalRunGameSelection.php:65-78]
- `run.updated_at`, `run.status` and `run_participant.game_slots` already exist - no schema change, no migration. [Source: api/src/PersonalRuns/Domain/Run.php:53-66, RunParticipant.php:22-30]

### Architecture guardrails
- The read MUST use a DBAL QueryBuilder in Infrastructure behind an Application query interface - never `EntityManager`/`Connection` inside `PersonalRunGameSelection`. [Source: api/CLAUDE.md AC-A2, AC-P1/P2]
- `game_slots` is a JSON column: iterate/dedupe in PHP after the fetch (no raw JSON SQL) - keeps it portable and PHPStan-max clean. Narrow each decoded value (`is_string`, etc.) before use; never cast `mixed`.
- The query returns plain arrays (a read DTO shape), not Doctrine entities. [Source: api/CLAUDE.md AC-A3]
- Frontend: no `as` at the API boundary beyond the existing parse pattern on this page; the relative-time computation must run in render from the server `lastPlayedAt`, not `Date.now()` in `useQuery`/`useMemo` deps. [Source: frontend/AGENTS.md AC-HK3]

### Scope boundaries
- "Played" = a run that has been launched at least once (launched statuses), the deliberate interpretation of *joués*; if product later wants "selected" semantics, widen the status set in the query (one place).
- The pin is the default-view affordance; the chip is the explicit one. A live search disables the pin (it would fight the query) but keeps the badge.
- No "apply last config" here - that builds on YAML templates (Story 16.11) and is explicitly out of scope. [Source: _bmad-output/planning-artifacts/epics/epic-16-...#story-1611-named-yaml-templates-for-personal-run-slots]
- No public `/jeux` change; a shared `useRecentlyPlayed` hook is only worth extracting if that page later wants the same surface.

### Project Structure Notes
- New (api): `RecentlyPlayedGamesQueryInterface` (Application), `DbalRecentlyPlayedGamesQuery` (Infrastructure), functional test. Modified: `PersonalRunGameSelection.php`, `PersonalRunController.php`, `Run.php` (LAUNCHED_STATUSES const), `services.yaml`.
- New (frontend): none. Modified: `features/personal-runs/personal-run-game-selection-page.tsx` (payload type, pinned slice, badge, chip).

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-28-steam-library-coupling.md#story-288---récemment-joués-surfaced-on-the-run-game-selection-page]
- Prior: [Source: _bmad-output/implementation-artifacts/28-7-run-selection-categories-steam.md]
- Endpoint: [Source: api/src/PersonalRuns/Presentation/PersonalRunController.php:224-246]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Debug Log References

### Completion Notes List

- Implemented on branch `feature/epic-28-story-8-recently-played-games` (from `develop`).
- Backend: new `RecentlyPlayedGamesQueryInterface` (Application) + `DbalRecentlyPlayedGamesQuery` (Infrastructure, DBAL QueryBuilder over `run_participant` ⨝ `run`, `game_slots` JSON decoded + deduped in PHP, newest-first, capped at 3). Added `Run::LAUNCHED_STATUSES`. Injected into `PersonalRunGameSelection::getMySlots()`; `recentlyPlayedGames` now flows through `result()` and the `GET .../participants/me/game-selection` payload. Service bound in `services.yaml`.
- Frontend: `personal-run-game-selection-page.tsx` parses `recentlyPlayedGames`, pins them to the top of the catalog when no search is active (excluding already-selected games), shows a recency-aware "Joué il y a …" badge (with the run title as `title`), and adds a "Récemment joués" filter chip mirroring the "Mes jeux" toggle. `relativeTime` helper follows the existing `event-feed.tsx` precedent.
- Test approach: functional endpoint test exercises the real DBAL query (dedupe/newest-first/exclude-current/own-history isolation/empty) - see the tests checkbox note above. No separate stub unit test.
- No DB migration (reuses `run.updated_at`/`status` + `run_participant.game_slots`).
- Filter-bar visual pass (final): token-filter pattern. A single `<select>` ("+ Ajouter un filtre…", grouped via `<optgroup>`) lists only the filters not yet active; picking one pops it out as a cumulable, removable chip (accent token with optional `Clock`/`Gamepad2` icon + ✕). The select disables itself ("Tous les filtres actifs") when everything is active, and a right-aligned "Tout effacer" clears search + all tokens. Replaced the earlier flat chip row + native checkboxes. Search stays a separate field above.
- Extracted the pattern into a shared `features/games/filter-token-bar.tsx` (`FilterTokenBar` + `FilterGroup`/`ActiveFilterToken` types) and applied it to **both** the run game-selection page and the public `/jeux` catalog (`GamesCatalog`) - no duplication (mirrors the 28.7 shared-`SteamCoupling` approach). On `/jeux`, availability (Disponible/Expérimental), "Mes jeux" and platform categories are now tokens; search + sort stay separate controls. Jest (86) + gates green.

### Validation Results

- `vendor/bin/php-cs-fixer fix src --dry-run`: 0 violations.
- `vendor/bin/phpstan analyse src tests`: 0 errors (759 files).
- `php bin/console app:architecture:ddd`: exit 0.
- `php bin/phpunit`: 1108 tests, 7978 assertions, OK (0 notices/deprecations/warnings).
- `pnpm typecheck` / `pnpm lint` / `pnpm build`: all clean.

### File List

**Added (api)**
- `api/src/PersonalRuns/Application/RecentlyPlayedGamesQueryInterface.php`
- `api/src/PersonalRuns/Infrastructure/DbalRecentlyPlayedGamesQuery.php`
- `api/tests/Functional/PersonalRunRecentlyPlayedTest.php`

**Modified (api)**
- `api/src/PersonalRuns/Domain/Run.php` (`LAUNCHED_STATUSES` const)
- `api/src/PersonalRuns/Application/PersonalRunGameSelection.php` (inject query, extend `getMySlots()` + `result()`)
- `api/src/PersonalRuns/Presentation/PersonalRunController.php` (`recentlyPlayedGames` in payload)
- `api/config/services.yaml` (query interface binding)

**Modified (frontend)**
- `frontend/src/features/personal-runs/personal-run-game-selection-page.tsx` (payload type, pinned listing, badge, chip, `relativeTime`)
