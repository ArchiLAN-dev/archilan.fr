# Story 3.1: Admin Event List and Draft Creation

Status: done

## Story

As an admin,
I want to create and view event drafts,
so that I can prepare events before publishing them publicly.

## Acceptance Criteria

1. Given an admin is authenticated, when they open the event backoffice, then they can view an event list with title, type, status, dates, capacity, and visibility.
2. The empty state invites them to create the first event.
3. They can create a draft event with title, description, type, dates, venue, capacity, registration window dates, and public/private access flag.
4. Required fields are validated server-side and displayed inline in the UI.
5. Non-admin users cannot access event management.

## Tasks / Subtasks

- [x] Implement backend event drafts (AC: 1, 3, 4, 5)
  - [x] Add Event domain entity and Doctrine mapping.
  - [x] Add migration for event drafts.
  - [x] Add admin event list/create service.
  - [x] Add `GET /api/v1/admin/events` and `POST /api/v1/admin/events`.
  - [x] Validate required fields and date ranges server-side.
- [x] Add backend tests (AC: 1, 3, 4, 5)
  - [x] Test anonymous/lambda access rejected.
  - [x] Test empty list response.
  - [x] Test admin can create draft and list it.
  - [x] Test validation errors for required fields/date ranges/capacity.
- [x] Implement frontend event backoffice (AC: 1, 2, 3, 4)
  - [x] Add `/admin/evenements` route.
  - [x] Add event list with required columns.
  - [x] Add empty state inviting first draft creation.
  - [x] Add draft creation form with inline errors.
- [x] Validate and handoff
  - [x] Run backend PHPUnit/PHPStan/CS Fixer.
  - [x] Run frontend lint, type-check, and build.

## Dev Notes

This story introduces event draft persistence and admin-only creation. Publishing, editing existing events, lifecycle transitions, public synchronization, game selection, private passwords, and registration logic are future Epic 3/4 stories.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-3.1-Admin-Event-List-and-Draft-Creation]
- [Source: _bmad-output/implementation-artifacts/2-8-api-rbac-enforcement.md]

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- Added `AdminEventDraftTest` before final validation to lock admin-only draft creation and list behavior.
- Extended `RbacEnforcementTest` with event management endpoints.

### Completion Notes List

- Added `Events\Domain\Event` draft entity and Doctrine mapping for the Events bounded context.
- Added migration `Version20260425000700` for `events_events`.
- Added admin-only `GET /api/v1/admin/events` and `POST /api/v1/admin/events`.
- Added server-side validation for required fields, capacity, event date range, and registration window range.
- Added `/admin/evenements` UI with event list, empty state, and draft creation form with inline validation feedback.

### Validation Results

- `composer test` passed: 57 tests, 664 assertions.
- `composer phpstan` passed with no errors.
- `composer cs-fixer` passed in dry-run mode. PHP CS Fixer emitted the existing PHP 8.4 runtime warning for a PHP 8.3 project.
- `pnpm lint` passed.
- `pnpm typecheck` passed.
- `pnpm build` passed.

### File List

- _bmad-output/implementation-artifacts/3-1-admin-event-list-and-draft-creation.md
- api/config/packages/doctrine.yaml
- api/migrations/Version20260425000700.php
- api/src/Events/Application/AdminEventDrafts.php
- api/src/Events/Domain/Event.php
- api/src/Events/Presentation/AdminEventController.php
- api/tests/Functional/AdminEventDraftTest.php
- api/tests/Functional/RbacEnforcementTest.php
- frontend/src/app/admin/evenements/page.tsx
- frontend/src/features/admin/admin-event-dashboard.tsx

### Change Log

- 2026-04-25: Implemented Story 3.1 admin event draft list/create API, persistence, tests, and frontend backoffice page.
