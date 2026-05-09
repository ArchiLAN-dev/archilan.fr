# Story 3.8: Attach Recap or VOD to Completed Event

Status: done

## Story

As an admin,
I want to attach a recap article or VOD link to a completed event,
so that public users can access the recap through the event page.

## Acceptance Criteria

1. Given an event is completed, when an admin attaches a recap article or VOD link, then the completed event public page links to that recap or VOD.
2. The event listing reflects recap availability.
3. Invalid VOD URLs are rejected.
4. Only completed events can be marked with final recap content.
5. Public users can access the recap through the event page.

## Tasks / Subtasks

- [x] Extend Event domain (AC: 1, 2, 4, 5)
  - [x] Add `vod_url` nullable string to Event entity.
  - [x] Add `recap_post_slug` nullable string to Event entity.
  - [x] Add `attachRecap()` domain method guarded by `STATUS_COMPLETED`.
  - [x] Add `getVodUrl()`, `getRecapPostSlug()`, `hasRecap()` accessors.
- [x] Add migration (AC: 1, 4)
  - [x] Create `Version20260430002000.php` adding the two new nullable columns.
- [x] Create AdminEventRecap application service (AC: 1, 2, 3, 4)
  - [x] `attach()`: validates input and persists recap fields.
  - [x] Validate: vodUrl must be a valid URL or null; recapPostSlug must match slug format or null.
  - [x] Domain exception mapped to `status` validation error for non-completed events.
- [x] Add controller endpoint (AC: 1, 2, 3, 4)
  - [x] `PATCH /api/v1/admin/events/{eventId}/recap` - save recap config.
- [x] Update public and admin payloads (AC: 2, 5)
  - [x] Add `vodUrl`, `recapPostSlug`, `hasRecap` to `AdminEventDrafts.payload()`.
  - [x] Add `vodUrl`, `recapPostSlug`, `hasRecap` to `PublicEventCatalog.payload()`.
- [x] Add backend tests (AC: 1, 2, 3, 4, 5)
  - [x] Test anonymous/lambda cannot access endpoint (RBAC).
  - [x] Test 404 for unknown event.
  - [x] Test cannot attach recap to non-completed event.
  - [x] Test admin attaches VOD URL only.
  - [x] Test admin attaches recap post slug only.
  - [x] Test admin attaches both.
  - [x] Test admin clears recap (both null).
  - [x] Test invalid VOD URL rejected.
  - [x] Test invalid recap slug rejected.
  - [x] Test recap reflected in admin event list.
  - [x] Extend RBAC enforcement test with new endpoint.
  - [x] Update Event constructor in 3 existing test helpers (AdminEventEditTest, AdminEventLifecycleTest, AdminEventPrivateAccessTest).
- [x] Update frontend admin event dashboard (AC: 1, 2)
  - [x] Add `vodUrl`, `recapPostSlug`, `hasRecap` to `AdminEvent` type.
  - [x] Add "Récap" column - shows attach button only for completed events.
  - [x] Add `RecapDialog` component with VOD URL input and recap slug input.
  - [x] Save with PATCH and update local state on success.
- [x] Validate and handoff
  - [x] Run backend PHPUnit/PHPStan/CS Fixer.
  - [x] Run frontend lint, type-check, and build.

### Review Findings

- [x] [Review][Patch] Public frontend drops recap/VOD fields from the API payload - fixed by updating `PublicEventPayload` type and `toPublicEvent()` mapping in `public-events-api.ts`.

## Dev Notes

`attachRecap()` is guarded by a domain invariant: only events with `STATUS_COMPLETED` can receive recap data. The application service catches the `DomainException` and maps it to a `status` field validation error.

Both `vodUrl` and `recapPostSlug` are independently optional. Passing both as `null` clears the recap.

`recapPostSlug` points to a content post that will be linked once the Content bounded context is implemented (Story 1.6). Existence is not validated here - only slug format (`^[a-z0-9][a-z0-9-]*$`).

The public payload exposes `vodUrl`, `recapPostSlug`, and `hasRecap` on all public statuses so the public event page can display recap links for completed events.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-3.8]
- [Source: _bmad-output/implementation-artifacts/3-7-event-game-selection-intake-configuration.md]

## Dev Agent Record

### Agent Model Used

Claude Sonnet 4.6

### Completion Notes List

- Added `vod_url` and `recap_post_slug` nullable columns to `Event` entity with `attachRecap()` domain method (status guard: completed only).
- Created `AdminEventRecap` application service with `attach()` (validate + persist + DomainException mapping).
- Added `PATCH /api/v1/admin/events/{eventId}/recap` route.
- Updated `AdminEventDrafts.payload()` and `PublicEventCatalog.payload()` with `vodUrl`, `recapPostSlug`, `hasRecap`.
- Created `AdminEventRecapTest` with 10 test methods.
- Extended `RbacEnforcementTest` with the new endpoint.
- Updated 3 existing test files that construct `Event` directly to pass the 2 new `null` constructor params.
- Added `RecapDialog` to `admin-event-dashboard.tsx` with VOD URL and recap slug inputs, "Récap" column (completed events only).

### Validation Results

- `composer test` passed: 104 tests, 1412 assertions.
- `composer phpstan` passed with no errors.
- `composer cs-fixer` passed in dry-run mode.
- `pnpm lint` passed.
- `pnpm typecheck` passed.
- `pnpm build` passed.

### File List

- _bmad-output/implementation-artifacts/3-8-attach-recap-or-vod-to-completed-event.md
- api/migrations/Version20260430002000.php
- api/src/Events/Domain/Event.php
- api/src/Events/Application/AdminEventDrafts.php
- api/src/Events/Application/AdminEventRecap.php
- api/src/Events/Application/PublicEventCatalog.php
- api/src/Events/Presentation/AdminEventController.php
- api/tests/Functional/AdminEventRecapTest.php
- api/tests/Functional/AdminEventEditTest.php
- api/tests/Functional/AdminEventLifecycleTest.php
- api/tests/Functional/AdminEventPrivateAccessTest.php
- api/tests/Functional/RbacEnforcementTest.php
- frontend/src/features/admin/admin-event-dashboard.tsx

### Change Log

- 2026-04-30: Implemented Story 3.8 - recap/VOD attachment to completed events with backend validation, tests, and admin UI.
