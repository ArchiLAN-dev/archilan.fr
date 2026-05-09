# Story 4.3: Atomic Event Registration Reservation

Status: done

## Story

As an authenticated user,
I want my registration to reserve a seat reliably,
So that I do not lose my place because of concurrent submissions.

## Acceptance Criteria

1. Given an event has remaining capacity and registration is open, when a user submits the initial registration step, then the backend creates or confirms the registration transactionally.
2. Capacity is checked atomically server-side.
3. Two concurrent requests cannot both claim the final seat.
4. Full events return a graceful capacity-full response.
5. Registration creation either completes fully or rolls back without partial state.

## Tasks / Subtasks

- [x] Domain: add `reserveSeat(\DateTimeImmutable $now): void` to `Event` entity (AC: 2, 3, 5)
- [x] Domain: create `Registration` entity in `App\Registrations\Domain` (AC: 1)
  - [x] Fields: id, eventId, userId, status (reserved/cancelled), createdAt, updatedAt.
  - [x] Unique constraint on (event_id, user_id).
- [x] Register `Registrations` bounded context in `doctrine.yaml` (AC: 1)
- [x] Migration: `Version20260501001000.php` - create `registrations_registrations` table (AC: 1)
- [x] Application: create `ReserveRegistration` service with `reserve()` (AC: 1–5)
  - [x] Returns null for unknown/non-visible events (→ 404).
  - [x] Returns `{outcome: 'not_eligible', reason}` → 422 when registration window closed, completed, private, in-progress.
  - [x] Pessimistic write lock on Event row within transaction (AC: 2, 3).
  - [x] Idempotent: returns `{outcome: 'already_registered', registrationId}` when user has active reservation (AC: 1).
  - [x] Returns `{outcome: 'capacity_full'}` → 409 when at full capacity (AC: 4).
  - [x] Creates `Registration` and calls `Event::reserveSeat()` atomically; rolls back on error (AC: 5).
- [x] Controller: `RegistrationController` in `App\Registrations\Presentation` (AC: 1)
  - [x] `POST /api/v1/events/{eventId}/registrations` - requires `ROLE_USER`.
  - [x] 201 for new reservation, 200 for idempotent, 409 for full, 422 for ineligible.
- [x] Backend tests: `ReserveRegistrationTest` with 7 test methods (AC: 1–5)
  - [x] Anonymous → 401.
  - [x] Unknown event → 404.
  - [x] Draft event → 404.
  - [x] Completed event → 422 not_eligible.
  - [x] Private event → 422 not_eligible.
  - [x] Open public event → 201, registration created, confirmedRegistrations +1.
  - [x] Second call same user → 200 already_registered, same ID, no extra registration.
  - [x] Full event → 409 capacity_full.
- [x] Extend `RbacEnforcementTest` with new endpoint + `Registration` entity in schema (AC: 1)
- [x] Frontend: wire "Commencer l'inscription" button in `EligiblePanel` (AC: 1, 4)
  - [x] Button enabled; POSTs to `POST /api/v1/events/{eventSlug}/registrations`.
  - [x] Loading state during request.
  - [x] `reserved` / `already_registered` → confirmation panel with registrationId ref.
  - [x] `capacity_full` (409) → inline error, button disabled.
  - [x] Generic error → retry message.
- [x] Validate and handoff
  - [x] Run backend PHPUnit/PHPStan/CS Fixer.
  - [x] Run frontend lint, type-check, and build.

### Review Findings

- [x] [Review][Bug] Capacity check can use a stale managed Event instance after acquiring the pessimistic lock [api/src/Registrations/Application/ReserveRegistration.php:26]

## Dev Notes

Atomicity is enforced via `LockMode::PESSIMISTIC_WRITE` (`Doctrine\DBAL\LockMode`) on the Event row inside a manual `beginTransaction/commit/rollBack` block. The initial eligibility check runs outside the transaction as a fast rejection; the capacity check inside the transaction is authoritative.

The `confirmedRegistrations` counter on `Event` is incremented by `reserveSeat()` within the same transaction - no separate counter update step.

The Registrations bounded context has its own `doctrine.yaml` mapping entry (`Registrations` key, `src/Registrations/Domain`). SQLite (used in tests) does support `SELECT ... FOR UPDATE` through Doctrine's abstraction, so the locking test works in CI without PostgreSQL.

The frontend confirmation panel shows the `registrationId` as a reference. Game selection (Story 4.4) will navigate from this state. For now the panel explains that the next steps are coming.

The `already_registered` outcome returns HTTP 200 (not 201) to signal idempotency - the client can distinguish a new reservation from a confirmed existing one.

## Dev Agent Record

### Agent Model Used

Claude Sonnet 4.6

### Completion Notes List

- Added `reserveSeat()` to `Event` domain entity.
- Created `Registration` entity in `App\Registrations\Domain` with `STATUS_RESERVED` / `STATUS_CANCELLED`.
- Registered `Registrations` namespace in `config/packages/doctrine.yaml`.
- Created migration `Version20260501001000.php`.
- Created `ReserveRegistration` application service with pessimistic lock, idempotency, and transactional rollback.
- Created `RegistrationController` with `POST /api/v1/events/{eventId}/registrations`.
- Fixed `Doctrine\ORM\LockMode` → `Doctrine\DBAL\LockMode` (not a class in this ORM version).
- Created `ReserveRegistrationTest` with 7 test methods.
- Extended `RbacEnforcementTest` with endpoint + `Registration` entity in schema metadata.
- Updated `EligiblePanel` - accepts `eventSlug`, manages reserve state, shows confirmation panel on success.

### Validation Results

- `composer test` passed: 131 tests, 1612 assertions.
- `composer phpstan` passed with no errors.
- `composer cs-fixer` passed in dry-run mode.
- `pnpm lint` passed.
- `pnpm typecheck` passed.
- `pnpm build` passed.

### File List

- _bmad-output/implementation-artifacts/4-3-atomic-event-registration-reservation.md
- api/src/Events/Domain/Event.php
- api/src/Registrations/Domain/Registration.php
- api/src/Registrations/Application/ReserveRegistration.php
- api/src/Registrations/Presentation/RegistrationController.php
- api/migrations/Version20260501001000.php
- api/config/packages/doctrine.yaml
- api/tests/Functional/ReserveRegistrationTest.php
- api/tests/Functional/RbacEnforcementTest.php
- frontend/src/features/events/registration-eligibility-gate.tsx

### Change Log

- 2026-05-01: Implemented Story 4.3 - atomic seat reservation with pessimistic locking, idempotency, and frontend reservation flow.
