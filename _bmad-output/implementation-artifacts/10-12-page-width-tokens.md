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
  `max-w-*` utilities, so three custom properties in `globals.css` produce `max-w-shell`,
  `max-w-content` and `max-w-reading`. This needs no component threaded through every page, works
  inside layouts, and reduces the sweep to a class rename.
- **Prose is the one thing not widened.** Past roughly 90 characters per line a measure gets
  *harder* to read, and 768px is already at that bound. Pushing legal pages and article bodies to
  the rail would have worked against the goal, so long-form prose keeps 48rem.
- **`/jeux` is the reference: every application page gets the full 80rem rail.** The first pass
  set `content` to 64rem and let the card-grid index pages keep a separate `wide` token. Reviewed
  live, that was still too narrow, and the split had a defect: `wide` was shared with the header
  and footer, so retuning page width would have dragged the rail with it. `content` is now 80rem
  and covers the index pages too.
- **`shell` stays a distinct token even though it holds the same value.** The header/footer rail and
  the page width answer to different concerns; collapsing them into one custom property would mean
  no page-width change is ever possible without moving the chrome.

### Non-goals

Inner-element widths (`max-w-2xl` on page ledes, `max-w-md` on forms and cards) are untouched.
Those constrain a block inside a page, not the page, and tokenising them is a separate concern.

## Acceptance Criteria

1. `--container-shell` / `--container-content` / `--container-reading` are defined in `globals.css`
   and generate the matching `max-w-*` utilities.
2. No `max-w-3xl`, `max-w-5xl` or `max-w-7xl` remains on a page container.
3. Every application page renders at 80rem, matching `/jeux`; long-form prose stays at 48rem; the
   header/footer rail is driven by its own token so page width can move independently.
4. Skeleton and empty states move with the container they mirror, so no layout jump between the
   loading state and the loaded page.
5. Gates green.

## Tasks / Subtasks

- [x] **Task 1 - Tokens** (AC 1). Three `--container-*` entries in the `@theme inline` block, with
      the reasoning for the prose tier recorded next to them.
- [x] **Task 2 - Sweep** (AC 2, 3, 4). 41 containers reclassified across 33 files: 5 to `reading`,
      34 to `content`, 2 to `shell`.
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

- Verified in the built CSS: `.max-w-content{max-width:80rem}`, `.max-w-reading{max-width:48rem}`,
  `.max-w-shell{max-width:80rem}`.
- Pre-existing lint warning in `admin-content-dashboard.tsx` (story 33.18) is unrelated and left in
  place.

### File List

- `frontend/src/app/globals.css`
- 33 page/feature/component files (container class rename)
