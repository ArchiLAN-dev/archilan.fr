# Story 32.11: Exchange log - search & type facets

**Status:** draft
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
