# Story 18.2: API - Public Run Results Endpoint

## Story

**As a** visitor or player,
**I want** to fetch the results of a completed run via a public API endpoint,
**So that** the frontend can display per-slot stats without requiring authentication.

## Status

done

## Acceptance Criteria

**AC1:** `GET /api/v1/runs/{id}/results` for a `finished` session returns 200 with the specified JSON structure (sessionId, eventName, startedAt, finishedAt, durationSeconds, slots).

**AC2:** Each slot includes: slotId, playerName, game, checksDone, itemsReceived, goalReachedAt (ISO8601|null), completionSeconds (int|null), wasReleased, isInvalidated.

**AC3:** `isInvalidated = wasReleased && goalReachedAt === null`.

**AC4:** `completionSeconds = (goalReachedAt - session.startedAt)` in whole seconds, or `null` if goalReachedAt is null.

**AC5:** Slots are ordered: goal-reached first (completionSeconds asc), then incomplete (no goal, not released), then invalidated.

**AC6:** Non-finished session → 404 with `run_not_found_or_not_finished`.

**AC7:** Non-existent session ID → 404.

**AC8:** No authentication required on this endpoint.

**AC9:** Functional tests cover all ACs.

## Tasks / Subtasks

- [x] Task 1: Create `RunResultsController` in `api/src/Sessions/Presentation/`
  - [x] 1a: Route `GET /api/v1/runs/{id}/results`, no auth guard
  - [x] 1b: Return 404 if session not found or not `finished`
  - [x] 1c: Resolve `eventName` - from `Event::getTitle()` or `PersonalRun::getTitle()`
  - [x] 1d: Load slots and resolve playerName (Registration→User for events; User directly for personal runs), game name - batch-loaded to avoid N+1
  - [x] 1e: Compute `completionSeconds`, `isInvalidated`; apply slot ordering (goal→incomplete→invalidated)
  - [x] 1f: Return 200 with full JSON payload
- [x] Task 2: Create `RunResultsTest` functional test
  - [x] 2a: finished event session → 200, correct payload, correct slot ordering (goal-reached sorted by completionSeconds asc, then incomplete, then invalidated)
  - [x] 2b: invalidated slot appears with `isInvalidated: true`, `wasReleased: true`
  - [x] 2c: non-finished session → 404 `run_not_found_or_not_finished`
  - [x] 2d: non-existent session → 404
- [x] Task 3: Quality gates - PHPStan 0 errors, CS Fixer 0 violations

## Dev Notes

### Data Model

- `Session`: `id`, `eventId` (event ID for event sessions; personal run ID for personal runs), `status`, `startedAt`, `finishedAt`
- `SessionSlot`: `id`, `sessionId`, `registrationId` (Registration.id for events; userId for personal runs), `gameId`, `slotName`, `slotOrder`, `checksDone`, `itemsReceived`, `goalReachedAt`, `wasReleased`
- `Event` (table: `events`): `id`, `title`
- `PersonalRun` (table: `personal_runs`): `id`, `sessionId`, `title`
- `Registration` (table: `registrations`): `id`, `userId`, `eventId`
- `User` (table: `identity_users`): `id`, `displayName` (nullable), `email`
- `ArchipelagoGame` (table: `games`): `id`, `name`

### eventName Resolution

```
$event = em->find(Event::class, $session->getEventId());
if ($event instanceof Event) { $eventName = $event->getTitle(); }
else {
    $pr = em->getRepository(PersonalRun::class)->findOneBy(['id' => $session->getEventId()]);
    $eventName = $pr?->getTitle() ?? 'Run';
}
```

### playerName Resolution

For event sessions: `registrationId` → Registration → userId → User → `displayName ?? email`
For personal runs: `registrationId` = userId → User → `displayName ?? email`

Distinguish: if `PersonalRun` exists for this session, it's a personal run.

### Slot Ordering

Priority: 0 = goal-reached (sort by completionSeconds asc), 1 = incomplete, 2 = invalidated. Use `usort`.

### completionSeconds

```php
$completionSeconds = null;
if (null !== $slot->getGoalReachedAt() && null !== $session->getStartedAt()) {
    $completionSeconds = $slot->getGoalReachedAt()->getTimestamp() - $session->getStartedAt()->getTimestamp();
}
```

### Functional Test Pattern

See `SessionActivityTest.php` - uses `WebTestCase`, `SchemaTool::createSchema`, creates entities directly via EntityManager. Include all relevant entity class metadata in `createSchema`.

### PHPStan Constraints

- All `->find()` calls return `null|Entity` - always null-check.
- `getTimestamp()` returns `int` - safe arithmetic.

## Dev Agent Record

### Implementation Plan

1. Controller (Task 1) - all logic inline in the controller (no separate service needed for this story scope)
2. Functional tests (Task 2) - RED before GREEN
3. Quality gates (Task 3)

### Debug Log

_(empty)_

### Completion Notes

- `RunResultsController`: route `GET /api/v1/runs/{id}/results`, no auth, 404 on non-finished/non-existent
- eventName resolution: tries `Event` first, falls back to `PersonalRun`, then `'Run'`
- playerName: for event sessions batch-loads Registrations → userIds → Users; for personal runs `registrationId` IS userId
- completionSeconds: `goalReachedAt - startedAt` in whole seconds (null if no goal)
- isInvalidated: `wasReleased && goalReachedAt === null`
- Slot ordering: priority 0=goal-reached (asc by completionSeconds), 1=incomplete, 2=invalidated via `usort`
- PHPStan level max: 0 errors; CS Fixer: 0 violations; 5/5 functional tests GREEN
- Post-review fixes: `slotId` fallback to `$slot->getId()` (added `getId()` getter to `SessionSlot`); personal run test added; `PersonalRun` added to test schema

## File List

- `api/src/Sessions/Presentation/RunResultsController.php` (new)
- `api/src/Sessions/Domain/SessionSlot.php` (modified - added `getId()` getter)
- `api/tests/Functional/RunResultsTest.php` (new - 5 tests including personal run)
- `_bmad-output/implementation-artifacts/18-2-api-public-run-results-endpoint.md` (updated)

## Change Log

| Date | Change |
|------|--------|
| 2026-05-14 | Story created |
