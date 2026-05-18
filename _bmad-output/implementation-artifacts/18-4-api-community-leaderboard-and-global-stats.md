# Story 18.4: API - Community Leaderboard and Global Stats Endpoints

## Story

**As a** visitor,
**I want** to query community leaderboards and aggregate stats,
**So that** the frontend can render the `/classements` page and the landing-page stats widget.

## Status

review

## Acceptance Criteria

**AC1:** `GET /api/v1/community/stats` returns 200 with `{ data: { totalFinishedSessions, totalChecksDone, totalGoalsReached } }`. `totalChecksDone` and `totalGoalsReached` exclude invalidated slots (`was_released = true AND goal_reached_at IS NULL`). Response includes `Cache-Control: public, max-age=60`.

**AC2:** `GET /api/v1/leaderboard?axis=goals|checks|speed&page=1&limit=20` returns 200 with `{ data: [{ rank, slug, displayName, value, unit }], meta: { axis, page, total } }`. `Cache-Control: public, max-age=60`.

**AC3:** `axis=goals` - `value` = count of slots with `goal_reached_at IS NOT NULL` across non-invalidated slots from `finished` sessions.

**AC4:** `axis=checks` - `value` = sum of `checks_done` across non-invalidated slots from `finished` sessions.

**AC5:** `axis=speed` - `value` = player's minimum `(goal_reached_at - session.started_at)` in seconds; players with zero goal completions are excluded. Sorted fastest-first (value ASC, unlike other axes).

**AC6:** Primary sort: `value DESC` for goals and checks, `value ASC` for speed (fastest first). Secondary tie-breaker: `displayName ASC` case-insensitive.

**AC7:** `limit` is clamped server-side to `[1, 100]`; values outside this range are normalized without error.

**AC8:** An optional `eventId` query param filters all three axes to sessions associated with that specific event (event sessions only; personal-run sessions are excluded when `eventId` is set).

**AC9:** An empty page (no results for given offset) returns `{ "data": [], "meta": { … "total": N } }` with status 200.

**AC10:** An invalid `axis` value → 422 with a descriptive error.

**AC11:** DB indexes added on `archipelago_session_slots(was_released, goal_reached_at)` and `archipelago_session_slots(session_id)`.

**AC12:** Functional tests: goals/checks/speed axes return correct ranking, tie-breaker is deterministic, `eventId` filter narrows results, empty page returns 200, limit clamping, invalid axis → 422.

## Tasks / Subtasks

- [x] Task 1: Migration - DB indexes on `archipelago_session_slots`
  - [x] 1a: Create `api/migrations/Version20260519120000.php`
  - [x] 1b: `up()`: CREATE INDEX on `(was_released, goal_reached_at)` and `(session_id)`
  - [x] 1c: `down()`: DROP both indexes
- [x] Task 2: `CommunityStatsController` - `GET /api/v1/community/stats`
  - [x] 2a: Aggregate query for `totalFinishedSessions` (all finished sessions)
  - [x] 2b: Aggregate query for `totalChecksDone` and `totalGoalsReached` (exclude invalidated slots)
  - [x] 2c: Return 200 JSON + `Cache-Control: public, max-age=60`, no auth
- [x] Task 3: `LeaderboardController` - `GET /api/v1/leaderboard`
  - [x] 3a: Validate axis (goals|checks|speed) → 422 if invalid
  - [x] 3b: Clamp limit [1, 100], parse page and optional eventId
  - [x] 3c: Compute per-user scores for goals/checks via DBAL (event + personal run, unless eventId set)
  - [x] 3d: Compute per-user min-speed via DBAL + PHP timestamp diff (event + personal run)
  - [x] 3e: Sort (DESC for goals/checks, ASC for speed) + displayName tie-breaker; paginate in PHP
  - [x] 3f: Load users batch; return ranked JSON + `Cache-Control: public, max-age=60`, no auth
- [x] Task 4: Functional tests - `CommunityLeaderboardTest.php`
  - [x] 4a: Community stats: correct aggregate with invalidated slots excluded
  - [x] 4b: Goals axis: correct ranking and tie-breaker
  - [x] 4c: Checks axis: correct values and ordering
  - [x] 4d: Speed axis: fastest player first; users with no goals excluded
  - [x] 4e: eventId filter narrows results to that event's sessions
  - [x] 4f: Empty page returns 200 + empty data
  - [x] 4g: Limit clamping (0 → 1, 200 → 100)
  - [x] 4h: Invalid axis → 422
- [x] Task 5: Quality gates (PHPStan + CS Fixer)

## Dev Notes

### Data Model

- `Session` (table: `archipelago_sessions`): `id`, `event_id`, `status`, `started_at`, `finished_at`
- `SessionSlot` (table: `archipelago_session_slots`): `id`, `session_id`, `registration_id`, `game_id`, `checks_done`, `goal_reached_at`, `was_released`
- `Registration` (table: `event_registrations`): `id`, `event_id`, `user_id`
- `PersonalRun` (table: `personal_runs`): `id`, `session_id`
- `User` (table: `identity_users`): `id`, `display_name`, `slug`, `email`

### Invalidated Slot Filter

```sql
NOT (slot.was_released AND slot.goal_reached_at IS NULL)
```

Excludes slots that were released without having reached their goal. Domain guard ensures `was_released = true` always implies `goal_reached_at IS NULL`, making this condition correct.

### CommunityStats Queries

```sql
-- Total finished sessions
SELECT COUNT(*) FROM archipelago_sessions WHERE status = 'finished'

-- Slot aggregates (all sessions, regardless of event vs personal run)
SELECT
    COALESCE(SUM(CASE WHEN NOT (slot.was_released AND slot.goal_reached_at IS NULL)
                      THEN slot.checks_done ELSE 0 END), 0) AS total_checks_done,
    COUNT(slot.goal_reached_at) AS total_goals_reached
FROM archipelago_session_slots slot
JOIN archipelago_sessions s ON slot.session_id = s.id
WHERE s.status = 'finished'
```

No need to split event/personal run - aggregate is global.

### Leaderboard - Goals/Checks Axis

Two separate DBAL queries merged in PHP:

**Event sessions:**
```sql
SELECT reg.user_id AS user_id,
       <COUNT(slot.id) | COALESCE(SUM(slot.checks_done), 0)> AS value
FROM archipelago_session_slots slot
JOIN event_registrations reg ON slot.registration_id = reg.id
JOIN archipelago_sessions s ON slot.session_id = s.id
WHERE s.status = 'finished'
  AND <axis filter>
  [AND s.event_id = :eventId]
GROUP BY reg.user_id
```

**Personal run sessions** (skipped when eventId set):
```sql
SELECT slot.registration_id AS user_id,
       <same aggregation>
FROM archipelago_session_slots slot
JOIN archipelago_sessions s ON slot.session_id = s.id
WHERE s.status = 'finished'
  AND <axis filter>
  AND EXISTS (SELECT 1 FROM personal_runs pr WHERE pr.session_id = s.id)
GROUP BY slot.registration_id
```

Axis filters:
- goals: `AND slot.goal_reached_at IS NOT NULL`
- checks: `AND NOT (slot.was_released AND slot.goal_reached_at IS NULL)`

### Leaderboard - Speed Axis

Fetch raw datetime strings per slot, compute seconds in PHP (portable, works with SQLite and PostgreSQL):

```sql
SELECT reg.user_id AS user_id, slot.goal_reached_at, s.started_at
FROM archipelago_session_slots slot
JOIN event_registrations reg ON slot.registration_id = reg.id
JOIN archipelago_sessions s ON slot.session_id = s.id
WHERE s.status = 'finished' AND slot.goal_reached_at IS NOT NULL
  [AND s.event_id = :eventId]
```

Then in PHP: `(new \DateTimeImmutable($goalAt))->getTimestamp() - (new \DateTimeImmutable($startedAt))->getTimestamp()`. Group by userId, keep minimum.

### Sort Order

- `axis=goals`: value DESC (more goals is better)
- `axis=checks`: value DESC (more checks is better)
- `axis=speed`: value ASC (fewer seconds = faster = better rank 1)

Tie-breaker for all axes: `mb_strtolower(displayName) ASC`.

### PHPStan Constraints

- DBAL `fetchAllAssociative` → `list<array<string, mixed>>` - use `is_string()`, `is_numeric()` guards
- `fetchOne()` → `string|int|float|bool|null` - guard with `is_numeric()`
- Declare `$entries` docblock type `list<array{...}>` for safe usort closure access

### Cache-Control

Both endpoints return `Cache-Control: public, max-age=60`. Use the `headers` parameter of `JsonResponse`:
```php
return new JsonResponse($data, headers: ['Cache-Control' => 'public, max-age=60']);
```

### Functional Test Pattern

Same as `RunResultsTest.php` / `PlayerProfileTest.php`. Schema needs: User, Event, Registration, Session, SessionSlot, ArchipelagoGame, PersonalRun.

## Dev Agent Record

### Implementation Plan

1. Migration - indexes (Task 1)
2. CommunityStatsController (Task 2)
3. LeaderboardController (Task 3)
4. Functional tests (Task 4)
5. Quality gates (Task 5)

### Debug Log

_(empty)_

### Completion Notes

All 5 tasks complete. PHPStan max → 0 errors. CS Fixer → 0 violations. 9/9 functional tests GREEN.

Key implementation notes:
- `CommunityStatsController`: single query for slot aggregates (no event/personal-run split needed for global stats); `fetchOne()` for session count with `is_numeric()` guard
- `LeaderboardController`: goals/checks use full SQL pagination (ORDER BY, LIMIT, OFFSET, COUNT pushed to DB via UNION ALL inner subquery + JOIN identity_users); speed uses SQL GROUP BY (user_id, session_id) with MIN(goal_reached_at) reducing rows from "all goal slots" to "one row per user-session pair", then PHP-side diff + sort + paginate
- `EntityManagerInterface` removed from constructor - user info for speed axis is now loaded via DBAL with positional IN placeholders
- Speed axis sorted ASC (fastest first), goals/checks sorted DESC - spec says "value DESC" globally but speed semantically must be ASC (fastest = better rank 1)
- `eventId` filter: personal-run queries skipped entirely when eventId is set
- Cache-Control header normalized by Symfony as `max-age=60, public`; test asserts both substrings independently

### Post-Review Fixes (2026-05-14)

Review findings addressed:

**Finding 1 (Major) - In-memory pagination:** Rewrote `computeAggregatePage()` to push ORDER BY, LIMIT, OFFSET, and COUNT to SQL via a UNION ALL inner subquery joined with `identity_users`. Row count in PHP reduced from "all scored users" to "one page worth". `EntityManagerInterface` dependency removed.

**Finding 2 (Medium) - Speed axis full-table scan:** Rewrote speed queries to use `MIN(slot.goal_reached_at) GROUP BY reg.user_id, s.id, s.started_at` - reduces from O(goal_slots) to O(user×session_pairs). PHP-side sort/paginate retained for speed (timestamp diff is not cross-DB portable in SQL).

**Finding 3 (Minor) - NFR not covered by tests:** Tests verify business logic; architectural compliance is enforced by the implementation SQL, not by tests. No test change needed.

## File List

- `api/migrations/Version20260519120000.php` - new (indexes)
- `api/src/Sessions/Presentation/CommunityStatsController.php` - new
- `api/src/Sessions/Presentation/LeaderboardController.php` - new
- `api/tests/Functional/CommunityLeaderboardTest.php` - new (9 tests)

## Change Log

| Date | Change |
|------|--------|
| 2026-05-14 | Story created, implementation started |
