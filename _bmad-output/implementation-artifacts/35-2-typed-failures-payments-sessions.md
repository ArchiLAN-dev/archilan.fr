# Story 35.2: Typed failures - Payments + Sessions (api/)

Status: done

## Story

As a maintainer continuing epic 35 Stage 1,
I want the Payments (`TriggerHelloAssoSync`) and Sessions (`SendBridgeCommand`) commands to throw the shared
typed failures instead of returning outcome arrays,
so that two more contexts adopt the foundation from 35.1 and their controllers get thinner, with the HTTP
contract unchanged.

## Context

Epic 35, Stage 1, second story. Builds directly on 35.1's foundation (`Shared\Application\Exception\*` +
`ApplicationFailureListener`). Converts the two outcome-array commands in Payments and Sessions.

**Envelope alignment (a 35.1 refinement done here).** The dominant error helper `ApiAccessGuard::errorResponse`
always emits `{ error: { code, message, details: [] } }` (the `details` key is always present). 35.1's
listener omitted `details` when empty (it matched Content's *raw* responses, which had no `details`). To make
every `errorResponse`-based conversion byte-identical, the listener now **always** includes `details`
(`[]` when empty). Content's test asserts only status + `error.code`, so this is safe there.

Exact mappings to preserve:

`TriggerHelloAssoSync` (via `AdminEventController`, `errorResponse`):

| Current | HTTP | code | message |
|---|---|---|---|
| `!found` | 404 | `not_found` | Événement introuvable. |
| `!hasFormSlug` | 422 | `no_form_configured` | Aucun formulaire HelloAsso configuré pour cet événement. |
| `configurationError !== null` | 503 | `helloasso_not_configured` | (the config error message) |
| success | 202 | - | `{ data: null, meta: { message: 'Synchronisation déclenchée.' } }` |

`SendBridgeCommand` (via `CommandsController`, `errorResponse`):

| Current | HTTP | code | message |
|---|---|---|---|
| `!found` | 404 | `not_found` | Session introuvable. |
| `error === 'session_not_running'` | 409 | `session_not_running` | La session n'est pas en cours. |
| `error === 'bridge_unavailable'` | 503 | `bridge_unavailable` | Bridge non disponible. |
| success | 200 | - | `{ data: { ok: true } }` |

## Acceptance Criteria

1. **AC1 - Listener always emits `details`.** `ApplicationFailureListener` includes `'details' => [...]`
   unconditionally (matching `errorResponse`). Its unit test updated. (No new exception types needed - the
   `NotFound`/`Validation`/`Conflict`/`ServiceUnavailable` set from 35.1 covers both contexts.)
2. **AC2 - Payments converted.** `TriggerHelloAssoSync::triggerForEvent` throws `NotFoundException`
   (event missing), `ValidationException('...', [], 'no_form_configured')` (no form), and
   `ServiceUnavailableException($configError, 'helloasso_not_configured')` (HelloAsso not configured); on
   success it returns void. `AdminEventController` drops the three branches and returns the 202 success body.
3. **AC3 - Sessions converted.** `SendBridgeCommand::execute` throws `NotFoundException` (session missing),
   `ConflictException('...', 'session_not_running')`, and `ServiceUnavailableException('...',
   'bridge_unavailable')`; on success it returns void. `CommandsController` drops the three branches and
   returns `{ data: { ok: true } }` (the controller keeps its own `invalid_command` 422 request-validation).
4. **AC4 - Contract unchanged.** Statuses, codes, messages and bodies are identical. The existing
   `HelloAssoSyncTest` and `AdminServerCommandsTest` (functional) stay green unchanged (regression proof);
   neither asserts `details`, so AC1 is safe.
5. **AC5 - Gates.** `composer gates` green. Controllers get thinner (AC-P3/P4). No behaviour change elsewhere.

## Tasks / Subtasks

- [x] Task 1: listener emits `details` unconditionally (AC: 1) - update
      `ApplicationFailureListener` + `ApplicationFailureListenerTest` (the "no details" case now expects `[]`).
- [x] Task 2: Payments (AC: 2) - `TriggerHelloAssoSync` throws + returns void; `AdminEventController`
      simplified to `triggerForEvent($eventId); return <202 body>`.
- [x] Task 3: Sessions (AC: 3) - `SendBridgeCommand` throws + returns void; `CommandsController` simplified
      to `execute(...); return { data: { ok: true } }`.
- [x] Task 4: verify + ship (AC: 4, 5) - `composer gates` (static + isolated functional for
      `HelloAssoSyncTest`, `AdminServerCommandsTest`, `AdminPostCoverImageTest`). PR to `develop`.

## Dev Notes

- **Void success.** Both commands now `return` nothing on success (their return payloads were only the
  outcome discriminant). The controllers build the success response themselves (202 with a meta message /
  `{ data: { ok: true } }`), which they already did.
- **`no_form_configured` as `ValidationException`.** It is a 422 precondition, not a field-validation, but
  `ValidationException` is the 422 type; the custom code `no_form_configured` communicates the reason and the
  empty details keep the body identical to the old `errorResponse('no_form_configured', ..., 422)`.
- **Controller-level validation stays.** `CommandsController`'s `invalid_command` (422, empty command) is
  request parsing done before the command call - it stays as an `errorResponse`, not a thrown failure.
- **Scope.** Payments + Sessions only. The `SyncHelloAssoFormMessage` async handler and the webhook are not
  touched (webhooks answer 200 regardless; not outcome-array command services). No Stage 2 here.
- House rules: DDD unchanged (exceptions already exist in Shared from 35.1; only commands/controllers edited).
  phpstan max, Yoda, `declare(strict_types=1)`. `composer gates`; isolated phpunit for the affected tests.

### References

- Foundation: story 35.1 (`Shared\Application\Exception\*`, `ApplicationFailureListener`).
- Convert: `src/Payments/Application/Command/TriggerHelloAssoSync.php` +
  `src/Events/Presentation/Controller/AdminEventController.php`;
  `src/Sessions/Application/Command/SendBridgeCommand.php` +
  `src/Sessions/Presentation/Controller/CommandsController.php`.
- Regression proof: `tests/Functional/HelloAssoSyncTest.php`, `tests/Functional/AdminServerCommandsTest.php`.
- Epic: `_bmad-output/planning-artifacts/epics/epic-35-strict-cqrs-write-path.md` (Stage 1 breakdown).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List

- Listener aligned to the dominant `errorResponse` envelope: `details` is now always present (`[]` when
  empty), so an `errorResponse` call site converts to a thrown failure byte-identically. Content (35.1)
  stays green (its test asserts only status + `error.code`).
- Payments: `TriggerHelloAssoSync::triggerForEvent` now `void`, throwing `NotFoundException` /
  `ValidationException(code 'no_form_configured')` / `ServiceUnavailableException(code
  'helloasso_not_configured')`. `AdminEventController::syncPayments` dropped the 3 branches -> just triggers
  and returns the 202 meta body.
- Sessions: `SendBridgeCommand::execute` now `void`, throwing `NotFoundException` /
  `ConflictException(code 'session_not_running')` / `ServiceUnavailableException(code 'bridge_unavailable')`.
  The non-2xx bridge response also throws inside the try; a `catch (ServiceUnavailableException)` re-throws
  it before the generic `catch (\Throwable)` re-wrap, so it isn't double-wrapped. `CommandsController`
  dropped the 3 branches (kept its own `invalid_command` request-validation).
- HTTP contract identical: `HelloAssoSyncTest`, `AdminServerCommandsTest` (and `PlayerStateTest`, which also
  hits /commands) pass unchanged - the regression proof.
- `composer gates` green: phpstan max 0, cs-fixer 0, ddd OK, rector OK, **phpunit 1541 tests / 10535
  assertions** (isolated DB). The listener change is global but only affects endpoints that throw an
  `ApplicationFailure` (Content + these two), all covered.

### File List

- `api/src/Shared/Infrastructure/Http/ApplicationFailureListener.php` (details always present)
- `api/tests/Unit/Shared/ApplicationFailureListenerTest.php` (updated assertion)
- `api/src/Payments/Application/Command/TriggerHelloAssoSync.php` (throws + void)
- `api/src/Events/Presentation/Controller/AdminEventController.php` (thinner syncPayments)
- `api/src/Sessions/Application/Command/SendBridgeCommand.php` (throws + void)
- `api/src/Sessions/Presentation/Controller/CommandsController.php` (thinner commands)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-16 | Story created (epic 35 Stage 1, 35.2). Converts Payments + Sessions off outcome arrays onto the 35.1 foundation; aligns the listener envelope to always include `details` (matching `errorResponse`). Status: ready-for-dev. |
| 2026-07-16 | Implemented: listener always emits `details`; `TriggerHelloAssoSync` + `SendBridgeCommand` throw + return void; both controllers thinned. `composer gates` green (1541 tests). Status: done. |
