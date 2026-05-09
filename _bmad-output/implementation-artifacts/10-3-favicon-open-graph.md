# Story 10.3: Favicon et Open Graph

Status: done

## Story

As a visitor sharing or bookmarking the site,
I want proper visual previews when sharing links and a recognisable favicon,
so that ArchiLAN looks professional when shared on Discord, Twitter, or in browser tabs.

## Acceptance Criteria

1. Given any public page is opened, when the page metadata is read by a browser, then the browser tab displays the ArchiLAN logo as favicon.
2. Sharing the homepage URL shows the event photo as og:image with correct title and description.
3. `og:locale` is set to `fr_FR` and `og:site_name` to `ArchiLAN`.
4. og:image dimensions are declared (6000×4000).
5. The site description is updated to reflect the ArchiLAN mission accurately.

## Tasks / Subtasks

- [x] Add favicon metadata (AC: 1)
  - [x] Set `icons.icon` and `icons.apple` to `/images/logo.webp` in `layout.tsx` metadata.
- [x] Add Open Graph metadata (AC: 2, 3, 4, 5)
  - [x] Add `openGraph.type: "website"`.
  - [x] Add `openGraph.locale: "fr_FR"`.
  - [x] Add `openGraph.siteName: "ArchiLAN"`.
  - [x] Add `openGraph.title` and `openGraph.description`.
  - [x] Add `openGraph.images` with url, width, height, and alt.
- [x] Update root description (AC: 5)
  - [x] Replace placeholder description with mission-accurate French copy.
- [x] Validate and handoff (AC: 1–5)
  - [x] Run frontend type-check.
  - [x] Update this story file.

## Dev Notes

Next.js `Metadata` type supports `icons.icon` as a string path - Next.js injects the appropriate `<link rel="icon">` tags. Modern browsers (Chrome, Firefox, Safari) support `.webp` favicons.

The `og:image` URL is a relative path (`/images/events/lan-photo-1.webp`). Next.js resolves it correctly in production. For local development the image won't be crawlable by social parsers but that is expected.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-10.3]

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Completion Notes List

- Added `icons`, `openGraph` metadata blocks to `src/app/layout.tsx`.
- Updated site description to mission-accurate French copy.
- Review correction: added `metadataBase` from `NEXT_PUBLIC_APP_URL` so relative Open Graph assets resolve to absolute share URLs.
- Review correction: regenerated `src/app/favicon.ico` from the official ArchiLAN logo so the App Router favicon convention does not expose the default icon.

### Validation Results

- `npx tsc --noEmit` - 0 errors.
- `pnpm lint -- src/app/layout.tsx` - 0 errors, 0 warnings.
- `pnpm typecheck` - 0 errors.
- `magick identify frontend/src/app/favicon.ico` - contains 256px, 48px, 32px, and 16px icon entries.

### File List

- `frontend/src/app/layout.tsx`
- `frontend/src/app/favicon.ico`
- `_bmad-output/implementation-artifacts/10-3-favicon-open-graph.md`

### Change Log

- 2026-05-02: Added favicon and Open Graph metadata.
- 2026-05-03: Review fixes for absolute Open Graph URL resolution and official favicon asset.
