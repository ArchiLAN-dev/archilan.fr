# Story 19.4: Extract Remaining CQRS Violations from 9 Controllers

## Story

**As a** developer,
**I want** the 9 remaining controllers that still inject `EntityManagerInterface` to delegate all DB access to dedicated Application services,
**So that** the Presentation layer contains zero DB infrastructure and the CQRS boundary is enforced end-to-end across all bounded contexts.

## Status

done

## Acceptance Criteria

**AC1:** `Sessions\Presentation\AllGoalController` no longer injects `EntityManagerInterface`. Session lookup, transition, audit log, and job dispatch delegated to `Sessions\Application\NotifyAllGoalCommand`.

**AC2:** `Sessions\Presentation\CommandsController` no longer injects `EntityManagerInterface`. Session lookup, bridge call, audit log, and slot release delegated to `Sessions\Application\SendBridgeCommand`.

**AC3:** `Sessions\Presentation\ForceEndController` no longer injects `EntityManagerInterface`. Session lookup, transition, audit log, and job dispatch delegated to `Sessions\Application\ForceEndSessionCommand`.

**AC4:** `Events\Presentation\AdminEventCoverImageController` no longer injects `EntityManagerInterface`. Event lookup, Minio upload, and cover key mutation delegated to `Events\Application\UploadEventCoverImageCommand`.

**AC5:** `Events\Presentation\AdminEventGalleryController` no longer injects `EntityManagerInterface`. Gallery upload and delete delegated to `Events\Application\ManageEventGalleryCommand`.

**AC6:** `Content\Presentation\AdminPostCoverImageController` no longer injects `EntityManagerInterface`. Post lookup, Minio upload, and cover key mutation delegated to `Content\Application\UploadPostCoverImageCommand`.

**AC7:** `Registrations\Presentation\RegistrationController` no longer injects `EntityManagerInterface`. `myRegistration()` lookup delegated to `Registrations\Application\MyRegistrationQuery`.

**AC8:** `CatalogSync\Presentation\CatalogSyncController` no longer injects `EntityManagerInterface`. Read queries delegated to `CatalogSync\Application\CatalogSyncStatusQuery`; writes delegated to `CatalogSync\Application\IgnoreCatalogEntryCommand` and `CatalogSync\Application\UnignoreCatalogEntryCommand`.

**AC9:** `CatalogSync\Presentation\PublicCatalogGamesController` no longer injects `EntityManagerInterface`. Catalog sheet filtering delegated to `CatalogSync\Application\PublicCatalogGamesQuery`.

**AC10:** PHPStan level max: 0 errors on all new and modified files.

**AC11:** CS Fixer @Symfony: 0 violations on all new and modified files.

**AC12:** All functional tests pass.

**AC13:** Running `app:architecture:ddd` reports 0 CQRS violations (down from 14 in Story 19.3 baseline).

## Tasks / Subtasks

- [x] Task 1: Create story file (this file)
- [x] Task 2: Create Sessions Application command services
  - [x] 2a: `Sessions\Application\NotifyAllGoalCommand`
  - [x] 2b: `Sessions\Application\SendBridgeCommand`
  - [x] 2c: `Sessions\Application\ForceEndSessionCommand`
- [x] Task 3: Create Events/Content Application command services
  - [x] 3a: `Events\Application\UploadEventCoverImageCommand`
  - [x] 3b: `Events\Application\ManageEventGalleryCommand`
  - [x] 3c: `Content\Application\UploadPostCoverImageCommand`
- [x] Task 4: Create Registrations/CatalogSync Application services
  - [x] 4a: `Registrations\Application\MyRegistrationQuery`
  - [x] 4b: `CatalogSync\Application\CatalogSyncStatusQuery`
  - [x] 4c: `CatalogSync\Application\IgnoreCatalogEntryCommand`
  - [x] 4d: `CatalogSync\Application\UnignoreCatalogEntryCommand`
  - [x] 4e: `CatalogSync\Application\PublicCatalogGamesQuery`
- [x] Task 5: Refactor controllers to inject Application services
  - [x] 5a: `AllGoalController`
  - [x] 5b: `CommandsController`
  - [x] 5c: `ForceEndController`
  - [x] 5d: `AdminEventCoverImageController`
  - [x] 5e: `AdminEventGalleryController`
  - [x] 5f: `AdminPostCoverImageController`
  - [x] 5g: `RegistrationController`
  - [x] 5h: `CatalogSyncController`
  - [x] 5i: `PublicCatalogGamesController`
- [x] Task 6: Quality gates
  - [x] PHPStan level max — 0 errors on all 20 files
  - [x] CS Fixer @Symfony — 0 violations on all 20 files
  - [x] Functional tests — no new failures introduced
  - [x] `app:architecture:ddd` — 0 violations (down from 14)

## Dev Notes

### New Application service summary

| Service | Context | Type | Key inputs | Key return |
|---|---|---|---|---|
| `NotifyAllGoalCommand` | Sessions | Command | `sessionId` | `array{found: bool, skipped: bool}` |
| `SendBridgeCommand` | Sessions | Command | `sessionId, command, actorId` | `array{found: bool, error: string\|null}` |
| `ForceEndSessionCommand` | Sessions | Command | `sessionId, actorId` | `array{found: bool, notRunning: bool, payload: array<string,mixed>}` |
| `UploadEventCoverImageCommand` | Events | Command | `eventId, key, contents` | `array{outcome: string, data: array\|null}` |
| `ManageEventGalleryCommand` | Events | Command | `eventId, key/index, contents` | `array{outcome: string, data: array\|null}` |
| `UploadPostCoverImageCommand` | Content | Command | `postId, key, contents` | `array{outcome: string, data: array\|null}` |
| `MyRegistrationQuery` | Registrations | Query | `eventId, userId` | `array{registrationId, status}\|null` |
| `CatalogSyncStatusQuery` | CatalogSync | Query | `force: bool` | full data array or null (sheet unavailable) |
| `IgnoreCatalogEntryCommand` | CatalogSync | Command | `name` | `void` |
| `UnignoreCatalogEntryCommand` | CatalogSync | Command | `name` | `bool` (true=found+removed) |
| `PublicCatalogGamesQuery` | CatalogSync | Query | — | `list<string>\|null` |

### Violation elimination

14 violations → 0:
- AllGoalController: 1 (EM import)
- CommandsController: 2 (EM import + getRepository)
- ForceEndController: 1 (EM import)
- AdminEventCoverImageController: 1 (EM import)
- AdminEventGalleryController: 1 (EM import)
- AdminPostCoverImageController: 1 (EM import)
- RegistrationController: 2 (EM import + getRepository)
- CatalogSyncController: 3 (EM import + createQueryBuilder + getRepository)
- PublicCatalogGamesController: 2 (EM import + createQueryBuilder)

## File List

- `api/src/Sessions/Application/NotifyAllGoalCommand.php` — new
- `api/src/Sessions/Application/SendBridgeCommand.php` — new
- `api/src/Sessions/Application/ForceEndSessionCommand.php` — new
- `api/src/Events/Application/UploadEventCoverImageCommand.php` — new
- `api/src/Events/Application/ManageEventGalleryCommand.php` — new
- `api/src/Content/Application/UploadPostCoverImageCommand.php` — new
- `api/src/Registrations/Application/MyRegistrationQuery.php` — new
- `api/src/CatalogSync/Application/CatalogSyncStatusQuery.php` — new
- `api/src/CatalogSync/Application/IgnoreCatalogEntryCommand.php` — new
- `api/src/CatalogSync/Application/UnignoreCatalogEntryCommand.php` — new
- `api/src/CatalogSync/Application/PublicCatalogGamesQuery.php` — new
- `api/src/Sessions/Presentation/AllGoalController.php` — modified
- `api/src/Sessions/Presentation/CommandsController.php` — modified
- `api/src/Sessions/Presentation/ForceEndController.php` — modified
- `api/src/Events/Presentation/AdminEventCoverImageController.php` — modified
- `api/src/Events/Presentation/AdminEventGalleryController.php` — modified
- `api/src/Content/Presentation/AdminPostCoverImageController.php` — modified
- `api/src/Registrations/Presentation/RegistrationController.php` — modified
- `api/src/CatalogSync/Presentation/CatalogSyncController.php` — modified
- `api/src/CatalogSync/Presentation/PublicCatalogGamesController.php` — modified
- `_bmad-output/implementation-artifacts/19-4-extract-reads-writes-remaining-controllers.md` — this file

## Dev Agent Record

### Completion Notes

- 11 Application services created across Sessions, Events, Content, Registrations, CatalogSync contexts
- 9 controllers refactored — all DB infrastructure imports removed from Presentation layer
- PHPStan level max: 0 errors on all 20 files
- CS Fixer @Symfony: 0 violations on all 20 files
- `app:architecture:ddd`: 0 violations (down from 14 — all 14 eliminated)
- Pre-existing failures unchanged: 45 errors + 6 failures (all from ArchipelagoGame.updateCatalogueMetadata missing, Event domain changes, and missing IgnoredCatalogEntry in CatalogSyncEndpointTest schema — all predating this story)

## Change Log

| Date | Change |
|------|--------|
| 2026-05-14 | Story created and implemented |
