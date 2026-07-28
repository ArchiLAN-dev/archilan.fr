# Story 32.14: Timeline - combined all-players curve

**Status:** review
**Epic:** 32 - Recaps
**Date:** 2026-07-28

## Story

As someone reading a run's timeline,
I want a single curve combining every player,
so that I can read the group's overall rhythm (or total progress, in cumulative mode) instead of
comparing individual lines.

## Context

The chart (32.7) draws one line per player, with view options (32.10: found/received x
interval/cumulative) and a player filter. On busy multiworlds the per-player reading hides the
collective picture: "how active was the room as a whole?". A "players combined" display folds the
kept players into one series.

### Decisions

- A third segmented control "Joueurs : Séparés / Confondus", default Séparés (nothing changes for
  existing views).
- Derived from the already-built per-player series (`combineChecksSeries(series, shownSlots)`), not
  a new transform: per bucket, sum the kept players' counts and progression counts. A sum of running
  totals is the running total of the sum, so it composes with cumulative mode for free.
- The player filter chips stay meaningful: a hidden player is excluded from the total.
- Goal markers stay per-player (their colour comes from the split series) - they mark instants on
  the X axis and remain readable over the single curve.

## Acceptance Criteria

1. A control switches the curve between per-player lines and one combined line; default per-player.
2. The combined curve sums exactly the shown players (player filter applies), in all four
   measure x mode combinations; progression dots mark buckets where any kept player found a
   progression item.
3. Day pager, bucket granularity, zoom, Brush, cross-highlight and goal markers keep working in
   combined view.
4. Unit tests cover the combination (sums, hidden-player exclusion, cumulative composition).
5. Gates green.

## Notes

- Files: `frontend/src/features/recap/build-checks-series.ts` (+ test), `run-timeline.tsx`
  (toggle + wiring). `checks-chart.tsx` untouched (it renders whatever players/rows it receives).

## Dev Notes (implementation, 2026-07-28)

As designed, no surprises. `combineChecksSeries(series, shownSlots)` derives the combined curve
from the split series (same buckets, same gap rows); the combined pseudo-player is
`{key: "all", slot: -1, name: "Tous les joueurs", progressionKey: "allp"}` on the palette's first
hue. `RunTimeline` keeps everything else on the split series - chips, goal markers (still
per-player coloured vertical lines over the single curve) and the log - and only swaps what the
chart draws. Unit tests: sums + progression, hidden-player exclusion, empty selection, cumulative
composition. Story created from a direct user request during the epic-32 session.
