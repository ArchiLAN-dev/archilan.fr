# Story 10.12: Centralise page container widths behind design tokens

**Status:** done
**Epic:** 10 - Design & identité visuelle
**Date:** 2026-07-22

## Story

As a visitor,
I want application pages to use the width the site layout actually offers,
so that content stops sitting in a narrow column under a much wider header.

## Context

The reported symptom was that `max-w-3xl` (768px) felt too narrow. The measurement behind it is
worse than a taste issue: `public-shell.tsx` rails its header and footer at `max-w-7xl` (1280px)
while `<main>` imposes no width at all, so every page picks its own. Content pages therefore
rendered 768px wide underneath a 1280px header - a 512px mismatch that no single page owned.

Page width was a literal repeated across roughly 40 containers with no token behind it, while
`AGENTS.md` (AC-CSS2) requires tokens for colours and spacing. Width was the last design axis left
hardcoded, and the drift was already visible: `session-recap-page.tsx` rendered at `max-w-5xl` when
loaded and `max-w-3xl` when empty, so the page changed width depending on its own content.

### Decisions

- **Tokens, not a `<PageContainer>` component.** Tailwind 4's `--container-*` namespace feeds the
  `max-w-*` utilities, so three custom properties in `globals.css` produce `max-w-wide`,
  `max-w-content` and `max-w-reading`. This needs no component threaded through every page, works
  inside layouts, and reduces the sweep to a class rename.
- **Three tiers, because one width is wrong for prose.** Past roughly 90 characters per line a
  measure gets *harder* to read, and 768px is already at that bound. Widening legal pages and
  article bodies would have worked against the goal, so long-form prose keeps 48rem while
  application pages move to 64rem.
- **`wide` is 80rem and shared by the shell and the card-grid index pages.** `/actualites`,
  `/evenements` and `/jeux` were already at `max-w-7xl`, deliberately flush with the rail. They keep
  that alignment through the same token rather than a duplicate value.
- **`content` = 64rem was already the de-facto standard.** Eleven containers (`/communaute`,
  `/compte`, `/runs-hebdo`, the admin editors, the recap) sat at `max-w-5xl`. For those the change
  is a pure rename with no visual effect, which is what makes 64rem the harmonising value rather
  than a fourth invented one.

### Non-goals

Inner-element widths (`max-w-2xl` on page ledes, `max-w-md` on forms and cards) are untouched.
Those constrain a block inside a page, not the page, and tokenising them is a separate concern.

## Acceptance Criteria

1. `--container-wide` / `--container-content` / `--container-reading` are defined in `globals.css`
   and generate the matching `max-w-*` utilities.
2. No `max-w-3xl`, `max-w-5xl` or `max-w-7xl` remains on a page container.
3. Application pages render at 64rem; long-form prose pages stay at 48rem; the shell and the
   card-grid index pages share 80rem.
4. Skeleton and empty states move with the container they mirror, so no layout jump between the
   loading state and the loaded page.
5. Gates green.

## Tasks / Subtasks

- [x] **Task 1 - Tokens** (AC 1). Three `--container-*` entries in the `@theme inline` block, with
      the reasoning for the prose tier recorded next to them.
- [x] **Task 2 - Sweep** (AC 2, 3, 4). 41 containers reclassified across 33 files: 5 to `reading`,
      30 to `content`, 6 to `wide`.
- [x] **Task 3 - Verify** (AC 5). Generated CSS inspected for the three utilities and their values;
      full gates.

## Dev Notes

- Each of the 41 sites was inspected before replacing rather than swept by pattern: the risk was
  widening an inner block that merely happened to share the class. All 41 turned out to be
  `mx-auto` page roots, including the `aria-hidden` skeletons in `slot-yaml-gate.tsx` and
  `personal-run-participant-detail-page.tsx`, which is why AC 4 holds.
- `session-recap-page.tsx` carried both `max-w-5xl` (loaded) and `max-w-3xl` (empty); both moved to
  `content`, which incidentally fixes the width jump.

### Project Structure Notes

- `frontend/src/app/globals.css` (tokens)
- 33 files under `frontend/src/app/(public)/`, `frontend/src/features/`, `frontend/src/components/`

### References

- [Source: frontend/AGENTS.md#styling] - AC-CSS2, design tokens over hardcoded values
- [Source: node_modules/tailwindcss/theme.css] - `--container-*` namespace backing `max-w-*`

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code, 1M context).

### Completion Notes List

- Verified in the built CSS: `.max-w-content{max-width:64rem}`, `.max-w-reading{max-width:48rem}`,
  `.max-w-wide{max-width:80rem}`.
- Pre-existing lint warning in `admin-content-dashboard.tsx` (story 33.18) is unrelated and left in
  place.

### File List

- `frontend/src/app/globals.css`
- 33 page/feature/component files (container class rename)
