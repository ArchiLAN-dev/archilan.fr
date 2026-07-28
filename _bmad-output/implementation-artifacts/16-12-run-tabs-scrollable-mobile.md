# Story 16.12: Run page tabs - scrollable bar on mobile

**Status:** review
**Epic:** 16 - Personal runs frontend
**Date:** 2026-07-28

## Story

As someone using the run page on a phone,
I want the tab menu (Vue d'ensemble / Progression / Participants / Streams / Overlay Stream /
Réglages) to be a proper mobile tab bar,
so that it does not wrap into a concatenated block and I can still see where I am and what exists.

## Context

The run detail page's tabs (`personal-run-detail-page.tsx`, story 16.5) used `flex flex-wrap`: on a
phone the six entries wrapped into a dense multi-line block. UX decision (user + assistant, from
the 32.13 mobile pass discussion): this is **primary navigation**, not a view filter - hiding it in
a select or burger menu would cost discoverability. The standard pattern for 4+ tabs on mobile is a
**horizontally scrollable tab bar** (Material scrollable tabs: GitHub mobile, Twitter, Play Store):
one row, edge fade to signal overflow, active tab auto-scrolled into view.

## Acceptance Criteria

1. The tab bar is a single row on every width: horizontal scroll on overflow, no wrap; the
   scrollbar is hidden and a right-edge fade (mobile only) signals there is more.
2. The active tab is scrolled into view on load and on tab change (no vertical page jump).
3. Desktop rendering is visually unchanged (everything fits, no fade needed at sm+).
4. Gates green.

## Dev Notes (implementation, 2026-07-28)

- `role="tablist"` container becomes `flex gap-1 overflow-x-auto` with `flex-wrap` removed,
  scrollbar hidden via arbitrary properties (`[scrollbar-width:none]`,
  `[&::-webkit-scrollbar]:hidden`); each tab button gains `shrink-0 whitespace-nowrap`.
- A `pointer-events-none` absolute right-edge gradient (`sm:hidden`) hints at the overflow.
- A `useEffect` on `activeTab` scrolls `[aria-selected="true"]` into view with
  `{block: "nearest", inline: "nearest"}` - `nearest` avoids the vertical jump.
- Verified in-browser at 375 px (single row, fade visible, active tab in view) and at desktop
  width (unchanged).
