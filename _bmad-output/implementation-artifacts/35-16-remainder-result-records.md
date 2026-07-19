# Story 35.16: Typed result records - remaining scattered write commands (api/)

Status: done

## Story

As a maintainer continuing epic 35 Stage 2,
I want the six remaining array-returning write commands across Events / SessionConfig / WeeklyRuns to return
`final readonly` result records instead of associative arrays, so that the last non-Community command-return
arrays are typed, with every HTTP body unchanged.

## Context

Epic 35, Stage 2. After the admin read-model stories (35.14 Content, 35.15 Events), the remaining
array-returning commands are scattered small ones that build their result inline (no read-model delegation):

- `VerifyPrivateEventAccess::verify` (Events) -> `array{granted: bool}|null`.
- `AdminUpdateSessionConfig::execute` (SessionConfig) -> `array{type, config}`.
- `AdminCreateWeeklyTemplate::execute` / `AdminUpdateWeeklyTemplate::execute` (WeeklyRuns) -> identical
  `array{id, name, gameId, gameName, yamlConfig, maxAttempts, isActive}` (shared record).
- `OptInToWeeklyRun::execute` (WeeklyRuns) -> `array{id, weeklyRunId, userId, attemptNumber}`.
- `LaunchWeeklyEntry::execute` (WeeklyRuns) -> `array{entryId, externalSessionId, connectionInfo: {host, port,
  password}}`.

All six are the same mechanical pattern (inline shape -> colocated record, controller pass-through), so they
batch cleanly.

**`UpdateCommunityProfile` deliberately split to 35.17.** Its return is `array{errorCode: string|null, errors}`
- a validation-outcome discriminant, not a result payload. Typing it cleanly is a Stage-1-style change (throw a
`ValidationException`, return `void`, drop the controller's `errorCode` branching), a different strategy from
these record conversions, so it gets its own story.

## Acceptance Criteria

1. **AC1 - Records (colocated in each command's `Application/Command/`).** `PrivateAccessResult {bool granted}`
   (Events); `SessionConfigResult {string type, array<string,mixed> config}` (SessionConfig);
   `WeeklyTemplateResult {id, ?name, gameId, gameName, yamlConfig, ?maxAttempts, isActive}` shared by the two
   template commands; `WeeklyOptInResult {id, weeklyRunId, userId, attemptNumber}`; `LaunchedEntry {entryId,
   externalSessionId, array{host,port,password} connectionInfo}` (WeeklyRuns).
2. **AC2 - Commands converted.** All six methods return the record (`?PrivateAccessResult` / `?WeeklyTemplateResult`
   keep their nullability); `array{...}` / `array<string,mixed>` docblocks dropped. Domain throws unchanged.
3. **AC3 - Controllers byte-identical.** Every controller passes the record straight to `['data' => ...]`; the
   records serialize via `json_encode` to the former arrays. `LaunchedEntry.connectionInfo` is the gateway's
   contract array, passed through verbatim (byte-safe). No controller change.
4. **AC4 - Test.** `LaunchWeeklyEntryTest` (the only unit test reading the result shape) reads `$result->entryId`
   / `->externalSessionId` / `->connectionInfo['host']`. No other test reads a converted result.
5. **AC5 - Gates.** `composer gates` green. Full isolated suite green.

## Tasks / Subtasks

- [x] Task 1: five records (AC: 1).
- [x] Task 2: convert the six command methods (AC: 2).
- [x] Task 3: confirm controllers need no change (AC: 3).
- [x] Task 4: `LaunchWeeklyEntryTest` reads the record (AC: 4).
- [x] Task 5: verify + ship (AC: 5). PR to `develop`.

## Dev Notes

- **Shared `WeeklyTemplateResult`.** The create and update template commands emit the identical 7-field shape;
  one record serves both (colocated in `Application/Command/`).
- **`LaunchedEntry.connectionInfo` stays a typed array.** The connection info is the orchestrator gateway's
  contract shape, returned to the command as an array and passed through verbatim - kept as an
  `array{host, port, password}` field (not a nested record) so serialization is byte-identical and no field
  order can drift. The record types the top level; the gateway owns the connection shape.
- **`SessionConfigResult.config`** likewise carries the SessionConfig VO's `toArray()` output as an
  `array<string, mixed>` field (the VO owns that shape); the record types the `{type, config}` envelope.
- **No controller edits.** All six controllers already do `['data' => $data]` / `['data' => $result]`;
  json_encode of the records (public props in declaration order = former array key order) is byte-identical.
  phpstan confirmed no consumer read the array keys except `LaunchWeeklyEntryTest`.
- **Domain throws untouched.** The WeeklyRuns commands throw `\DomainException` (mapped by their controllers) and
  `AdminUpdateSessionConfig` throws on invalid VO input - Stage 2 only types the success return; the throw paths
  are out of scope here.
- House rules: `final readonly`, strict types, phpstan max. `composer gates`.

### References

- Convention source: 35.8-35.15 records; epic Stage 2 breakdown (35.16 = scattered remainder).
- Convert: `src/Events/Application/Command/VerifyPrivateEventAccess.php`,
  `src/SessionConfig/Application/Command/AdminUpdateSessionConfig.php`,
  `src/WeeklyRuns/Application/Command/{AdminCreateWeeklyTemplate,AdminUpdateWeeklyTemplate,OptInToWeeklyRun,LaunchWeeklyEntry}.php`.
- New: `PrivateAccessResult`, `SessionConfigResult`, `WeeklyTemplateResult`, `WeeklyOptInResult`, `LaunchedEntry`.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List

- Five colocated records; six command methods return them (`?PrivateAccessResult` / `?WeeklyTemplateResult`
  keep nullability). `array{...}` docblocks dropped; domain throws unchanged.
- `WeeklyTemplateResult` shared by create/update template commands. `LaunchedEntry.connectionInfo` +
  `SessionConfigResult.config` keep their contract/VO array shapes as typed array fields (byte-safe pass-through).
- No controller change (all pass-through, byte-identical). `LaunchWeeklyEntryTest` reads the record; no other
  test touched.
- `UpdateCommunityProfile` split to 35.17 (outcome->throw, different strategy).
- `composer gates` green: phpstan max 0 (at `src tests` scope), cs-fixer 0, ddd OK, rector OK, phpunit green
  (full isolated suite).

### File List

- `api/src/Events/Application/Command/PrivateAccessResult.php` (new)
- `api/src/Events/Application/Command/VerifyPrivateEventAccess.php` (returns `?PrivateAccessResult`)
- `api/src/SessionConfig/Application/Command/SessionConfigResult.php` (new)
- `api/src/SessionConfig/Application/Command/AdminUpdateSessionConfig.php` (returns `SessionConfigResult`)
- `api/src/WeeklyRuns/Application/Command/WeeklyTemplateResult.php` (new)
- `api/src/WeeklyRuns/Application/Command/AdminCreateWeeklyTemplate.php` (returns `WeeklyTemplateResult`)
- `api/src/WeeklyRuns/Application/Command/AdminUpdateWeeklyTemplate.php` (returns `?WeeklyTemplateResult`)
- `api/src/WeeklyRuns/Application/Command/WeeklyOptInResult.php` (new)
- `api/src/WeeklyRuns/Application/Command/OptInToWeeklyRun.php` (returns `WeeklyOptInResult`)
- `api/src/WeeklyRuns/Application/Command/LaunchedEntry.php` (new)
- `api/src/WeeklyRuns/Application/Command/LaunchWeeklyEntry.php` (returns `LaunchedEntry`)
- `api/tests/Unit/WeeklyRuns/LaunchWeeklyEntryTest.php` (reads the record)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-18 | Story created + implemented (epic 35 Stage 2). Five result records; six scattered write commands (Events/SessionConfig/WeeklyRuns) typed. `UpdateCommunityProfile` split to 35.17. `composer gates` green. Status: done. |
