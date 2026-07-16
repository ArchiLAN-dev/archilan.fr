# Story 35.3: Typed failures - PersonalRuns (api/)

Status: done

## Story

As a maintainer continuing epic 35 Stage 1,
I want the PersonalRuns command services (`PersonalRunGameConfig`, `PersonalRunLifecycle`) to throw the
shared typed failures instead of returning rich `found/authorized/blocked` outcome arrays,
so that the context adopts the 35.1 foundation and its two controllers get much thinner, with the HTTP
contract unchanged.

## Context

Epic 35, Stage 1, third story - the largest so far. PersonalRuns has 2 command classes with **6 outcome-array
methods** total, each branching on `found` / `authorized` / `blocked(blockReason)` / (config: validation
`errorCode`+`errors`). Two controllers (`PersonalRunController`, `PersonalRunCallbackController`) translate
those discriminants to HTTP.

Adds one exception type to the foundation: **`ForbiddenException` (403)** - the `authorized: false` -> 403
`forbidden` `Accès refusé.` pattern is uniform across all owner-guarded methods.

Exact mappings to preserve (from the two controllers):

| Command method | found=false | authorized=false | blocked -> (status, code=blockReason, message) | success |
|---|---|---|---|---|
| `PersonalRunLifecycle::start` | 404 `not_found` `Run introuvable.` | 403 `forbidden` `Accès refusé.` | **422** `Démarrage impossible dans l'état actuel.` | 202 `{runId, status}` |
| `::stop` | idem | idem | **422** `Arrêt impossible dans l'état actuel.` | 202 `{runId, status}` |
| `::finish` | idem | idem | **409** `Impossible de terminer la run dans son état actuel.` | 200 `{runId, status}` |
| `::markRunning` | idem | (no owner check) | **422** `Transition de run invalide.` | 200 `{runId, status}` |
| `::markStopped` | idem | (no owner check) | **422** `Transition de run invalide.` | 200 `{runId, status}` |
| `PersonalRunGameConfig::configure` | idem | 403 idem | **422** `Modification impossible dans l'état actuel.` | 204 (void) |

`configure` validation branch (`errors` non-empty): 422, code = `errorCode` (`game_id_required` /
`games_required` / `unknown_game`), message `Configuration de jeux invalide.`, `details` = the field map.

## Acceptance Criteria

1. **AC1 - ForbiddenException.** Add `Shared\Application\Exception\ForbiddenException` (403, default code
   `forbidden`) to the foundation, with a unit test.
2. **AC2 - PersonalRunGameConfig converted.** `configure` throws `NotFoundException` / `ForbiddenException` /
   `ConflictException`?-no: **`ValidationException`** for the `run_generated` block (422) and for the field
   validation (422 with the field map + `errorCode`); returns void on success. Controller's `configureGames`
   drops the branches and returns `204`.
3. **AC3 - PersonalRunLifecycle converted.** `start`/`stop`/`markRunning`/`markStopped`/`finish` throw
   `NotFoundException` (missing), `ForbiddenException` (start/stop/finish owner check), and for `blocked`:
   `ValidationException` (422) for start/stop/markRunning/markStopped and **`ConflictException` (409)** for
   `finish` - each with the exact per-action message. On success each returns `array{runId, status}`. Both
   controllers (`PersonalRunController` start/stop/finish, `PersonalRunCallbackController` running/stopped)
   drop the branches and build their success responses from the returned data.
4. **AC4 - Contract unchanged.** Statuses/codes/messages/bodies identical. The PersonalRuns functional tests
   stay green unchanged (byte-exact regression proof). `details` is `[]` on the non-validation failures
   (matches `errorResponse`, per 35.2).
5. **AC5 - Gates.** `composer gates` green. Controllers get thinner (AC-P3/P4). No behaviour change elsewhere.

## Tasks / Subtasks

- [x] Task 1: `ForbiddenException` (AC: 1) + unit test.
- [x] Task 2: `PersonalRunGameConfig::configure` throws + void success; `PersonalRunController::configureGames`
      simplified to `configure(...); return new JsonResponse(null, 204)`.
- [x] Task 3: `PersonalRunLifecycle` - 5 methods throw + return `{runId, status}` on success; both controllers
      simplified. Preserve the finish=409 vs others=422 distinction and each exact message.
- [x] Task 4: verify + ship (AC: 4, 5) - `composer gates` (static + isolated functional for the PersonalRuns
      suite). PR to `develop`.

## Dev Notes

- **Success returns data (not void) for lifecycle.** Unlike 35.2, the lifecycle methods' success returns
  `{runId, status}` (the controllers echo it). Keep that: `return ['runId' => $run->getId(), 'status' =>
  $run->getStatus()]`. Only `configure` is void-on-success (204).
- **Per-action messages live in the command now.** Each method throws with its own message (e.g. start's
  "Démarrage impossible..." vs stop's "Arrêt impossible..."). The blockReason stays the error `code`.
- **finish is 409, the rest are 422.** `finish`'s blocked path is a `ConflictException`; all other blocked
  paths are `ValidationException`. Do not flatten them.
- **markRunning/markStopped have no owner check** (bridge-token auth is in the callback controller) - they
  only throw NotFound + Validation.
- **Controller pre-command validation stays** (`invalid_payload`, bridge-token 401, JSON parsing) - those are
  request concerns, not command failures.
- **Regression proof.** The PersonalRuns functional tests exercise every branch; they are the byte-exact
  safety net. Run them isolated. No test edits expected (contract unchanged).
- House rules: exceptions in `Shared/Application/Exception/` (ForbiddenException is new here). phpstan max,
  Yoda, strict types. `composer gates`.

### References

- Foundation: 35.1 (`ApplicationFailure*`), 35.2 (listener always emits `details`).
- Convert: `src/PersonalRuns/Application/Command/{PersonalRunGameConfig,PersonalRunLifecycle}.php` +
  `src/PersonalRuns/Presentation/Controller/{PersonalRunController,PersonalRunCallbackController}.php`.
- Epic: `_bmad-output/planning-artifacts/epics/epic-35-strict-cqrs-write-path.md` (Stage 1 breakdown).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List

- Added `ForbiddenException` (403, code `forbidden`) to the foundation + unit test. The `NotFound`/`Forbidden`/
  `Validation`/`Conflict`/`ServiceUnavailable` set now covers the common HTTP failure statuses.
- `PersonalRunGameConfig::configure` now `void`, throwing `NotFoundException` / `ForbiddenException` /
  `ValidationException` (run_generated 422; the 3 field-validation cases 422 with the field map). The
  orphaned `result()` helper removed. `configureGames` controller -> `configure(...); return 204`.
- `PersonalRunLifecycle` (5 methods) now return `array{runId, status}` on success and throw on failure -
  crucially preserving **finish's 409 (`ConflictException`)** vs the others' 422 (`ValidationException`), and
  each action's exact message. The `result()` helper removed. `markRunning`/`markStopped` throw only
  NotFound + Validation (no owner check - bridge-token auth stays in the callback controller).
- Both controllers (`PersonalRunController` start/stop/finish/configureGames, `PersonalRunCallbackController`
  running/stopped) dropped all the `found`/`authorized`/`blocked`/`errors` branches - much thinner (AC-P3/P4).
  Their pre-command request validation (invalid_payload, bridge-token 401, JSON parsing) stays.
- HTTP contract identical: the PersonalRuns functional suite (83 tests incl. `PersonalRunLifecycleTest`,
  `PersonalRunGameConfigTest`, callbacks, invites) passes unchanged - byte-exact regression proof.
- `composer gates` green: phpstan max 0, cs-fixer 0, ddd OK, rector OK, **phpunit 1542 tests / 10538
  assertions** (isolated DB).

### File List

- `api/src/Shared/Application/Exception/ForbiddenException.php` (new)
- `api/tests/Unit/Shared/ApplicationExceptionTest.php` (Forbidden case)
- `api/src/PersonalRuns/Application/Command/PersonalRunGameConfig.php` (throws + void)
- `api/src/PersonalRuns/Application/Command/PersonalRunLifecycle.php` (throws + returns {runId,status})
- `api/src/PersonalRuns/Presentation/Controller/PersonalRunController.php` (thinner start/stop/finish/configureGames)
- `api/src/PersonalRuns/Presentation/Controller/PersonalRunCallbackController.php` (thinner running/stopped)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-16 | Story created (epic 35 Stage 1, 35.3). PersonalRuns: 6 outcome-array methods across 2 commands + 2 controllers; adds `ForbiddenException` (403). Mapping table grounded in both controllers (note finish=409 vs 422 elsewhere; lifecycle success returns {runId, status}). Status: ready-for-dev. |
| 2026-07-16 | Implemented: `ForbiddenException` + 6 methods converted (finish=409 preserved) + 2 controllers thinned. `composer gates` green (1542 tests). Status: done. |
