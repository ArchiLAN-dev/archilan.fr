# Story 12.2: Default YAML Fallback for Unconfigured Slots

## Story

**As a** player who added a game to my registration without customising its options,
**I want** the game's default YAML to be used automatically at session generation,
**So that** my slot generates successfully without me having to open and save the editor first.

## Status

review

## Context (bug)

The registration recap explicitly promises *"Si tu ne configures pas les options d'un jeu,
les valeurs par défaut seront utilisées"* and renders a slot with no saved `playerYaml` as
"Par défaut". The session builder does **not** honour that promise: when a slot has no saved
YAML, `SessionOrchestrator` sends an empty/`null` YAML to the runner instead of the game's
`defaultYaml`, so multiworld generation fails until the player opens the editor and saves
(which persists a non-empty `playerYaml`). Reported on private events but the defect is
general — it affects any slot left unconfigured.

Two collection points in `SessionOrchestrator` drop the default:

- `enrichSlotsForValidation()` (feeds `configureSession` → real generation):
  `… ? $regSlot['playerYaml'] : ''` — empty string when unsaved.
- `buildPreflightSlotsForCreation()` (feeds `preflight` validation):
  `'playerYaml' => $registrationSlot['playerYaml'] ?? null` — null when unsaved.

The `Game` aggregate is already loaded at both sites and exposes `getDefaultYaml(): ?string`.

## Acceptance Criteria

**AC1:** In `enrichSlotsForValidation()`, when a slot's saved `playerYaml` is null or empty, the YAML passed to the runner falls back to the slot's game `getDefaultYaml()` (empty string only if the game itself has no default).

**AC2:** In `buildPreflightSlotsForCreation()`, the same fallback applies to the `playerYaml` sent for preflight.

**AC3:** A slot **with** a saved `playerYaml` is unchanged — the saved value is always used as-is (custom config wins).

**AC4:** Functional coverage: validating a session whose slot has no saved YAML sends the game's `defaultYaml` to `configureSession` (not `''`). A slot with a saved YAML sends the saved YAML.

**AC5:** All API quality gates pass (phpstan, php-cs-fixer, phpunit, `app:architecture:ddd`).

## Tasks / Subtasks

- [x] Task 1: `enrichSlotsForValidation()` — fall back to `$game?->getDefaultYaml()` when saved YAML is null/empty.
- [x] Task 2: `buildPreflightSlotsForCreation()` — same fallback (replace the `?? null`).
- [x] Task 3: `NullRunnerGateway` — record the last `configureSession` slots (static, reset-able) so tests can assert the YAML sent.
- [x] Task 4: Functional test in `RunnerValidatePipelineTest`: unconfigured slot → default YAML forwarded; configured slot → saved YAML forwarded.
- [x] Task 5: Run all API quality gates — green (phpunit 911/911, phpstan/cs-fixer/DDD clean).

## Dev Notes

### Fallback expression

```php
// enrichSlotsForValidation()
$saved = is_string($regSlot['playerYaml'] ?? null) ? $regSlot['playerYaml'] : '';
$playerYaml = '' !== $saved ? $saved : ($game?->getDefaultYaml() ?? '');
```

```php
// buildPreflightSlotsForCreation()
$saved = is_string($registrationSlot['playerYaml'] ?? null) ? $registrationSlot['playerYaml'] : '';
$playerYaml = '' !== $saved ? $saved : ($game instanceof Game ? ($game->getDefaultYaml() ?? '') : '');
// 'playerYaml' => $playerYaml,
```

### Why not persist default at slot creation

The recap and the slot editor both treat `playerYaml === null` as "use default" (the editor
seeds from `defaultYaml` when `playerYaml` is null; the recap badges "Par défaut"). Persisting
the default into `playerYaml` at creation would break the "custom vs default" distinction and
duplicate the template into every row. The fallback at generation time keeps `null = default`
semantics intact and is the minimal, localised fix (Application layer, where `Game` is loaded).

### Test double

`NullRunnerGateway` already exposes a static result hook (`$apworldUploadResult` + `reset()`);
add a `public static ?array $lastConfigureSlots` recorded in `configureSession()` and cleared in
`reset()`. No services.yaml change — `NullRunnerGateway` is already the `when@test` runner.
The test reads `NullRunnerGateway::$lastConfigureSlots` after the validate request.

## File List

### API
- `api/src/Sessions/Application/SessionOrchestrator.php` — modified (two fallback sites)
- `api/src/Sessions/Infrastructure/NullRunnerGateway.php` — modified (record configure slots)
- `api/tests/Functional/RunnerValidatePipelineTest.php` — modified (default-fallback cases)

## Change Log

| Date       | Change                                                                 |
|------------|------------------------------------------------------------------------|
| 2026-06-06 | Story created — fixes unconfigured slots generating with empty YAML instead of the game default, honouring the contract already shown in the recap UI. |
