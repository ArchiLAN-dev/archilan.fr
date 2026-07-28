# Story 32.12: Extend the persisted feed to hints & goals

**Status:** draft
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
