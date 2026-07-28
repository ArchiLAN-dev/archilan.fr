# Story 32.11: Exchange log - search & type facets

**Status:** review
**Epic:** 32 - Recaps
**Date:** 2026-07-28

## Story

As someone reading the exchange log under the timeline,
I want to search it by item or location name and filter by exchange type,
so that I can answer "when did I get X?" or "show only what I sent" without scrolling.

## Context

The log (story 32.7) lists item finds chronologically, filtered by player, zoom and day (32.8+). It has
no text search and no type facet. Two additions make it a real lookup tool:

- **Text search**: a free-text box filtering rows by item name or location name (client-side, on the
  already-loaded `FeedEvent[]`).
- **Type facets**: chips for **reçu / envoyé / local** - a find where the current viewer (or a selected
  player) is the receiver vs the sender vs a self-find (sender == receiver). Lets you isolate "items sent
  to others" from "items I found for myself".

### Decisions

- All client-side over the already-fetched events - no new endpoint. The feed is already loaded for the
  chart.
- Facets compose with the existing player filter (per-player), the zoom and the day. Search is a plain
  substring, case/accent-insensitive.
- The log is capped at 300 rows (32.7); search/facets narrow *before* the cap, so a match late in a long
  run is still found (avoid the silent-cap trap - keep the "N most recent of the filtered set" note).

## Acceptance Criteria (sketch)

1. A search box filters log rows by item or location name (accent-insensitive substring).
2. Facet chips filter by reçu / envoyé / local; they compose with player filter, zoom, day.
3. The row cap applies after filtering, and the count note reflects the filtered set.
4. Gates green.

## Notes

- Files: `frontend/src/features/recap/run-timeline.tsx` (log filtering + controls). No API change.
- "local" = `event.sender.slot === event.receiver.slot`; "envoyé"/"reçu" are relative to the selected
  player(s) - decide whether the facet keys on the viewer or on the player-filter selection.

## Dev Notes (implementation, 2026-07-28)

- **Decision made**: the facet keys on the **player-filter selection** (the shown players), not on a
  viewer - the recap page is public and has no viewer identity. With every player shown, "Envoyés" and
  "Reçus" both mean "all transfers" (symmetric); isolate one player and they read as "what X sent" /
  "what X received". "Locaux" is selection-independent.
- Logic extracted to a pure module `log-filters.ts` (`normalizeSearch` - NFD + strip diacritics +
  lowercase, `matchesSearch` - substring on item/location name, `matchesFacet`) with unit tests
  (8 tests). `run-timeline.tsx` only wires state + UI.
- UI: a `type="search"` input and a "Type : Tous / Reçus / Envoyés / Locaux" segmented control (reuses
  32.10's `Segmented`), placed just above the log. Both narrow the set **before** the 300-row cap
  (AC 3); the truncation note now reads "Les 300 évènements les plus récents sur N correspondants",
  and an empty state row appears when nothing matches.
- Composes with player filter, zoom, day pager by construction (same `shown` pipeline).
