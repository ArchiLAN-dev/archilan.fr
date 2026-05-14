# Story 15.5: Upload cover article

**Status:** review
**Epic:** 15 - MinIO Object Storage - APWorld Files & Image Assets
**Date:** 2026-05-12

## Story

As an admin editing an article in the backoffice,
I want to upload an image as the article cover instead of entering a URL,
So that I can use local files without needing an externally hosted URL.

## Acceptance Criteria

1. Given an admin edits an article in the backoffice, when they select "Upload" and choose a JPEG/PNG/WebP file, then the frontend sends the file via `POST /api/v1/admin/posts/{postId}/cover-image` (multipart/form-data).
2. The API validates the MIME type (image/jpeg, image/png, image/webp) and the file size (≤ 10 MB); a failed validation returns HTTP 422 with error code `image_invalid_type` or `image_too_large`.
3. The file is stored in MinIO bucket `media` with key `posts/{postId}/cover.{ext}`.
4. The `content_posts` table receives a nullable `cover_image_key` column (VARCHAR 500); the existing `cover_image_url` column is preserved.
5. When `cover_image_key` is set, the API payload serializes `coverImageUrl` as a pre-signed MinIO URL (TTL = `MINIO_PRESIGN_TTL_SECONDS`).
6. The admin post form shows the same "URL" / "Upload" toggle as Story 15.4.

## Tasks / Subtasks

- [x] Task 1: DB migration - add `cover_image_key` column to `content_posts` (AC: 4)
  - [x] Create `api/migrations/Version20260513110000.php`: `ALTER TABLE content_posts ADD cover_image_key VARCHAR(500) DEFAULT NULL`
  - [x] Add `#[ORM\Column(name: 'cover_image_key', type: 'string', length: 500, nullable: true)]` + `$coverImageKey` property to `Post` entity
  - [x] Add `getCoverImageKey(): ?string` and `setCoverImageKey(string $key): void` methods

- [x] Task 2: PHP upload endpoint (AC: 1, 2, 3, 5)
  - [x] Create `api/src/Content/Presentation/AdminPostCoverImageController.php`
  - [x] Route: `POST /api/v1/admin/posts/{postId}/cover-image`, requires admin role
  - [x] Same MIME and size validation as Story 15.4 (duplicated as private const - two occurrences, not extracted)
  - [x] Upload to MinIO bucket `media` with key `posts/{postId}/cover.{ext}`
  - [x] Call `$post->setCoverImageKey(...)` and flush
  - [x] Return updated post payload (reuse `AdminPostCatalog::payload()` response shape)
  - [x] Wire in `config/services.yaml` via `_defaults` bindings (no explicit entry needed)

- [x] Task 3: Serialize `coverImageUrl` from MinIO key (AC: 5)
  - [x] Inject `MinioStorageInterface`, `string $minioBucket`, `int $minioPresignTtl` into `AdminPostCatalog`
  - [x] In `payload()`: when `$post->getCoverImageKey() !== null`, resolve presigned URL instead of raw `$post->getCoverImageUrl()`
  - [x] Apply same logic in `PublicPostCatalog::payload()` (public article pages also display cover)
  - [x] Wire new dependencies in `config/services.yaml`

- [x] Task 4: Frontend - URL/Upload toggle on cover image field (AC: 6)
  - [x] Locate admin post form component (`frontend/src/features/admin/admin-post-form.tsx`)
  - [x] Apply same URL/Upload toggle pattern as Story 15.4
  - [x] On upload success, `uploadedCoverUrl` state updates to show new cover thumbnail

- [x] Task 5: Tests (AC: 1–6)
  - [x] Create `api/tests/Functional/AdminPostCoverImageTest.php`:
    - Upload valid JPEG → 200, `coverImageKey` set, `coverImageUrl` is presigned URL in response
    - Upload invalid MIME → 422 `image_invalid_type`
    - Upload oversized file → 422 `image_too_large`
    - Unauthenticated → 401
  - [x] Run `vendor/bin/phpunit tests/Functional/AdminPostCoverImageTest.php` → all pass
  - [x] Run `vendor/bin/phpstan` on Story 15.5 files → no errors
  - [x] Run `vendor/bin/php-cs-fixer` → clean

## Dev Notes

- `Post` entity: `#[ORM\Table(name: 'content_posts')]`. The `cover_image_url` column must be preserved.
- MinIO key pattern: `posts/{postId}/cover.{ext}`. Use the same MIME→extension map as Story 15.4.
- `AdminPostCatalog` is the application service for post serialization/update (see `api/src/Content/Application/AdminPostCatalog.php`).
- `PublicPostCatalog` handles public-facing serialization (see `api/src/Content/Application/PublicPostCatalog.php`) - must also resolve presigned URLs.
- Consider extracting the MIME validation and extension derivation into a shared service `MediaUploadValidator` under `api/src/Shared/Application/` if the exact same logic appears in both 15.4 and 15.5; otherwise a private static helper in each controller is acceptable for two occurrences.
- The admin post form component: locate in `frontend/src/features/admin/` - likely a form rendered by `admin-content-dashboard.tsx`. Apply the same toggle pattern as Story 15.4.

### References

- `api/src/Content/Domain/Post.php` - entity, `content_posts` table, `cover_image_url` column
- `api/src/Content/Application/AdminPostCatalog.php` - `payload()`, validation
- `api/src/Content/Application/PublicPostCatalog.php` - public payload
- `api/src/Content/Presentation/AdminPostController.php` - auth/response pattern
- Story 15.4 implementation - identical approach, different entity/table
- `api/config/services.yaml` - wiring pattern

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Completion Notes List

- `$coverImageKey = null` doit être le DERNIER paramètre du constructeur `Post` - en PHP, un paramètre optionnel avant des paramètres requis est implicitement requis (erreur "too few arguments" dans `Post::draft()` qui passait 14 args)
- `finfo_file()` sur Windows ne détecte pas `image/png` avec les seuls magic bytes `\x89PNG\r\n\x1a\n` suivis de zéros - test happy path modifié pour utiliser JPEG (identique à 15.4)
- `AdminPostCatalog` et `PublicPostCatalog` : injection MinIO via `_defaults` bind, pas besoin d'entrée explicite dans `services.yaml`

### File List

- `api/migrations/Version20260513110000.php` - new
- `api/src/Content/Domain/Post.php` - modified: `$coverImageKey` column + accessors (placed last in constructor)
- `api/src/Content/Presentation/AdminPostCoverImageController.php` - new
- `api/src/Content/Application/AdminPostCatalog.php` - modified: MinIO injection + `resolveCoverImageUrl()` + `coverImageKey` in payload
- `api/src/Content/Application/PublicPostCatalog.php` - modified: MinIO injection + `resolveCoverImageUrl()`
- `frontend/src/features/admin/admin-post-form.tsx` - modified: URL/Upload toggle, `coverImageKey` in `AdminPost` type
- `api/tests/Functional/AdminPostCoverImageTest.php` - new

### Change Log

- 2026-05-12: Implementation complete, 4/4 tests pass, PHPStan clean, CS-Fixer clean
