# Story 10.7: Cover image par article

Status: done

## Story

As a visitor,
I want news and recap articles to have cover images,
so that the content section feels as polished as the events section.

## Acceptance Criteria

1. Given a published news post or recap exists, when a visitor opens the news listing or article detail page, then articles with a cover image show it in the listing card and as a header on the detail page.
2. Articles without a cover image display a neutral placeholder.
3. `cover_image_url` is added to the `posts` table via a Doctrine migration.
4. The API serialises `coverImageUrl` in the post payload.
5. The admin content editor includes a cover image URL field.

## Tasks / Subtasks

- [x] Add `cover_image_url` column to posts table (AC: 3)
  - [x] Create Doctrine migration: `ALTER TABLE content_posts ADD cover_image_url VARCHAR(2048) DEFAULT NULL`.
  - [x] Add `coverImageUrl` property (nullable string) to `Post` entity.
- [x] Expose field in API (AC: 4)
  - [x] Add `coverImageUrl` to public post payloads.
  - [x] Add `coverImageUrl` to frontend post DTOs and draft input types.
- [x] Update admin content editor (AC: 5)
  - [x] Add `coverImageUrl` to the existing admin content draft/edit input contract.
  - [x] Note: the `/admin/contenu` page is still intentionally disabled with `notFound()`, so there is no active content editor UI to render yet.
- [x] Update public news listing (AC: 1, 2)
  - [x] In the post card component, render a cropped `<Image fill>` when `coverImageUrl` is set.
  - [x] Render a neutral placeholder when no cover exists.
- [x] Update public article detail page (AC: 1, 2)
  - [x] Render a full-width hero image when `coverImageUrl` is set.
  - [x] Omit the hero section when no cover exists.
- [x] Validate and handoff (AC: 1-5)
  - [x] Run API tests.
  - [x] Run frontend type-check.
  - [x] Update this story file.

## Dev Notes

Same URL-based approach as `cover_image_url` on events. The project table is `content_posts`, matching the Content bounded context naming already used by Doctrine.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-10.7]
- [Source: _bmad-output/implementation-artifacts/10-5-cover-image-par-evenement.md]

## Dev Agent Record

### Agent Model Used

GPT-5 Codex

### Completion Notes List

- Added `cover_image_url` persistence on `content_posts`.
- Public post list/show payloads include `coverImageUrl`.
- Frontend post cards render a 16:9 cover or a neutral placeholder.
- Article detail pages render a wide cover hero only when a cover URL exists.
- Open Graph and structured data include the cover image when present.
- Regenerated Next route types after the route group migration left stale `.next/types` references.

### Validation Results

- `php bin/phpunit tests/Functional/PublicPostCatalogTest.php` - 2 tests, 15 assertions.
- `vendor/bin/phpstan analyse src/Content/Domain/Post.php src/Content/Application/PublicPostCatalog.php --level=6` - no errors.
- `vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php ...` - no changes required.
- `pnpm exec next typegen` - route types generated successfully.
- `pnpm lint -- src/features/content/post-card.tsx src/features/content/public-posts-api.ts src/features/content/content-types.ts src/features/content/mock-posts.ts src/app/(public)/actualites/[postSlug]/page.tsx` - 0 errors, 0 warnings.
- `pnpm typecheck` - 0 errors.

### File List

- `api/migrations/Version20260503102000.php`
- `api/src/Content/Domain/Post.php`
- `api/src/Content/Application/PublicPostCatalog.php`
- `api/tests/Functional/PublicPostCatalogTest.php`
- `frontend/src/app/(public)/actualites/[postSlug]/page.tsx`
- `frontend/src/features/content/content-types.ts`
- `frontend/src/features/content/mock-posts.ts`
- `frontend/src/features/content/post-card.tsx`
- `frontend/src/features/content/public-posts-api.ts`
- `_bmad-output/implementation-artifacts/10-7-cover-image-par-article.md`

### Change Log

- 2026-05-03: Implemented article cover image persistence, API serialization, and public listing/detail rendering.
