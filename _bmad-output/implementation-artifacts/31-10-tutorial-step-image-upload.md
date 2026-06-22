# Story 31.10: Upload images for tutorial steps

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an admin or a contributing member,
I want to upload an image file directly on a tutorial step (instead of only pasting a URL),
so that tutorials can show screenshots without hosting images elsewhere first.

Extends the shared install-steps editor (stories 31.1/31.5) and every step-rendering path
(GameSelection + the global guide + community contributions).

## Acceptance Criteria

1. **Upload endpoint.** `POST /api/v1/tutorial-images` accepts a multipart `file`, **authenticated-user
   gated** (`ApiAccessGuard::requireUser`, like the contribution submit endpoint), validates type
   (JPEG/PNG/WebP/GIF) and size (≤ 10 Mo), stores it in the MinIO **media bucket** under
   `tutorials/{ulid}.{ext}`, and returns `{ data: { key, url } }` where `url` is a presigned URL for
   immediate preview. Errors mirror the cover-image controller (`missing_file` / `image_too_large` /
   `image_invalid_type` 422, `storage_unavailable` 503).
2. **Step model.** A step gains an optional `imageKey` (string|null) alongside the existing `imageUrl`.
   `imageKey` = an uploaded MinIO object key; `imageUrl` = an external pasted URL. `InstallStepsNormalizer`
   accepts/validates `imageKey` (non-empty, length-capped, `tutorials/` prefix) and persists it.
3. **Presign at read.** Every path that emits steps resolves the displayed image: `imageUrl = imageKey ?
   presign(imageKey, ttl) : imageUrl`. Covered: public game detail, public guide, and the admin
   contributions diff (proposed + current). Admin/editor read paths also expose the raw `imageKey` so the
   editor can round-trip it on save without persisting the (expiring) presigned URL.
4. **Editor.** The shared `InstallStepsEditor` gets a file-picker per step ("Téléverser une image") **in
   addition to** the existing URL field. Selecting a file uploads it, shows a preview, and sets
   `imageKey`; an uploaded image takes precedence over the URL field. On save, a step with `imageKey`
   persists the key (not the presigned preview URL); otherwise the external `imageUrl` is persisted.
5. **Surfaces.** Works in the admin game tutorial editor, the global guide editor
   (`/admin/aide-archipelago`), and the community contribution form.
6. **Gates green:** backend (php-cs-fixer, phpstan max, phpunit 0 notices, `app:architecture:ddd`) and
   frontend (typecheck, lint, build, jest).

## Tasks / Subtasks

- [ ] **api/ upload**: `UploadTutorialImageCommand` (Application; injects `MinioStorageInterface`,
      `string $minioMediaBucket`, `int $minioPresignTtl`) → stores bytes, returns `{key, url}` (presigned).
      `TutorialImageController` (Presentation, `requireUser`) validates mime/size like
      `AdminPostCoverImageController` and calls it. Route `POST /api/v1/tutorial-images`.
- [ ] **api/ model**: `InstallStepsNormalizer` accepts + validates `imageKey`; the persisted step shape
      gains `imageKey`. Update the step array shapes in `Game`, the normalizer docblock, and the query DTOs.
- [ ] **api/ presign at read**: introduce a shared `InstallStepsCodec` (GameSelection Infrastructure) that
      decodes step JSON **and** presigns `imageKey` → `imageUrl` (injecting MinIO + bucket + ttl), replacing
      the duplicated `decodeSteps`/`decodeInstallSteps` in `DbalGameCatalogQuery`, the guide query, and
      `DbalAdminGameContributionsQuery`. Emit both `imageKey` (raw) and resolved `imageUrl`. Public DTOs may
      keep `imageKey` (ignored by the public view).
- [ ] **frontend editor**: `InstallStepsEditor` - add an upload button + preview per step; `InstallStep`
      gains `imageKey?: string|null`; a `serializeStepsForSave(steps)` helper drops the preview URL when
      `imageKey` is set. New `uploadTutorialImage(file)` api helper (FormData → `{key, url}`). Wire it in the
      admin game editor save, guide settings save, and the contribution form submit.
- [ ] **frontend view/types**: `GameStep` + `isGameStep` gain optional `imageKey`; `InstallStepsView`
      keeps rendering `imageUrl` (resolved) - no visual change beyond now-working uploads.
- [ ] **Tests**: backend functional (upload happy path + bad mime/size + auth; presigned URL appears for an
      `imageKey` step in a read DTO via `NullMinioStorage`); frontend jest (`uploadTutorialImage` posts
      FormData and returns `{key,url}`; `serializeStepsForSave` nulls the preview URL when `imageKey` set).

## Dev Notes

- **Reuse**: `AdminPostCoverImageController` + `UploadPostCoverImageCommand` are the template for validation
  + MinIO upload; `ManageEventGalleryCommand` for the key-per-asset pattern; reads presign via
  `MinioStorageInterface::presignedUrl(bucket, key, ttl)`. [Source: api/src/Content/Presentation/AdminPostCoverImageController.php, api/src/Events/Application/ManageEventGalleryCommand.php]
- **DI**: `string $minioMediaBucket` and `int $minioPresignTtl` are globally bound in services.yaml - inject
  them directly. `MinioStorageInterface` may be injected into Application (precedent:
  `UploadPostCoverImageCommand`). [Source: api/config/services.yaml]
- **Gating**: the contribution submit endpoint uses `ApiAccessGuard::requireUser`; match it so members can
  upload for contributions (which stay moderated before publish - story 31.7). Never `ROLE_MEMBER` (AC-M1).
- **Step decode sites today (to converge on the codec)**: `InstallStepsNormalizer` (write),
  `DbalGameCatalogQuery` (public detail + admin read), the guide query, `DbalAdminGameContributionsQuery`
  (diff). [Source: grep of imageUrl across GameSelection]
- **Tests**: `NullMinioStorage` (when@test) stores bytes and returns a stub presigned URL containing the
  object key - assert the key path appears in the resolved `imageUrl`. [Source: api/src/Shared/Infrastructure/NullMinioStorage.php]
- **Scope**: images only (videos stay URL/YouTube). No public bucket / permanent URL - reuse the private
  media bucket + presign-at-read, consistent with covers.

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-31-archipelago-install-tutorials.md]
- Standards: [Source: api/CLAUDE.md], [Source: frontend/AGENTS.md], [Source: api/CLAUDE.md#membership-access-control]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Centralized the previously-duplicated step decoding into one Application `InstallStepsReader` that drops
  unknown-type steps and resolves images (presigning an uploaded `imageKey`, else passing through the
  external `imageUrl`). It now feeds the game-catalog query, the guide query, the admin contributions diff
  (which previously dropped images entirely) and the admin editor read - so images are consistent
  everywhere. Infrastructure DBAL queries inject this Application service (Infra → App, allowed).
- `imageKey` is validated by the normalizer (must be a `tutorials/`-prefixed, length-capped string) so a
  crafted body can't make the read side presign an arbitrary object.
- Editor keeps both inputs: upload (file picker, with preview + remove) takes precedence; an external URL
  is still accepted. `serializeStepsForSave` drops the transient presigned preview before persisting so the
  expiring URL never lands in the DB; the key round-trips.
- Upload endpoint is `requireUser` (matches contribution submit) so members can attach screenshots; their
  contributions stay moderated.
- Gates: phpstan max ✅, php-cs-fixer ✅, `app:architecture:ddd` ✅, functional (TutorialImageUpload,
  PublicGameDetail incl. presign, AdminGameTutorial, contributions, guide) ✅; typecheck ✅, lint ✅,
  jest 20 suites/121 tests ✅, build ✅.

### File List

- api/src/GameSelection/Application/InstallStepsReader.php (new - decode + presign)
- api/src/GameSelection/Application/UploadTutorialImageCommand.php (new)
- api/src/GameSelection/Presentation/TutorialImageController.php (new - POST /api/v1/tutorial-images)
- api/src/GameSelection/Application/InstallStepsNormalizer.php (accept/validate imageKey)
- api/src/GameSelection/Application/ArchipelagoGuideQuery.php (use reader)
- api/src/GameSelection/Application/AdminGameLibrary.php (use reader for editor read)
- api/src/GameSelection/Application/GameCatalogQueryInterface.php (step shape + imageKey)
- api/src/GameSelection/Application/AdminGameContributionsQueryInterface.php (step shape + imageKey)
- api/src/GameSelection/Infrastructure/DbalGameCatalogQuery.php (use reader)
- api/src/GameSelection/Infrastructure/DbalAdminGameContributionsQuery.php (use reader; now carries images)
- api/tests/Functional/TutorialImageUploadTest.php (new)
- api/tests/Functional/PublicGameDetailTest.php (imageKey presign test)
- frontend/src/features/games/tutorial-image-api.ts (new - uploadTutorialImage)
- frontend/src/features/games/install-steps-editor.tsx (imageKey, upload field, serializeStepsForSave)
- frontend/src/features/games/public-games-api.ts (GameStep.imageKey + guard)
- frontend/src/features/admin/admin-game-editor.tsx (serialize on save)
- frontend/src/features/admin/archipelago-guide-settings.tsx (serialize on save)
- frontend/src/features/games/game-contribution-form.tsx (serialize on submit)
- frontend/src/features/games/tutorial-image-api.test.ts (new)
- frontend/src/features/games/install-steps-serialize.test.ts (new)
