# Story 1.5: Public News and Recap Reading

Status: done

## Story

As a visitor,
I want to browse and read public news posts and event recaps,
so that I can follow ArchiLAN activity outside registration windows.

## Acceptance Criteria

1. Given public content exists, when a visitor opens the news section, then they can browse published posts with title, excerpt, publication date, and type.
2. Visitors can open a post detail page with readable long-form content.
3. Unpublished content is not publicly visible.
4. Post pages include social sharing metadata.
5. The empty news state gives a clear path to Twitch or upcoming events.

## Tasks / Subtasks

- [x] Create mocked public content source (AC: 1, 2, 3)
  - [x] Add typed public content data for news, recaps, and announcements.
  - [x] Add helpers that expose only published content.
  - [x] Avoid backend/API/admin publishing implementation.
- [x] Implement public news listing route (AC: 1, 5)
  - [x] Add `/actualites` public route.
  - [x] Render published posts with title, excerpt, publication date, and type.
  - [x] Add empty state with paths to upcoming events and Twitch.
- [x] Implement public post detail route (AC: 2, 3, 4)
  - [x] Add `/actualites/[postSlug]` route.
  - [x] Render readable long-form content.
  - [x] Return not-found for missing or unpublished slugs.
  - [x] Add social sharing metadata.
- [x] Validate and handoff (AC: 1, 2, 3, 4, 5)
  - [x] Run frontend lint, type-check, and build.
  - [x] Confirm no backend files were changed.
  - [x] Update this story file with commands run, validation results, and file list.

## Dev Notes

This story implements public content reading only. It must not implement CMS persistence, admin authoring, publication workflows, comments, authentication, or backend API calls. Unpublished content is represented by absence from the exported public collection.

Use existing public navigation, design tokens, and card patterns from Epic 1 stories. The `/actualites` route is already linked from the public shell.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-1.5-Public-News-and-Recap-Reading]
- [Source: _bmad-output/implementation-artifacts/1-1-public-shell-navigation-and-design-tokens.md]
- [Source: _bmad-output/implementation-artifacts/1-3-public-event-listings-and-event-cards.md]
- [Source: _bmad-output/implementation-artifacts/1-4-event-detail-public-page-with-seo-metadata.md]

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- Implemented this as frontend mock public content only; no API calls, CMS persistence, or admin publishing workflow was added.
- ESLint caught one unescaped apostrophe in JSX (`Lire l'article`); fixed with `&apos;` and re-ran validation.
- Production build confirmed `/actualites/[postSlug]` is SSG and generated from `generateStaticParams`.

### Completion Notes List

- Added `/actualites` listing route with cards for published public posts.
- Added typed content model and mock published content for announcement, recap, and news post types.
- Added `/actualites/[postSlug]` post detail route with readable long-form paragraphs.
- Added local not-found state for missing or unpublished post slugs.
- Added canonical, Open Graph, and Twitter Card metadata for post detail pages.
- Added empty news state with clear paths to `/evenements` and Twitch.
- No backend/API, CMS persistence, admin publishing, authentication, or comments were implemented.

### Validation Results

- `pnpm lint` - passed.
- `pnpm typecheck` - passed.
- `pnpm build` - passed.
- Build output includes static `/actualites` route and SSG `/actualites/[postSlug]` with three generated public post paths.
- Search confirmed no `fetch(`, `axios`, or `api/v1` usage in content feature or `/actualites` routes.
- Search confirmed social metadata (`openGraph`, `twitter`), `generateStaticParams`, and `notFound()` are present.
- No backend/API files were changed for this story.

### File List

- `frontend/src/app/actualites/page.tsx`
- `frontend/src/app/actualites/[postSlug]/page.tsx`
- `frontend/src/app/actualites/[postSlug]/not-found.tsx`
- `frontend/src/features/content/content-types.ts`
- `frontend/src/features/content/mock-posts.ts`
- `frontend/src/features/content/post-card.tsx`
- `frontend/src/features/content/posts-empty-state.tsx`
- `_bmad-output/implementation-artifacts/1-5-public-news-and-recap-reading.md`

### Change Log

- 2026-04-25: Implemented public news/recap listing, post detail pages, social metadata, not-found state, and empty-state navigation.
