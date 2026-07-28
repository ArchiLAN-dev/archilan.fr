# Story 32.10: Timeline view options - cumulative toggle & items-received curve

**Status:** review
**Epic:** 32 - Recaps
**Date:** 2026-07-28

## Story

As someone reading a run's timeline,
I want to switch what the curve measures - per-interval vs cumulative, checks found vs items received,
so that I can look at the rhythm, the overall progress, or the receiving side, as needed.

## Context

The curve (story 32.7) is fixed to **checks found per interval** (a burst view: the count in each bucket,
0 in quiet buckets - the deliberate choice from that story). Two orthogonal view options would round it out
without losing that default:

- **Cumulative toggle** ("par intervalle" / "cumulé"): the same finds, summed into a running total, for
  an "overall progress" reading. `buildChecksSeries` already did cumulative before 32.7; it is a small
  branch in the transform plus a toggle in `RunTimeline`.
- **Items received curve**: every feed event has a `receiver` slot too. A second measure - items *received*
  per player over time - shows the receiving side (when each player got their items), complementary to
  checks *found* (the sender side). A toggle "trouvés / reçus" would switch the measure.

### Decisions

- Keep **per-interval, checks-found** the default (32.7's choice). The toggles are additive.
- `buildChecksSeries(events, bucketSeconds, { measure: "found" | "received", mode: "interval" | "cumulative" })`
  - one function, two options; unit-test each.
- Colour/slot identity unchanged - the toggles change what is counted, not who is who.

## Acceptance Criteria (sketch)

1. A control switches the curve between **par intervalle** and **cumulé**.
2. A control switches the measure between **checks trouvés** (sender) and **objets reçus** (receiver).
3. Both compose with the day pager, bucket granularity, player filter, zoom and cross-highlight.
4. `buildChecksSeries` unit tests cover the four combinations.
5. Gates green.

## Notes

- Files: `frontend/src/features/recap/build-checks-series.ts` (+ test), `run-timeline.tsx` (toggles),
  `checks-chart.tsx` (Y-axis label reflects the measure).
- The received measure keys on `event.receiver.slot`; a self-find (sender == receiver) counts once on
  the found curve and once on the received curve, which is correct.

## Dev Notes (implementation, 2026-07-28)

Frontend-only, exactly as scoped. `buildChecksSeries(events, bucketSeconds, {measure, mode})` with
defaults `found`/`interval` (32.7's view unchanged); cumulative accumulates over the emitted rows
only, which is safe because skipped buckets are empty by construction. The 32.9 progression count
stays **per-bucket even in cumulative mode** - the dot marks the moment, not a total.

- `RunTimeline`: two segmented controls ("Mesure : Checks trouvés / Objets reçus", "Courbe : Par
  intervalle / Cumulé") via a small generic `Segmented` helper, same look as the bucket picker.
  Both feed the same `buildChecksSeries` memo, so they compose with the day pager, bucket size,
  player filter, zoom and cross-highlight for free.
- `ChecksChart`: new `measureLabel` prop rendered as the Y-axis label (angle -90, axis width 36 -> 48).
- Goal markers (32.9) are unaffected: they mark instants on the X axis, valid under every combination.
- Tests: interval/found (existing), cumulative/found, received/interval, received/cumulative.
- Works on the live timeline too (the toggles live inside `RunTimeline`).
