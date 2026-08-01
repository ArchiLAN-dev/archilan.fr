# Story 9.45: Editable default YAML template

Status: review
Related: 9.38 (upload preflight), 9.42 (per-slot preflight), 9.46 (reset from apworld)

## Story

As an **admin curating the game library**,
I want **to edit the default YAML template of a game**,
so that **an apworld whose generated template is not a valid configuration can still be
offered to players with a config that actually generates**.

## Context - this is not a test-only concern

Atlyss ships a generated template where `main_class` and `secondary_class` hold the same
value, which the world explicitly rejects (`OptionError: You cannot have the same class
selected for main_class and secondary_class`). The template is not just the preflight's
subject - `game.default_yaml` is what the platform hands to players. It is used in five
places:

- `PersonalRunGameSelection` and `RegistrationGameSelection`: pre-fills the YAML of **every
  new slot**;
- `SessionOrchestrator` (x2) and `LaunchPersonalRunJobHandler`: the **fallback at launch**
  when a player never configured anything
  (`$playerYaml = '' !== $savedYaml ? $savedYaml : $game->getDefaultYaml()`).

So an invalid default means every new slot starts from a config that cannot generate, and a
player who never edits it takes the whole multiworld down at launch. Today the only writer
is `Game::configureApworld()` at upload: there is no way to fix it short of re-uploading a
patched apworld.

## Decision - edit the default itself, not a separate "test config"

A test-only config would let two things that must stay identical drift apart. If the default
fails, it fails for players too; fixing the default fixes both, and the preflight verdict
becomes honest because it tests what we actually ship.

## Acceptance Criteria

**AC1 - Editable:** the admin game page lets an admin edit the game's default YAML and save
it. `Game` gains a dedicated write method (not a setter): the aggregate keeps its BOM strip
and records the edit timestamp.

**AC2 - Validated:** a save is rejected with a field error when the YAML does not parse,
when it has no `game:` key, or when that key does not match the game's
`archipelagoGameName`. Size is bounded (64 KB). The stored value keeps the admin's
formatting and comments otherwise untouched.

**AC3 - One template, one truth:** saving also replaces the template stored next to the
apworld in object storage (`{hash}.yaml`), through a new orchestrator endpoint, so the
upload preflight tests the template we actually ship instead of the original generated one.
When that sync fails the save is still persisted and the admin is told the verdict may be
stale - the player-facing value must never be blocked by the runner.

**AC4 - Verdict stays meaningful:** a successful save re-runs the apworld preflight
automatically, so the admin immediately sees whether the edited config generates. The
existing pending-state polling (story 9.38) surfaces the result without a reload.

**AC5 - Traceability:** the edit is logged (`game.default_yaml_edited`) with the game id and
the actor, and the admin UI states plainly that the template is served to players, not just
used for the test.

**AC6 - Quality gates:** orchestrateur `go test ./...`, api `composer gates`, frontend
`pnpm gates`.

## Tasks / Subtasks

- [x] Task 1: orchestrateur - `PUT /apworlds/{hash}/yaml` replacing the stored template
      (AC3) + Go test.
- [x] Task 2: package `orchestrateur-client` - `updateYamlTemplate(hash, yaml)`, own repo,
      version bump.
- [x] Task 3: api - `Game` write method, Application command with validation (AC1, AC2),
      storage sync + preflight re-run (AC3, AC4), admin endpoint, unit tests.
- [x] Task 4: frontend - editor in the apworld tab: textarea seeded from the current
      template, Save, dirty-state guard, field errors, copy stating players receive it
      (AC1, AC5).
- [x] Task 5: gates (AC6).

## Dev Notes

- Two copies of the template exist by design: `game.default_yaml` (Postgres, what players
  get) and `{hash}.yaml` (object storage, what the 9.38 preflight reads). AC3 keeps them in
  sync; without it a fixed default would keep showing a failed verdict.
- **Known edge case:** if the same apworld file is attached to two games, they share a hash,
  so editing the template for one changes what is tested for the other. Same world, same
  apworld - acceptable, but stated here so it is not discovered later.
- The player-facing seeding path is unchanged: slots keep taking `getDefaultYaml()`, they
  simply get a valid value now. Slots already created keep their stored YAML - the edit
  changes future slots only.
