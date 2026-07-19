# Story 35.6b: Typed failures - Registrations admin (audit-log move) (api/)

Status: done

## Story

As a maintainer finishing epic 35 Stage 1 for Registrations,
I want `AdminRegistrationCancellation` and `AdminRegistrationModification` to throw the shared typed failures,
with their per-outcome audit logging moved from the controller into the command,
so that no failure-path audit log is lost and the Registrations context is fully converted.

## Context

Epic 35, Stage 1, story 35.6b - the follow-up 35.6 deferred. Both admin commands returned outcome arrays,
and their controllers logged `admin.registrations.{cancel,update}` with the outcome for **every** attempt
(success and failure) before branching. Converting to throw would silently drop the failure-path audit logs -
so 35.6 deferred them. This story **moves that audit logging into the command** (adding an `adminId` param),
then converts to throw. No new exception type.

Exact mappings to preserve:

| Command | mapping |
|---|---|
| `AdminRegistrationCancellation::cancel` | not_found -> 404 "Inscription introuvable." ; already_cancelled -> 409 `already_cancelled` "L'inscription est déjà annulée." ; cancelled -> `{ outcome: cancelled }` |
| `AdminRegistrationModification::update` | not_found -> 404 "Inscription introuvable." ; inactive -> 409 `inactive_registration` "L'inscription n'est plus modifiable." ; error -> 422 `invalid_registration_update` "La modification contient des erreurs." (+ field map) ; updated -> `{ data: <inspector detail>, meta: { outcome: updated } }` |

## Acceptance Criteria

1. **AC1 - Audit log moved.** Each command gains a private `auditLog(eventId, registrationId, adminId,
   outcome)` emitting `admin.registrations.{cancel,update}` with `occurredAt` (from the injected clock), called
   on **every** path (each failure before throwing, and on success). `cancel`/`update` gain an `$adminId`
   parameter. The existing success-only `registration.admin_{cancelled,updated}` logs are kept.
2. **AC2 - Throw + void.** `cancel` throws `NotFoundException` / `ConflictException` (`already_cancelled`);
   `update` throws `NotFoundException` / `ConflictException` (`inactive_registration`) / `ValidationException`
   (`invalid_registration_update`, with the field map). Both return void (the controller re-derives the
   success body; `update`'s success reads the inspector, not the command).
3. **AC3 - Controllers thinned.** `AdminRegistrationController::cancel`/`update` pass `$user->getId()`, drop
   the controller-side outcome log and the branches, and return their success bodies.
4. **AC4 - Contract unchanged.** Statuses/codes/messages/bodies identical; the audit-log entries still fire
   for every outcome (now from the command, `occurredAt` from the clock). Registrations functional suite green
   unchanged (no test asserts these logs; no test calls the commands directly).
5. **AC5 - Gates.** `composer gates` green. **Registrations is now fully converted (6/6).**

## Tasks / Subtasks

- [x] Task 1: `AdminRegistrationCancellation::cancel(+adminId)` - audit log moved in, throws NotFound/Conflict, void.
- [x] Task 2: `AdminRegistrationModification::update(+adminId)` - audit log moved in, throws NotFound/Conflict/Validation, void.
- [x] Task 3: `AdminRegistrationController` cancel/update actions thinned (pass adminId, drop log + branches).
- [x] Task 4: verify + ship - `composer gates` (static + isolated Registrations functional). PR to `develop`.

## Dev Notes

- **`occurredAt` now from the clock**, not `new \DateTimeImmutable()`. More testable; no test asserts the
  value, so behaviour-equivalent.
- **`update` success is void**: the controller's success path calls `adminRegistrationInspector->inspect(...)`
  (it never used the command's returned `slots`), so dropping the return value is safe.
- The `cancel` audit log fires `outcome=not_found|already_cancelled|cancelled`; `update` fires
  `outcome=not_found|inactive|error|updated` - matching exactly what the controllers logged before.
- House rules: logging in Application is allowed (PSR-3 `LoggerInterface`, already injected). phpstan max,
  Yoda, strict types. `composer gates`.

### References

- Predecessor: 35.6 (deferred these two). Foundation: 35.1-35.6.
- Convert: `src/Registrations/Application/Command/{AdminRegistrationCancellation,AdminRegistrationModification}.php`
  + `src/Registrations/Presentation/Controller/AdminRegistrationController.php`.
- Epic: `_bmad-output/planning-artifacts/epics/epic-35-strict-cqrs-write-path.md` (Stage 1 breakdown).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List

- Moved the per-outcome audit logging into both commands (private `auditLog`, `admin.registrations.{cancel,
  update}`, `occurredAt` from the clock), called on every path - so failure-path audit logs are preserved.
  Added `$adminId` to both command signatures.
- `AdminRegistrationCancellation::cancel` -> void, throwing NotFound / Conflict (`already_cancelled`).
  `AdminRegistrationModification::update` -> void, throwing NotFound / Conflict (`inactive_registration`) /
  Validation (`invalid_registration_update`, with field map). Both keep their success-only
  `registration.admin_{cancelled,updated}` logs.
- Controller cancel/update actions pass `$user->getId()`, drop the outcome log + branches; success bodies
  unchanged (`{ outcome: cancelled }` / `{ data: <inspector>, meta: { outcome: updated } }`).
- HTTP contract identical; 98 registration functional tests pass unchanged (no test asserts the logs or calls
  the commands directly). No new exception type.
- `composer gates` green: phpstan max 0, cs-fixer 0, ddd OK, rector OK, **phpunit 1543 tests / 10541
  assertions** (isolated DB). **Registrations fully converted (6/6).**

### File List

- `api/src/Registrations/Application/Command/AdminRegistrationCancellation.php`
- `api/src/Registrations/Application/Command/AdminRegistrationModification.php`
- `api/src/Registrations/Presentation/Controller/AdminRegistrationController.php` (cancel + update actions)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-16 | Story created + implemented (epic 35 Stage 1, 35.6b). The two admin commands 35.6 deferred: audit logging moved into the command, then converted to throw. `composer gates` green (1543 tests). Registrations 6/6. Status: done. |
