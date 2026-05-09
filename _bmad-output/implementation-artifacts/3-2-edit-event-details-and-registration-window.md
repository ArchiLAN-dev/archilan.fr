# Story 3.2: Edit Event Details and Registration Window

Status: done

## Story

As an admin,
I want to edit event details and registration windows,
so that event information and signup timing can be corrected before and after publication.

## Acceptance Criteria

1. Given an event draft or published event exists, when an admin edits it, then they can update title, description, type, dates, venue, capacity, registration open/close dates, and public/private access flag.
2. Invalid event and registration date ranges are rejected server-side with field-level errors.
3. Capacity cannot be lowered below the confirmed registration count and returns a clear validation error.
4. Changes are persisted and reflected in admin views, with public-page synchronization deferred until events are backed by persisted public data.
5. The edit form is accessible and keyboard navigable.

## Tasks / Subtasks

- [x] Extend backend event editing support (AC: 1, 2, 3, 4)
  - [x] Add confirmed registration count to the event model.
  - [x] Add a domain update method for editable event details.
  - [x] Add admin event fetch/update application methods.
  - [x] Add `GET /api/v1/admin/events/{eventId}` and `PATCH /api/v1/admin/events/{eventId}`.
  - [x] Validate required fields, event date ranges, registration window ranges, and capacity guard server-side.
- [x] Add backend tests (AC: 1, 2, 3, 4)
  - [x] Test admin can fetch one event.
  - [x] Test admin can update event details and persistence.
  - [x] Test invalid date ranges are rejected.
  - [x] Test capacity cannot be lowered below confirmed registrations.
  - [x] Test non-admin users cannot edit events.
- [x] Implement frontend admin edit flow (AC: 1, 4, 5)
  - [x] Add edit action to the admin event list.
  - [x] Reuse the event form for create and edit modes.
  - [x] Pre-fill existing event values.
  - [x] Submit updates through the PATCH endpoint and refresh list state.
  - [x] Surface inline server validation errors.
- [x] Validate and handoff
  - [x] Run backend PHPUnit/PHPStan/CS Fixer.
  - [x] Run frontend lint, type-check, and build.

## Dev Notes

Confirmed registrations are not implemented as first-class registration records yet. This story introduces an event-level `confirmedRegistrations` counter initialized to `0` so the capacity invariant is enforceable now and can be replaced by registration aggregation in a later registration story.

Public event pages still use mock data in the current implementation. Persisted public synchronization is intentionally deferred until public events are migrated from mock content to API-backed data.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-3.2-Edit-Event-Details-and-Registration-Window]
- [Source: _bmad-output/implementation-artifacts/3-1-admin-event-list-and-draft-creation.md]

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- Added `AdminEventEditTest` for admin fetch/update, invalid ranges, capacity guard, and RBAC.
- Added `confirmedRegistrations` to the event entity as the current capacity invariant source.
- Reworked `AdminEventDashboard` so the draft form supports both create and edit modes.

### Completion Notes List

- Added `GET /api/v1/admin/events/{eventId}` and `PATCH /api/v1/admin/events/{eventId}` behind admin-only access.
- Added `Event::updateDetails()` and server-side validation for editable fields, date ordering, registration window ordering, and capacity below confirmed registrations.
- Added migration `Version20260425000800` for the `confirmed_registrations` column.
- Updated the admin event table with edit actions and confirmed/capacity display.
- Added an accessible edit flow using the existing labeled form controls and inline field errors.

### Validation Results

- `composer test` passed: 64 tests, 752 assertions.
- `composer phpstan` passed with no errors.
- `composer cs-fixer` passed in dry-run mode. PHP CS Fixer emitted the existing PHP 8.4 runtime warning for a PHP 8.3 project.
- `pnpm lint` passed.
- `pnpm typecheck` passed.
- `pnpm build` passed.

### File List

- _bmad-output/implementation-artifacts/3-2-edit-event-details-and-registration-window.md
- api/migrations/Version20260425000800.php
- api/src/Events/Application/AdminEventDrafts.php
- api/src/Events/Domain/Event.php
- api/src/Events/Presentation/AdminEventController.php
- api/tests/Functional/AdminEventDraftTest.php
- api/tests/Functional/AdminEventEditTest.php
- api/tests/Functional/RbacEnforcementTest.php
- frontend/src/features/admin/admin-event-dashboard.tsx

### Change Log

- 2026-04-25: Implemented Story 3.2 event detail and registration window editing with server-side validation, capacity guard, tests, and frontend edit form.
