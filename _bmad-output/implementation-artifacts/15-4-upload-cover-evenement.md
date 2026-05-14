# Story 15.4: Upload cover événement

**Status:** review
**Epic:** 15 - MinIO Object Storage - APWorld Files & Image Assets
**Date:** 2026-05-12

## Story

As an admin editing an event in the backoffice,
I want to upload an image as the event cover instead of entering a URL,
So that I can use local files without needing an externally hosted URL.

## Acceptance Criteria

1. Given an admin edits an event in the backoffice, when they select "Upload" on the cover image field and choose a JPEG/PNG/WebP file, then the frontend sends the file via `POST /api/v1/admin/events/{eventId}/cover-image` (multipart/form-data).
2. The API validates the MIME type (image/jpeg, image/png, image/webp) and the file size (≤ 10 MB) before storing; a failed validation returns HTTP 422 with error code `image_invalid_type` or `image_too_large`.
3. The file is stored in MinIO bucket `media` with key `events/{eventId}/cover.{ext}` (ext derived from MIME type: jpg, png, webp).
4. The `events` table receives a nullable `cover_image_key` column (VARCHAR 500); the existing `cover_image_url` column is preserved.
5. When `cover_image_key` is set on an event, the API payload serializes `coverImageUrl` as a pre-signed MinIO URL (TTL = `MINIO_PRESIGN_TTL_SECONDS`) instead of the stored string.
6. The admin form shows a "URL" / "Upload" toggle; selecting "Upload" reveals a file input; the active mode persists while the form is open.
7. On a successful upload, the form state updates to show the new cover (URL field is cleared / replaced by the uploaded key indicator).

## Tasks / Subtasks

- [x] Task 1: DB migration - add `cover_image_key` column to `events` (AC: 4)
  - [x] Create `api/migrations/Version20260513100000.php`: `ALTER TABLE events ADD cover_image_key VARCHAR(500) DEFAULT NULL`
  - [x] Add `#[ORM\Column(name: 'cover_image_key', type: 'string', length: 500, nullable: true)]` + `$coverImageKey` property to `Event` entity
  - [x] Add `getCoverImageKey(): ?string` and `setCoverImageKey(string $key): void` methods

- [x] Task 2: PHP upload endpoint (AC: 1, 2, 3, 5)
  - [x] Create `api/src/Events/Presentation/AdminEventCoverImageController.php`
  - [x] Route: `POST /api/v1/admin/events/{eventId}/cover-image`, requires admin role
  - [x] Validate uploaded file: MIME in `[image/jpeg, image/png, image/webp]` → 422 `image_invalid_type`; size > 10 MB → 422 `image_too_large`
  - [x] Derive extension from MIME (jpeg→jpg, png→png, webp→webp)
  - [x] Upload to MinIO bucket `$minioBucket` (from `$env(MINIO_BUCKET_MEDIA)`) with key `events/{eventId}/cover.{ext}` via `MinioStorageInterface::upload()`
  - [x] Call `$event->setCoverImageKey("events/{eventId}/cover.{ext}")` and flush
  - [x] Return updated event payload (reuse `AdminEventDrafts::payload()` response shape)
  - [x] Wire service in `config/services.yaml` with `$minioBucket: '%env(MINIO_BUCKET_MEDIA)%'` and `$minioPresignTtl`

- [x] Task 3: Serialize `coverImageUrl` from MinIO key (AC: 5)
  - [x] Inject `MinioStorageInterface`, `string $minioBucket`, `int $minioPresignTtl` into `AdminEventDrafts`
  - [x] In `payload()`: when `$event->getCoverImageKey() !== null`, set `coverImageUrl = $this->minioStorage->presignedUrl($this->minioBucket, $event->getCoverImageKey(), $this->minioPresignTtl)` instead of `$event->getCoverImageUrl()`
  - [x] Apply same logic in `PublicEventCatalog::payload()` (public pages also show cover)
  - [x] Wire new dependencies in `config/services.yaml`

- [x] Task 4: Frontend - URL/Upload toggle on cover image field (AC: 6, 7)
  - [x] In `admin-event-form.tsx`: add local `coverMode: "url" | "upload"` state (default `"url"`); if event already has a `coverImageKey` hint in payload, initialise to `"upload"` mode
  - [x] Render two radio/tab buttons "URL" / "Upload" above the cover image field
  - [x] In "URL" mode: existing `<EventTextField ... name="coverImageUrl" />` unchanged
  - [x] In "Upload" mode: render `<input type="file" accept="image/jpeg,image/png,image/webp" />` + a `POST .../cover-image` fetch on change using `FormData`; on success, invalidate/reload event data
  - [x] Show a small preview thumbnail (object-contain 80×80) when a key is active

- [x] Task 5: Tests (AC: 1–7)
  - [x] Create `api/tests/Functional/AdminEventCoverImageTest.php`:
    - Upload valid JPEG → 200, `coverImageKey` set in DB, `coverImageUrl` in response is a presigned URL
    - Upload invalid MIME → 422 `image_invalid_type`
    - Upload oversized file → 422 `image_too_large`
    - Unauthenticated → 401
  - [x] Run `vendor/bin/phpunit tests/Functional/AdminEventCoverImageTest.php` → all pass
  - [x] Run `vendor/bin/phpstan` on Story 15.4 files → no errors
  - [x] Run `vendor/bin/php-cs-fixer` → clean

## Dev Notes

- `Event` entity: `#[ORM\Table(name: 'events')]`. The existing `cover_image_url` column must be **kept** as-is; `cover_image_key` is additive.
- MinIO bucket for media assets is `media` (env `MINIO_BUCKET_MEDIA`, default `media` via `_defaults` bind). Key pattern: `events/{eventId}/cover.{ext}`.
- Extension derivation: `['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp']`.
- `MinioStorageInterface::upload(string $bucket, string $key, string $contents): void` - pass `$file->getContent()` as contents.
- Pre-signed URL for public serialization: `$minioStorage->presignedUrl($bucket, $key, $ttl)`. Only inject MinIO in `AdminEventDrafts` and `PublicEventCatalog`; the pre-signed URL replaces the stored `coverImageUrl` value only when `coverImageKey !== null`.
- `$minioPresignTtl` and `$minioBucket` are already bound globally via `_defaults` in `services.yaml`; explicit `arguments:` entries are only needed if the parameter name differs.
- For the functional test, use `NullMinioStorage` (injected in `when@test`) - it accepts any upload and returns a stable fake URL.
- Symfony `UploadedFile` in tests: use `UploadedFile` with a temp file; set MIME type manually in the test.
- The `AdminEventDrafts` service is injected into `AdminEventController` - adding MinIO injection there is safe as it is already a tagged service.
- Public event serialization in `PublicEventCatalog` also calls `payload()` and should resolve the presigned URL so cover images appear correctly on public pages.

### References

- `api/src/Events/Domain/Event.php` - entity, `cover_image_url` column, `photoGallery` pattern
- `api/src/Events/Application/AdminEventDrafts.php` - `payload()` serialization, validation pattern
- `api/src/Events/Application/PublicEventCatalog.php` - public payload
- `api/src/Events/Presentation/AdminEventController.php` - auth/response pattern
- `api/src/Sessions/Presentation/ApworldDownloadUrlController.php` - MinIO presigned URL pattern
- `api/src/Shared/Infrastructure/MinioStorageInterface.php`
- `frontend/src/features/admin/admin-event-form.tsx` - current form structure
- `api/config/services.yaml` - wiring pattern, `_defaults` bindings

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Completion Notes List

- `UploadedFile instanceof` guard required to narrow `mixed` from `$request->files->get()` for PHPStan level 8
- Functional test schema must include `Registration::class` because `AdminEventDrafts::payload()` calls `RegistrationCounter::countConfirmed()` which queries `event_registrations`
- `_defaults` bind in `services.yaml` for `$minioMediaBucket` and `$minioPresignTtl` removes the need for explicit `arguments:` on `AdminEventDrafts` and `PublicEventCatalog`

### File List

- `api/migrations/Version20260513100000.php` - new
- `api/src/Events/Domain/Event.php` - modified: `$coverImageKey` column + accessors
- `api/src/Events/Presentation/AdminEventCoverImageController.php` - new
- `api/src/Events/Application/AdminEventDrafts.php` - modified: MinIO injection + `resolveCoverImageUrl()`
- `api/src/Events/Application/PublicEventCatalog.php` - modified: MinIO injection + `resolveCoverImageUrl()`
- `api/config/services.yaml` - modified: `$minioMediaBucket` bind + `default_minio_media_bucket` param
- `frontend/src/features/admin/admin-event-form.tsx` - modified: URL/Upload toggle
- `api/tests/Functional/AdminEventCoverImageTest.php` - new

### Change Log

- 2026-05-12: Implementation complete, 4/4 tests pass, PHPStan clean, CS-Fixer clean
