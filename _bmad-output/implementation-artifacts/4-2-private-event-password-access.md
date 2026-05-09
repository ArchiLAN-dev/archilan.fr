# Story 4.2: Private Event Password Access

Status: done

## Story

As an authenticated user,
I want to enter a private event password,
So that I can register for an invitation-only event when I have access.

## Acceptance Criteria

1. Given an event is private and registration is open, when a user opens the registration page, then the PasswordAccessGate is available behind a "J'ai un code d'accès" disclosure.
2. Valid passwords unlock the registration flow (transition to eligible state locally).
3. Invalid passwords show an inline field error without revealing whether the event password format is correct.
4. Successful password access is recorded for admin visibility.
5. Password access does not promote the user to membre.

## Tasks / Subtasks

- [x] Domain: add `verifyPrivateAccessPassword(string $password): bool` to `Event` entity (AC: 2, 3)
- [x] Domain: create `EventPrivateAccessLog` entity (AC: 4)
- [x] Migration: `Version20260501000000.php` - create `events_private_access_logs` table (AC: 4)
- [x] Application: create `VerifyPrivateEventAccess` service with `verify()` (AC: 2, 3, 4, 5)
  - [x] Returns null for unknown/non-visible events (→ 404).
  - [x] Returns `{granted: false}` without logging when no password hash is configured.
  - [x] Logs every attempt (granted or denied) when a password hash exists.
- [x] Controller: add `POST /api/v1/events/{eventId}/verify-private-access` - requires `ROLE_USER` (AC: 1, 2, 3)
- [x] Backend tests: `VerifyPrivateEventAccessTest` with 6 test methods (AC: 1–5)
  - [x] Anonymous → 401.
  - [x] Unknown event → 404.
  - [x] Draft event → 404.
  - [x] Correct password → `{granted: true}`, log created with user + event IDs.
  - [x] Wrong password → `{granted: false}`, log created as denied.
  - [x] No password configured (private or public) → `{granted: false}`, no log.
- [x] Extend `RbacEnforcementTest.protectedRequests()` with new endpoint (AC: 1)
- [x] Frontend: `PrivateAccessDisclosure` in `RegistrationEligibilityGate` (AC: 1, 2, 3)
  - [x] Shown only when `reason === "private_event"`.
  - [x] `<details>/<summary>` disclosure "J'ai un code d'accès".
  - [x] Password field + submit button; loading and error states.
  - [x] On `granted: true` → transition gate to eligible in-place.
  - [x] On `granted: false` / error → show "Code d'accès invalide." inline.
- [x] Update `EligibilityReason` type to include `"event_in_progress"` (pre-existing gap)
- [x] Validate and handoff
  - [x] Run backend PHPUnit/PHPStan/CS Fixer.
  - [x] Run frontend lint, type-check, and build.

### Review Findings

- [x] [Review][Patch] Private access password could unlock registration locally even when the private event was not otherwise open for registration [api/src/Events/Application/VerifyPrivateEventAccess.php:31]

## Dev Notes

The `VerifyPrivateEventAccess` service only logs attempts when the event has a `privateAccessPasswordHash` configured. Public events and private events without a configured password return `{granted: false}` silently - this avoids phantom log entries.

The frontend disclosure uses a `<details>/<summary>` element for zero-JS disclosure of the password form, keeping the ineligible state visible while offering the escape hatch below it.

`password_verify()` is used on the backend; the hash in the DB comes from `password_hash()` via the existing `configurePrivateAccessPassword()` domain method.

The `event_in_progress` reason was already implemented in `RegistrationEligibility.php` and the switch in `registration-eligibility-gate.tsx`, but was missing from the `EligibilityReason` TypeScript union - fixed as part of this story.

## Dev Agent Record

### Agent Model Used

Claude Sonnet 4.6

### Completion Notes List

- Added `verifyPrivateAccessPassword()` to `Event` entity.
- Created `EventPrivateAccessLog` entity with `events_private_access_logs` table.
- Created migration `Version20260501000000.php`.
- Created `VerifyPrivateEventAccess` application service.
- Added `POST /api/v1/events/{eventId}/verify-private-access` to controller.
- Created `VerifyPrivateEventAccessTest` with 6 test methods.
- Extended `RbacEnforcementTest` with new endpoint + `EventPrivateAccessLog` schema metadata.
- Added `PrivateAccessDisclosure` component to `RegistrationEligibilityGate`.
- Updated `private_event` ineligible description to mention the access code.
- Added `"event_in_progress"` to `EligibilityReason` TypeScript type.

### Validation Results

- `composer test` passed: 121 tests, 1534 assertions.
- `composer phpstan` passed with no errors.
- `composer cs-fixer` passed in dry-run mode.
- `pnpm lint` passed.
- `pnpm typecheck` passed.
- `pnpm build` passed.

### File List

- _bmad-output/implementation-artifacts/4-2-private-event-password-access.md
- api/src/Events/Domain/Event.php
- api/src/Events/Domain/EventPrivateAccessLog.php
- api/migrations/Version20260501000000.php
- api/src/Events/Application/VerifyPrivateEventAccess.php
- api/src/Events/Presentation/AdminEventController.php
- api/tests/Functional/VerifyPrivateEventAccessTest.php
- api/tests/Functional/RbacEnforcementTest.php
- frontend/src/features/events/registration-eligibility-gate.tsx

### Change Log

- 2026-05-01: Implemented Story 4.2 - private event password gate with backend verification, access logging, and frontend disclosure form.
