# Story 19.1: Extend `DddArchitectureValidator` - Missing Contexts + CQRS Rules

## Story

**As a** developer,
**I want** the `app:architecture:ddd` command to detect all CQRS boundary violations and know about every bounded context,
**So that** no future controller can bypass the architecture without a CI failure.

## Status

review

## Acceptance Criteria

**AC1:** The following bounded contexts are registered in the validator and their directories validated: `Sessions`, `PersonalRuns`, `CatalogSync`, `Streaming`.

**AC2:** Given a Presentation layer file that imports `Doctrine\DBAL\Connection` or `Doctrine\ORM\EntityManagerInterface`, when the validator runs, then a violation is reported: `"Presentation layer must not inject DB infrastructure (Doctrine\DBAL\Connection): src/{file}"` (the matched import is shown in parentheses).

**AC3:** Given a Presentation layer file that calls any of `fetchAllAssociative`, `fetchOne`, `executeQuery`, `createQueryBuilder`, `createQuery`, `getRepository`, when the validator runs, then a violation is reported: `"Presentation layer must not execute queries directly (fetchAllAssociative): src/{file}"` (the matched method name is shown in parentheses).

**AC4:** Given an Application layer file that imports `Connection` or `EntityManagerInterface`, when the validator runs, then NO violation is reported for those imports.

**AC5:** Unit tests cover: Presentation with forbidden import → violation; Presentation with forbidden method → violation; Application with same imports → no violation; clean controller → no violation.

**AC6:** `services.yaml` excludes `src/CatalogSync/Domain/` from autowiring.

**AC7:** Running `app:architecture:ddd` on the current codebase (pre-refactor) exits non-zero and lists all CQRS violations as a documented baseline.

## Tasks / Subtasks

- [x] Task 1: Create story file (this file)
- [x] Task 2: Extend `DddArchitectureValidator`
  - [x] 2a: Add `Sessions`, `PersonalRuns`, `CatalogSync`, `Streaming` to `CONTEXTS`
  - [x] 2b: Simplify `validateContextDirectories` - only require the context directory to exist, not all 4 layer sub-dirs (the file placement check handles the rest)
  - [x] 2c: Add `validatePresentationCqrs(string $srcDir): list<string>` method
  - [x] 2d: Wire `validatePresentationCqrs` into `validate()`
- [x] Task 3: Update `services.yaml` - add `src/CatalogSync/Domain/` to autowiring excludes
- [x] Task 4: Write unit tests `tests/Unit/DddArchitectureValidatorTest.php`
  - [x] 4a: Presentation with forbidden `Connection` import → violation reported
  - [x] 4b: Presentation with forbidden `EntityManagerInterface` import → violation reported
  - [x] 4c: Presentation with forbidden SQL method call (`fetchAllAssociative`) → violation reported
  - [x] 4d: Application with `EntityManagerInterface` + `fetchAllAssociative` → no CQRS violation
  - [x] 4e: Clean Presentation controller → no violation
- [x] Task 5: Quality gates (PHPStan + CS Fixer + tests)
- [x] Task 6: Run `app:architecture:ddd` → baseline violations documented

## Dev Notes

### CQRS scan logic

Scan all files under `*/Presentation/`:
1. **Forbidden imports** - search for `Doctrine\DBAL\Connection` or `Doctrine\ORM\EntityManagerInterface` in `use` statements or constructor type hints
2. **Forbidden method calls** - search for any of: `fetchAllAssociative`, `fetchOne`, `executeQuery`, `createQueryBuilder`, `createQuery`, `getRepository`

Use `str_contains()` on file contents - no AST parsing needed at this stage.

### Why simplify directory check

`CatalogSync` and `PersonalRuns` have no `Infrastructure/` layer yet (no external infrastructure needed). Requiring all 4 layers would produce false positive violations. The `validateSourceFiles` check already ensures every PHP file is in a valid layer - the directory existence check is redundant and noisy.

### services.yaml

`CatalogEntry` is a value object (`final readonly class`, no ORM annotations). Still needs to be excluded from autowiring scan to avoid Symfony trying to instantiate it as a service.

## Dev Agent Record

### Completion Notes

- `DddArchitectureValidator::CONTEXTS` extended with `Sessions`, `PersonalRuns`, `CatalogSync`, `Streaming`
- `validateContextDirectories` simplified: only checks that the context directory exists (not all 4 layer sub-dirs). Rationale: `CatalogSync` and `PersonalRuns` have no `Infrastructure/` layer yet; the file placement check in `validateSourceFiles` already ensures PHP files are in valid layers.
- `validatePresentationCqrs` added: scans `*/Presentation/*.php` for forbidden DB infrastructure imports (`Doctrine\DBAL\Connection`, `Doctrine\ORM\EntityManagerInterface`) and forbidden SQL method calls (`fetchAllAssociative`, `fetchOne`, `executeQuery`, `createQueryBuilder`, `createQuery`, `getRepository`). Application layer files are excluded by design (scans only `Presentation/` dirs).
- `src/Schedule.php` (Symfony Scheduler) added to the root-file allowlist alongside `Kernel.php`.
- `src/CatalogSync/Domain/` added to `services.yaml` autowiring excludes (`CatalogEntry` is a pure value object, not a service).
- `phpstan analyse` on modified files → 0 errors; `php-cs-fixer check` → 0 violations.
- 8/8 unit tests green (14 assertions). 42 pre-existing unit errors on `ArchipelagoGame::updateCatalogueMetadata()` are unrelated to this story.
- **Baseline:** `app:architecture:ddd` exits 1 and reports **47 CQRS violations** across 19 controllers (Identity, Events, Content, Sessions, CatalogSync, Registrations). These are the targets for Stories 19.2–19.4.
- Post-review fix: `createQuery` detection switched from `str_contains` to `preg_match('/(?:->|::)createQuery\s*\(/')` - eliminates false positives where `createQueryBuilder` previously also triggered a `createQuery` violation. All SQL method call detections now use the same regex pattern.

### CQRS Baseline - 54 violations (19 controllers)

| Module | Controllers |
|---|---|
| Identity | `PlayerHistoryController`, `PlayerProfileController` |
| Events | `AdminEventCoverImageController`, `AdminEventGalleryController` |
| Content | `AdminPostCoverImageController` |
| Registrations | `RegistrationController` |
| Sessions | `AllGoalController`, `ApworldDownloadUrlController`, `CommandsController`, `CommunityStatsController`, `ContainerController`, `DownloadController`, `ExportController`, `FeedTokenController`, `ForceEndController`, `LeaderboardController`, `LogsController`, `PlayerStateController`, `PublisherTokenController`, `RunResultsController`, `SessionOrchestrationController`, `SessionResultsController` |
| CatalogSync | `CatalogSyncController`, `PublicCatalogGamesController` |

## File List

- `api/src/Shared/Application/DddArchitectureValidator.php` - modified
- `api/config/services.yaml` - modified (added `CatalogSync/Domain/` exclusion)
- `api/tests/Unit/DddArchitectureValidatorTest.php` - modified (new contexts + 5 CQRS test cases)
- `_bmad-output/implementation-artifacts/19-1-extend-ddd-validator-missing-contexts-cqrs-rules.md` - this file

## Change Log

| Date | Change |
|------|--------|
| 2026-05-14 | Story created and implemented |
| 2026-05-14 | Fixed review finding: `createQuery` detection uses regex `(?:->|::)createQuery\s*\(` to avoid false positives from `createQueryBuilder`. Baseline corrected: 54 → 47 real violations. Test added: `testCreateQueryBuilderDoesNotTriggerCreateQueryViolation` |
