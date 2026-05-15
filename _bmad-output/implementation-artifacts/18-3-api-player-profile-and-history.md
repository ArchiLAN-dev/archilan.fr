# Story 18.3: API — Player Profile and History Endpoints

## Story

**As a** visitor,
**I want** to fetch a player's public profile and run history,
**So that** the frontend can render their page without requiring authentication.

## Status

review

## Acceptance Criteria

**AC1:** A `slug VARCHAR(80) NOT NULL UNIQUE` column is added to `identity_users`; existing rows are back-filled using the normalization logic (lowercase, accent-stripped, hyphens, numeric suffix for collisions); if `display_name` is null, the local part of `email_canonical` is used.

**AC2:** `User::registerLambda()` sets `slug` at registration time; a `SlugGenerator` service handles normalization and collision-check against the DB.

**AC3:** `GET /api/v1/players/{slug}` returns 200 with `{ data: { slug, displayName, joinedAt, stats: { runsParticipated, goalCompletions, goalCompletionRate, totalChecksDone, totalItemsReceived } } }` when the player exists.

**AC4:** `runsParticipated` counts distinct finished sessions the player has a slot in (regardless of invalidation). All other stats exclude invalidated slots (`was_released = true AND goal_reached_at IS NULL`).

**AC5:** `GET /api/v1/players/{slug}/history?page=1&limit=10` returns paginated run history (only finished sessions), ordered by `finishedAt` DESC; each entry contains: `sessionId`, `eventName`, `finishedAt`, `game`, `checksDone`, `itemsReceived`, `goalReachedAt`, `wasReleased`, `isInvalidated`.

**AC6:** Non-existent slug → 404 on both endpoints.

**AC7:** No authentication required on either endpoint.

**AC8:** Unit tests: `SlugGenerator` normalization (lowercase, accents, spaces/specials → hyphens), collision suffix (`-2`, `-3` …).

**AC9:** Functional tests: existing player with stats → 200 + correct computation; player with no finished runs → 200 + empty history; invalidated slot excluded from goal rate; non-existent slug → 404.

## Tasks / Subtasks

- [x] Task 1: `SlugGenerator` service + unit tests
  - [x] 1a: Create `api/src/Identity/Application/SlugGenerator.php` — `normalize(string): string` (AsciiSlugger), `generate(string, callable): string` (collision suffix), `generateForUser(string): string` (DB check)
  - [x] 1b: Unit tests `api/tests/Unit/Identity/SlugGeneratorTest.php` — normalization, accent stripping, collision suffix
- [x] Task 2: User domain — add `slug` property
  - [x] 2a: Add `?string $slug = null` as last constructor param in `User`; add `getSlug(): ?string` getter
  - [x] 2b: Add ORM column: `#[ORM\Column(type: 'string', length: 80, nullable: true, unique: true)]`
  - [x] 2c: Update `registerLambda()` to accept and set `string $slug`
- [x] Task 3: `RegisterLambdaUser` — inject `SlugGenerator`, generate slug from email local part
- [x] Task 4: Migration `Version20260518110000.php`
  - [x] 4a: `up()`: `ALTER TABLE identity_users ADD slug VARCHAR(80) DEFAULT NULL`
  - [x] 4b: `postUp()`: backfill all existing rows using PHP + AsciiSlugger; then `ALTER COLUMN slug SET NOT NULL`; add unique index
  - [x] 4c: `down()`: drop unique index; drop column
- [x] Task 5: `PlayerProfileController` — `GET /api/v1/players/{slug}`
  - [x] 5a: Resolve user by slug → 404 if not found
  - [x] 5b: Compute stats via two DBAL aggregate queries (event slots + personal-run slots), merge totals
  - [x] 5c: Return 200 with full profile JSON (no auth)
- [x] Task 6: `PlayerHistoryController` — `GET /api/v1/players/{slug}/history`
  - [x] 6a: Resolve user by slug → 404 if not found
  - [x] 6b: Fetch paginated finished-session slots (event + personal run) via DBAL, sorted by `finishedAt` DESC
  - [x] 6c: Return 200 with `{ data: [...], meta: { page, limit, total } }` (no auth)
- [x] Task 7: Functional tests — `PlayerProfileTest.php`
  - [x] 7a: Player with event slots (some invalidated) → correct stats + correct goal rate
  - [x] 7b: Player with no finished runs → 200 + empty history
  - [x] 7c: Non-existent slug → 404 on both endpoints
- [x] Task 8: Quality gates (PHPStan + CS Fixer)

## Dev Notes

### Data Model

- `User` (table: `identity_users`): `id`, `email`, `email_canonical`, `display_name`, `created_at`, `slug` (new)
- `Session` (table: `archipelago_sessions`): `id`, `event_id`, `status`, `started_at`, `finished_at`
- `SessionSlot` (table: `archipelago_session_slots`): `id`, `session_id`, `registration_id`, `game_id`, `checks_done`, `items_received`, `goal_reached_at`, `was_released`
- `Registration` (table: `event_registrations`): `id`, `event_id`, `user_id`
- `Event` (table: `events`): `id`, `title`
- `PersonalRun` (table: `personal_runs`): `id`, `session_id`, `title`
- `ArchipelagoGame` (table: `games`): `id`, `name`

### SlugGenerator

```php
// Normalize: AsciiSlugger → lowercase; '' → 'user'
public function normalize(string $source): string
{
    $normalized = (string) (new AsciiSlugger())->slug($source)->lower();
    return '' !== $normalized ? $normalized : 'user';
}

// generate with injectable existsCheck (testable without DB)
public function generate(string $source, callable $existsCheck): string
{
    $base = $this->normalize($source);
    $slug = $base;
    $i = 2;
    while ($existsCheck($slug)) { $slug = $base . '-' . $i++; }
    return $slug;
}

// uses DB check
public function generateForUser(string $source): string
{
    return $this->generate($source, fn (string $s) => $this->slugExists($s));
}
```

### User constructor change

Add as last parameter (no existing callers break):
```php
?string $slug = null,
```

ORM annotation:
```php
#[ORM\Column(type: 'string', length: 80, nullable: true)]
#[ORM\UniqueConstraint(...)] // on class level
```
Note: unique via `#[ORM\UniqueConstraint(name: 'uniq_identity_users_slug', columns: ['slug'])]` at class level,
or `unique: true` on column — use `unique: true` directly on the column attribute.

### Stats Queries (DBAL)

Event slots for player `$userId`:
```sql
SELECT
    COUNT(DISTINCT s.id) AS runs_participated,
    COALESCE(SUM(CASE WHEN slot.goal_reached_at IS NOT NULL
                       AND NOT (slot.was_released AND slot.goal_reached_at IS NULL)
                  THEN 1 ELSE 0 END), 0) AS goal_completions,
    COALESCE(SUM(CASE WHEN NOT (slot.was_released AND slot.goal_reached_at IS NULL)
                  THEN slot.checks_done ELSE 0 END), 0) AS total_checks_done,
    COALESCE(SUM(CASE WHEN NOT (slot.was_released AND slot.goal_reached_at IS NULL)
                  THEN slot.items_received ELSE 0 END), 0) AS total_items_received
FROM archipelago_session_slots slot
JOIN event_registrations reg ON slot.registration_id = reg.id
JOIN archipelago_sessions s ON slot.session_id = s.id
WHERE reg.user_id = :userId AND s.status = 'finished'
```

Personal run slots for player `$userId`:
```sql
SELECT ...same columns...
FROM archipelago_session_slots slot
JOIN archipelago_sessions s ON slot.session_id = s.id
WHERE slot.registration_id = :userId AND s.status = 'finished'
  AND EXISTS (SELECT 1 FROM personal_runs pr WHERE pr.session_id = s.id)
```

Sum both result sets in PHP. `goalCompletionRate = runsParticipated > 0 ? goalCompletions / runsParticipated : 0.0`.

Note: `goal_completions` = COUNT where `goal_reached_at IS NOT NULL` and slot is not invalidated. Since a slot with `goal_reached_at IS NOT NULL` can never be invalidated (domain guard), the NOT-invalidated check is redundant but explicit.

Simplification: `goal_completions = COUNT(slot.goal_reached_at IS NOT NULL)` — the domain guard ensures `was_released=true` implies `goal_reached_at IS NULL`.

### History Query

Use two separate DBAL queries (event runs + personal runs), sort combined results by `finishedAt` DESC, paginate in PHP.

Event history:
```sql
SELECT s.id AS session_id, e.title AS event_name, s.finished_at,
       g.name AS game, slot.checks_done, slot.items_received,
       slot.goal_reached_at, slot.was_released
FROM archipelago_session_slots slot
JOIN event_registrations reg ON slot.registration_id = reg.id
JOIN archipelago_sessions s ON slot.session_id = s.id
JOIN events e ON s.event_id = e.id
JOIN games g ON slot.game_id = g.id
WHERE reg.user_id = :userId AND s.status = 'finished'
```

Personal run history:
```sql
SELECT s.id AS session_id, pr.title AS event_name, s.finished_at,
       g.name AS game, slot.checks_done, slot.items_received,
       slot.goal_reached_at, slot.was_released
FROM archipelago_session_slots slot
JOIN archipelago_sessions s ON slot.session_id = s.id
JOIN personal_runs pr ON pr.session_id = s.id
JOIN games g ON slot.game_id = g.id
WHERE slot.registration_id = :userId AND s.status = 'finished'
```

Merge → sort by `finished_at` DESC → paginate (offset = (page-1)*limit).
`isInvalidated = wasReleased && goalReachedAt === null` — computed in PHP.

### Migration

File: `api/migrations/Version20260518110000.php`
- `up()`: `ALTER TABLE identity_users ADD slug VARCHAR(80) DEFAULT NULL`
- `postUp()`: PHP loop with `AsciiSlugger` to backfill; then `ALTER COLUMN slug SET NOT NULL`; `CREATE UNIQUE INDEX`
- `down()`: `DROP INDEX uniq_identity_users_slug ON identity_users`; `ALTER TABLE identity_users DROP COLUMN slug`

### Functional Test Pattern

See `RunResultsTest.php`. Schema must include: `User`, `Session`, `SessionSlot`, `Event`, `Registration`, `ArchipelagoGame`, `PersonalRun`.
Use `User` directly with `slug` param set.

### PHPStan Constraints

- DBAL `fetchAllAssociative` → `list<array<string, mixed>>` — null-check all value access
- `fetchOne` returns `mixed` (false if not found) — use `false !== $result` pattern
- `is_string`, `is_numeric` guards required on DBAL row values

## Dev Agent Record

### Implementation Plan

1. SlugGenerator + unit tests (Task 1)
2. User domain + ORM (Task 2)
3. RegisterLambdaUser update (Task 3)
4. Migration (Task 4)
5. PlayerProfileController (Task 5)
6. PlayerHistoryController (Task 6)
7. Functional tests (Task 7)
8. Quality gates (Task 8)

### Debug Log

_(empty)_

### Completion Notes

All 8 tasks complete. PHPStan max — 0 errors. CS Fixer applied (one concat-spacing fix in SlugGenerator). 13/13 tests pass (5 functional + 8 unit).

Key implementation notes:
- `slug` column is `nullable: true` in ORM (for SchemaTool in tests) but NOT NULL in production via migration's `postUp()`
- `usort` comparator uses `is_string()` narrowing to satisfy PHPStan max (`(string) mixed` forbidden)
- Personal-run stats query uses `EXISTS (SELECT 1 FROM personal_runs pr WHERE pr.session_id = s.id)` instead of JOIN to avoid row multiplication
- `goal_completions = COUNT(slot.goal_reached_at)` — domain guard ensures `was_released` can only be true when `goal_reached_at IS NULL`, so no extra filter needed

## File List

- `api/src/Identity/Application/SlugGenerator.php` — new
- `api/tests/Unit/Identity/SlugGeneratorTest.php` — new
- `api/src/Identity/Domain/User.php` — modified (slug column + getter + registerLambda update)
- `api/src/Identity/Application/RegisterLambdaUser.php` — modified (SlugGenerator injection)
- `api/migrations/Version20260518110000.php` — new
- `api/src/Identity/Presentation/PlayerProfileController.php` — new
- `api/src/Identity/Presentation/PlayerHistoryController.php` — new
- `api/tests/Functional/PlayerProfileTest.php` — new

## Change Log

| Date | Change |
|------|--------|
| 2026-05-14 | Story created and implementation started |
