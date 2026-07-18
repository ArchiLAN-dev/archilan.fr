# Story 35.9: Typed result records - Registrations (api/)

Status: done

## Story

As a maintainer continuing epic 35 Stage 2,
I want `ReserveRegistration::reserve` and `RegistrationSubmission::submit` to return `final readonly` result
records (a reservation outcome enum + id, and a confirmed-submission payload) instead of associative arrays,
so that the Registrations write path drops its last `array{...}` return shapes with the HTTP contract unchanged.

## Context

Epic 35, Stage 2, second story (first roll-out after the 35.8 pilot). Two public command methods still return
arrays:

- `ReserveRegistration::reserve` -> `array{outcome: 'reserved'|'already_registered', registrationId}`. `outcome`
  is a **success discriminant** (drives 201 vs 200 + the `data.outcome` string) - a textbook case for a
  string-backed enum.
- `RegistrationSubmission::submit` -> `array{registrationId, eventTitle, selectedGameIds}` - a small
  confirmation payload the controller echoes.

`RegistrationCancellation::cancel` is already `void` (Stage 1). The `RegistrationGameSelection` service
(`saveSelection`/`saveSlotYaml`) returns `outcome`/`errors` arrays but is a mixed read+write **service**
(`Application/Service/`), not a `Command` - out of Stage 2's command scope; handled if/when its own story lands.

## Acceptance Criteria

1. **AC1 - Reservation record + enum.** Add `ReservationOutcome` (string-backed enum: `Reserved = 'reserved'`,
   `AlreadyRegistered = 'already_registered'`) and `ReservationResult` (`final readonly`, `ReservationOutcome
   $outcome` + `string $registrationId`), both colocated in `Registrations/Application/Command/`.
2. **AC2 - reserve() converted.** `ReserveRegistration::reserve` returns `ReservationResult`; the two success
   returns build the record; `@return array{...}` docblock dropped (stale "Returns null" prose removed too),
   `@throws` kept. Failure throws unchanged (Stage 1).
3. **AC3 - Submission record.** Add `SubmissionResult` (`final readonly`, `registrationId` + `eventTitle` +
   `list<string> selectedGameIds`), colocated. `RegistrationSubmission::submit` returns it.
4. **AC4 - Controller reads records.** `RegistrationController::reserve` compares
   `ReservationOutcome::AlreadyRegistered === $result->outcome` and reads `$result->registrationId`;
   `submitRegistration` reads `$result->{registrationId,eventTitle,selectedGameIds}`. The response bodies
   (literal `'reserved'`/`'already_registered'` strings, same keys) are byte-identical.
5. **AC5 - Gates.** `composer gates` green. Registrations functional suite green unchanged (regression proof).

## Tasks / Subtasks

- [x] Task 1: `ReservationOutcome` enum + `ReservationResult` + `SubmissionResult` records (AC: 1, 3).
- [x] Task 2: convert `reserve()` and `submit()` returns + signatures + docblocks (AC: 2, 3).
- [x] Task 3: `RegistrationController` reads the records (AC: 4).
- [x] Task 4: verify + ship (AC: 5) - static gates + isolated Registrations + full suite. PR to `develop`.

## Dev Notes

- **Enum, not a string, for the discriminant.** `outcome` had exactly two success values driving status/body -
  a string-backed enum kills the magic strings while keeping the wire value (`->value`), though the controller
  emits the literals directly since the body strings are fixed.
- **Colocation.** All three types sit in `Application/Command/` with their commands (taxonomy rule), no imports
  needed inside the commands (same namespace); the controller imports only `ReservationOutcome` (the only named
  reference - record fields are read via `->`).
- **`RegistrationGameSelection` is a Service, not a Command** - its outcome arrays are outside Stage 2's
  "command returns record" scope. Left as-is.
- **Regression proof.** `ReserveRegistrationTest` / `RegistrationSubmitTest` assert the JSON body (unchanged);
  `ReserveRegistrationTest` also calls `reserve()` directly but only to assert a thrown `ConflictException`, not
  the result shape. No test reads the command result array. No test edits expected.
- House rules: `final readonly`, strict types, Yoda, phpstan max. `composer gates`.
- **Narrow-scope phpstan caveat:** `phpstan analyse src/Registrations` alone falsely flags
  `AccountRegistrationsController::$apiAccessGuard` as write-only (the reading `RequiresAuthTrait` lives in
  `src/Shared/`, out of scope). The real gate scope (`src tests`) is clean - always verify at gate scope.

### References

- Convention source: 35.8 (`RunLifecycleResult`), epic Stage 2 breakdown.
- Convert: `src/Registrations/Application/Command/{ReserveRegistration,RegistrationSubmission}.php` +
  `src/Registrations/Presentation/Controller/RegistrationController.php`.
- New: `src/Registrations/Application/Command/{ReservationOutcome,ReservationResult,SubmissionResult}.php`.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List

- `ReservationOutcome` (string-backed, `reserved`/`already_registered`) + `ReservationResult` +
  `SubmissionResult` records, all colocated in `Application/Command/`.
- `reserve()` returns `ReservationResult`; `submit()` returns `SubmissionResult`. Stale "Returns null" docblock
  prose removed (both methods throw, never return null). Stage 1 typed throws untouched.
- `RegistrationController` reads the records (`ReservationOutcome::AlreadyRegistered === $result->outcome`,
  `$result->registrationId/eventTitle/selectedGameIds`). Response bodies byte-identical.
- Contract unchanged: Registrations functional suite (127 tests) green unchanged; full suite green (isolated).
- `composer gates` green: phpstan max 0 (at `src tests` scope), cs-fixer 0, ddd OK, rector OK, phpunit green.

### File List

- `api/src/Registrations/Application/Command/ReservationOutcome.php` (new)
- `api/src/Registrations/Application/Command/ReservationResult.php` (new)
- `api/src/Registrations/Application/Command/SubmissionResult.php` (new)
- `api/src/Registrations/Application/Command/ReserveRegistration.php` (returns `ReservationResult`)
- `api/src/Registrations/Application/Command/RegistrationSubmission.php` (returns `SubmissionResult`)
- `api/src/Registrations/Presentation/Controller/RegistrationController.php` (reads the records)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-17 | Story created + implemented (epic 35 Stage 2). `ReservationOutcome` enum + `ReservationResult` + `SubmissionResult`; `reserve()`/`submit()` + controller converted. `composer gates` green. Status: done. |
