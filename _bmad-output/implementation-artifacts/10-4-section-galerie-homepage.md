# Story 10.4: Section galerie événements sur la homepage

Status: done

## Story

As a visitor,
I want to see photos from past events on the homepage,
so that I can visualise the atmosphere before deciding to register.

## Acceptance Criteria

1. Given the homepage exists, when a visitor scrolls past the Archipelago explainer section, then they see a "Nos événements" gallery section with a masonry-style grid.
2. Available photos display an event label (e.g. "ARCHILAN 3") and a gradient overlay.
3. Unavailable slots show styled placeholder cards with an image icon and "Photos à venir".
4. A "Voir tous les événements →" link is visible at the top right of the section.
5. The grid is responsive: 1 column mobile, 2 columns tablet, 3 columns desktop with row-spanning large card.

## Tasks / Subtasks

- [x] Build gallery section structure (AC: 1, 4, 5)
  - [x] Add section below Archipelago explainer with `border-t border-border pt-12`.
  - [x] Add header row with "En images" overline + "Nos événements" h2 and right-aligned "Voir tous les événements →" link.
  - [x] Build 3-column grid: `grid gap-3 sm:grid-cols-2 lg:grid-cols-3`.
- [x] Add large photo card with row-span (AC: 2, 5)
  - [x] `sm:col-span-2 lg:col-span-1 lg:row-span-2` large card using `lan-photo-1.webp`.
  - [x] Bottom-to-top gradient overlay and "ArchiLAN 3" label.
- [x] Add second photo crop card (AC: 2)
  - [x] Same image with `object-[50%_60%]` to crop a different part (screen of play).
  - [x] Gradient overlay and "ArchiLAN 3" label.
- [x] Add placeholder cards (AC: 3)
  - [x] Three `ImageIcon` placeholder cards with dashed border and "Photos à venir" text.
- [x] Validate and handoff (AC: 1–5)
  - [x] Run frontend type-check.
  - [x] Update this story file.

## Dev Notes

The gallery uses `lan-photo-1.webp` for two visible slots, cropped differently via `object-position` to simulate distinct photos from the event. The remaining 3 slots are placeholder cards awaiting real photographs.

The large card uses `lg:row-span-2 lg:min-h-[480px]` with `lg:aspect-auto` so it fills both rows on desktop while maintaining `aspect-[4/3]` on smaller screens.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-10.4]
- [Source: _bmad-output/implementation-artifacts/10-2-hero-immersif-homepage.md]

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Completion Notes List

- Built masonry-style gallery grid with 1 large card, 1 alternate-crop card, and 3 `ImageIcon` placeholder cards.
- "Voir tous les événements →" link navigates to `/evenements`.
- Review correction: made the gallery header stack on mobile before switching to the top-right link layout on wider screens.
- Review correction: normalized event photo labels to the uppercase `ARCHILAN 3` format shown in the acceptance criteria.

### Validation Results

- `npx tsc --noEmit` - 0 errors.
- `pnpm lint -- src/app/page.tsx` - 0 errors, 0 warnings.
- `pnpm typecheck` - 0 errors.

### File List

- `frontend/src/app/page.tsx`
- `_bmad-output/implementation-artifacts/10-4-section-galerie-homepage.md`

### Change Log

- 2026-05-02: Implemented homepage gallery section with placeholders.
- 2026-05-03: Review fixes for mobile header layout and uppercase event labels.
