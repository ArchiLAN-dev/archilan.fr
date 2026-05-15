# Story 19.8: Named Entity Resolution Helper in Application Services

## Story

**As a** developer,
**I want** a shared `findOrFail` helper for entity lookups in application services,
**So that** the null-check + exception-throw boilerplate is written once and services remain readable.

## Status

done

## Acceptance Criteria

**AC1:** `EntityFinderTrait` is added to `src/Shared/Application/` exposing a `findOrFail(string $class, string $id): object` method annotated with `@template T of object`, `@param class-string<T> $class`, `@return T` so PHPStan infers the concrete entity type at call sites.

**AC2:** The method throws a consistent `\RuntimeException` when the entity is not found, so services that previously caught a context-specific null result can wrap with catch+rethrow — the inline null-check boilerplate is eliminated.

**AC3:** Application services that previously inlined `$entity = $this->entityManager->find(...); if (null === $entity) { throw ...; }` are migrated to `$this->findOrFail(EntityClass::class, $id)`.

**AC4:** PHPStan level max reports 0 errors across all migrated services (template inference verified).

**AC5:** CS Fixer reports 0 violations, `phpunit` passes, DDD validator exits 0.

## Tasks / Subtasks

- [x] Task 1: Create story file (this file)
- [x] Task 2: Implement `EntityFinderTrait` in `src/Shared/Application/`
  - [x] 2a: `@template T of object` + `@param class-string<T>` + `@return T` annotations
  - [x] 2b: `null === $entity` guard throws `\RuntimeException`
- [x] Task 3: Migrate application services to `findOrFail()`
  - [x] `Sessions` context — `SessionOrchestrator`, `SessionLifecycleManager`, `RunResultsQuery`, `SessionResultsQuery`, `SessionQuery`, `SessionExportQuery`, `SendBridgeCommand`, `NotifyAllGoalCommand`, `ForceEndSessionCommand`, `PlayerSessionConnection`
  - [x] `Registrations` context — `RegistrationSubmission`, `RegistrationGameSelection`, `AdminRegistrationModification`, `AdminRegistrationInspector`, `AdminRegistrationCancellation`, `RegistrationCancellation`, `AdminRegistrationExporter`, `AdminRegistrationDashboard`, `SendMessageToRegistrant`
  - [x] `Events` context — `VerifyPrivateEventAccess`, `RegistrationEligibility`, `AdminEventGameSelection`, `UploadEventCoverImageCommand`, `ManageEventGalleryCommand`, `AdminEventRecap`, `AdminEventDrafts`
  - [x] `PersonalRuns` context — `PersonalRunGameSelection`, `PersonalRunGameConfig`, `PersonalRunDrafts`, `PersonalRunLifecycle`, `Handler/StopPersonalRunJobHandler`, `Handler/LaunchPersonalRunJobHandler`
  - [x] `Payments` context — `TriggerHelloAssoSync`, `HelloAssoPaymentLookup`, `AdminHelloAssoSyncStatus`
  - [x] `GameSelection` context — `AdminGameLibrary`
  - [x] `CatalogSync` context — `UnignoreCatalogEntryCommand`
  - [x] `Content` context — `UploadPostCoverImageCommand`
  - [x] `Identity` context — `AuthenticateUser`
  - [x] `PublicEventCatalog` — `PublicEventCatalog` (Events context, read side)
- [x] Task 4: Quality gates

## Dev Notes

### Trait design

`EntityFinderTrait` uses a `@property EntityManagerInterface $entityManager` doc annotation rather than an abstract property declaration, keeping the trait usable in application services that inject `$entityManager` via the constructor without requiring a separate abstract accessor.

The thrown exception is a plain `\RuntimeException`. Services that need a domain-specific HTTP response (e.g. 404) wrap the call in `try { $this->findOrFail(...); } catch (\RuntimeException $e) { throw new NotFoundException(...); }` — the null-check boilerplate is still eliminated.

### Remaining `entityManager->find()` calls

The `entityManager->find()` calls that were **not** migrated are intentional:
- **Pessimistic-lock patterns** — use `find($class, $id, LockMode::PESSIMISTIC_WRITE)` which requires the third argument that `findOrFail` does not expose.
- **Optional lookups** — callers intentionally handle a `null` result (e.g. "create if not found" or "skip if absent").
- **Best-effort / validation paths** — where a null result is a valid early-exit condition, not an error.

## File List

- `api/src/Shared/Application/EntityFinderTrait.php` — new trait (created)
- `api/src/Sessions/Application/SessionOrchestrator.php` — migrated
- `api/src/Sessions/Application/SessionLifecycleManager.php` — migrated
- `api/src/Sessions/Application/RunResultsQuery.php` — migrated
- `api/src/Sessions/Application/SessionResultsQuery.php` — migrated
- `api/src/Sessions/Application/SessionQuery.php` — migrated
- `api/src/Sessions/Application/SessionExportQuery.php` — migrated
- `api/src/Sessions/Application/SendBridgeCommand.php` — migrated
- `api/src/Sessions/Application/NotifyAllGoalCommand.php` — migrated
- `api/src/Sessions/Application/ForceEndSessionCommand.php` — migrated
- `api/src/Sessions/Application/PlayerSessionConnection.php` — migrated
- `api/src/Registrations/Application/RegistrationSubmission.php` — migrated
- `api/src/Registrations/Application/RegistrationGameSelection.php` — migrated
- `api/src/Registrations/Application/AdminRegistrationModification.php` — migrated
- `api/src/Registrations/Application/AdminRegistrationInspector.php` — migrated
- `api/src/Registrations/Application/AdminRegistrationCancellation.php` — migrated
- `api/src/Registrations/Application/RegistrationCancellation.php` — migrated
- `api/src/Registrations/Application/AdminRegistrationExporter.php` — migrated
- `api/src/Registrations/Application/AdminRegistrationDashboard.php` — migrated
- `api/src/Registrations/Application/SendMessageToRegistrant.php` — migrated
- `api/src/Events/Application/VerifyPrivateEventAccess.php` — migrated
- `api/src/Events/Application/RegistrationEligibility.php` — migrated
- `api/src/Events/Application/PublicEventCatalog.php` — migrated
- `api/src/Events/Application/AdminEventGameSelection.php` — migrated
- `api/src/Events/Application/UploadEventCoverImageCommand.php` — migrated
- `api/src/Events/Application/ManageEventGalleryCommand.php` — migrated
- `api/src/Events/Application/AdminEventRecap.php` — migrated
- `api/src/Events/Application/AdminEventDrafts.php` — migrated
- `api/src/PersonalRuns/Application/PersonalRunGameSelection.php` — migrated
- `api/src/PersonalRuns/Application/PersonalRunGameConfig.php` — migrated
- `api/src/PersonalRuns/Application/PersonalRunDrafts.php` — migrated
- `api/src/PersonalRuns/Application/PersonalRunLifecycle.php` — migrated
- `api/src/PersonalRuns/Application/Handler/StopPersonalRunJobHandler.php` — migrated
- `api/src/PersonalRuns/Application/Handler/LaunchPersonalRunJobHandler.php` — migrated
- `api/src/Payments/Application/TriggerHelloAssoSync.php` — migrated
- `api/src/Payments/Application/HelloAssoPaymentLookup.php` — migrated
- `api/src/Payments/Application/AdminHelloAssoSyncStatus.php` — migrated
- `api/src/GameSelection/Application/AdminGameLibrary.php` — migrated
- `api/src/CatalogSync/Application/UnignoreCatalogEntryCommand.php` — migrated
- `api/src/Content/Application/UploadPostCoverImageCommand.php` — migrated
- `api/src/Identity/Application/AuthenticateUser.php` — migrated
- `_bmad-output/implementation-artifacts/19-8-entity-resolution-helper.md` — this file

## Change Log

| Date       | Change                                  |
|------------|-----------------------------------------|
| 2026-05-15 | Story created; implementation pre-dates artifact creation — code validated via 4 quality gates |
