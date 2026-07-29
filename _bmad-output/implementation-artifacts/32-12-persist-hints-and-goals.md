# Story 32.12: Extend the persisted feed to hints & goals

**Status:** review
**Epic:** 32 - Recaps
**Date:** 2026-07-28

## Story

As someone reliving a run,
I want hints and goal completions kept alongside item finds,
so that the timeline and log can show "who hinted what" and "when each player finished", not only item
exchanges.

## Context

Story 32.6 deliberately persists **item events only** - the timeline's checks/curves derive from them,
and chat/join/system noise is skipped. But the bridge also emits **hints** (`_track_hint`,
`hints-push`) and **goal** frames (`Goal` PrintJSON), which are broadcast live and then dropped. These
are meaningful history: a hint marks intent, a goal marks completion.

### Decisions / scope

- Extend `RecordSessionFeedEvent` to also persist `hint` and `goal` frames (a `type` already
  distinguishes them). Keep chat/join/system out.
- `session_feed_event` may need a couple of nullable columns for hint specifics (the hinted item /
  finder / receiver - largely the same shape as an item event) and nothing extra for a goal (slot +
  time). Review the bridge payloads (`bridge/core/ap_client.py`) before settling the schema.
- Surface: the log gains hint/goal rows (distinct styling); the timeline can drop a goal marker
  (overlaps story 32.9's goal markers - coordinate so goal-time comes from one source).
- Access unchanged: same `SessionRecapAudience` gate as items.

### Non-goals

- Chat/join/part/system stay unpersisted (noise).
- Death-link and other niche frames are out unless a use appears.

## Acceptance Criteria (sketch)

1. Hint and goal frames pushed to `feed-push` are persisted (item filtering relaxed to include them).
2. `GET /parties/{id}/feed` returns them; the frontend renders hints/goals distinctly in the log and (goal)
   on the timeline.
3. Existing item-only behaviour and access rules are unchanged; non-item noise still ignored.
4. Functional tests cover hint/goal persistence and retrieval. Gates green both sides.

## Notes

- Files: `api` `RecordSessionFeedEvent`, `SessionFeedEvent` (+ migration if columns added),
  `SessionFeedQuery`, `FeedPushController`; `frontend` `feed-api.ts`, `run-timeline.tsx`.
- Coordinate goal handling with story 32.9 so a goal instant has a single source of truth.

## Dev Notes (implementation, 2026-07-28)

Bridge payloads reviewed as the story asked: an AP Hint carries the **same NetworkItem shape** as an
ItemSend (player = would-be finder) and a Goal only a `slot`. Consequences: **no new columns, no
migration** - the existing row shape covers all three types (a goal row keeps item/location null).

- **Bridge** (commit `c7f707a`): `_build_feed_event` attaches the structured origin to `hint` frames
  (same `_build_item_origin`, flags included) and a resolved `sender` {slot,name,game} to `goal`
  frames.
- **API**: `RecordSessionFeedEvent` persists `['item-received', 'hint', 'goal']`
  (`PERSISTED_TYPES`); join/part/chat/system still ignored. `FeedPushController` unchanged (hint/goal
  pass through, only `item_sent` needed mapping). Functional test covers hint + goal persisted,
  join ignored, ordering.
- **Frontend**:
  - Curves: `buildChecksSeries` filters to `item-received` - a hint is intent, not a find (its
    progression flag must not dot the curve).
  - Log: `LogRowContent` renders three shapes - find (32.7 prose), hint (badge "Indice",
    "{item} pour {receiver} · {location} (monde de {sender})"), goal (badge "Objectif").
  - Facets: `LogFacet` gains `hints`/`goals` ("Indices"/"Objectifs" chips); the transfer facets
    (Reçus/Envoyés/Locaux) now keep item events only.
  - **Goal-source coordination (32.9)**: one source per surface. The recap passes its podium
    instants (authoritative, unchanged); when no `goals` prop is given (live timeline), `RunTimeline`
    derives markers from the feed's goal events. So live now has goal markers too - the 32.9
    deferral is closed by this story.
  - Live dedup (`findKey`) is type-aware: `goal:{slot}` (a slot finishes once),
    `hint:{slot}:{location}` (AP re-broadcasts hints verbatim), `item:{slot}:{location}`.
