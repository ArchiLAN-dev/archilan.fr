# Story 10.1: Logo officiel dans la navigation et le footer

Status: done

## Story

As a visitor,
I want to see the real ArchiLAN logo throughout the site,
so that I immediately recognise the association's visual identity.

## Acceptance Criteria

1. Given the public shell exists, when a visitor views any public page, then the ArchiLAN illustrated logo (six circular game worlds) appears in the sticky navigation bar.
2. The same logo appears in the footer alongside the copyright text.
3. The old placeholder badge ("A") is fully removed from all public-facing surfaces.
4. The logo is stored in `public/images/logo.webp` and served locally - no external CDN dependency.
5. The logo renders at 36px in the navbar and 24px in the footer with correct aspect ratio.

## Tasks / Subtasks

- [x] Download logo asset from archilan.fr and store locally (AC: 4)
  - [x] Fetch `https://archilan.fr/assets/logo-BJiHQdyr.webp` via curl.
  - [x] Store as `frontend/public/images/logo.webp`.
- [x] Replace navbar "A" badge with Image component (AC: 1, 3, 5)
  - [x] Import `Image` from `next/image` in `public-shell.tsx`.
  - [x] Replace `<span>A</span>` with `<Image src="/images/logo.webp" width={36} height={36} />`.
  - [x] Remove the old badge span and its CSS classes.
- [x] Add logo to footer (AC: 2, 5)
  - [x] Wrap copyright text in a flex container with the logo at 24px.
- [x] Validate and handoff (AC: 1–5)
  - [x] Run frontend type-check.
  - [x] Confirm no backend files were changed.
  - [x] Update this story file.

## Dev Notes

The logo is the official ArchiLAN mark: six illustrated circular game-world icons arranged in a flower pattern (magenta, red, teal, gold, dark blue). Source: `https://archilan.fr/assets/logo-BJiHQdyr.webp` (2048×2048).

The `next/image` component handles srcset and optimisation automatically. No explicit `sizes` needed for fixed-dimension usage.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-10.1]
- [Source: _bmad-output/implementation-artifacts/1-1-public-shell-navigation-and-design-tokens.md]

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Completion Notes List

- Downloaded logo from archilan.fr and stored as `frontend/public/images/logo.webp`.
- Replaced the placeholder "A" badge span in `public-shell.tsx` navbar with `next/image` at 36×36px.
- Added logo (24×24px, opacity-70) to footer alongside copyright `<p>`.

### Validation Results

- `npx tsc --noEmit` - 0 errors.

### File List

- `frontend/public/images/logo.webp` (new)
- `frontend/src/components/public-shell.tsx`
- `_bmad-output/implementation-artifacts/10-1-logo-officiel-navbar-footer.md`

### Change Log

- 2026-05-02: Implemented logo in navbar and footer, removed "A" badge placeholder.
