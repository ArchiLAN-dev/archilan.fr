# Story 10.2: Hero immersif avec photo d'événement

Status: done

## Story

As a first-time visitor,
I want a visually impactful homepage hero that shows real event atmosphere,
so that I immediately understand the LAN gaming culture of ArchiLAN.

## Acceptance Criteria

1. Given the homepage exists, when a visitor opens the landing page, then the hero section spans the full container width with the event photo as background.
2. A dark gradient overlay (left → right and top → bottom) ensures headline text is readable.
3. The ArchiLAN logo is displayed within the hero above the headline.
4. The headline "Un item de ton jeu. Le monde entier." remains the primary message.
5. The two-column fake Archipelago example card is removed.
6. The "C'est quoi Archipelago ?" explainer below is restructured into three clean feature cards.
7. The layout is responsive and readable at 375px.

## Tasks / Subtasks

- [x] Download event photo and store locally (AC: 1)
  - [x] Fetch `https://archilan.fr/assets/IMG_1509-DowuoQY5.webp` via curl.
  - [x] Store as `frontend/public/images/events/lan-photo-1.webp`.
- [x] Implement full-bleed hero with photo background (AC: 1, 2, 3, 4, 7)
  - [x] Use negative margins (`-mx-6 -mt-16 md:-mx-12 lg:-mx-20`) to break out of padded container.
  - [x] Set `min-h-[88vh]` and `items-end` to anchor content at the bottom.
  - [x] Add `Image fill priority sizes="100vw"` for the background photo.
  - [x] Add left→right gradient overlay: `from-background from-35% via-background/80 to-background/20`.
  - [x] Add top→bottom gradient overlay: `from-background/40 via-transparent to-background/60`.
  - [x] Place logo (64×64px), overline, headline, tagline, and two CTAs.
- [x] Remove fake Archipelago example card (AC: 5)
  - [x] Delete the right-column `<aside>` with the fake mechanic card.
  - [x] Delete the mobile inline mechanic example blocks.
- [x] Restructure Archipelago explainer (AC: 6, 7)
  - [x] Keep the section title and explanatory paragraph.
  - [x] Replace the old layout with a 3-card grid: Multiworld, Coopératif, Communauté.
- [x] Validate and handoff (AC: 1–7)
  - [x] Run frontend type-check.
  - [x] Confirm no backend files were changed.
  - [x] Update this story file.

## Dev Notes

The event photo (`lan-photo-1.webp`) is 6000×4000px. Using `next/image` with `fill` and `object-cover` - Next.js generates optimised srcsets automatically. The `priority` flag ensures it is preloaded as the LCP image.

The negative-margin full-bleed technique works within the `main` element's `max-w-7xl` constraint. On screens wider than 1280px, the background color of `main` (`bg-background`) matches the hero's dark gradient so the transition is seamless.

Gradient overlay layers:
- Layer 1 (left→right): ensures text on the left is readable; the photo shows through on the right.
- Layer 2 (top→bottom): softens the top (behind navbar) and bottom (transition to next section).

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-10.2]
- [Source: _bmad-output/implementation-artifacts/1-2-landing-page-with-archilan-identity-and-archipelago-explainer.md]

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Completion Notes List

- Downloaded event photo and stored as `frontend/public/images/events/lan-photo-1.webp`.
- Replaced the 2-column hero layout with a full-bleed photo hero using negative margins.
- Removed the fake "Une équipe, plusieurs mondes" aside card entirely.
- Replaced the 4-box Archipelago explainer with 3 clean feature cards.
- Logo displayed at 64×64px above the headline within the hero.
- Review correction: restored the required headline "Un item de ton jeu. Le monde entier." as the hero primary message.
- Review correction: added an explicit top-to-bottom dark overlay in addition to the left-to-right overlay.

### Validation Results

- `npx tsc --noEmit` - 0 errors.
- `pnpm lint -- src/app/page.tsx` - 0 errors, 0 warnings.
- `pnpm typecheck` - 0 errors.

### File List

- `frontend/public/images/events/lan-photo-1.webp` (new)
- `frontend/src/app/page.tsx`
- `_bmad-output/implementation-artifacts/10-2-hero-immersif-homepage.md`

### Change Log

- 2026-05-02: Implemented full-bleed photo hero, removed fake example card, restructured Archipelago explainer into 3 cards.
- 2026-05-03: Review fixes for hero headline acceptance criterion and vertical readability overlay.
