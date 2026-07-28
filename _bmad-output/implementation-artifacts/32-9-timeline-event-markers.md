# Story 32.9: Timeline event markers - goal reached & progression items

**Status:** draft
**Epic:** 32 - Recaps
**Date:** 2026-07-28

## Story

As someone reading a run's timeline (story 32.7/32.8),
I want the key moments marked on the curve - when a player reached their goal, and when a progression
(not filler) item was found,
so that I can spot the turning points, not just the raw rhythm.

## Context

The checks curve (`ChecksChart`) shows checks-per-bucket per player over real time. It has no notion of
*which* moments mattered. Two annotations would add a lot of meaning:

- **Goal reached**: a vertical marker (per player, in their colour) at the moment each player completed
  their goal. The recap already exposes this: `RecapPodiumSlot.goalReachedAt` / `completionSeconds`, and
  `SessionRecapView` even renders a "Chronologie des objectifs" list from it. The timeline could show
  the same instants on the curve.
- **Progression item found**: a dot on a player's line when the item found was a *progression* item
  (vs filler). Archipelago flags this; the bridge tracks item flags (`DataPackageStore.record_item_flags`
  / `resolve_item_flags`, `bridge/core/ap_client.py`).

### Decisions / open questions

- Goal markers are **low-effort**: the data is already on the recap podium. A `ReferenceLine` per player
  at their `goalReachedAt` (mapped to epoch on the chart's `t` axis), coloured by slot.
- Progression markers **depend on the feed carrying item flags**. `session_feed_event` (story 32.6) does
  **not** store a flag today, and `_build_item_origin` may not include it in the pushed event. So this
  half likely needs: (1) the bridge to add `item.flags` (or a `progression` bool) to the feed event,
  (2) a `progression` column on `session_feed_event`, (3) the frontend to render dots on progression
  finds. Confirm the bridge payload before committing.
- Live (32.8): goal markers on a running game would need a goal signal on the live feed; deferrable -
  ship goal markers on the historical recap first.

## Acceptance Criteria (sketch)

1. On the recap timeline, each finished player has a goal marker on the curve at their completion time,
   in their series colour, labelled on hover.
2. Progression-item finds are visually distinct from filler finds (a marker/dot), gated on the feed
   carrying the flag - if it does not yet, this AC becomes "bridge + persistence + render".
3. Markers respect the day pagination and the zoom.
4. Gates green.

## Notes

- Files: `frontend/src/features/recap/checks-chart.tsx` (markers), `build-checks-series.ts` (may expose
  per-bucket progression counts), `feed-api.ts` (flag field), `api` `SessionFeedEvent` + bridge (flags).
- Reference: `SessionRecapView` "Chronologie des objectifs" already uses `goalReachedAt`.
