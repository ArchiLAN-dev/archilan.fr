# Story 27.1: Session config domain — value objects, validation, defaults

Status: ready-for-dev

## Story

As the ArchiLAN platform,
I want a typed, validated domain model for the configurable Archipelago server & generation options,
so that every session-launch/generation path resolves the same well-formed config and bad values are
rejected before they ever reach the orchestrateur (a bad flag crashes `ArchipelagoServer`).

## Context

Epic 27 (`_bmad-output/planning-artifacts/epic-27-configurable-session-server-options.md`) introduces
admin-configurable options per session **type** (private / event / weekly) with an optional **per-session
override**. This first story builds the pure domain core that the persistence (27.2), gateway wiring
(27.5) and UI (27.6) all depend on. No I/O, no framework — just value objects, validation, defaults, and
the **per-field override merge** rule.

The options split into two enforcement points (see epic): **server** (launch-time flags) and
**generation** (generator section).

## Acceptance Criteria

1. A `final readonly` value object `SessionServerConfig` (in `App\Sessions\Domain` or a shared
   `App\SessionConfig\Domain` context — see Project Structure Notes) carries the **server** options:
   `releaseMode`, `collectMode`, `remainingMode` (enums), `disableItemCheat` (bool), `hintCost` (int),
   `locationCheckPoints` (int), `countdownMode` (enum), `autoShutdown` (int ≥ 0), `compatibility`
   (enum 0|2), `joinPassword` (?string — the player `password`, never the admin `server_password`).
2. A `final readonly` value object `SessionGenerationConfig` carries the **generation** options:
   `plandoOptions` (set of bosses|items|texts|connections), `race` (bool), `spoiler` (enum 0|1|2|3).
3. Construction validates every field; an invalid value throws a domain exception with a stable code
   (e.g. `\DomainException('invalid_release_mode')`). Enums use the exact Archipelago string values
   (`disabled|enabled|goal|auto|auto-enabled` for release/collect; `enabled|disabled|goal` for
   remaining; `enabled|disabled|auto` for countdown). Ranges: `hintCost` 0–100, `locationCheckPoints`
   ≥ 0, `autoShutdown` ≥ 0.
4. Each config exposes named defaults per session type: **weekly** and **event** = competitive
   (`release=disabled`, `collect=disabled`, `remaining=goal`, `disableItemCheat=true`, `race` per type,
   `spoiler` low for race); **private** = lax (`release=goal`, `collect=goal`, `disableItemCheat=false`,
   `spoiler=3`). Exact default table lives in this story's Dev Notes and is the single source of truth.
5. A pure **merge** method produces the *effective* config: `profile.withOverride(partialOverride)`
   replaces only the fields present in the override (per-field, not whole-object), returning a new
   immutable instance. A "partial override" representation (nullable fields / a `SessionConfigOverride`
   VO) is defined here.
6. The VOs expose a serialisation seam for transport to the orchestrateur (e.g. `toServerFlags(): array`
   / `toGenerationParams(): array`) returning only **set/non-default** values where that matters, so the
   gateway (27.5) and the orchestrateur env mapping stay dumb.
7. Unit tests cover: valid construction, each invalid value → throws, every type default, and the
   per-field merge (override wins per field; absent fields keep the profile value).
8. Quality gates green: phpstan (max), php-cs-fixer (@Symfony), `app:architecture:ddd`, phpunit.

## Tasks / Subtasks

- [ ] Task 1 — Decide context + create skeleton (AC: 1, 2). Either reuse `App\Sessions\Domain` or add a
  new bounded context `SessionConfig` (if new: register in `DddArchitectureValidator::CONTEXTS`, add
  Domain exclusion in `services.yaml` — see api/CLAUDE.md "Adding a new context"). Prefer a dedicated
  `SessionConfig` context since the config is shared by Sessions, WeeklyRuns and PersonalRuns.
- [ ] Task 2 — Implement enums (PHP 8.1 backed enums with the exact AP string values) for release,
  collect, remaining, countdown, compatibility, spoiler; a `PlandoOption` enum + set wrapper.
- [ ] Task 3 — Implement `SessionServerConfig` + `SessionGenerationConfig` (`final readonly`,
  validation in constructor, no setters; AC: 1–3).
- [ ] Task 4 — Implement `SessionConfigOverride` (all fields nullable) + `withOverride()` merge (AC: 5).
- [ ] Task 5 — Implement per-type default factories + the `toServerFlags()`/`toGenerationParams()`
  seams (AC: 4, 6).
- [ ] Task 6 — Unit tests `tests/Unit/SessionConfig/...` (AC: 7) and run all four API gates (AC: 8).

## Dev Notes

- **Pure domain** (api/CLAUDE.md AC-D1..D5): no Symfony, no clock, no I/O. Value objects are
  `final readonly`. Validation throws `\DomainException('<code>')` (same convention as
  `AdminCreateWeeklyTemplate` / `GenerateWeeklyRunForTemplate`).
- **Exact AP value sets** (verified in the bundled source,
  `runner/docs archipelago/Archipelago-main/MultiServer.py` argparse + `settings.py`):
  - release/collect choices: `auto, enabled, disabled, goal, auto-enabled` (AP default `auto`).
  - remaining choices: `enabled, disabled, goal`. countdown: `enabled, disabled, auto`.
  - compatibility: `2` (casual) / `0` (tournament). spoiler: `0|1|2|3`. plando_options subset of
    `bosses, items, texts, connections`.
- **Default table (source of truth):**
  | field | private | event | weekly |
  |-------|---------|-------|--------|
  | releaseMode | goal | disabled | disabled |
  | collectMode | goal | disabled | disabled |
  | remainingMode | goal | goal | goal |
  | disableItemCheat | false | true | true |
  | hintCost | 10 | 10 | 10 |
  | locationCheckPoints | 1 | 1 | 1 |
  | countdownMode | auto | auto | auto |
  | autoShutdown | 0 | 0 | 0 |
  | compatibility | 2 | 2 | 2 |
  | race | false | false | false |
  | spoiler | 3 | 3 | 3 |
  (Adjust during review with Jean; this table is the contract 27.2/27.6 render.)
- Foundation already shipped: release/collect are already accepted by the orchestrateur per session
  (`LaunchRequest.ReleaseMode/CollectMode`) and the archipelago scripts (`RELEASE_MODE`/`COLLECT_MODE`).
  This story models the **full** set; 27.3/27.4 extend the orchestrateur for the rest.

### Project Structure Notes

- New context `SessionConfig` keeps WeeklyRuns/Sessions/PersonalRuns depending on a shared model rather
  than duplicating it. Domain only here; persistence is 27.2.
- Naming per api/CLAUDE.md CQRS: value objects, not commands/queries.

### References

- [Source: _bmad-output/planning-artifacts/epic-27-configurable-session-server-options.md]
- [Source: runner/docs archipelago/Archipelago-main/MultiServer.py (argparse choices)]
- [Source: runner/docs archipelago/Archipelago-main/settings.py (defaults)]
- [Source: api/CLAUDE.md#DDD layer rules]

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

## Change Log

| Date       | Change |
|------------|--------|
| 2026-06-09 | Story created from epic 27 plan (config domain core). |
