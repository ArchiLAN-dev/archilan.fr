# Story 19.2: Extract SQL Reads from Epic 18 Controllers

## Story

**As a** developer,
**I want** the 5 Epic 18 public-API controllers to delegate all SQL reads to dedicated Application query classes,
**So that** the Presentation layer contains zero DB infrastructure and the CQRS boundary is enforced end-to-end.

## Status

review

## Acceptance Criteria

**AC1:** `Sessions\Presentation\LeaderboardController` no longer injects `Doctrine\DBAL\Connection`. All aggregate-page and speed-page SQL delegated to `Sessions\Application\LeaderboardQuery`.

**AC2:** `Sessions\Presentation\CommunityStatsController` no longer injects `Doctrine\DBAL\Connection`. Stats query delegated to `Sessions\Application\CommunityStatsQuery`.

**AC3:** `Sessions\Presentation\RunResultsController` no longer injects `Doctrine\ORM\EntityManagerInterface`. All ORM lookups and DQL queries delegated to `Sessions\Application\RunResultsQuery`.

**AC4:** `Identity\Presentation\PlayerProfileController` no longer injects `EntityManagerInterface` or `Connection`. User lookup + stats queries delegated to `Identity\Application\PlayerProfileQuery`.

**AC5:** `Identity\Presentation\PlayerHistoryController` no longer injects `EntityManagerInterface` or `Connection`. User lookup + history queries delegated to `Identity\Application\PlayerHistoryQuery`.

**AC6:** All 20 functional tests covering these controllers pass unchanged (`CommunityLeaderboardTest`, `PlayerProfileTest`, `RunResultsTest`).

**AC7:** Running `app:architecture:ddd` reports 32 CQRS violations (down from 47 in Story 19.1 baseline — 15 eliminated by this story).

**AC8:** PHPStan level max: 0 errors on all 10 modified/new files. CS Fixer @Symfony: 0 violations.

## Tasks / Subtasks

- [x] Task 1: Create story file (this file)
- [x] Task 2: Create Application query classes
  - [x] 2a: `Sessions\Application\LeaderboardQuery` — `computeAggregatePage()` + `computeSpeedPage()`
  - [x] 2b: `Sessions\Application\CommunityStatsQuery` — `execute(): array{...}`
  - [x] 2c: `Sessions\Application\RunResultsQuery` — `execute(string $id): ?array`
  - [x] 2d: `Identity\Application\PlayerProfileQuery` — `execute(string $slug): ?array`
  - [x] 2e: `Identity\Application\PlayerHistoryQuery` — `execute(string $slug, int $page, int $limit): ?array`
- [x] Task 3: Refactor controllers to inject query classes
  - [x] 3a: `LeaderboardController` — injects `LeaderboardQuery`, delegates compute methods
  - [x] 3b: `CommunityStatsController` — injects `CommunityStatsQuery`, one-liner action
  - [x] 3c: `RunResultsController` — injects `RunResultsQuery`, null-check → 404
  - [x] 3d: `PlayerProfileController` — injects `PlayerProfileQuery`, null-check → 404
  - [x] 3e: `PlayerHistoryController` — injects `PlayerHistoryQuery`, null-check → 404
- [x] Task 4: Quality gates
  - [x] PHPStan level max — 0 errors
  - [x] CS Fixer @Symfony — 0 violations
  - [x] Functional tests — 20/20 pass
  - [x] `app:architecture:ddd` — 32 violations (down from 47)

## Dev Notes

### Query class design

Each query class:
- Is `final readonly` (immutable service)
- Injects DB infrastructure (`Connection`, `EntityManagerInterface`) at construction
- Returns typed arrays (`array{...}|null`) — no raw Doctrine entities escape to the controller
- Returns `null` when the primary entity is not found; the controller converts that to a 404

### Null-return pattern

Controllers adopt a uniform null-check idiom:
```php
$result = $this->someQuery->execute($id);
if (null === $result) {
    return $this->apiAccessGuard->errorResponse('error_code', 'Message.', 404);
}
return new JsonResponse(['data' => $result]);
```

### `PlayerHistoryQuery` array_map

The `array_map(function (array $row): array {...}, $pageRows)` closure cannot be static because the original code's non-static style was preserved exactly. No PHPStan issue arises since `@Symfony` CS Fixer preset does not enforce `static_lambda`.

## Dev Agent Record

### Completion Notes

- 5 Application query classes created (2 in `Sessions\Application`, 2 in `Identity\Application`, 1 in `Sessions\Application`)
- 5 controllers refactored — all DB infrastructure imports removed from Presentation layer
- PHPStan level max: 0 errors on all 10 files
- CS Fixer @Symfony: 0 violations on all 10 files
- Functional tests: 20/20 pass (no behavioural changes)
- `app:architecture:ddd`: 32 violations remaining (down from 47 — 15 eliminated)

### Remaining CQRS Violations Baseline (32 violations, 14 controllers)

| Module | Controllers |
|---|---|
| Events | `AdminEventCoverImageController`, `AdminEventGalleryController` |
| Content | `AdminPostCoverImageController` |
| Registrations | `RegistrationController` |
| Sessions | `AllGoalController`, `ApworldDownloadUrlController`, `CommandsController`, `ContainerController`, `DownloadController`, `ExportController`, `FeedTokenController`, `ForceEndController`, `LogsController`, `PlayerStateController`, `PublisherTokenController`, `SessionOrchestrationController`, `SessionResultsController` |
| CatalogSync | `CatalogSyncController`, `PublicCatalogGamesController` |

## File List

- `api/src/Sessions/Application/LeaderboardQuery.php` — new
- `api/src/Sessions/Application/CommunityStatsQuery.php` — new
- `api/src/Sessions/Application/RunResultsQuery.php` — new
- `api/src/Identity/Application/PlayerProfileQuery.php` — new
- `api/src/Identity/Application/PlayerHistoryQuery.php` — new
- `api/src/Sessions/Presentation/LeaderboardController.php` — modified (removed `Connection`, injects `LeaderboardQuery`)
- `api/src/Sessions/Presentation/CommunityStatsController.php` — modified (removed `Connection`, injects `CommunityStatsQuery`)
- `api/src/Sessions/Presentation/RunResultsController.php` — modified (removed `EntityManagerInterface`, injects `RunResultsQuery`)
- `api/src/Identity/Presentation/PlayerProfileController.php` — modified (removed `EntityManagerInterface` + `Connection`, injects `PlayerProfileQuery`)
- `api/src/Identity/Presentation/PlayerHistoryController.php` — modified (removed `EntityManagerInterface` + `Connection`, injects `PlayerHistoryQuery`)
- `_bmad-output/implementation-artifacts/19-2-extract-reads-epic18-controllers.md` — this file

## Change Log

| Date | Change |
|------|--------|
| 2026-05-14 | Story implemented |
