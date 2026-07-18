# Story 35.19: Typed session read-view - ForceEndSessionCommand + Session::payload() (api/)

Status: done

## Story

As a maintainer continuing epic 35 Stage 2,
I want `ForceEndSessionCommand::execute` to stop returning a raw `array<string, mixed>`, by making the shared
`Session::payload()` return a `final readonly SessionView` value object, so that the last Command that returned
an array is typed - unblocking the Stage 2 validator rule - with every session HTTP body unchanged.

## Context

Epic 35, Stage 2, split from 35.18. `ForceEndSessionCommand::execute` returns `$session->payload()` - the
session read view. Unlike the other Stage 2 commands, this view is produced by a **Domain** method
(`Session::payload()`) and shared across the Sessions read surface: `SessionLifecycleManager` (create/get/
transition/forceReset...), `PlayerSessionConnection`, `SessionResultsQuery`, `SessionOrchestrator::listSessions`.

**Design: a Domain ValueObject.** A Domain method cannot return an Application record (Domain imports nothing
from the project), so the view is typed as `Sessions/Domain/ValueObject/SessionView` (a `final readonly` VO,
no ORM). `Session::payload()` already formatted its dates as ATOM strings, so the VO only formalises the shape
that was already there - and typing it once benefits the entire read surface, not just the force-end command.
(The alternative - moving the view-building into an Application mapper and dropping `Session::payload()` - is a
larger refactor that changes the Domain entity's public API at every call site; the Domain VO is the smaller,
DDD-legal change.)

Only 35.20 (the validator rule) needed `ForceEndSessionCommand` typed - `SessionLifecycleManager`/queries are a
Service/queries and exempt - but typing the shared `payload()` cleans all of them at once for the same cost.

## Acceptance Criteria

1. **AC1 - VO.** Add `SessionView` (`final readonly`, the 24 `payload()` fields in order, incl. `?list<...>
   validationErrors` and the nullable connection/timestamp fields) in `Sessions/Domain/ValueObject/`.
2. **AC2 - payload() typed.** `Session::payload()` returns `SessionView`; `array<string, mixed>` docblock gone.
3. **AC3 - Command typed.** `ForceEndSessionCommand::execute` returns `SessionView`; `array<string, mixed>`
   docblock dropped, `@throws` kept.
4. **AC4 - Consumers.** `SessionResultsQuery` reads `payload()->startedAt/finishedAt`; `PlayerSessionConnection`
   / `SessionLifecycleManager::forceReset` / `SessionOrchestrator::listSessions` docblocks reference `SessionView`
   instead of `array<string, mixed>`; `PlayerPatchController` reads `$session->status`/`->id` (the now-dead
   `is_string($session->id)` guards removed). No other consumer read the payload keys.
5. **AC5 - Byte-identical.** Every place that serialized the payload (ForceEnd controller, session endpoints via
   the lifecycle/connection/orchestrator) emits the identical JSON - `json_encode` of the VO reproduces the
   former array (public props in declaration order).
6. **AC6 - Gates.** `composer gates` green. Full isolated suite green.

## Tasks / Subtasks

- [x] Task 1: `SessionView` Domain VO - 24 fields (AC: 1).
- [x] Task 2: `Session::payload()` returns the VO (AC: 2).
- [x] Task 3: `ForceEndSessionCommand::execute` returns the VO (AC: 3).
- [x] Task 4: fix the key-reading + docblock consumers surfaced by phpstan (AC: 4).
- [x] Task 5: verify + ship (AC: 5, 6). PR to `develop`.

## Dev Notes

- **Why Domain, not Application.** `Session::payload()` is a Domain method; a VO in `Domain/ValueObject/` is the
  only way to type its return without inverting the dependency direction. `SessionView` holds pre-formatted
  strings, which is a pre-existing trait of `payload()` - the VO does not introduce new presentation-in-domain,
  it types what already existed.
- **phpstan drove the consumer sweep.** Building the VO and running `phpstan analyse src tests` surfaced every
  consumer that read the array (SessionResultsQuery offsets, PlayerPatchController offsets) or declared its shape
  (PlayerSessionConnection / SessionLifecycleManager / SessionOrchestrator docblocks). Fixed each; the
  `{session: ...}` / `{found, session: ...}` outcome envelopes now nest the VO (serialises the same).
- **Dead guards removed.** `PlayerPatchController` had `is_string($session['id'])` guards; with a typed
  `SessionView::$id` (always `string`) they are dead, so they were dropped.
- **No test edits.** Session functional tests assert the decoded HTTP JSON (byte-identical); no unit test read
  `Session::payload()` as an array (`SessionSlot::payload()` is a different method, untouched).
- House rules: `final readonly` VO, no ORM in VO, strict types, phpstan max. `composer gates`.

### References

- Convention source: 35.8-35.18; epic Stage 2 breakdown (35.19 = ForceEndSession + Session read-model).
- Convert: `src/Sessions/Domain/Entity/Session.php` (payload), `src/Sessions/Application/Command/ForceEndSessionCommand.php`.
- Consumers: `SessionResultsQuery`, `PlayerSessionConnection`, `SessionLifecycleManager`, `SessionOrchestrator`,
  `PlayerPatchController`.
- New: `src/Sessions/Domain/ValueObject/SessionView.php`.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List

- `SessionView` Domain VO (24 fields) types `Session::payload()`; `ForceEndSessionCommand::execute` returns it.
- Consumers surfaced by phpstan fixed: `SessionResultsQuery` offsets -> props; `PlayerPatchController` offsets ->
  props (+ removed dead `is_string` id guards); `PlayerSessionConnection` / `SessionLifecycleManager::forceReset`
  / `SessionOrchestrator::listSessions` docblocks now reference `SessionView`.
- HTTP bodies byte-identical (VO json-encodes to the former array). No test edits (functional asserts the JSON;
  no unit test read the payload array).
- `composer gates` green: phpstan max 0 (at `src tests` scope), cs-fixer 0, ddd OK (VO in `Domain/ValueObject/`),
  rector OK, phpunit green (full isolated suite).
- With this, no `Application/Command/` method returns an array -> 35.20 (validator rule) is unblocked.

### File List

- `api/src/Sessions/Domain/ValueObject/SessionView.php` (new)
- `api/src/Sessions/Domain/Entity/Session.php` (`payload()` returns `SessionView`)
- `api/src/Sessions/Application/Command/ForceEndSessionCommand.php` (returns `SessionView`)
- `api/src/Sessions/Application/Query/SessionResultsQuery.php` (reads props)
- `api/src/Sessions/Application/Query/PlayerSessionConnection.php` (docblock)
- `api/src/Sessions/Application/Service/SessionLifecycleManager.php` (docblock)
- `api/src/Sessions/Application/Service/SessionOrchestrator.php` (docblock)
- `api/src/Sessions/Presentation/Controller/PlayerPatchController.php` (reads props, dead guards removed)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-18 | Story created + implemented (epic 35 Stage 2, split from 35.18). `SessionView` Domain VO; `Session::payload()` + `ForceEndSessionCommand` + read consumers typed. `composer gates` green. Unblocks 35.20 validator rule. Status: done. |
