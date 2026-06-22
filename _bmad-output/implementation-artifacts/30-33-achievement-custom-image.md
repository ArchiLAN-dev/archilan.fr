# Story 30.33: Optional Custom Image on an Achievement (in Place of the Default Trophy)

## Story

**As an** admin,
**I want** to optionally upload a custom image for an achievement (shown instead of the default trophy
icon),
**So that** each achievement can have its own visual identity instead of all looking alike.

## Context

Achievements are DB-backed definitions (story 30.16) rendered with a generic trophy/lock icon by the
shared `AchievementCard` (story 30.31) on the profile card and the catalogue page. As the catalogue grows
(stories 30.31, 30.32 added the catalogue page + event achievements), a per-achievement image makes them
recognisable. The image is **optional**: when absent, the current trophy/lock icon is kept.

The project already has an image-upload pattern to reuse end to end (story 30.27, profile avatar): MinIO
storage behind `MinioStorageInterface`, an admin-gated multipart upload endpoint, a key column on the
entity, and a presigned-URL resolver (`AvatarUrlResolver`) for read payloads.

This is a Community-context change only (admin authoring + read payloads + render). No rule-engine change.

## Status

done

## Acceptance Criteria

**AC1 (upload):** A new admin-gated endpoint `POST /api/v1/admin/community/achievements/image` accepts a
multipart `file`, validates mime (`image/jpeg`, `image/png`, `image/webp`) and size (max 5 MB), stores it
in MinIO under `community/achievement-images/{random}.{ext}`, and returns
`{ data: { key, imageUrl } }` (imageUrl presigned). Mirrors `CommunityAvatarController`.

**AC2 (persist):** `AchievementDefinition` gains an optional `customImageKey` (column
`custom_image_key VARCHAR(512) NULL` on `community_achievement_definition`, nullable migration with a
`down`). `create`/`update` accept an optional image key; passing an explicit null/empty clears it. The
admin payload (`present`) and the dashboard expose the key + a resolved `customImageUrl`.

**AC3 (resolve for reads):** a new `AchievementImageUrlResolver` (Community/Application, reusing
`MinioStorageInterface` + the media bucket + presign TTL, like `AvatarUrlResolver`) turns the stored key
into a presigned URL. `CommunityProfileView` exposes `customImageUrl: string|null` on each achievement in
both the profile recent slice (`forSlug`) and the catalogue (`achievementsCatalogFor`).

**AC4 (render):** `AchievementCard` renders the custom image in place of the default icon when
`customImageUrl` is set: shown for the unlocked state, and shown faded for the locked state (consistent
with the current locked opacity); the trophy (unlocked) / lock (locked) icons remain the fallback when no
image. `alt` stays empty (decorative; the name is adjacent text).

**AC5 (admin authoring):** the admin form (`admin-achievements-dashboard` `AchievementForm`) gains an
image picker between description and the rule builder: pick a file -> upload via the new endpoint -> show
a thumbnail preview; a "remove image" control clears it. The chosen key flows through create/update.

**AC6:** All quality gates pass (phpstan, php-cs-fixer, phpunit, app:architecture:ddd; frontend
typecheck/lint/build/jest). No em-dashes anywhere (see root CLAUDE.md typography rule).

## Tasks / Subtasks

- [x] Task 1: API - `AchievementDefinition`: add nullable `customImageKey` field + ORM column + getter +
  `setCustomImage(?string $key, now)`; thread it through `create`/`update`. Migration adding
  `custom_image_key` (reversible).
- [x] Task 2: API - `AchievementImageUrlResolver` (Community/Application) reusing `MinioStorageInterface`
  + media bucket + presign TTL (model on `AvatarUrlResolver`).
- [x] Task 3: API - upload endpoint on `AdminAchievementController`
  (`POST /admin/community/achievements/image`), admin-gated, mime/size validation, MinIO put, presigned
  response. Extract a small application command/service for the upload (no infra in the controller).
- [x] Task 4: API - `AdminAchievementService`: accept `customImageKey` in create/update (clear on
  null/empty); `present` returns `customImageKey` + resolved `customImageUrl`. `CommunityProfileView`
  (`achievementsFor`) adds `customImageUrl` to each achievement, resolved once per request.
- [x] Task 5: API tests - upload returns key + url; non-image / oversized rejected; a definition with an
  image exposes `customImageUrl` on the profile slice and the catalogue; clearing the key removes it.
- [x] Task 6: Frontend - `admin-achievements-api.ts`: `customImageKey?` on the definition + create/update
  payloads; `uploadAchievementImage(file)` client. `AchievementForm`: image picker + preview + remove,
  passing the key through.
- [x] Task 7: Frontend - `Achievement` type gains `customImageUrl: string | null` (+ type guard);
  `AchievementCard` renders the image when present (faded when locked), else the trophy/lock.
- [x] Task 8: Frontend tests (jest) - api parses `customImageUrl`; the upload client returns key+url on
  success and null on failure.
- [x] Task 9: Quality gates.

## Dev Notes

### Reuse, do not reinvent

The whole upload path already exists for avatars (story 30.27): `MinioStorageInterface` +
`CommunityAvatarController` + `AvatarUrlResolver`. Mirror it; only the key prefix, the entity column, and
the admin gate differ. Keep all MinIO calls in Infrastructure / a thin application command, never in the
controller (AC-A/AC-I).

### Locked vs unlocked

The image is the achievement's identity, so show it in both states but keep the "not earned" affordance:
faded image when locked (the card already applies `opacity-70` to locked tiles), full image when unlocked.
Fallback to trophy/lock when no image.

### Payload size

`customImageUrl` is added to the per-achievement objects already returned by the profile slice and the
catalogue, so no extra request. Resolve presigned URLs once per request for the set of achievements shown.

### Out of scope

- Deleting the old MinIO object when an image is replaced/removed (orphaned objects are harmless; a
  sweep can be a later chore).
- Image cropping / resizing / a media library picker (upload-and-store only).
- Animated images or per-state (locked vs unlocked) distinct images.
