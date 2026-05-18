# Story 19.3: Extract SQL Reads from Sessions Presentation Controllers

## Story

**As a** developer,
**I want** the 10 Sessions Presentation controllers to delegate all SQL reads to dedicated Application query classes,
**So that** the Presentation layer contains zero DB infrastructure and the CQRS boundary is enforced end-to-end.

## Status

done

## Acceptance Criteria

**AC1:** `Sessions\Presentation\SessionResultsController` no longer injects `EntityManagerInterface`. All ORM lookups and DQL queries delegated to `Sessions\Application\SessionResultsQuery`.

**AC2:** `Sessions\Presentation\ExportController` no longer injects `EntityManagerInterface`. All ORM lookups and DQL queries delegated to `Sessions\Application\SessionExportQuery`.

**AC3:** `Sessions\Presentation\PlayerStateController` no longer injects `EntityManagerInterface`. Session lookup + authorization queries delegated to `Sessions\Application\SessionQuery`.

**AC4:** `Sessions\Presentation\FeedTokenController` no longer injects `EntityManagerInterface`. Session lookup + registration check delegated to `Sessions\Application\SessionQuery`.

**AC5:** `Sessions\Presentation\ApworldDownloadUrlController` no longer injects `EntityManagerInterface`. Game lookup delegated to `Sessions\Application\ApworldQuery`.

**AC6:** `Sessions\Presentation\SessionOrchestrationController` no longer injects `EntityManagerInterface`. Session lookup in `downloadGeneration()` delegated to `Sessions\Application\SessionQuery`.

**AC7:** `Sessions\Presentation\ContainerController` no longer injects `EntityManagerInterface`. Session existence check delegated to `Sessions\Application\SessionQuery`.

**AC8:** `Sessions\Presentation\DownloadController` no longer injects `EntityManagerInterface`. Session lookup delegated to `Sessions\Application\SessionQuery`.

**AC9:** `Sessions\Presentation\LogsController` no longer injects `EntityManagerInterface`. Session lookup delegated to `Sessions\Application\SessionQuery`.

**AC10:** `Sessions\Presentation\PublisherTokenController` no longer injects `EntityManagerInterface`. Session lookup delegated to `Sessions\Application\SessionQuery`.

**AC11:** All functional tests covering these controllers pass unchanged (`FeedTokenTest`, `TraefikAndPublisherTokenTest`, and all other tests).

**AC12:** Running `app:architecture:ddd` reports 14 CQRS violations (down from 32 in Story 19.2 baseline - 18 eliminated by this story).

**AC13:** PHPStan level max: 0 errors on all 14 modified/new files. CS Fixer @Symfony: 0 violations.

## Tasks / Subtasks

- [x] Task 1: Create story file (this file)
- [x] Task 2: Create Application query classes
  - [x] 2a: `Sessions\Application\SessionQuery` - `findById()` + `hasActiveEventRegistration()` + `isUserAuthorizedForSession()`
  - [x] 2b: `Sessions\Application\SessionResultsQuery` - `findForEvent(string $eventId): ?array`
  - [x] 2c: `Sessions\Application\SessionExportQuery` - `findSlotsForSession(string $sessionId): ?array`
  - [x] 2d: `Sessions\Application\ApworldQuery` - `findApworldMinioKey(string $sha256): ?string`
- [x] Task 3: Refactor controllers to inject query classes
  - [x] 3a: `SessionResultsController` - injects `SessionResultsQuery`, delegates all ORM reads
  - [x] 3b: `ExportController` - injects `SessionExportQuery`, CSV/JSON formatting stays in controller
  - [x] 3c: `PlayerStateController` - injects `SessionQuery`, authorization delegated
  - [x] 3d: `FeedTokenController` - injects `SessionQuery`, registration check delegated
  - [x] 3e: `ApworldDownloadUrlController` - injects `ApworldQuery`, minio key lookup delegated
  - [x] 3f: `SessionOrchestrationController` - injects `SessionQuery` for `downloadGeneration()` only
  - [x] 3g: `ContainerController` - injects `SessionQuery` for existence check
  - [x] 3h: `DownloadController` - injects `SessionQuery`, path lookup delegated
  - [x] 3i: `LogsController` - injects `SessionQuery`, status + logs lookup delegated
  - [x] 3j: `PublisherTokenController` - injects `SessionQuery` for existence check
- [x] Task 4: Quality gates
  - [x] PHPStan level max - 0 errors
  - [x] CS Fixer @Symfony - 0 violations
  - [x] Functional tests - all pass
  - [x] `app:architecture:ddd` - 14 violations (down from 32)

## Dev Notes

### Query class responsibilities

| Query class | Consumers | Key methods |
|---|---|---|
| `SessionQuery` | 7 controllers | `findById()`, `hasActiveEventRegistration()`, `isUserAuthorizedForSession()` |
| `SessionResultsQuery` | `SessionResultsController` | `findForEvent()` - null=event not found, session=null=no finished session |
| `SessionExportQuery` | `ExportController` | `findSlotsForSession()` - null=session not found |
| `ApworldQuery` | `ApworldDownloadUrlController` | `findApworldMinioKey()` - null=not found or no key |

### Deferred controllers (19.4+)

`AllGoalController`, `CommandsController`, `ForceEndController` - all involve writes (persist/flush/entity state transitions) and require Application command services, not query classes.

### Violation count

18 violations eliminated (10 EM imports + 2×`createQueryBuilder` calls + 2×`getRepository` calls + 2×`createQueryBuilder` calls in PlayerState + 2×`getRepository` calls in PlayerState and ApworldDownload), leaving 14 remaining.

## Dev Agent Record

### Completion Notes

- 4 Application query classes created in `Sessions\Application`
- 10 controllers refactored - all DB infrastructure imports removed from Presentation layer
- PHPStan level max: 0 errors on all 14 files
- CS Fixer @Symfony: 0 violations on all 14 files
- Functional tests: all pass (36 assertions across FeedToken, TraefikAndPublisherToken, PlayerState, AdminServerCommands, RunResults)
- `app:architecture:ddd`: 14 violations remaining (down from 32 - 18 eliminated)
- Post-review fix: `PlayerStateTest` and `TraefikAndPublisherTokenTest` had missing `PersonalRun` + `PersonalRunParticipant` in their `SchemaTool::createSchema()` metadata - `SessionQuery.isUserAuthorizedForSession()` and `SessionLifecycleManager` both query `personal_runs` at runtime

### Remaining CQRS Violations Baseline (14 violations)

| Module | Controllers |
|---|---|
| Sessions | `AllGoalController` (1), `CommandsController` (2), `ForceEndController` (1) |
| Events | `AdminEventCoverImageController` (1), `AdminEventGalleryController` (1) |
| Content | `AdminPostCoverImageController` (1) |
| Registrations | `RegistrationController` (2) |
| CatalogSync | `CatalogSyncController` (3), `PublicCatalogGamesController` (2) |

## File List

- `api/src/Sessions/Application/SessionQuery.php` - new
- `api/src/Sessions/Application/SessionResultsQuery.php` - new
- `api/src/Sessions/Application/SessionExportQuery.php` - new
- `api/src/Sessions/Application/ApworldQuery.php` - new
- `api/src/Sessions/Presentation/SessionResultsController.php` - modified
- `api/src/Sessions/Presentation/ExportController.php` - modified
- `api/src/Sessions/Presentation/PlayerStateController.php` - modified
- `api/src/Sessions/Presentation/FeedTokenController.php` - modified
- `api/src/Sessions/Presentation/ApworldDownloadUrlController.php` - modified
- `api/src/Sessions/Presentation/SessionOrchestrationController.php` - modified
- `api/src/Sessions/Presentation/ContainerController.php` - modified
- `api/src/Sessions/Presentation/DownloadController.php` - modified
- `api/src/Sessions/Presentation/LogsController.php` - modified
- `api/src/Sessions/Presentation/PublisherTokenController.php` - modified
- `api/tests/Functional/PlayerStateTest.php` - modified (added PersonalRun + PersonalRunParticipant to schema)
- `api/tests/Functional/TraefikAndPublisherTokenTest.php` - modified (added PersonalRun + PersonalRunParticipant to schema)
- `_bmad-output/implementation-artifacts/19-3-extract-reads-sessions-controllers.md` - this file

## Change Log

| Date | Change |
|------|--------|
| 2026-05-14 | Story implemented |
| 2026-05-14 | Post-review fix: added PersonalRun/PersonalRunParticipant to test schemas |
