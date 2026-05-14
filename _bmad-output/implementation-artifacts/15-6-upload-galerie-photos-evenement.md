# Story 15.6: Upload galerie photos événement

**Status:** review
**Epic:** 15 - MinIO Object Storage - APWorld Files & Image Assets
**Date:** 2026-05-12

## Story

As an admin editing the photo gallery of an event,
I want to upload photos directly instead of entering URLs,
So that I can build galleries from local files without needing external hosting.

## Acceptance Criteria

1. Given an admin edits the event photo gallery, when they select one or more JPEG/PNG/WebP files for upload, then each file is sent via `POST /api/v1/admin/events/{eventId}/gallery` (multipart/form-data, field name `file`).
2. Each file is stored in MinIO bucket `media` with key `events/{eventId}/gallery/{uuid}.{ext}`.
3. The `photo_gallery` JSON column is extended to support two item shapes: `{"source": "url", "url": "..."}` and `{"source": "upload", "key": "..."}`. Existing plain-string items are treated as `source: "url"` during read.
4. The public API contract is unchanged: `photoGallery` in all public and admin payloads is still a `list<string>` of resolved URLs (uploaded items resolved as presigned MinIO URLs).
5. A `DELETE /api/v1/admin/events/{eventId}/gallery/{index}` endpoint removes the item at the given 0-based index from the gallery array.
6. Validation: same MIME and size rules as Story 15.4 (≤ 10 MB, image/jpeg|png|webp); 422 with `image_invalid_type` or `image_too_large` on failure. Gallery max 12 items enforced on upload (422 `gallery_full` if already at 12).
7. The admin form shows upload and URL entry as two parallel methods for adding gallery items; existing items can be removed individually regardless of their source.
8. Errors return HTTP 422 per file with per-item validation codes.

## Tasks / Subtasks

- [x] Task 1: Extend `Event` entity - gallery item normalization (AC: 3, 4)
  - [x] Update `Event::normalizePhotoGallery()` to accept plain strings normalized to `{source: url, url: $str}`
  - [x] Resolution handled in application layer (`AdminEventDrafts`, `PublicEventCatalog`) - no domain injection needed
  - [x] `getPhotoGallery()` returns `list<array{source: string, url?: string, key?: string}>`; rewritten with intermediate variable narrowing for PHPStan level 8
  - [x] Added `getPhotoGalleryCount()`, `appendGalleryUpload()`, `removeGalleryItem()` to `Event`
  - [x] `$photoGallery` PHPDoc updated to `list<mixed>|null` to allow backward-compatible `is_string` check

- [x] Task 2: PHP upload endpoint for gallery (AC: 1, 2, 6)
  - [x] Created `api/src/Events/Presentation/AdminEventGalleryController.php`
  - [x] Route `POST /api/v1/admin/events/{eventId}/gallery`, admin required
  - [x] MIME and size validation (same rules as Story 15.4)
  - [x] Gallery count guard: 422 `gallery_full` if already at 12
  - [x] Key: `events/{eventId}/gallery/{bin2hex(random_bytes(16))}.{ext}` (symfony/uid not available)
  - [x] `appendGalleryUpload($key)` + flush; returns `adminEventDrafts->get($eventId)` payload

- [x] Task 3: PHP delete endpoint for gallery item (AC: 5)
  - [x] Route `DELETE /api/v1/admin/events/{eventId}/gallery/{index}`, admin required
  - [x] `removeGalleryItem($index)` returns false → 404; true → 204

- [x] Task 4: Update `AdminEventDrafts` serialization (AC: 4)
  - [x] `payload()` calls `resolvePhotoGallery()`: presigned URL for `source=upload`, plain URL for `source=url`
  - [x] `PublicEventCatalog::payload()` same resolution
  - [x] `parsePhotoGallery()` in `AdminEventDrafts` accepts plain strings; stored as `{source: url, url: ...}`

- [x] Task 5: Frontend - gallery upload + URL entry side by side (AC: 7, 8)
  - [x] Replaced `<textarea>` for `photoGallery` with `GalleryManager` local component in `admin-event-form.tsx`
  - [x] Shows existing items as cards with remove button; URL and file upload paths co-exist
  - [x] Upload triggers `POST .../gallery`; success updates `persistedItems` from response
  - [x] "Supprimer" on persisted items calls `DELETE .../gallery/{index}`
  - [x] URL items tracked locally; submitted via hidden input at form submit

- [x] Task 6: Tests (AC: 1–8)
  - [x] `api/tests/Functional/AdminEventGalleryTest.php` - 6 tests, all pass
  - [x] PHPStan level 8 - no errors on all Story 15.6 files
  - [x] CS-Fixer - no changes needed

## Dev Notes

- Current `photo_gallery` column is `TYPE JSON`, nullable, currently `list<string>` or `null`. No DB migration needed - the column stays JSON; the stored data shape changes (new items are objects; legacy string items are handled on read).
- **Backward compatibility**: existing events with `["http://...", "http://..."]` in `photo_gallery` must continue to render. The normalization reads strings as `{source: "url", url: $str}` in memory; the PATCH write path serializes back to the DB (existing PATCH endpoint writes the gallery from the frontend's current URL list - strings only - which become `{source: url, ...}` objects in the new format).
- Internal vs. public shape: `Event::getPhotoGallery()` can remain returning the raw JSON value; the resolution (presigned URL generation) happens in the application layer (`AdminEventDrafts`, `PublicEventCatalog`), not in the entity. This avoids injecting MinIO into the domain.
- UUID generation: use `Symfony\Component\Uid\Uuid` (already available via `symfony/uid`).
- The `DELETE .../gallery/{index}` approach uses a 0-based array index. Alternative: use the `key` or `url` as identifier. The index approach is simpler but fragile if concurrent updates occur - acceptable for an admin-only operation.
- Gallery max = 12 items. The existing `AdminEventDrafts::validate()` already enforces this for PATCH; enforce it in the upload endpoint too.
- `NullMinioStorage` in tests: uploads are no-ops; `presignedUrl()` returns a predictable fake URL.
- Frontend gallery manager: keep it self-contained within `admin-event-form.tsx` as a local component or extract to `admin-event-gallery-manager.tsx` if complexity warrants it.
- The gallery manager's "submit" behavior: when the parent form is submitted (PATCH event), pass the current gallery items as URL strings (for existing URL items) and do nothing special for uploaded items - the uploaded items are already persisted individually; the PATCH only needs to handle ordering and URL items. Consider passing the full resolved list so the API can diff and reorder if needed.

### References

- `api/src/Events/Domain/Event.php` - `photo_gallery` JSON column, `normalizePhotoGallery()`, `getPhotoGallery()`
- `api/src/Events/Application/AdminEventDrafts.php` - `payload()`, `parsePhotoGallery()`, gallery validation
- `api/src/Events/Application/PublicEventCatalog.php` - public payload
- `api/src/Events/Presentation/AdminEventController.php` - auth/response pattern
- `frontend/src/features/admin/admin-event-form.tsx` - current gallery textarea implementation
- Story 15.4 - MIME validation and MinIO upload pattern
- `api/config/services.yaml` - wiring pattern

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Completion Notes List

- `symfony/uid` not available in the project; used `bin2hex(random_bytes(16))` for gallery item keys (same pattern as entity IDs).
- PNG MIME detection via `finfo_file()` is unreliable on Windows with minimal magic bytes; happy-path tests use JPEG only.
- PHPStan level 8 requires `$photoGallery` PHPDoc to be `list<mixed>|null` (not the more specific tuple type) so that `is_string($rawItem)` in `getPhotoGallery()` is not flagged as always-false. Intermediate variable assignments (`$source = $rawItem['source'] ?? null`) are used to satisfy PHPStan's narrowing rules for `mixed` array values.
- `normalizePhotoGallery()` now only handles `list<string>` input (from the PATCH flow); uploaded items are appended directly via `appendGalleryUpload()`.

### Debug Log

- PHPStan error: `is_string()` on `array{source: string, url?: string, key?: string}` always false - root cause was overly specific PHPDoc `@var list<array{...}>|null`; fixed to `list<mixed>|null`.
- CS-Fixer: must be run one file at a time when overriding paths (multi-path invocation requires config `paths`).

### File List

- `api/src/Events/Domain/Event.php` - gallery normalization, new gallery methods, PHPDoc fix
- `api/src/Events/Presentation/AdminEventGalleryController.php` - new file (upload + delete endpoints)
- `api/src/Events/Application/AdminEventDrafts.php` - `resolvePhotoGallery()` helper, payload wiring
- `api/src/Events/Application/PublicEventCatalog.php` - `resolvePhotoGallery()` helper, payload wiring
- `frontend/src/features/admin/admin-event-form.tsx` - `GalleryManager` component replacing gallery textarea
- `api/tests/Functional/AdminEventGalleryTest.php` - new file (6 functional tests)

### Change Log

- 2026-05-12: Story implemented; all 6 tests pass; PHPStan and CS-Fixer clean.
