# Story 17.21: Player progress viewable when the bridge is down

**Status:** review
**Epic:** 17 - Session lifecycle (idle & restart)
**Date:** 2026-07-30

## Story

As anyone opening a run's Progression tab while the session is paused (or its bridge is otherwise
unreachable),
I want the player progress grid and the timeline to show the last known state,
so that the tab stays useful instead of going blank the moment the container stops.

## Context (user-reported 2026-07-30)

The run timeline already survives a dead bridge - the feed is persisted (32.6/32.12) and the chart
renders from it (verified in-browser on an idle run with 165 events). What does NOT survive is
"Progression des joueurs": `GET /sessions/{runId}/players` **proxies the live bridge** and answers
**409** for any non-running status before even trying (idle sessions - the everyday case since
epic 17's auto-idle), or **503** when the port is gone/unreachable. Yet the bridge pushes exactly
that state to the API on every change (`players-push`) - it was published to Mercure and dropped.

## Acceptance Criteria

1. Every `players-push` also persists the payload as the session's last-known players snapshot
   (upsert, one row per session; a persistence failure never breaks the push).
2. `GET /sessions/{runId}/players` serves the snapshot (200, `meta.stale: true` + `updatedAt`)
   when the session is not running, the bridge port is absent, or the live call fails; previous
   error semantics (409/503/bridge error) apply only when no snapshot exists.
3. The live timeline's empty state distinguishes "nothing recorded" (loaded, empty feed) from
   "connecting" - no more eternal "Connexion au direct…" on a paused run with no events.
4. Access rules unchanged (same auth as the live proxy). Gates green both sides.

## Dev Notes (implementation, 2026-07-30)

- New `session_players_snapshot` (session_id PK, payload JSON, updated_at) +
  `SessionPlayersSnapshot` entity, repository interface, Doctrine impl,
  `RecordPlayersSnapshot` command (mirrors `RecordSessionFeedEvent`'s best-effort pattern in the
  push controller) and `PlayersSnapshotQuery` for the read side.
- `PlayerStateController::players` falls back to the snapshot in its three failure branches; the
  payload shape is exactly what the bridge's `/state` returns (the bridge pushes
  `to_api_dict()`, same contract), so `PlayerProgressGrid` needs no change - it just receives
  data again.
- Frontend: `LiveRunTimeline` tracks snapshot load completion and renders "Aucun évènement
  enregistré pour cette partie pour l'instant." when loaded-and-empty without a live connection.
