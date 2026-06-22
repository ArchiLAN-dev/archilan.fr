# Story 17.16: Capture Goal Stats on the session_slot at Goal Time (event / personal runs)

## Story

**As a** player finishing a private (personal) run,
**I want** my checks, items and goal-reached date recorded on the run's `session_slot` the moment I
reach the goal,
**So that** my completed run shows the correct stats even when the bridge container is stopped before
the later archival step.

## Context

The bridge fires `POST /internal/sessions/{sessionId}/slot-goal` per slot when a goal is reached
(`bridge/core/ap_client.py::_notify_goal`), with `{slotId, checksTotal, itemsTotal, goalReachedAt}`.
`SlotGoalCallbackController` only routed this to `RecordWeeklyGoal` (a no-op for non-weekly sessions),
so for event / personal runs the `session_slot` was **never** written at goal time. Those slots were
only populated later by `ArchiveRunJobHandler`, which re-reads bridge state from
`http://localhost:{bridgePort}/state` - but the bridge container may already be stopped by then
(idle=stop), so the fetch returns `[]` and the slot keeps its defaults (checks=0, items=0,
goal_reached_at=null).

Reported as bug #6 in `HOTFIX-BACKLOG.md`. Cross-repo (bridge + api).

## Status

done

## Acceptance Criteria

**AC1:** The bridge `/slot-goal` payload includes `slotName` (`bridge/core/ap_client.py`).

**AC2:** For a non-weekly session, the callback records `checks_done`, `items_received` and
`goal_reached_at` onto the `session_slot` matched **by name** (`findBySessionAndSlotName`), never by
the AP slot index (no reliable mapping to `slot_order`).

**AC3:** Weekly runs are unchanged - the weekly path (`RecordWeeklyGoal` → `weekly_entries`) still
runs first and short-circuits.

**AC4:** Idempotent: a second callback for a slot that already has `goal_reached_at` is a no-op (no
overwrite). Unknown slot name, or a payload without `slotName` (legacy bridge), is a safe no-op
returning 200.

**AC5:** The controller makes a single Application call (AC-P4) - dispatch logic lives in the
`RecordSlotGoal` Application facade, not the controller.

**AC6:** All quality gates pass: `phpstan`, `php-cs-fixer`, `phpunit` (0 notices), `app:architecture:ddd`;
plus the bridge test suite (pytest).

## Tasks / Subtasks

- [x] Task 1: Bridge - add `slotName: ps.slot_name` to the `/slot-goal` payload in `_notify_goal`.
- [x] Task 2: API - new `Sessions/Application/RecordSlotGoal` facade: try `RecordWeeklyGoal`; if not a
  weekly entry and a `slotName` is given, look up the `session_slot` by name and set
  checks/items/goal_reached_at (idempotent on `goal_reached_at`).
- [x] Task 3: API - `SlotGoalCallbackController` injects `RecordSlotGoal` (one Application call), reads
  the optional `slotName`, and delegates.
- [x] Task 4: Functional tests - `SlotGoalSessionSlotTest` (happy path, idempotency, unknown slot,
  legacy no-slotName). Existing `WeeklyGoalCallbackTest` still green (weekly path unchanged).
- [x] Task 5: All backend gates + bridge pytest.

## Dev Notes

### Matching by name, not index

The archival path deliberately matches slots by `slot_name` because the AP numeric slot index has no
reliable mapping to `session_slot.slot_order`. `RecordSlotGoal` follows the same strategy, which is
why the bridge must send `slotName` (it already has it on the player state, `ps.slot_name`).

### Cross-context coupling

`RecordSlotGoal` (Sessions/Application) injects `RecordWeeklyGoal` (WeeklyRuns/Application). This
matches the pre-existing coupling (the controller already injected `RecordWeeklyGoal`) and is not
flagged by the DDD validator. Unit-mocking `RecordWeeklyGoal` is impractical (it is `final`), so the
facade is covered by functional tests against the real container.

### Deployment

Two repos, two releases. The api change is backward-compatible with the current bridge (no `slotName`
→ safe no-op for non-weekly), and the bridge change is additive, so they can ship independently.

## File List

- `bridge/core/ap_client.py` - modified (slotName in payload)
- `api/src/Sessions/Application/RecordSlotGoal.php` - new
- `api/src/Sessions/Presentation/SlotGoalCallbackController.php` - modified
- `api/tests/Functional/SlotGoalSessionSlotTest.php` - new

## Change Log

| Date | Change |
|------|--------|
| 2026-06-21 | Story created and implemented (bug #6 from HOTFIX-BACKLOG) |
