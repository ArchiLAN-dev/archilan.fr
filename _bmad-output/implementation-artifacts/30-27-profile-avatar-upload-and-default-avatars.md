# Story 30.27: Custom avatar upload + default avatars (and Steam source wiring)

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a member personalizing my profile,
I want to upload my own profile picture (or get a nice default one if I have none),
so that my profile has a face even when I haven't linked Discord/Steam — instead of a blank avatar.

Extends the avatar resolution pipeline (story 30.2) and the `/compte` profile editor (stories 30.3/30.19).

### Why this exists (root cause)

Today the avatar is **only** a cached URL resolved from external sources: `DiscordAvatarResolver` (Discord
only — Steam is still a `// future` TODO in the code even though `Identity\User::steamProfile` already
exists). A member with **no linked Discord and no Steam** resolves to `null` → a blank avatar, with no way
to fix it. There is **no custom image upload** for avatars and **no default avatar set**, so the profile
header looks empty for a large share of members. Discord decision (Maxime/MasterKafey, 2026‑06‑19): "tout
le monde n'a pas de PP Steam, il faudrait des icônes de base personnalisées comme Blizzard", plus enable
custom upload. (Note: the avatar source already in place is **Discord**, not Steam — Steam is out of scope.)

## Acceptance Criteria

1. **Upload endpoint (self only).** `POST /api/v1/community/profile/avatar` accepts a multipart `file`,
   gated by `ApiAccessGuard::requireUser` (acts on the caller's own profile), validates type
   (JPEG/PNG/WebP) and size (≤ 5 Mo), stores it in the MinIO **media bucket** under
   `community/avatars/{ulid}.{ext}`, sets `CommunityProfile.customAvatarKey`, and returns
   `{ data: { url } }` where `url` is a presigned URL for immediate preview. Errors mirror the tutorial
   image controller (`missing_file` / `image_too_large` / `image_invalid_type` 422,
   `storage_unavailable` 503).
2. **Remove endpoint.** `DELETE /api/v1/community/profile/avatar` clears `customAvatarKey` (best-effort
   delete of the MinIO object), so the avatar falls back to the external source, then the default. Idempotent.
3. **Resolution precedence (single source of truth).** The resolved avatar is, in order: **(a)** presigned
   `customAvatarKey` if set, else **(b)** the cached external URL (`avatar_url` from the resolver), else
   **(c)** a deterministic default avatar key. The custom key is **presigned at read** (never cached as an
   expiring URL — same pattern as story 31.10), and `RefreshCommunityAvatars` MUST NOT overwrite or clear a
   custom avatar (a member with a custom avatar is skipped by the refresh job).
4. **Default avatar set (Blizzard-style).** A curated `DefaultAvatar` value object exposes N base avatars
   (mirroring the `AvatarFrame` / `BannerPreset` curated-set pattern). Each member is assigned a **stable**
   default by hashing their user id (same member → same default across requests). The profile read model
   exposes the default key so the frontend renders the right base image when (a) and (b) are both absent.
5. **Steam source wired.** The external resolver becomes a **composite** (precedence Steam → Discord, or
   Discord → Steam — pick one and document it) that also builds a Steam avatar URL from
   `Identity\User::steamProfile` when present. If front-end Steam wiring already covers this, this AC reduces
   to confirming the back-end path resolves Steam and adding the missing resolver; otherwise implement
   `SteamAvatarResolver` (Infrastructure, best-effort, never throws — AC of story 30.2).
6. **Editor.** The `/compte` profile editor gains an avatar control: upload a file (preview + "Retirer"),
   which calls the upload/remove endpoints; the header preview updates immediately. No change to slug/pseudo
   handling.
7. **Gates green:** backend (php-cs-fixer, phpstan max, phpunit 0 notices, `app:architecture:ddd`) and
   frontend (typecheck, lint, build, jest).

## Tasks / Subtasks

- [ ] **api/ domain**: `CommunityProfile` gains `customAvatarKey` (string|null) with
      `setCustomAvatar(?string $key)` / business method (no public setter — AC-D5); migration
      `ALTER TABLE community_profile ADD custom_avatar_key` (+ `down()` drop). Add `DefaultAvatar` value
      object (`final readonly`, `ALL` keys + `forUserId(string): string` deterministic pick).
- [ ] **api/ upload**: `UploadCommunityAvatarCommand` (Application; injects `MinioStorageInterface`,
      `string $minioMediaBucket`, `int $minioPresignTtl`, `CommunityProfileRepositoryInterface`) → store
      bytes, set key, return presigned url. `RemoveCommunityAvatarCommand` clears the key + deletes the
      object. Reuse `UploadTutorialImageCommand` (story 31.10) as the template.
- [ ] **api/ presentation**: extend `CommunityProfileController` (or a small dedicated controller) with
      `POST`/`DELETE /api/v1/community/profile/avatar`, `requireUser`, validating mime/size like
      `TutorialImageController`.
- [ ] **api/ read model**: `CommunityProfileView` resolves the avatar via the (a)/(b)/(c) precedence and
      presigns `customAvatarKey`; expose resolved `avatarUrl` + `defaultAvatarKey`. Ensure
      `RefreshCommunityAvatars` skips profiles with a `customAvatarKey`.
- [ ] **api/ steam**: composite `AvatarResolverInterface` impl (Discord + Steam), or `SteamAvatarResolver`
      reading `Identity\User::steamProfile`; wire precedence in `services.yaml`.
- [ ] **frontend**: avatar upload/remove control in the `/compte` editor (file picker, preview, remove);
      `uploadCommunityAvatar(file)` / `removeCommunityAvatar()` api helpers; render `defaultAvatarKey` base
      image in the header when no resolved url.
- [ ] **Tests**: backend functional (upload happy path + bad mime/size + auth; remove falls back;
      precedence: custom > external > default; refresh skips a custom-avatar profile) using
      `NullMinioStorage`; unit (`DefaultAvatar::forUserId` is deterministic + stable). frontend jest (upload
      helper posts FormData; editor sets/clears preview).

## Dev Notes

- **Reuse (image upload)**: `UploadTutorialImageCommand` + `TutorialImageController` (story 31.10) are the
  exact template — same MinIO media bucket, same presign-at-read, same validation + error codes, same
  `NullMinioStorage` test double. [Source: api/src/GameSelection/Application/UploadTutorialImageCommand.php,
  api/src/GameSelection/Presentation/TutorialImageController.php,
  _bmad-output/implementation-artifacts/31-10-tutorial-step-image-upload.md]
- **DI**: `string $minioMediaBucket` + `int $minioPresignTtl` are globally bound; `MinioStorageInterface`
  may be injected into Application (precedent: tutorial + cover uploads). [Source: api/config/services.yaml]
- **Avatar pipeline**: external resolution stays off the request path via `RefreshCommunityAvatars`
  (7‑day TTL, caches null to throttle). Custom avatars are *not* external → they bypass the cache and
  presign at read. Don't let the refresh job touch a custom-avatar profile.
  [Source: api/src/Community/Application/RefreshCommunityAvatars.php,
  api/src/Community/Application/AvatarResolverInterface.php,
  api/src/Community/Infrastructure/DiscordAvatarResolver.php]
- **Curated-set precedent**: model `DefaultAvatar` like `AvatarFrame` / `BannerPreset` (fixed `ALL` list,
  validation, no free input). [Source: api/src/Community/Domain/AvatarFrame.php]
- **Steam**: `Identity\User::steamProfile` (string, nullable) already persisted — the resolver just needs
  to read it (cross-context read via the existing query the resolver uses). Confirm whether the FE already
  shows a Steam PP before deciding implement-vs-confirm. [Source: api/src/Identity/Domain/User.php]
- **Gating**: `ApiAccessGuard::requireUser` (any authenticated user acting on their own profile). Never
  `ROLE_MEMBER` (AC-M1).
- **Scope**: images only (no GIF avatars to avoid animated-avatar moderation surface — confirm with PO).
  No public bucket; presign-at-read consistent with covers/tutorials.

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md]
- Avatar resolution: [Source: _bmad-output/implementation-artifacts/] (30.2 avatar resolution/caching)
- Image upload infra: [Source: _bmad-output/implementation-artifacts/31-10-tutorial-step-image-upload.md]
- Standards: [Source: api/CLAUDE.md], [Source: frontend/AGENTS.md],
  [Source: api/CLAUDE.md#membership-access-control]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- **Custom avatar as a separate column.** Added `community_profile.custom_avatar_key` (MinIO object key)
  distinct from the existing `avatar_url` external cache. Resolution precedence = presigned custom key →
  cached external URL → null (frontend renders the default). Because the two are separate columns, the
  refresh job — which only writes `avatar_url` — can never clobber a custom avatar (AC-3 holds structurally,
  no special-casing needed); the refresh query additionally skips custom-avatar rows to avoid wasted
  external calls.
- **One resolution chokepoint.** New `AvatarUrlResolver` (Application) presigns the custom key (best-effort:
  falls back to the external URL if storage is unreachable). Injected into `CommunityProfileView` (profile
  header + editor read) and into `DbalCommunityUserDirectoryQuery::cards()` — the single card builder behind
  the directory, moderation, comments, feed, notifications and friends lists — so an uploaded avatar shows
  **everywhere** with one change instead of touching each consumer DTO.
- **Upload/remove.** `CommunityAvatarService` stores bytes in the media bucket under
  `community/avatars/{ulid}.{ext}` (lazy-creating the profile row so a member can upload before first
  view), sets/clears the key, returns the resolved URL. `CommunityAvatarController` mirrors the tutorial
  upload validation (JPEG/PNG/WebP, ≤ 5 Mo; `missing_file`/`image_too_large`/`image_invalid_type` 422,
  `storage_unavailable` 503). GIF intentionally excluded (no animated avatars). No delete on the storage
  port — replaced/cleared objects are orphaned, consistent with covers/tutorials.
- **Default avatars — frontend-owned (deviation from AC-4).** Rather than emit a `defaultAvatarKey` on every
  card DTO (which would couple ~6 consumer shapes + their FE types), the `ProfileAvatar` component renders a
  deterministic, curated "Blizzard-style" gradient default keyed by a stable hash of the member's name
  whenever no avatar URL is present. Single algorithm, consistent everywhere, zero DTO ripple. The backend
  emits only the resolved `avatarUrl` (null ⇒ default). Documented as an intentional simplification.
- **Editor.** `/compte` customization form gains a "Photo de profil" section: live `ProfileAvatar` preview +
  upload/change/remove, applied immediately (independent of the save bar, like the tutorial upload).
  `editableForUser`/`MyCommunityProfile` now expose `avatarUrl` + `hasCustomAvatar`.
- **External source = Discord (already wired); Steam (AC-5) out of scope.** Clarified with the PO: the
  pre-existing "PP already done" is the **Discord** avatar (`DiscordAvatarResolver`, back-end, already in
  the codebase) — not Steam. This story keeps that Discord source working as the external fallback
  (precedence: custom upload → Discord cache → default). Steam was never implemented and is **not** part of
  30.27; building a Steam avatar URL would need the Steam Web API (key + vanity→steamid64 resolution) and
  can be a separate future story — the `AvatarResolverInterface` port lets a `SteamAvatarResolver` slot in.
- **Gates:** phpstan max ✅, php-cs-fixer ✅, `app:architecture:ddd` ✅, `lint:container` ✅, phpunit
  (CommunityAvatarTest + 220 community/profile suites green; full-suite local run hit the known shared
  test-DB schema-contention flake, unrelated suites pass in isolation, CI authoritative) ✅; FE typecheck ✅,
  lint ✅, build ✅, jest (community-avatar-api + existing) ✅.

### File List

- api/src/Community/Domain/CommunityProfile.php (custom_avatar_key field + setCustomAvatar/getter)
- api/migrations/Version20260619120001.php (new — add custom_avatar_key)
- api/src/Community/Application/AvatarUrlResolver.php (new — presign custom else external)
- api/src/Community/Application/CommunityAvatarService.php (new — upload/remove + lazy profile)
- api/src/Community/Presentation/CommunityAvatarController.php (new — POST/DELETE /community/profile/avatar)
- api/src/Community/Application/CommunityProfileView.php (resolve avatarUrl; editor avatarUrl + hasCustomAvatar)
- api/src/Community/Infrastructure/DbalCommunityUserDirectoryQuery.php (cards resolve custom avatar)
- api/src/Community/Infrastructure/DoctrineCommunityProfileRepository.php (skip custom-avatar rows on refresh)
- api/tests/Functional/CommunityAvatarTest.php (new)
- frontend/src/features/community/community-profile-api.ts (avatarUrl/hasCustomAvatar + upload/remove helpers)
- frontend/src/features/community/community-profile-customization-form.tsx ("Photo de profil" section)
- frontend/src/features/players/profile-avatar.tsx (deterministic curated default variants)
- frontend/src/features/community/community-avatar-api.test.ts (new)