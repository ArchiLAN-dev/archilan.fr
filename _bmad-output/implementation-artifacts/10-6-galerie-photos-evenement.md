# Story 10.6: Galerie photos par evenement

Status: done

## Story

As a visitor,
I want to see a photo gallery on past event pages,
so that I can relive or discover the atmosphere of a specific event.

## Acceptance Criteria

1. Given a completed event with photos configured exists, when a visitor opens the event detail page, then they see a responsive photo gallery grid below the main event content.
2. Photos are stored as a JSON array of URLs in a `photo_gallery` column on the `events` table.
3. The gallery displays between 2 and 12 photos.
4. Events with no photos configured do not show the gallery section.
5. An admin can set and update photo URLs from the event edit form in the backoffice.

## Tasks / Subtasks

- [x] Add `photo_gallery` column to events table (AC: 2)
  - [x] Create Doctrine migration: `ALTER TABLE events_events ADD photo_gallery JSON DEFAULT NULL`.
  - [x] Add `photoGallery` property (`list<string>|null`) to `Event` entity with JSON storage.
- [x] Expose field in API (AC: 2)
  - [x] Add `photoGallery` to admin and public event payloads.
  - [x] Add `photoGallery` to the API write schema as an array of URL strings.
- [x] Update admin event form (AC: 5)
  - [x] Add photo gallery textarea in the backoffice event edit form, one URL per line.
  - [x] Convert between newline-delimited textarea value and JSON array on read/write.
  - [x] Validate each entry as a URL; require 2-12 entries when the gallery is configured.
- [x] Build public gallery section (AC: 1, 3, 4)
  - [x] On completed event detail pages, add a "Photos" section visible only when at least 2 photos are configured.
  - [x] Render a responsive `grid grid-cols-2 sm:grid-cols-3 gap-3`.
  - [x] Cap display at 12 photos.
- [x] Validate and handoff (AC: 1-5)
  - [x] Run API tests.
  - [x] Run frontend type-check.
  - [x] Update this story file.

## Dev Notes

The project table is `events_events`, matching the Events bounded context naming already used by Doctrine.

The textarea-based admin UX is intentionally simple; drag-and-drop upload remains out of scope.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-10.6]
- [Source: _bmad-output/implementation-artifacts/10-4-section-galerie-homepage.md]

## Dev Agent Record

### Agent Model Used

GPT-5 Codex

### Completion Notes List

- Added `photo_gallery` persistence on `events_events` with a Doctrine migration and nullable JSON-backed domain property.
- Admin create/update now accepts, validates, stores, and serializes `photoGallery`.
- Public event list/show payloads include `photoGallery`.
- Admin event form includes a one-URL-per-line gallery textarea.
- Completed event detail pages render a responsive gallery only when 2+ photos are configured.

### Validation Results

- `php bin/phpunit tests/Functional/AdminEventDraftTest.php tests/Functional/AdminEventEditTest.php tests/Functional/AdminEventLifecycleTest.php` - 20 tests, 166 assertions.
- `vendor/bin/phpstan analyse src/Events/Domain/Event.php src/Events/Application/AdminEventDrafts.php src/Events/Application/PublicEventCatalog.php --level=6` - no errors.
- `vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php ...` - no changes required.
- `pnpm lint -- src/app/evenements/[eventSlug]/page.tsx src/features/admin/admin-event-dashboard.tsx src/features/events/event-types.ts src/features/events/public-events-api.ts src/features/events/mock-events.ts` - 0 errors, 0 warnings.
- `pnpm typecheck` - 0 errors.

### File List

- `api/migrations/Version20260503101000.php`
- `api/src/Events/Domain/Event.php`
- `api/src/Events/Application/AdminEventDrafts.php`
- `api/src/Events/Application/PublicEventCatalog.php`
- `api/tests/Functional/AdminEventDraftTest.php`
- `api/tests/Functional/AdminEventEditTest.php`
- `api/tests/Functional/AdminEventLifecycleTest.php`
- `frontend/src/app/evenements/[eventSlug]/page.tsx`
- `frontend/src/features/admin/admin-event-dashboard.tsx`
- `frontend/src/features/events/event-types.ts`
- `frontend/src/features/events/mock-events.ts`
- `frontend/src/features/events/public-events-api.ts`
- `_bmad-output/implementation-artifacts/10-6-galerie-photos-evenement.md`

### Change Log

- 2026-05-03: Implemented event photo gallery persistence, API serialization, admin editing, and completed-event public rendering.
