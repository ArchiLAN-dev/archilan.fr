# Story 9.37: Honor the player's custom slot name (it reaches the AP server)

**Status:** review
**Epic:** 9 - Sessions, bridge & slots
**Date:** 2026-06-29

## Story

As a player who configures an Archipelago slot,
I want the custom name I type in "Nom en jeu" (the YAML `name:`) to be the name used by the AP server,
so that my slot is identified by my chosen name instead of being silently replaced by the derived default
`{pseudo}_{abbr}` (e.g. `masterkafey_LM`).

## Context

There are intentionally **two** names (established in [[9-36-slot-name-validation]]):

- the user-typed YAML `name:` ("Nom en jeu"), validated to `[A-Za-z0-9_]` + the AP placeholders
  `{number}`/`{player}`, capped at 16 chars; and
- the **derived `slotName`** produced by `SlotNameGenerator` (`{sanitizedPseudo}_{gameAbbr}`, 16-char cap,
  collision suffixes), which is treated as the AP-authoritative name.

The custom name is saved verbatim in the slot's `playerYaml` and even validated on save
(`PersonalRunGameSelection::saveSlotYaml`, `RegistrationGameSelection::saveSlotYaml`). But it is **never
used**: at validate/launch the pipeline regenerates the derived name and discards the YAML `name:`.

Bug trace (issue #251):
1. `SlotNameGenerator::generate()` unconditionally computes `{pseudo}_{abbr}`
   [Source: api/src/Sessions/Application/SlotNameGenerator.php:20-52].
2. That value is written to `SessionSlot.slotName`
   [Source: api/src/Sessions/Application/SessionOrchestrator.php:227-231;
   api/src/PersonalRuns/Application/Handler/LaunchPersonalRunJobHandler.php:98-121].
3. `RunnerGateway::buildPlayerYaml()` rebuilds the staged YAML from `$slotName` and ignores `$parsed['name']`
   [Source: api/src/Sessions/Infrastructure/RunnerGateway.php:262-279], so the runner receives
   `name: masterkafey_LM`.

`SessionSlot.slotName` is **not display-only**: it is an authoritative key downstream - patch-file lookup
keys off it ("the resolved slot name used by the AP server to name the patch files")
[Source: api/src/PersonalRuns/Application/PersonalRunPatchQuery.php:47-52], plus the bridge/results path.
Therefore the fix must make a **single effective name** consistent everywhere - it is not enough to read
`$parsed['name']` inside `buildPlayerYaml`.

## Goal / approach

Resolve one **effective slot name** per slot at validate/launch time and write it to `SessionSlot.slotName`
(which then flows unchanged into `buildPlayerYaml` and all downstream consumers):

- prefer the player's YAML `name:` when it is a **literal custom name** (valid per `SlotName`, non-empty,
  contains **no** AP placeholder, and is not the unconfigured default);
- otherwise fall back to the derived `{pseudo}_{abbr}` (current behavior).

Keep the two existing safety nets from `SlotNameGenerator` for the whole resulting set: **uniqueness**
(collision suffix, AP requires unique slot names in a multiworld) and the **16-char cap**.

Names containing `{number}`/`{player}` keep falling back to the derived name on purpose: those resolve
AP-side to a different literal, which would desync `SessionSlot.slotName` from the AP-generated patch
filename (see Scope boundaries).

## Acceptance Criteria

1. A player who sets a literal custom `name:` (valid `SlotName`, no placeholder, not the default) sees that
   exact name as their AP slot name end to end: it is written to `SessionSlot.slotName` and appears as the
   staged YAML `name:` sent to the runner - for **both** pipelines (event sessions via `SessionOrchestrator`
   and personal runs via `LaunchPersonalRunJobHandler`).
2. When the YAML `name:` is empty, invalid, an AP-placeholder name (`{number}`/`{player}`), or equal to the
   unconfigured default, the slot falls back to the derived `{pseudo}_{abbr}` exactly as today (no
   behavior change for unconfigured slots).
3. Uniqueness is preserved across all slots of a session: two players choosing the same custom name (or a
   custom name colliding with a derived one) get deterministic collision suffixes; no two slots in one
   session share a name.
4. The effective name respects the 16-char AP cap (a custom name is already ≤16 by the 9.36 field rule;
   the resolver must still guarantee it after suffixing).
5. `RunnerGateway::buildPlayerYaml` stays correct with no special-casing: because `SessionSlot.slotName`
   now equals the effective name, the staged `name:` matches it. The user's `game` + options are still
   copied verbatim (unchanged).
6. Downstream consistency: `PersonalRunPatchQuery` (patch lookup) and the bridge/results resolve the same
   effective name (covered by a test that a custom-named personal-run slot's patch is found).
7. Existing derived-name behavior is unchanged for every slot that does not set a literal custom name
   (regression-guarded by the existing `SlotNameGeneratorTest` cases staying green).
8. Gates green: API (`phpstan`, `php-cs-fixer`, `phpunit` 0-notice, `app:architecture:ddd`); frontend
   (`typecheck`, `lint`, `build`) if any FE change is needed (none expected - the field already exists).

## Tasks / Subtasks

- [x] **Backend: resolver** (AC 1, 2, 3, 4, 7)
  - [x] `SlotNameGenerator::generate()` now accepts `preferredName?: string|null` per slot. A usable
    literal custom name becomes the base; otherwise `{pseudo}_{abbr}` as before. Existing collision +
    16-char passes run unchanged over the resulting bases.
  - [x] Private predicate `isUsableCustomName()`: `!str_contains($name, '{') && SlotName::isValid($name)`.
    The placeholder check excludes the default `Player{number}` and any AP-placeholder name in one go.
- [x] **Backend: event pipeline** (AC 1, 2)
  - [x] `SessionOrchestrator::orchestrateValidate` passes `'preferredName' => SlotYamlNameReader::read($s['playerYaml'])`
    into the generator input; resolved names still flow to `SessionSlot.slotName` + `configureSlots`. No
    change to `buildPlayerYaml`.
- [x] **Backend: personal-run pipeline** (AC 1, 2, 6)
  - [x] `LaunchPersonalRunJobHandler` feeds `preferredName` from each slot's saved YAML; resolved name
    persists on `SessionSlot` and flows to `configureSession`.
- [x] **Backend: YAML name extraction helper** (AC 1, 2)
  - [x] New `App\Shared\Application\SlotYamlNameReader::read()` (BOM-safe parse + `name:` string read),
    shared by both pipelines. Mirrors the `saveSlotYaml` / `buildPlayerYaml` parse.
- [~] **Backend: patch attribution** (AC 6) - **expanded scope (necessary):** honoring custom names broke
  the documented invariant in `PersonalRunPatchController::belongsToOwnSlot` ("every slot name has one
  underscore, so none is a `_`-boundary prefix of another"). Custom names like `master` vs `master_kafey`
  violate it, which would let one player download another's patch. Fixed by attributing each file to the
  **single longest** matching name among ALL session slots and granting access only if that winner is the
  caller's. `PersonalRunPatchQuery::forParticipant` now also returns `allSlotNames`.
- [x] **Backend: tests** (AC 1-7)
  - [x] Unit `SlotNameGeneratorTest`: preferred honored; fallback (empty/invalid/placeholder/over-16);
    identical-custom collision; custom-vs-derived collision. `SlotYamlNameReaderTest` (6 cases).
  - [x] `LaunchPersonalRunJobHandlerTest`: literal custom name reaches `configureSlots[0]['slotName']`
    end-to-end (via `NullRunnerGateway` capture). `PersonalRunPatchFilterTest`: 3 cross-name cases (no
    cross-player grab; own patch still matched). `PersonalRunPatchQueryTest`: `allSlotNames` returned.
- [x] **Gates** (AC 8)
  - [x] `php-cs-fixer` 0, `phpstan` 0, `phpunit` 542 unit + 42 affected functional (0 notices),
    `app:architecture:ddd` exit 0. No frontend change needed (field + validation exist from 9.36).

## Dev Notes

### Reuse, don't reinvent
- The collision + 16-char logic already lives in `SlotNameGenerator` (passes 1-3). Thread a preferred name
  into pass 1 instead of writing a parallel resolver. [Source: api/src/Sessions/Application/SlotNameGenerator.php:20-52]
- The `name:` validation rule and the 16-char cap are already centralized in `App\Shared\Domain\SlotName`
  (PHP) from [[9-36-slot-name-validation]]; reuse `SlotName::isValid` - do not re-derive a regex. [Source: api/src/Shared/Domain/SlotName.php]
- BOM-safe YAML parsing + `name:` read already exist in `RunnerGateway::buildPlayerYaml` and the two
  `saveSlotYaml` services; reuse that pattern so the resolver, the save-time validator and the runner agree.

### Architecture guardrails
- `SlotNameGenerator` is Application and **pure** (no clock/IO) - keep it that way; pass the YAML name in as
  data, parse it in the orchestrator/handler (which already hold the `playerYaml`). [Source: api/CLAUDE.md AC-D3, AC-A]
- No new context; `Sessions` and `PersonalRuns` are existing. No DB schema change: `SessionSlot.slotName`
  already stores the authoritative name - we only change which value is computed into it.
- Keep `buildPlayerYaml` untouched beyond what AC 5 implies (it already emits `$slotName`); the fix is the
  upstream value, not a second source of truth. [Source: api/src/Sessions/Infrastructure/RunnerGateway.php:238-279]

### Scope boundaries
- **In:** literal custom names (no AP placeholder) honored end to end, with uniqueness + 16-char safety nets;
  derived fallback otherwise.
- **Out:** resolving AP placeholders (`{number}`/`{player}`) into the authoritative `slotName`. A
  placeholder name resolves AP-side to a different literal than the stored string, which would desync the
  patch-file lookup key; such names keep using the derived name. Revisit only if players ask for
  placeholder-templated authoritative names (needs AP-side resolution wired into patch lookup + bridge).
- **Out:** retro-fixing already-launched sessions; the change applies at the next validate/launch.

### Open questions (resolve before/at dev start)
- Exact "unconfigured default" sentinel for the YAML `name:`: confirm what the frontend serializer writes
  for a fresh slot (`archipelago-yaml.ts` writes `doc["name"] = parsed.playerName`; 9.36 references a
  `Player{number}` default). The fallback predicate must treat that default as "no custom name". Verify the
  literal before coding so AC 2 is exact. [Source: frontend/src/lib/archipelago-yaml.ts:681-704]
- Collision determinism when mixing custom + derived bases: confirm suffix order is stable (slot index
  order) so re-validation is idempotent.

### Project Structure Notes
- Modified (api): `Sessions/Application/SlotNameGenerator.php` (preferred-name input), `Sessions/Application/SessionOrchestrator.php`,
  `PersonalRuns/Application/Handler/LaunchPersonalRunJobHandler.php`; tests `Unit/Sessions/SlotNameGeneratorTest.php`
  + a functional case (custom name end-to-end + patch lookup). Possibly a small shared `name:` reader.
- No migration, no frontend change expected (the "Nom en jeu" field + validation already exist from 9.36).

### References
- Issue: ArchiLAN-dev/archilan.fr#251 (Nom de slot override par la configuration par défaut).
- Prior art: [[9-36-slot-name-validation]] (the `SlotName` rule + the two-name model); 9.18 session-configure
  (origin of `SlotNameGenerator`).
- Bug points: `SlotNameGenerator.php:20-52`, `SessionOrchestrator.php:222-247`,
  `LaunchPersonalRunJobHandler.php:88-152`, `RunnerGateway.php:238-279`, `PersonalRunPatchQuery.php:47-52`.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Completion Notes List

- One effective slot name is resolved at validate/launch and written to `SessionSlot.slotName`, so every
  downstream consumer (staged YAML, patch lookup, bridge) stays consistent without touching
  `buildPlayerYaml`. The custom YAML `name:` wins when it is a valid literal with no AP placeholder;
  otherwise the derived `{pseudo}_{abbr}` is used. Collision suffixing + the 16-char cap apply to both.
- **Expanded scope (AC 6):** the patch-file attribution in `PersonalRunPatchController::belongsToOwnSlot`
  relied on the "one underscore per generated name" invariant that custom names break. Reworked it to a
  longest-match-among-all-session-slots attribution (and `PersonalRunPatchQuery` now returns `allSlotNames`)
  so a player can never grab another's patch when their custom name is a `_`-prefix of it. The existing
  `belongsToOwnSlot` tests stay green because `$allSlotNames` defaults to `$ownSlotNames`.
- Implemented on branch `feature/epic-9-story-37-custom-slot-name` (from `develop`). Note: 9.37 depends on
  9.36 (`SlotName` VO), which is on develop but not yet on `main` - so this is a feature → develop, not a
  hotfix to prod.
- Out of scope (documented): AP placeholder names (`{number}`/`{player}`) keep using the derived name, since
  they resolve AP-side to a different literal than the patch-lookup key.

### File List

**Added (api)**
- `api/src/Shared/Application/SlotYamlNameReader.php`
- `api/tests/Unit/Shared/SlotYamlNameReaderTest.php`

**Modified (api)**
- `api/src/Sessions/Application/SlotNameGenerator.php` (preferredName input + `isUsableCustomName`)
- `api/src/Sessions/Application/SessionOrchestrator.php` (event pipeline feeds preferredName)
- `api/src/PersonalRuns/Application/Handler/LaunchPersonalRunJobHandler.php` (personal-run pipeline)
- `api/src/PersonalRuns/Application/PersonalRunPatchQuery.php` (returns `allSlotNames`)
- `api/src/PersonalRuns/Presentation/PersonalRunPatchController.php` (longest-match attribution)
- `api/tests/Unit/Sessions/SlotNameGeneratorTest.php`, `api/tests/Unit/PersonalRuns/LaunchPersonalRunJobHandlerTest.php`,
  `api/tests/Unit/PersonalRuns/PersonalRunPatchFilterTest.php`, `api/tests/Unit/PersonalRuns/PersonalRunPatchQueryTest.php`

### Validation Results

- `vendor/bin/phpstan analyse src tests`: 0 errors.
- `vendor/bin/php-cs-fixer check src` + modified tests: 0 violations.
- `php bin/console app:architecture:ddd`: exit 0.
- `php bin/phpunit tests/Unit`: 542 tests OK (0 notices). Affected functional (Patch / PersonalRunLifecycle /
  RegistrationSlotYaml / SessionConfigure): 42 tests OK.

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-29 | Story drafted from issue #251 investigation (root cause: derived `slotName` overrides the YAML `name:` in both pipelines; `SessionSlot.slotName` is an authoritative downstream key). Status → draft. |
| 2026-06-29 | Implemented: resolver honors literal custom names with derived fallback; both pipelines feed the YAML `name:`; patch attribution hardened (longest-match) against custom-name prefix collisions. Gates green. Status → review. |
