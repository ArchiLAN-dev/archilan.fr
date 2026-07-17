# Story 35.8: Typed result records - foundation/pilot (PersonalRuns) (api/)

Status: done

## Story

As a maintainer opening epic 35 Stage 2 (typed result records),
I want `PersonalRunLifecycle` to return a `final readonly RunLifecycleResult` record instead of an
`array{runId: string, status: string}` shape,
so that the colocated-record convention is established on a small, well-understood context before rolling it
out, with the HTTP contract byte-identical.

## Context

Epic 35, Stage 2, first story - the pilot that sets the pattern every later Stage 2 story copies:

- a command's result record is a `final readonly` class, **colocated** in `Application/Command/` with the
  command (taxonomy colocation rule), one constructor-promoted property per field, no behaviour;
- the command's method return type becomes the record (dropping the `@return array{...}` phpdoc, which the
  real type now subsumes);
- controllers read `->field` instead of `['field']`; the JSON body they build is unchanged.

`PersonalRunLifecycle` has 5 methods (`start`, `stop`, `markRunning`, `markStopped`, `finish`) that each
returned `array{runId, status}` (introduced in 35.3). Two controllers consume it: `PersonalRunController`
(start/stop/finish -> 202/202/200) and `PersonalRunCallbackController` (markRunning/markStopped). The
`configure` method on the sibling `PersonalRunGameConfig` is already `void` (35.3) - untouched here.

Deliberately NOT the strict Stage 3 form (command -> void + separate read query): see the epic's Stage 2
breakdown for why records-carrying-data is the cleaner end-state here (one Application call, no redundant read).

## Acceptance Criteria

1. **AC1 - Record.** Add `App\PersonalRuns\Application\Command\RunLifecycleResult` - `final readonly`, two
   promoted `string` props `runId` + `status`, no methods. Colocated with the commands (Application/Command/).
2. **AC2 - Command converted.** `PersonalRunLifecycle::{start,stop,markRunning,markStopped,finish}` return
   `RunLifecycleResult` (return `new RunLifecycleResult($run->getId(), $run->getStatus())`); the `@return
   array{...}` docblock lines drop, the `@throws` lines stay. No failure-path change (Stage 1 typed throws
   untouched).
3. **AC3 - Controllers read the record.** `PersonalRunController` (start/stop/finish) and
   `PersonalRunCallbackController` (markRunning/markStopped) read `$result->runId` / `$result->status`. The
   other `$result['status']` reads in `PersonalRunController` (from *different* services/queries) are NOT
   touched.
4. **AC4 - Contract unchanged.** Statuses and bodies (`{data: {runId, status}}`) byte-identical. The
   PersonalRuns functional suite stays green unchanged (regression proof).
5. **AC5 - Gates.** `composer gates` green (phpstan max, cs-fixer, ddd, rector, phpunit).

## Tasks / Subtasks

- [x] Task 1: `RunLifecycleResult` record (AC: 1).
- [x] Task 2: convert the 5 `PersonalRunLifecycle` methods (AC: 2).
- [x] Task 3: both controllers read the record (AC: 3), leaving unrelated `$result['status']` reads alone.
- [x] Task 4: verify + ship (AC: 4, 5) - static gates + isolated PersonalRuns + full suite. PR to `develop`.

## Dev Notes

- **Colocation, not a shared DTO folder.** The record lives with its command (`Application/Command/`), per the
  taxonomy colocation rule - not in `Support/` or a cross-context DTO bag.
- **No import needed** - the record and the command share the `App\PersonalRuns\Application\Command` namespace.
- **Only lifecycle reads change.** `PersonalRunController` also reads `$result['status']` from other calls
  (e.g. a query) - those stay array reads. Convert only the `['runId' => $result['runId'], 'status' =>
  $result['status']]` fragments (unique to the lifecycle results).
- **Regression proof.** The PersonalRuns functional tests assert the JSON body (`$data['status']` etc.), which
  is unchanged; no domain/unit test reads the command's return shape. No test edits expected.
- House rules: `final readonly`, strict types, Yoda, phpstan max. `composer gates`.

### References

- Convention source: epic Stage 2 breakdown (`epic-35-strict-cqrs-write-path.md`).
- Convert: `src/PersonalRuns/Application/Command/PersonalRunLifecycle.php` +
  `src/PersonalRuns/Presentation/Controller/{PersonalRunController,PersonalRunCallbackController}.php`.
- New: `src/PersonalRuns/Application/Command/RunLifecycleResult.php`.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List

- `RunLifecycleResult` (`final readonly`, `runId` + `status`) colocated in `Application/Command/`. Establishes
  the Stage 2 record convention.
- `PersonalRunLifecycle`'s 5 methods return the record; `@return array{...}` docblocks dropped, `@throws`
  preserved, Stage 1 typed throws untouched.
- Both controllers read `$result->runId`/`$result->status` (5 fragments). The unrelated `$result['status']`
  reads in `PersonalRunController` (other services) left as arrays.
- HTTP contract byte-identical: PersonalRuns suite (131 tests) green unchanged; full suite green (isolated DB).
- `composer gates`: phpstan 0, cs-fixer 0, ddd OK, rector OK, phpunit green.

### File List

- `api/src/PersonalRuns/Application/Command/RunLifecycleResult.php` (new)
- `api/src/PersonalRuns/Application/Command/PersonalRunLifecycle.php` (returns the record)
- `api/src/PersonalRuns/Presentation/Controller/PersonalRunController.php` (reads the record)
- `api/src/PersonalRuns/Presentation/Controller/PersonalRunCallbackController.php` (reads the record)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-17 | Story created + implemented (epic 35 Stage 2 pilot). `RunLifecycleResult` record; `PersonalRunLifecycle` 5 methods + 2 controllers converted. `composer gates` green. Status: done. |
