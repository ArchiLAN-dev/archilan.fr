# Story 34.4: ISR strategy & stable public image URLs (frontend/ + api/)

Status: in-progress (backend delivered; frontend ISR = follow-up PR)

Delivered in two PRs for reviewability: **PR 1 = backend infra** (public bucket, resolver, upload
routing, compose bootstrap, safe fallback - `composer gates`); **PR 2 = frontend ISR** (fetchers
`no-store`->`revalidate`, pages `force-dynamic`->`revalidate`, drop `unoptimized` - `pnpm gates`).

## Story

As a visitor (and as Google measuring Core Web Vitals),
I want public pages served from cache with a fast TTFB and their images optimised,
so that the site loads quickly and ranks better, without ever showing a stale realtime signal as
authoritative.

## Context

Epic 34 baseline audit (2026-07-08), gaps this story closes:

- **Gap 6** - `export const dynamic = "force-dynamic"` on every public page (8 files); `revalidate` nowhere.
  Zero ISR/CDN caching, higher TTFB. **Root cause**: public cover images are served as **short-TTL presigned
  MinIO URLs** (`minioStorage->presignedUrl(mediaBucket, key, 3600)`) that expire ~1h after generation, which
  killed the original SSG on event detail (story 1.4). So the whole page tree was forced dynamic to keep
  regenerating fresh presigned URLs.
- **Gap 9 (partial)** - remote MinIO images bypass Next optimization via `unoptimized` because presigned URLs
  (query-string, short-lived) don't cache/optimise well. (`remotePatterns` tightening + AVIF is **34.5**, not
  this story.)

**Decision (locked with the product owner, 2026-07-15): dedicated public MinIO bucket.** Public media get a
stable public URL from a public-read bucket, so every public page can adopt `revalidate`, images become
CDN-cacheable and Next-optimisable, and OG image URLs stop rotating in social caches. Private surfaces
(spoilers, patches, saves) keep presigning unchanged. The two alternatives (API proxy route; long-lived
presign) were rejected: the proxy streams every image through PHP, and long-lived presign still rotates the
URL (breaks CDN + OG stability) and is a mild security smell.

### Grounding (verified 2026-07-15)

- `MinioStorageInterface`: `upload/download/exists/presignedUrl` (no public-url method yet). Concrete
  `S3MinioStorage`; test double `NullMinioStorage`.
- Buckets (`config/services.yaml`): `apworlds`, `archipelago-saves`, `media`, `sessions`; presign TTL 3600.
  The `media` bucket **mixes public covers with avatar + achievement images** - so a *dedicated* public bucket
  is required, not a public policy on `media`.
- **Presigned (short-TTL) public images = the only backend work:**
  - Event cover + gallery: `PublicEventCatalog::resolveCoverImageUrl` / gallery -> `presignedUrl(media, key)`.
    Uploaded by `UploadEventCoverImageCommand` / `ManageEventGalleryCommand` -> `upload(media, key)`.
  - Post cover: `PublicPostCatalog::resolveCoverImageUrl` -> `presignedUrl(media, key)`; uploaded by
    `UploadPostCoverImageCommand`.
  - Admin previews (`AdminEventDrafts`, `AdminPostCatalog`) also presign from `media`.
- **Game covers are already stable** (`$game->getCoverImageUrl()` is a plain external/admin URL, not a MinIO
  key) - **no backend change for games**.
- MinIO is bootstrapped by a `createbuckets` (`minio/mc`) service in both `docker-compose.yml` and
  `docker-compose.prod.yml` - so the public bucket + anonymous policy are provisioned **in-repo** (a compose
  redeploy), not by an undocumented manual step.
- **All frontend public fetchers use `cache: "no-store"`** (`public-events-api`, `public-posts-api`,
  `public-games-api`) - the ISR switch requires changing these to a `revalidate` strategy, or the pages stay
  dynamic regardless of `export const revalidate`.

## Acceptance Criteria

1. **AC1 - Stable public image delivery (decision record + implementation).** A dedicated public-read bucket
   (`media-public`) is provisioned in the compose `createbuckets` step (dev + prod) with an anonymous
   `download` policy. Public event cover + gallery and post cover uploads target this bucket; the public
   catalogs return a **stable** URL (`{MINIO_PUBLIC_MEDIA_BASE_URL}/{key}`) for keyed media, via a
   `PublicMediaUrlResolver`. Private surfaces (spoilers/patches/saves) are untouched. The decision + rejected
   alternatives are recorded in this story.
2. **AC2 - ISR on public pages.** Public pages move from `force-dynamic` to `revalidate` (home, `/evenements`,
   `/actualites`, `/jeux`, and the event/post/game detail pages), with the interval documented. The public
   fetchers those pages use switch from `cache: "no-store"` to `next: { revalidate }`. Realtime widgets (seat
   counter, Twitch live badge) stay client-side and keep their own fresh fetch - a cached shell never shows a
   stale seat count as authoritative server content.
3. **AC3 - Measured before/after.** TTFB (or lab LCP) on the home page and one event detail page is recorded
   before and after in this story file. (Requires the public bucket + base URL live - see the ops handoff.)
4. **AC4 - Optimisable images.** Public cover images drop `unoptimized` (stable URLs are Next-optimisable);
   `remotePatterns` tightening + AVIF is explicitly deferred to 34.5.
5. **AC5 - Safe fallback + gates.** With `MINIO_PUBLIC_MEDIA_BASE_URL` unset, the catalogs fall back to
   presigning from the public bucket (no breakage before the CDN base URL is configured). `pnpm gates` +
   `composer gates` green; no behaviour change on private/admin surfaces.

## Tasks / Subtasks

- [x] Task 1: public bucket + config (AC: 1, 5) [api]
  - [x] `config/services.yaml`: add `default_minio_public_media_bucket: 'media-public'` bound to
        `MINIO_BUCKET_MEDIA_PUBLIC`, and a `MINIO_PUBLIC_MEDIA_BASE_URL` (default empty string) bound arg.
  - [x] `docker-compose.yml` + `docker-compose.prod.yml` `createbuckets`: `mc mb --ignore-existing
        local/media-public; mc anonymous set download local/media-public;`.
  - [x] `envs/*.env.example` (or the documented env list): add `MINIO_BUCKET_MEDIA_PUBLIC` and
        `MINIO_PUBLIC_MEDIA_BASE_URL` with dev (`http://localhost:9000/media-public`) + prod (CDN) examples.
- [x] Task 2: `PublicMediaUrlResolver` (AC: 1, 5) [api]
  - [x] New `App\Shared\Application\Support\PublicMediaUrlResolver` (injected `string $publicMediaBaseUrl`):
        `isConfigured(): bool` and `resolve(string $key): string` (`rtrim(base,'/')."/".ltrim(key,'/')`).
        Domain/Application-pure (no DBAL, no HTTP).
  - [x] Unit test: `resolve` joins base + key correctly; `isConfigured` reflects empty base.
- [x] Task 3: route public media to the public bucket (AC: 1, 5) [api]
  - [x] Uploads target the public bucket: `UploadEventCoverImageCommand`, `ManageEventGalleryCommand`,
        `UploadPostCoverImageCommand` inject `$minioPublicMediaBucket` and `upload(publicBucket, key, ...)`.
  - [x] `PublicEventCatalog` + `PublicPostCatalog`: for keyed media, return
        `resolver.isConfigured() ? resolver.resolve(key) : presignedUrl(publicBucket, key, ttl)`. Non-keyed
        (stored URL) media stays as-is.
  - [x] `AdminEventDrafts` + `AdminPostCatalog` presign from the **public** bucket (admin preview; stable URL
        not required, just point at the right bucket).
  - [x] Update affected `functional`/unit tests + any `NullMinioStorage`-based assertions.
- [x] Task 4: migration = ops step (AC: 1) [ops handoff, no code]
  - [x] Object keys are prefixed by kind: covers/galleries live under `events/` and `posts/`; avatars under
        `avatars/`, achievements under their own prefix. So the migration is a **prefix-scoped copy**, not a
        blanket bucket copy (which would expose avatars): `mc cp --recursive local/media/events/
        local/media-public/events/` + same for `posts/`. Reversible (no delete from `media`). Documented in
        the ops handoff - no PHP command, no DB enumeration, no new repository surface.
- [ ] Task 5: ISR + optimisation (AC: 2, 4) [frontend]
  - [ ] Public fetchers used by the ISR pages: `cache: "no-store"` -> `next: { revalidate: <N> }`
        (`getPublicEvents`, `getPublicEvent`, `getPublicPosts`, `getPublicPostBySlugFromApi`,
        `getAllPublicGames`, `getPublicGame`). Keep `no-store` where a caller genuinely needs live data.
  - [ ] Pages: replace `export const dynamic = "force-dynamic"` with `export const revalidate = <N>` on home,
        `/evenements`, `/actualites`, `/jeux`, `evenements/[eventSlug]`, `actualites/[postSlug]`,
        `jeux/[slug]`. Document the interval choice.
  - [ ] Remove `unoptimized` on public cover images (`evenements/[eventSlug]`, `actualites/[postSlug]`,
        `event-card`, `post-card`) now that URLs are stable. Realtime widgets untouched.
  - [ ] Adjust/extend tests (fetcher cache option; page still renders).
- [ ] Task 6: verify + ship (AC: 3, 5)
  - [ ] `pnpm gates` + `composer gates` green. Document the ops handoff (deploy compose; set
        `MINIO_PUBLIC_MEDIA_BASE_URL`; run `app:media:migrate-public`; record TTFB) and the AC3 numbers once
        the base URL is live (or mark AC3 pending the ops step).
  - [ ] Branch `feature/epic-34-story-4-isr-public-images` from `develop`, PR to `develop` (Gitflow).

## Dev Notes

### Safe fallback (why nothing breaks before the CDN is set)

`MINIO_PUBLIC_MEDIA_BASE_URL` defaults to empty. When empty, the catalogs presign from the **public** bucket
(same mechanism as today, just a different bucket) - the app keeps working in dev and in prod the moment the
compose change lands, *before* the CDN/base URL is configured. Setting the base URL later flips delivery to
stable URLs with no code change. Uploads always go to the public bucket, so once migrated, both paths resolve.

### ISR interval

Content changes are editor-driven and infrequent. Propose **`revalidate = 300`** (5 min) on home + listings +
detail pages - fresh enough for an association's cadence, big enough to shed load. Realtime correctness is not
affected: seat counts (`LiveSeatCounter`) and Twitch live state are client components that fetch on the client,
so the cached server shell never carries a stale authoritative seat count (locked decision). Record the final
interval here if it changes during implementation.

### The `no-store` -> `revalidate` coupling (the crux)

A page with `export const revalidate = 300` is still dynamic if the `fetch` inside uses `cache: "no-store"`.
Both must change together. The public fetchers are shared (e.g. `getPublicEvents` feeds home, `/evenements`
and the 34.1 sitemap) - caching them is fine everywhere (a slightly stale sitemap/listing is acceptable). Do
**not** touch fetchers behind authenticated/admin/realtime surfaces.

### Scope boundaries (do not bleed into neighbours)

- Games: **no backend change** (covers already stable URLs). The game *page* still gets `revalidate` + its
  fetcher switched.
- `remotePatterns` tightening + AVIF = **34.5**. Here we only drop `unoptimized`; the current permissive
  `remotePatterns` already allows the MinIO/CDN host, so optimisation works.
- Avatars + achievement images stay in `media` (presigned) - privacy nuance, out of scope.
- `/aide/archipelago` tutorial images stay presigned for now (that page keeps its current caching); extending
  the public bucket to guide images is a follow-up, not this story.

### Ops handoff (needs live infra - cannot be done from the repo alone)

1. Deploy the compose change so `media-public` exists with the anonymous `download` policy.
2. Copy existing public media (prefix-scoped, so avatars/achievements stay private):
   `mc cp --recursive local/media/events/ local/media-public/events/` and
   `mc cp --recursive local/media/posts/ local/media-public/posts/`.
3. Set `MINIO_PUBLIC_MEDIA_BASE_URL` (dev `http://localhost:9000/media-public`; prod the public domain/CDN
   mapped to the bucket) and `MINIO_BUCKET_MEDIA_PUBLIC` if overriding the default.
4. Record home + event-detail TTFB before/after (AC3) into this file.

Note on ordering: uploads switch to `media-public` immediately, so new covers land there. Existing covers
resolve correctly only after step 2. Until `MINIO_PUBLIC_MEDIA_BASE_URL` is set (step 3), the catalogs
presign from `media-public` (safe fallback) - so once step 2 has copied the objects, everything resolves
even before the CDN base URL is configured.

### House rules

- API: DDD layering (`PublicMediaUrlResolver` is Application/Support, pure; no DBAL in Application per the
  repo rule); `composer gates` (phpstan max, cs-fixer, ddd, rector, phpunit). Inject config via constructor
  binding only (no container access at runtime).
- Frontend: AC-ENV1 (`env.ts`, no `process.env`); `pnpm gates`. Server Components keep fetching server-side.

### References

- Epic: `_bmad-output/planning-artifacts/epics/epic-34-seo-search-visibility.md` (gaps 6/9, story 34.4 ACs,
  "MinIO presigned-URL blocker solved once as infrastructure" + "performance must not sacrifice realtime"
  locked decisions).
- Backend touchpoints: `PublicEventCatalog`, `PublicPostCatalog`, `UploadEventCoverImageCommand`,
  `ManageEventGalleryCommand`, `UploadPostCoverImageCommand`, `AdminEventDrafts`, `AdminPostCatalog`,
  `MinioStorageInterface`/`S3MinioStorage`/`NullMinioStorage`, `config/services.yaml`, `docker-compose*.yml`.
- Frontend touchpoints: `public-events-api`, `public-posts-api`, `public-games-api`, the 7 public pages,
  `event-card`, `post-card`, `next.config.ts`.
- Predecessors: 34.1 (sitemap uses `getPublicEvents`), 34.2 (canonical/OG - stable OG image is a bonus of
  this story), 34.3 (structured data image urls also stabilise).
- Standards: `api/CLAUDE.md`, `frontend/AGENTS.md`; root `CLAUDE.md` (gates, Gitflow, no em-dashes).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List (PR 1 - backend infra)

- Introduced `App\Shared\Application\Support\PublicMediaUrlResolver`: owns the public bucket + base URL and
  returns a stable URL when configured, else a presigned URL against the **public** bucket (safe fallback).
  It replaced the scattered `presignedUrl(mediaBucket, key, ttl)` calls, so the 4 read services
  (`PublicEventCatalog`, `PublicPostCatalog`, `AdminEventDrafts`, `AdminPostCatalog`) dropped their
  `MinioStorageInterface`/`$minioMediaBucket`/`$minioPresignTtl` constructor args for a single resolver.
- The 3 uploads (`UploadEventCoverImageCommand`, `ManageEventGalleryCommand`, `UploadPostCoverImageCommand`)
  now `upload($this->publicMedia->bucket(), ...)` -> new covers/galleries land in `media-public`.
- **Gallery URL->key parser made bucket-agnostic**: `AdminEventDrafts::extractMediaObjectKey` matched an exact
  `/{mediaBucket}/` prefix; it now matches the `events/{id}/gallery/{file}` suffix regardless of the prefix,
  so it accepts old `media/` URLs, new `media-public/` URLs, and stable CDN URLs (no bucket segment).
- `media-public` bucket + anonymous `download` policy added to both compose `createbuckets` steps; env vars
  documented (`MINIO_BUCKET_MEDIA_PUBLIC`, `MINIO_PUBLIC_MEDIA_BASE_URL` - the latter empty in dev/test so
  the presigned fallback is exercised; set in real deploys).
- `composer gates` green locally: phpstan (max) 0, cs-fixer 0, `app:architecture:ddd` OK, rector dry-run OK,
  **phpunit 1533 tests / 10511 assertions green** on an isolated DB. The only test updates were 3
  `exists('media', ...)` -> `exists('media-public', ...)` assertions (the upload target moved).

### Completion Notes List (PR 2 - frontend ISR)

- _Pending (follow-up PR): fetchers `no-store`->`revalidate`, pages `force-dynamic`->`revalidate`, drop
  `unoptimized` on public covers, `pnpm gates`._

### File List (PR 1 - backend)

- `api/src/Shared/Application/Support/PublicMediaUrlResolver.php` (new)
- `api/tests/Unit/Shared/PublicMediaUrlResolverTest.php` (new)
- `api/config/services.yaml` (public bucket + base URL binds)
- `api/src/Events/Application/Query/PublicEventCatalog.php`
- `api/src/Content/Application/Query/PublicPostCatalog.php`
- `api/src/Events/Application/Service/AdminEventDrafts.php` (resolver + bucket-agnostic parser)
- `api/src/Content/Application/Service/AdminPostCatalog.php`
- `api/src/Events/Application/Command/UploadEventCoverImageCommand.php`
- `api/src/Events/Application/Command/ManageEventGalleryCommand.php`
- `api/src/Content/Application/Command/UploadPostCoverImageCommand.php`
- `api/tests/Functional/{AdminEventCoverImageTest,AdminEventGalleryTest,AdminPostCoverImageTest}.php`
- `docker-compose.yml`, `docker-compose.prod.yml` (createbuckets: media-public + anonymous download)
- `envs/api.env.example`, `.env.prod.example`, `api/.env` (env docs)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-15 | Story created from epic 34 (gaps 6/9). Product-owner decision: dedicated public MinIO bucket (over API proxy / long-lived presign). Grounded in the MinIO storage interface, bucket config, the exact presigned-vs-stable image sources (games already stable; only event/post uploaded media need moving), the compose bucket bootstrap, and the frontend `no-store` fetchers. Status: ready-for-dev. |
| 2026-07-15 | PR 1 (backend infra) implemented: `PublicMediaUrlResolver`, uploads -> public bucket, catalogs -> resolver with presign fallback, bucket-agnostic gallery parser, compose bootstrap + env docs. `composer gates` green (1533 tests). Frontend ISR split to PR 2. Status: in-progress. |
