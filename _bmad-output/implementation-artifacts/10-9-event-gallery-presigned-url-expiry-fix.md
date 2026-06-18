# Story 10.9: Fix expired presigned URLs in event photo gallery

Status: done

## Story

As a visitor,
I want the photo gallery on past event pages to keep loading every time I visit,
so that the images never silently turn into broken/404 thumbnails a short while after an admin last edited the event.

## Context / Bug Report

On a completed event (e.g. `https://archilan.fr/evenements/e157d1253cec7f4c148da3a1baff3964`, "ArchiLAN #1"), the **cover image loads fine but every gallery photo is broken**.

Browser-level symptom: gallery image requests fail with `net::ERR_BLOCKED_BY_ORB`. MinIO returns `403 AccessDenied` (XML) for an **expired** presigned URL; the browser tries to render that XML as an image, fails, and ORB-blocks it — surfacing to the user as a missing/"404" image.

Evidence (live, **uncached** API response — `cache-control: no-cache, private`, served fresh by FrankenPHP at request time):

| Field | MinIO signature (`X-Amz-Date`) | State |
|---|---|---|
| `coverImageUrl` | `20260617…` (today) | fresh ✓ |
| `photoGallery[*]` | `20260611T084643Z` + `X-Amz-Expires=3600` | expired ~1h after 2026-06-11 08:46 UTC ✗ |

This is **not** a frontend/CDN cache problem (`force-dynamic` is already set on the page and the API response is uncached). The backend itself stores and returns frozen, already-expired presigned URLs for the gallery.

### Root cause

`PublicEventCatalog::resolvePhotoGallery()` (and the admin equivalent in `AdminEventDrafts`) re-signs gallery items **only** when they are stored as `{source: 'upload', key: '…'}` — items stored as `{source: 'url', url: '…'}` are returned verbatim. The cover image is always stored as `coverImageKey` and re-signed on every read, which is why it stays fresh.

The gallery items for affected events are stored as `source: 'url'` holding a one-time presigned URL. They got there via a defect in `AdminEventDrafts::reconcilePhotoGallery()` (`api/src/Events/Application/AdminEventDrafts.php` ~lines 414-440):

1. The admin edit form loads the gallery via `resolvePhotoGallery()`, which returns **presigned** URLs (a fresh signature each call).
2. On save, the frontend submits those same presigned URL strings back.
3. `reconcilePhotoGallery()` tries to match each submitted URL against the **currently** re-signed URLs (`$currentUrls = $this->resolvePhotoGallery($event)`) using strict equality `$currentUrl !== $submittedUrl`.
4. A presigned URL is **non-deterministic** — `X-Amz-Date` and `X-Amz-Signature` differ between the form-load signing and the save-time signing. So the match **never succeeds**.
5. With no match, the code falls back to `$result[] = $submittedUrl`, persisting the raw (soon-expired) presigned URL as a plain string, which `Event::normalizePhotoGallery()` stores as `{source: 'url', url: '…'}`.

Net effect: **every admin save of an event that has gallery uploads silently demotes the upload items (`key`, re-signed on read) to frozen `url` items.** Since presigned URLs carry `X-Amz-Expires=3600` (1h), the gallery breaks ~1 hour after that save. The `20260611` timestamp is the last time ArchiLAN #1 was saved in the backoffice.

## Acceptance Criteria

1. Given a completed event whose gallery photos were uploaded (stored under our MinIO media bucket), when a visitor opens the event detail page at any time (including days/weeks after the last admin edit), then all gallery photos load successfully (HTTP 200), because their URLs are re-signed on every read.
2. Given an admin opens an event with gallery uploads in the backoffice and saves the form without changing the gallery, when the event is persisted, then the gallery items remain `source: 'upload'` with their object `key` — they are **not** converted to frozen `source: 'url'` entries.
3. `reconcilePhotoGallery()` matches submitted gallery entries against existing items by their **stable object key** (the URL path component, ignoring the query string / presign params), not by the full presigned URL string.
4. Genuinely external gallery URLs (entries that are not objects in our MinIO media bucket) continue to be stored and served verbatim as `source: 'url'`, unchanged.
5. A data-correction migration repairs existing events: any `photo_gallery` entry stored as `{source: 'url', url}` whose URL points at our own media bucket (`…/media/events/<id>/gallery/<file>`) is rewritten to `{source: 'upload', key: 'events/<id>/gallery/<file>'}`. Entries pointing elsewhere are left untouched. The migration is reversible (`down()` is a documented no-op since the original frozen URLs are not worth restoring).
6. After the fix, the live API response for ArchiLAN #1 returns `photoGallery` URLs signed with the current date, and all 12 thumbnails render.

## Tasks / Subtasks

- [x] Fix `reconcilePhotoGallery()` matching logic (AC: 2, 3, 4)
  - [x] Compare on the stable object key derived from the URL path (strip scheme/host/query string) instead of the full presigned URL.
  - [x] When a submitted entry resolves to a managed gallery object, store `{source: 'upload', key}` (self-healing: works whether the current item was already upload or a frozen url).
  - [x] When a submitted entry is a new external URL (not in our media bucket), keep storing it as a plain URL string (→ `source: 'url'`).
  - [x] Add a small private helper (`extractMediaObjectKey`) to extract the object key from a media-bucket URL (matches the `events/<id>/gallery/<uuid>.<ext>` key format produced by `AdminEventGalleryController`).
- [x] Data-correction migration (AC: 5)
  - [x] New `Version20260617220001.php` (timestamp one second after the latest existing migration).
  - [x] `up()`: select `event` rows with a non-null `photo_gallery`, in PHP rewrite each `{source:'url'}` (or legacy string) entry whose URL is a media-bucket gallery object into `{source:'upload', key}`, leave external entries as-is, queue per-row parameterised `UPDATE` via `addSql`. No raw JOIN updates.
  - [x] `down()`: documented no-op.
- [x] Tests (AC: 1-4)
  - [x] Functional regression test (`testEditPreservesUploadSourceWhenSubmittingStalePresignedUrls`) proving an admin save that re-submits stale/expired presigned gallery URLs keeps the items as `source: 'upload'` with the original key.
  - [x] Made `NullMinioStorage::presignedUrl()` non-deterministic (signature changes per call, mirroring real S3/MinIO) so the existing round-trip test (`testUpdateWithResolvedUploadedUrlKeepsUploadSource`) now genuinely exercises the bug.
  - [x] External gallery URL staying `source: 'url'` is covered by the existing round-trip test's second entry.
- [x] Validate and handoff (AC: 1-6)
  - [x] `vendor/bin/phpstan analyse src tests` → 0 errors (also analysed `migrations`).
  - [x] `vendor/bin/php-cs-fixer check` → 0 violations.
  - [x] `php bin/phpunit` → 1139 tests green, 0 notices/deprecations/warnings.
  - [x] `php bin/console app:architecture:ddd` → exit 0.
  - [ ] Manually re-check the live event page after deploy + run the migration on prod (or via the API response) that gallery URLs are freshly signed. _(post-deploy step)_
  - [x] Update this story file.

## Dev Notes

- The defect is **purely backend**. The frontend page (`frontend/src/app/(public)/evenements/[eventSlug]/page.tsx`) already uses `export const dynamic = "force-dynamic"` and renders `event.photoGallery` directly; no frontend change is expected.
- Two parallel copies of `resolvePhotoGallery()` exist — `PublicEventCatalog` (read) and `AdminEventDrafts` (read + the buggy `reconcilePhotoGallery` write). Only the **write** path (`AdminEventDrafts::reconcilePhotoGallery`) needs the fix; the read paths are already correct for `upload` items.
- Object-key format (from `AdminEventGalleryController::upload`): `events/<eventId>/gallery/<uuid>.<ext>`. The public URL is `https://<minio-host>/<media-bucket>/<key>?<presign params>`, so the key is the path after the bucket segment. Reconstruct the key from the path, never trust the query string.
- DDD/layer rules: matching/normalisation logic belongs in Application (`AdminEventDrafts`) or Domain (`Event::normalizePhotoGallery`); keep MinIO calls behind `MinioStorageInterface`. Do not inject `Connection`/`EntityManagerInterface` into Application. The migration may use DBAL directly (it is infrastructure-level, not Application).
- Consider extracting the duplicated `resolvePhotoGallery()` into a shared helper to avoid the two copies drifting, but keep the change minimal and contained if it risks scope creep.

### References

- [Source: _bmad-output/implementation-artifacts/10-6-galerie-photos-evenement.md] — original gallery story
- [Source: _bmad-output/implementation-artifacts/15-4-upload-cover-evenement.md] — MinIO upload/presign foundation
- Code: `api/src/Events/Application/AdminEventDrafts.php` (`reconcilePhotoGallery`, `resolvePhotoGallery`)
- Code: `api/src/Events/Application/PublicEventCatalog.php` (`resolvePhotoGallery`)
- Code: `api/src/Events/Domain/Event.php` (`normalizePhotoGallery`, `getPhotoGallery`, `appendGalleryUpload`)
- Code: `api/src/Events/Presentation/AdminEventGalleryController.php` (key format)

## Dev Agent Record

### Agent Model Used

Claude Opus 4.8 (claude-opus-4-8)

### Completion Notes List

- `AdminEventDrafts::reconcilePhotoGallery()` no longer compares full presigned URLs. It now derives the stable object key from each submitted URL's path via a new `extractMediaObjectKey()` helper and stores managed gallery objects as `{source: 'upload', key}` (re-signed on every read), keeping external URLs as plain strings. The `$event` parameter was dropped (no longer needed).
- The fix is self-healing: re-saving an event that already has frozen `source: 'url'` gallery entries (the bug's output) converts them back to `source: 'upload'`, because the key is re-derived from the submitted URL regardless of the stored shape.
- Data-correction migration `Version20260617220001.php` repairs existing rows: any `photo_gallery` entry whose URL matches `events/{id}/gallery/{file}` is rewritten to an upload key; external entries untouched; `down()` is a no-op.
- Root-cause was masked in tests because `NullMinioStorage::presignedUrl()` was deterministic. Made it non-deterministic (per-call signature) so the existing round-trip test now fails against the old logic and passes against the fix; added a dedicated stale-URL regression test.
- No frontend change required — the event page already renders `event.photoGallery` and uses `force-dynamic`.

### Validation Results

- `vendor/bin/phpstan analyse src tests migrations` — No errors (805 files).
- `vendor/bin/php-cs-fixer check` — 0 violations.
- `php bin/phpunit tests/Functional/AdminEventGalleryTest.php tests/Functional/AdminEventEditTest.php tests/Functional/AdminEventCoverImageTest.php tests/Functional/AdminEventLifecycleTest.php tests/Functional/AdminEventDraftTest.php` — 33 tests, 768 assertions.
- `php bin/phpunit` (full suite) — 1139 tests, 8207 assertions, 0 notices/deprecations/warnings.
- `php bin/console app:architecture:ddd` — exit 0.

### File List

- `api/src/Events/Application/AdminEventDrafts.php`
- `api/src/Shared/Infrastructure/NullMinioStorage.php`
- `api/migrations/Version20260617220001.php`
- `api/tests/Functional/AdminEventGalleryTest.php`
- `_bmad-output/implementation-artifacts/10-9-event-gallery-presigned-url-expiry-fix.md`

### Change Log

- 2026-06-17: Story drafted from production bug investigation (gallery presigned URLs expired; root-caused to `reconcilePhotoGallery` comparing non-deterministic presigned URLs).
- 2026-06-17: Implemented key-based reconciliation + data-repair migration + regression tests. All four quality gates green.