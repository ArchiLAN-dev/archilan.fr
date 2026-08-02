# Story 29.6: Show hints in clear on the log overlay

**Status:** review
**Epic:** 29 - OBS stream overlays
**Date:** 2026-07-30

## Story

As a caster using the log overlay,
I want hint events to show their actual text,
so that the stream shows the same public information every player already sees in-game.

## Context (user-reported 2026-07-30)

The log overlay masks "spoiler" event types as `•••••` unless the URL carries `?spoilers=1` - a
deliberate 29.x design so a caster does not leak the seed. Hints were in that set, and it went
unnoticed for months because the bridge almost never received hint frames (the AP `Hint` PrintJSON
only reaches the involved slots). Story 32.12's datastorage announce made every hint reach the
feed, and the mask suddenly became the visible default ("on les reçoit mais il affiche \\*\\*\\*\\*\\*").

## Decision

An Archipelago hint is broadcast to every player in the session - it is public in-game knowledge.
Masking it on stream protects nothing. **Hints render in clear by default**; `location-checked`
stays masked unless `?spoilers=1` (that param keeps its meaning for the remaining spoiler types).

## Acceptance Criteria

1. Hint rows on the log overlay show their text and origin without any URL param.
2. `location-checked` keeps the default mask; `?spoilers=1` still reveals it.
3. Gates green.

## Dev Notes

One-line change: `SPOILER_TYPES` drops `"hint"` (`log-overlay.tsx`), with the decision documented
in place. No API change.
