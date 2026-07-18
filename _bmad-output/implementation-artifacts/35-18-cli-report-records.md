# Story 35.18: Typed report records - CatalogSync / GameSelection CLI commands (api/)

Status: done

## Story

As a maintainer continuing epic 35 Stage 2,
I want the six report-returning maintenance commands (`SeedGameTutorials`, `CheckApworldUpdatesService`,
`BackfillApworldDeployedVersionService`, `BackfillGameOptionTypes`, `BackfillGamePlatforms`,
`BackfillSteamAppIds`) to return `final readonly` report records instead of associative arrays, so that the
CLI/maintenance command returns are typed for full consistency, with console + HTTP output unchanged.

## Context

Epic 35, Stage 2. These are the maintenance/console commands whose run returns a small report the console
command (and, for the apworld check, an admin HTTP endpoint) prints:

- `SeedGameTutorials::run` -> `array{processed, seeded}`.
- `CheckApworldUpdatesService::checkAll` -> `array{checked, rateLimitHit}` (also read by `AdminCatalogSyncController`).
- `BackfillApworldDeployedVersionService::backfill` -> `array{matched, unmatched, total, unmatchedGames, rateLimitHit}`.
- `BackfillGameOptionTypes` / `BackfillGamePlatforms` / `BackfillSteamAppIds` `::run` -> identical
  `array{processed, updated}` (shared record).

All build their report inline (no read-model delegation), so they convert cleanly to colocated records.

**`ForceEndSessionCommand` deliberately split out.** The epic listed it here, but it returns
`$session->payload()` - the shared **domain** session view used across `SessionLifecycleManager`,
`PlayerSessionConnection`, and `SessionResultsQuery`. Typing it means typing the Session read-model, which is a
separate chantier and is complicated by `Session::payload()` being a Domain method (a Domain method cannot
return an Application record). It gets its own story before the validator rule lands.

## Acceptance Criteria

1. **AC1 - Records (colocated).** `TutorialSeedReport {processed, seeded}` + `GameBackfillReport {processed,
   updated}` (shared by the three game backfills) in `GameSelection/Application/Command/`;
   `ApworldUpdateCheckReport {checked, rateLimitHit}` + `ApworldDeployedVersionBackfillReport {matched, unmatched,
   total, list<string> unmatchedGames, rateLimitHit}` in `CatalogSync/Application/Command/`.
2. **AC2 - Services converted.** All six methods return the record; `array{...}` docblocks dropped.
3. **AC3 - Consumers read the record.** The six console commands, plus `AdminCatalogSyncController` (apworld
   check endpoint), read `->field`. Console output + the HTTP `data.{checked,rateLimitHit}` body byte-identical.
4. **AC4 - Tests.** The backfill unit tests (`BackfillApworldDeployedVersionServiceTest`,
   `BackfillGameOptionTypesTest`, `BackfillGamePlatformsTest`, `BackfillSteamAppIdsTest`) read `->field`.
5. **AC5 - Gates.** `composer gates` green. Full isolated suite green.

## Tasks / Subtasks

- [x] Task 1: four report records (AC: 1).
- [x] Task 2: convert the six service methods (AC: 2).
- [x] Task 3: six console commands + `AdminCatalogSyncController` read the records (AC: 3).
- [x] Task 4: backfill unit tests read the records (AC: 4).
- [x] Task 5: verify + ship (AC: 5). PR to `develop`.

## Dev Notes

- **Shared `GameBackfillReport`.** The three game-catalogue backfills emit the identical `{processed, updated}`
  shape; one record serves all three (colocated in `GameSelection/Application/Command/`).
- **No imports needed in the services** - each record is colocated in the same namespace as its command(s).
- **`CheckApworldUpdatesService` has an HTTP consumer too.** `AdminCatalogSyncController` reads
  `->checked`/`->rateLimitHit` to build `data`; the body is byte-identical (explicit field rebuild).
- **`BackfillActivity` excluded** - it returns `int` (a scalar count), not an array, so it is already
  Stage-2-clean and out of scope.
- House rules: `final readonly`, strict types, phpstan max. `composer gates`.

### References

- Convention source: 35.8-35.17 records; epic Stage 2 breakdown (35.18 = Sessions + CLI reports, minus the
  split-out `ForceEndSessionCommand`).
- Convert (services): `SeedGameTutorials`, `BackfillGameOptionTypes`, `BackfillGamePlatforms`, `BackfillSteamAppIds`
  (GameSelection); `CheckApworldUpdatesService`, `BackfillApworldDeployedVersionService` (CatalogSync).
- New records: `TutorialSeedReport`, `GameBackfillReport`, `ApworldUpdateCheckReport`,
  `ApworldDeployedVersionBackfillReport`.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List

- Four colocated report records; six service methods return them. `GameBackfillReport` shared by the three game
  backfills. `array{...}` docblocks dropped.
- Six console commands + `AdminCatalogSyncController` read `->field`; console + HTTP output byte-identical.
- Four backfill unit tests read `->field` (no other test read a converted report - `SeedGameTutorials` /
  `CheckApworldUpdatesService` had no unit test reading the shape).
- `ForceEndSessionCommand` split to its own story (delegates to the shared domain `Session::payload()`).
- `composer gates` green: phpstan max 0 (at `src tests` scope), cs-fixer 0, ddd OK, rector OK, phpunit green
  (full isolated suite).

### File List

- `api/src/GameSelection/Application/Command/TutorialSeedReport.php` (new)
- `api/src/GameSelection/Application/Command/GameBackfillReport.php` (new)
- `api/src/CatalogSync/Application/Command/ApworldUpdateCheckReport.php` (new)
- `api/src/CatalogSync/Application/Command/ApworldDeployedVersionBackfillReport.php` (new)
- `api/src/GameSelection/Application/Command/{SeedGameTutorials,BackfillGameOptionTypes,BackfillGamePlatforms,BackfillSteamAppIds}.php` (return records)
- `api/src/CatalogSync/Application/Command/{CheckApworldUpdatesService,BackfillApworldDeployedVersionService}.php` (return records)
- `api/src/GameSelection/Presentation/Command/{SeedGameTutorials,BackfillGameOptionTypes,BackfillGamePlatforms,BackfillSteamAppIds}Command.php` (read records)
- `api/src/CatalogSync/Presentation/Command/{CheckApworldUpdates,BackfillApworldDeployedVersion}Command.php` (read records)
- `api/src/CatalogSync/Presentation/Controller/AdminCatalogSyncController.php` (reads the record)
- `api/tests/Unit/CatalogSync/BackfillApworldDeployedVersionServiceTest.php` + `api/tests/Unit/GameSelection/{BackfillGameOptionTypes,BackfillGamePlatforms,BackfillSteamAppIds}Test.php` (read records)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-18 | Story created + implemented (epic 35 Stage 2). Four report records; six CatalogSync/GameSelection maintenance commands typed; console + HTTP consumers + backfill tests updated. `ForceEndSessionCommand` split out (domain `Session::payload()`). `composer gates` green. Status: done. |
