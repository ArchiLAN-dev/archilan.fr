# Story 30.35: Account sub-nav - URL-persisted tab + polish

**Status:** review
**Epic:** 30 - Community & account
**Date:** 2026-06-28

## Story

As a member on `/compte`,
I want the active sub-section to live in the URL and the sub-nav to be cleaner,
so that reloading or sharing a link reopens the same section, and the navigation reads well on mobile.

## Context

The account page (`AccountTabs`, story 30.21) has a two-level nav (groups Communauté/Jeux/Compte +
sub-tabs). The active tab was a plain `useState("profil")` - lost on reload, not deep-linkable, and the
sub-tabs were a horizontal-scroll bar on mobile. (Reported by Jean: keep the 2-level structure, polish it,
and at minimum persist the tab in the URL.)

## Acceptance Criteria

1. The active tab is read from `?tab=<id>` on load (server passes it as `initialTab`) and written back on
   every tab change via `history.replaceState` (client-side, no navigation/refetch, no history clutter).
2. A reload or deep link (`/compte?tab=amis`) reopens that exact sub-section; an unknown/absent value
   falls back to `profil`. The Discord OAuth callback (`?discord_linked`) lands on Connexions & sécurité.
3. Two-level structure kept and polished: groups segmented control + sub-tabs. On mobile the sub-tabs
   become a `<select>` dropdown (no more horizontal scroll); on `sm+` the underlined tabs.
4. Gates green: frontend `typecheck` / `lint` / `build`.

## Tasks / Subtasks

- [x] **Task 1** (AC 1,2). `compte/page.tsx` reads `tab` from `searchParams` → `initialTab`; `AccountTabs`
  initialises state from it (with `isTab` guard + Discord fallback) and `selectTab` updates the URL.
- [x] **Task 2** (AC 3). Mobile `<select>` for the active group's sub-tabs (`sm:hidden`); underlined nav
  becomes `hidden sm:flex`; group/sub-tab clicks go through `selectTab`.
- [x] **Task 3** (AC 4). typecheck / lint / build green; verified live (deep link, tab switch updates URL,
  reload-safe).

## Dev Notes

- `history.replaceState` (not `router.replace`) keeps the change purely client-side - no server round-trip
  / data refetch on each tab click - and the server reads the URL on the next full load. `pushState` was
  avoided so tab clicks don't pile up in the browser history.
- The page is already a server component reading `searchParams`, so `initialTab` is passed as a prop -
  no `useSearchParams`/Suspense needed in the client component.

### Project Structure Notes

- `frontend/src/app/(public)/compte/page.tsx`
- `frontend/src/features/auth/account-tabs.tsx`

### References

- [Source: _bmad-output/implementation-artifacts/30-21-account-navigation-grouping.md (the 2-level nav)]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Completion Notes List

- Active account sub-tab now persists in `?tab=` (reload/deep-link safe), Discord callback opens
  Connexions & sécurité, and the 2-level nav uses a mobile dropdown for sub-sections.
- Frontend-only; gates green; verified live.

### File List

- `frontend/src/app/(public)/compte/page.tsx`
- `frontend/src/features/auth/account-tabs.tsx`

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-28 | Created + implemented. Account sub-nav: tab persisted in `?tab=` (reload/deep-link), mobile `<select>` for sub-sections, kept the polished 2-level structure. Frontend-only; gates green; verified live. Status → review. |
