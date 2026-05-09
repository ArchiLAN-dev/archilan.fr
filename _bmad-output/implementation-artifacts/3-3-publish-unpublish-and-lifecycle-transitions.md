# Story 3.3: Publish, Unpublish and Lifecycle Transitions

Status: done

## Story

As an admin,
I want to transition events through their lifecycle,
so that public visibility and operational status are controlled.

## Acceptance Criteria

1. Given an event exists, when an admin changes its status, then supported lifecycle statuses include draft, published, in-progress, and completed.
2. Publishing makes the event visible on public listings.
3. Unpublishing or reverting to draft hides the event from public listings.
4. Destructive or visibility-changing actions require confirmation.
5. Invalid lifecycle transitions are rejected with a clear error.

## Tasks / Subtasks

- [x] Extend event lifecycle domain behavior (AC: 1, 3, 5)
  - [x] Add supported statuses: draft, published, in-progress, completed.
  - [x] Add explicit allowed transition graph.
  - [x] Reject unsupported or invalid transitions.
  - [x] Add public visibility predicate based on status and public flag.
- [x] Add backend lifecycle and public APIs (AC: 1, 2, 3, 5)
  - [x] Add admin status transition application method.
  - [x] Add `PATCH /api/v1/admin/events/{eventId}/status`.
  - [x] Add public event list endpoint `GET /api/v1/events`.
  - [x] Add public event detail endpoint `GET /api/v1/events/{eventId}`.
- [x] Add backend tests (AC: 1, 2, 3, 5)
  - [x] Test valid lifecycle transitions.
  - [x] Test invalid transition rejection.
  - [x] Test non-admin users cannot transition events.
  - [x] Test public list hides drafts/private events and exposes published/in-progress/completed events.
  - [x] Test public detail hides non-visible events.
- [x] Implement frontend lifecycle controls (AC: 1, 4, 5)
  - [x] Add status display in the admin event table.
  - [x] Add contextual transition actions.
  - [x] Require browser confirmation for visibility-changing lifecycle actions.
  - [x] Update admin list state after successful transition.
- [x] Connect public event pages to published API data (AC: 2, 3)
  - [x] Add API-backed public event fetcher with mock fallback for local build/dev resilience.
  - [x] Update `/evenements` to render public API events.
  - [x] Update `/evenements/[eventSlug]` to resolve API-backed public event details.
- [x] Validate and handoff
  - [x] Run backend PHPUnit/PHPStan/CS Fixer.
  - [x] Run frontend lint, type-check, and build.

## Dev Notes

Lifecycle is intentionally constrained to a small graph:

- `draft -> published`
- `published -> draft | in-progress`
- `in-progress -> published | completed`
- `completed -> published`

Public visibility requires both `isPublic = true` and a public lifecycle status: `published`, `in-progress`, or `completed`. Draft events remain hidden even if their public flag is enabled.

The frontend public pages use API data when available and mock data as a fallback so static builds still pass without a running local Symfony server.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-3.3-Publish-Unpublish-and-Lifecycle-Transitions]
- [Source: _bmad-output/implementation-artifacts/3-2-edit-event-details-and-registration-window.md]

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- Added `AdminEventLifecycleTest` before final validation for lifecycle transitions and public visibility.
- Added `PublicEventCatalog` to keep public listing/detail queries separate from admin event management.
- Converted public event pages to dynamic rendering so publication changes can be reflected at request time.

### Completion Notes List

- Added lifecycle constants and `Event::transitionTo()` with explicit transition validation.
- Added admin status transition endpoint and clear validation errors for invalid transitions.
- Added public event list/detail endpoints that expose only public, published/in-progress/completed events.
- Added admin UI transition buttons with confirmation for publish/unpublish and other lifecycle changes.
- Connected public event list/detail pages to API-backed data with mock fallback.

### Validation Results

- `composer test` passed: 69 tests, 819 assertions.
- `composer phpstan` passed with no errors.
- `composer cs-fixer` passed in dry-run mode. PHP CS Fixer emitted the existing PHP 8.4 runtime warning for a PHP 8.3 project.
- `pnpm lint` passed.
- `pnpm typecheck` passed.
- `pnpm build` passed.

### File List

- _bmad-output/implementation-artifacts/3-3-publish-unpublish-and-lifecycle-transitions.md
- api/src/Events/Application/AdminEventDrafts.php
- api/src/Events/Application/PublicEventCatalog.php
- api/src/Events/Domain/Event.php
- api/src/Events/Presentation/AdminEventController.php
- api/tests/Functional/AdminEventLifecycleTest.php
- api/tests/Functional/RbacEnforcementTest.php
- frontend/src/app/evenements/page.tsx
- frontend/src/app/evenements/[eventSlug]/page.tsx
- frontend/src/features/admin/admin-event-dashboard.tsx
- frontend/src/features/events/public-events-api.ts

### Change Log

- 2026-04-25: Implemented Story 3.3 lifecycle transitions, public visibility API, admin status controls, API-backed public event pages, and validation coverage.
