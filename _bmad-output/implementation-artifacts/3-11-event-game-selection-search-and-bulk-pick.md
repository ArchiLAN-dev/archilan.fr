# Story 3.11: Event game-selection - rich search & bulk pick

**Status:** review
**Epic:** 3 - Events & game library management
**Date:** 2026-06-17

## Story

As an admin configuring an event,
I want to search/filter the game catalogue and select games **in bulk** on the event
game-selection page (`/admin/evenements/{eventId}/jeux`),
so that I no longer have to tick every single game by hand to whitelist a large set.

## Context

Reported by Jean. Today `AdminEventGameSelectionPage` lists every available game and lets the
admin toggle them **one row at a time**. There is a basic client-side text filter (name/slug) and
an "available / + experimental" scope toggle, but:

- the search is poorer than what we already ship elsewhere - the global admin games dashboard
  (`AdminGameLibraryDashboard`, story 23.7 / 28.5) has a real search + availability filter +
  `apworld_ready` filter + sort;
- there is **no way to select a batch** - whitelisting "all apworld-ready games" or "everything
  matching a search" means dozens of manual clicks.

The page already receives everything it needs in one payload: `GET /admin/events/{eventId}/game-selection`
returns `availableGames` as `{id, name, slug, availability, isApworldReady, coverImageUrl}[]`, and
`PATCH` persists `{gameSelectionEnabled, gameSelectionMax, games: [{gameId}]}`. So the search/filter +
bulk logic can stay **front-end only** over the already-loaded list - no new endpoint required.

## Acceptance Criteria

1. **Search/filter parity** with the global games dashboard, applied to the event's available games:
   - free-text search on name **and** slug (instant, client-side);
   - availability scope (keep the existing "Disponibles / + Expérimentaux" toggle);
   - an **`apworld_ready` filter** (all / ready only / not-ready) using the payload's `isApworldReady`;
   - a **platform filter** (curated platform families) so combining it with "select all filtered"
     gives a **batch-by-platform** pick;
   - sort by name (default) - asc/desc.
   The result set ("X affichés sur Y") and the running selected count stay accurate.
2. **Bulk selection** acting on the **current filtered result set**:
   - "Tout sélectionner (résultats filtrés)" adds every currently-matching game to the selection;
   - "Tout désélectionner (résultats filtrés)" removes them;
   - "Vider la sélection" clears everything;
   - a header checkbox / select-all affordance reflects the tri-state (none / some / all of the
     filtered rows selected).
3. **Max-slots cap is respected**: when `gameSelectionMax` is set, a bulk-add never exceeds it - it
   adds up to the cap and surfaces a non-blocking notice ("Limite de N atteinte, M jeux non ajoutés").
4. **"Sélectionnés uniquement" view**: a toggle to show only the currently-selected games regardless
   of the active search, so the admin can review/trim a batch built across several searches. The
   selection is never silently lost when a selected game is filtered out of the current view.
5. **Persistence unchanged**: bulk add/remove only mutates the in-memory selection; the existing PATCH
   (`games: [{gameId}]`) saves it on "Enregistrer". No backend change required for the core scope.
6. **UX/a11y**: keyboard-operable controls, selected-row highlight kept, sensible empty states
   ("Aucun jeu ne correspond", "Aucun jeu sélectionné"), and the bulk actions are disabled when the
   filtered set is empty.
7. Gates green - Frontend: `pnpm typecheck` / `pnpm lint` / `pnpm build`. Verified on
   `/admin/evenements/{eventId}/jeux`: search + a bulk "select all filtered" whitelists the set in
   one action and persists.

## Tasks / Subtasks

- [x] **Task 1** (AC 1): added `apworld_ready` filter + name sort to `AdminEventGameSelectionPage`,
  search now name+slug; filter bar aligned with the global dashboard look.
- [x] **Task 2** (AC 2,3): bulk actions over `filteredGames` (select-all-filtered /
  deselect-all-filtered / clear) with the max-slots cap notice + tri-state header checkbox.
- [x] **Task 3** (AC 4): "Sélectionnés" toggle; the selection `Set` is the single source of truth and
  survives filter changes.
- [x] **Task 4** (AC 6): empty states, disabled states, a11y (aria-labels, indeterminate checkbox);
  save flow unchanged (AC 5).
- [x] **Task 5** (AC 1 - platforms): extended `AdminEventGameSelection` payload with curated platform
  families (`PlatformCategory::families`) + added a platform `<select>` filter on the page.
- [x] **Task 6** (AC 7): verified live on `/admin/evenements/{id}/jeux` (search+bulk, platform→select
  all) via chrome-devtools; frontend + backend gates green.

## Dev Notes

- Frontend-only: `frontend/src/features/admin/admin-event-game-selection-page.tsx`. The selection is
  already a `Set<string>` of game ids - bulk ops are set unions/differences over the filtered ids.
- Reuse, don't duplicate: pull the filter-bar look & the `apworld_ready` / sort controls from
  `AdminGameLibraryDashboard` (story 23.7 introduced the server-side picker + `apworld_ready` filter;
  story 28.5 the instant-search/filters UX). Here the data is already in-memory, so keep it client-side.
- **Platforms (added on request)**: `AdminEventGameSelection`'s payload now carries `platforms:
  string[]` (curated families via `PlatformCategory::families($game->getPlatforms())`, the same mapping
  used by the public catalog / story 28.7). The page derives the distinct families for the filter
  `<select>`; combined with "select all filtered" this yields batch-by-platform whitelisting.
- Respect frontend standards (AGENTS.md): env via `src/lib/env.ts`, API boundary validated by type
  guards, no `any`, Tailwind tokens, stable list keys (game id).
