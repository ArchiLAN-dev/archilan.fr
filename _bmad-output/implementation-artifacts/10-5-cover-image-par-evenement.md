# Story 10.5: Cover image par evenement

Status: done

## Story

As a visitor,
I want each event to have a representative cover photo,
so that the event listing and detail pages feel visually rich.

## Acceptance Criteria

1. Given an admin is editing an event, when they set a cover image URL, then the event detail page displays the cover as a hero image.
2. The event listing card displays a cropped version of the cover.
3. Events without a cover image display a neutral placeholder.
4. `cover_image_url` (snake_case) is stored in the `events` table via a Doctrine migration.
5. The API serialises the field as `coverImageUrl` (camelCase) in the event payload.
6. The admin event edit form includes a cover image URL field.

## Tasks / Subtasks

- [x] Add `cover_image_url` column to events table (AC: 4)
  - [x] Create Doctrine migration: `ALTER TABLE events_events ADD cover_image_url VARCHAR(2048) DEFAULT NULL`.
  - [x] Add `coverImageUrl` property (nullable string) to `Event` entity with `#[ORM\Column(nullable: true)]`.
- [x] Expose field in API (AC: 5)
  - [x] Add `coverImageUrl` to admin and public event payloads.
  - [x] Add `coverImageUrl` to the API write schema to allow admin to set it.
- [x] Update admin event form (AC: 6)
  - [x] Add cover image URL input to the event edit form in the backoffice.
  - [x] Validate as optional URL (empty = no cover).
- [x] Update public event listing (AC: 2, 3)
  - [x] In the event card component, if `coverImageUrl` is set, render `<Image fill>` with `object-cover`.
  - [x] If null, render a neutral placeholder with an image icon.
- [x] Update public event detail page (AC: 1, 3)
  - [x] Below the event header, render a full-width hero `<Image>` if `coverImageUrl` is set.
  - [x] If null, render a styled placeholder matching the page design.
- [x] Validate and handoff (AC: 1-6)
  - [x] Run API tests.
  - [x] Run frontend type-check.
  - [x] Update this story file.

## Dev Notes

`cover_image_url` is a free-form URL field. Admins paste a URL. Max length is 2048 characters.

The project table is `events_events`, matching the Events bounded context naming already used by Doctrine.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-10.5]
- [Source: _bmad-output/implementation-artifacts/10-2-hero-immersif-homepage.md]

## Dev Agent Record

### Agent Model Used

GPT-5 Codex

### Completion Notes List

- Added `cover_image_url` persistence on `events_events` with a Doctrine migration and nullable domain property.
- Admin create/update now accepts, validates, stores, and serializes `coverImageUrl`.
- Public event list/show payloads include `coverImageUrl`.
- Event cards render a 16:9 cropped cover image or a neutral placeholder.
- Event detail pages render a wide cover hero or matching placeholder, and include cover image in metadata/structured data when present.
- Next image config allows admin-provided remote cover URLs.

### Validation Results

- `php bin/phpunit tests/Functional/AdminEventDraftTest.php tests/Functional/AdminEventEditTest.php tests/Functional/AdminEventLifecycleTest.php` - 19 tests, 153 assertions.
- `vendor/bin/phpstan analyse src/Events/Domain/Event.php src/Events/Application/AdminEventDrafts.php src/Events/Application/PublicEventCatalog.php --level=6` - no errors.
- `vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php ...` - no changes required.
- `pnpm lint -- src/features/events/event-card.tsx src/features/events/public-events-api.ts src/features/events/mock-events.ts src/app/evenements/[eventSlug]/page.tsx src/features/admin/admin-event-dashboard.tsx next.config.ts` - 0 errors, 0 warnings.
- `pnpm typecheck` - 0 errors.

### File List

- `api/migrations/Version20260503100000.php`
- `api/src/Events/Domain/Event.php`
- `api/src/Events/Application/AdminEventDrafts.php`
- `api/src/Events/Application/PublicEventCatalog.php`
- `api/tests/Functional/AdminEventDraftTest.php`
- `api/tests/Functional/AdminEventEditTest.php`
- `api/tests/Functional/AdminEventLifecycleTest.php`
- `frontend/next.config.ts`
- `frontend/src/app/evenements/[eventSlug]/page.tsx`
- `frontend/src/features/admin/admin-event-dashboard.tsx`
- `frontend/src/features/events/event-card.tsx`
- `frontend/src/features/events/event-types.ts`
- `frontend/src/features/events/mock-events.ts`
- `frontend/src/features/events/public-events-api.ts`
- `_bmad-output/implementation-artifacts/10-5-cover-image-par-evenement.md`

### Change Log

- 2026-05-03: Implemented event cover image persistence, API serialization, admin editing, and public rendering.
