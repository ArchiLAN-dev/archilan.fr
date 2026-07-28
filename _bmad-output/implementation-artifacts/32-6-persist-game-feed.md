# Story 32.6: Persist the live game feed (backend socle for a run timeline)

**Status:** review
**Epic:** 32 - Recaps
**Date:** 2026-07-24

## Story

As a player,
I want my run's item/check events kept, not just broadcast and forgotten,
so that a timeline and per-player check curves can be shown live and replayed after the run.

## Context

The bridge already builds exactly the data a timeline needs - for every AP `ItemSend` it produces a
structured event (item name, origin location, sender world, receiver world, ISO timestamp;
`bridge/core/ap_client.py:_build_feed_event` / `_build_item_origin`, story 29.4) and POSTs it to
`POST /api/v1/internal/sessions/{sessionId}/feed-push`. But `FeedPushController` only **republishes it
on Mercure** `runs/{id}/feed` and forgets it: nothing is persisted, and the bridge keeps only the last
200 events in memory. Every persisted number today is an aggregate counter with no history.

Verified empirically on a real finished run: no `feed`/`event`/`timeline` table exists (only
`session_recap`), and `session_slot` holds bare counters. Also verified: `_handle_print_json` emits a
feed event for **every** `ItemSend` with **no self-send filter**, so a **solo** run (every find is a
local self-send) still produces item events - a solo timeline/check-curve has content; only the
cross-world exchange graph is empty for solo.

This story is the **backend socle**: capture the events into a table, expose a gated read endpoint,
and fix the live-feed token gate for personal runs. The visualisations are story 32.7.

### Decisions

- **Persist item events only, for now.** The timeline (item, source world -> dest world, time) and the
  per-player check curve (each find is a check, bucketable by minute) both derive from item events.
  Chat/join/part/system noise is skipped; hints/goals/deaths can be added later if a use appears.
- **Persist at the existing choke point.** `FeedPushController::push` already receives every event
  server-side; it now records the event (best-effort, logged on failure) *and* publishes to Mercure as
  today. Persistence never blocks the live path.
- **Same visibility as the recap (story 32.5).** The read endpoint reuses the exact viewer-aware rule:
  event -> public event only; personal run -> owner/participant, or anyone once published. The feed is
  not spoiler-sensitive (it only shows the past, and is already shown live), so it is gated like the
  recap graph, never like the spoiler download (owner/admin only).
- **Fix the live-feed token gate.** `FeedTokenController` currently authorises via
  `hasActiveEventRegistration` (event-only), which a personal-run participant never satisfies. It now
  uses `SessionQuery::isUserAuthorizedForSession`, which already handles owner/participant - so
  participants can actually watch a private run's live feed. Latent-bug fix.

## Acceptance Criteria

1. A new `session_feed_event` table stores each item event: session id, type, text, item id/name,
   location id/name, sender slot/name/game, receiver slot/name/game, `occurred_at`. Indexed on
   `(session_id, occurred_at)`.
2. `FeedPushController::push` records every item event before/after publishing to Mercure; a
   persistence failure is logged and never breaks the live publish. Non-item events are ignored.
3. `GET /api/v1/parties/{sessionId}/feed` returns the session's events ordered by `occurred_at`, gated
   with the **same** viewer-aware rule as the recap (owner/participant, public when published or a
   public event), 404 otherwise.
4. `FeedTokenController` authorises via `isUserAuthorizedForSession`, so a personal-run
   owner/participant can obtain a live-feed token (not only admins/event registrants).
5. Solo runs are covered: a self-send item event is persisted and served like any other.
6. Gates green, with functional tests for persistence, the read endpoint's access cases, and the token
   fix.

## Tasks / Subtasks

- [x] **Task 1 - Entity + storage** (AC 1). `SessionFeedEvent` entity, repository interface + Doctrine
      repo, migration (reversible, indexed).
- [x] **Task 2 - Capture** (AC 2, 5). `RecordSessionFeedEvent` command parsing the pushed event;
      `FeedPushController` calls it (best-effort) around the Mercure publish.
- [x] **Task 3 - Read** (AC 3). `SessionFeedQuery` (viewer-aware, mirrors `SessionRecapQuery`) +
      `SessionFeedController` (`optionalUser`).
- [x] **Task 4 - Live gate fix** (AC 4). `FeedTokenController` -> `isUserAuthorizedForSession`.
- [x] **Task 5 - Tests + gates** (AC 6).

## Dev Notes

- The pushed event shape (from the bridge): `{type, text, color, timestamp, item:{id,name},
  location:{id,name}, sender:{slot,name,game}, receiver:{slot,name,game}}`. `FeedPushController` maps
  `item_sent` -> `item-received`; persist the mapped type.
- Volume: one row per item event, a game produces up to a few thousand. The `(session_id, occurred_at)`
  index keeps both the read and any bucketing cheap.
- Access reuses `SessionQuery::isUserAuthorizedForSession` (owner/participant/registration) plus the
  run's `isRecapPublic()` (story 32.5) for the public case - one rule across recap + feed.

### Project Structure Notes

- `api/src/Sessions/Domain/Entity/SessionFeedEvent.php`, `Domain/Repository/…`, `Infrastructure/Doctrine/…`
- `api/src/Sessions/Application/Command/RecordSessionFeedEvent.php`, `Application/Query/SessionFeedQuery.php`
- `api/src/Sessions/Presentation/Controller/FeedPushController.php`, `SessionFeedController.php`, `FeedTokenController.php`
- a migration

### References

- [Source: _bmad-output/implementation-artifacts/32-5-personal-run-recap.md] - the shared visibility rule
- [Source: bridge/core/ap_client.py] - `_build_feed_event` / `_build_item_origin`, the event shape

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code, 1M context).

### Completion Notes List

### File List
