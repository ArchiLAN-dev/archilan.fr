# Story 9.36: Validate slot/player names (charset + length)

**Status:** review
**Epic:** 9 - Sessions, bridge & slots
**Date:** 2026-06-28

## Story

As the platform,
I want slot/player names (the YAML `name:`) restricted to letters, digits, underscore and the AP
placeholders `{number}`/`{player}`, capped at 16 chars,
so that special characters (apostrophes, accents, spaces, ...) can't reach Archipelago - they break
generation and the in-game / text-client display. (Players currently end up with names like `O'Brien`.)

## Context

Two names exist: the user-typed YAML `name:` (free-form today, the source of the apostrophes) and the
derived `slotName` (sanitized to `[A-Za-z0-9]`, which also stripped `_`). There was no charset
validation at any entry point - names were silently stripped (dropping `_` too) or deferred to AP.

The rule (confirmed with Jean): allow `[A-Za-z0-9_]` plus the AP placeholders `{number}`/`{player}`
(and uppercase variants, needed by the default name `Player{number}`); max length 16; reject everything
else at entry rather than silently mutating.

## Acceptance Criteria

1. Canonical rule, mirrored front + back: name matches `^(?:[A-Za-z0-9_]|\{(?:number|player|NUMBER|PLAYER)\})+$`
   and length ≤ 16. (`SlotName` in PHP, `isValidSlotName` in TS.)
2. **Backend (authoritative)**: the slot-YAML save endpoints reject an invalid `name:` (422) -
   `PersonalRunGameSelection::saveSlotYaml` and `RegistrationGameSelection::saveSlotYaml`.
3. **Frontend**: the "Nom en jeu" field shows a precise error and blocks save (Save button path **and**
   the imperative `validate()` used by template/onChange surfaces); input `maxLength` lowered to 16.
4. Underscore is preserved everywhere (it was being stripped): `SlotNameGenerator::sanitize` and the
   runner's `sanitize_player_name` now keep `_` (and the admin session-page preview).
5. The default name `Player{number}` and templated names stay valid.
6. Gates green: API (`phpstan` / `php-cs-fixer` / `phpunit` / `ddd`), frontend (`typecheck` / `lint` /
   `build` + jest), runner (`ruff` / `pytest`; changed file mypy-clean).

## Tasks / Subtasks

- [x] **Task 1** (AC 1). `App\Shared\Domain\SlotName` (PATTERN + MAX_LENGTH + isValid); TS
  `isValidSlotName` / `SLOT_NAME_MAX_LENGTH` in `archipelago-yaml.ts`.
- [x] **Task 2** (AC 2). Parse the YAML `name:` in both `saveSlotYaml` services and reject invalid (422).
- [x] **Task 3** (AC 3). `slotNameError` in the editor; wired into `handleSave`, `runValidation`,
  input onChange/onBlur, error copy; `maxLength=16`.
- [x] **Task 4** (AC 4). Allow `_` in `SlotNameGenerator::sanitize`, runner `sanitize_player_name`,
  admin session-page `sanitizePlayerName`.
- [x] **Task 5** (AC 6). Tests: `SlotNameTest`, updated `SlotNameGeneratorTest`, `RegistrationSlotYamlTest`
  reject case, runner `test_sanitize_keeps_underscore`, frontend `archipelago-slot-name.test.ts`. Gates green.

## Dev Notes

- Validation **rejects** (not silently strips) at the entry points (frontend + saveSlotYaml) so the
  player fixes the name. The generator's `sanitize` remains a strip (it derives a slotName from the
  server display name, which has no placeholders) - now keeping `_`.
- Existing stored YAMLs with bad names are not auto-migrated: a player must re-save to clear the error.
  A further backstop (reject/sanitize the verbatim `name:` in the runner `/yamls` write, and a charset
  assertion in `SessionOrchestrator::validateSlots`) is a possible defense-in-depth follow-up - the two
  entry-point gates already block all new bad names.
- Length is checked on the literal string (16, AP's limit). A placeholder-heavy name can exceed 16
  literally though it resolves shorter; that's the accepted trade-off for a simple, consistent cap.
- Drive-by: fixed a pre-existing phpstan error in `PlayerStateTest.php` (offset on mixed) from story 9.34,
  missed then because only `src` was analysed.

### Project Structure Notes

- `api/src/Shared/Domain/SlotName.php` (new)
- `api/src/Sessions/Application/SlotNameGenerator.php`
- `api/src/PersonalRuns/Application/PersonalRunGameSelection.php`, `api/src/Registrations/Application/RegistrationGameSelection.php`
- `frontend/src/lib/archipelago-yaml.ts`, `frontend/src/features/events/yaml-option-editor.tsx`, `frontend/src/features/admin/admin-session-page.tsx`
- `runner/app/slot_names.py`

### References

- [Source: api/src/Sessions/Application/SlotNameGenerator.php (derived slotName + 16-char cap)]
- [Source: runner/app/slot_names.py (AP-side mirror)]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Completion Notes List

- Single rule (`[A-Za-z0-9_]` + {number}/{player}, ≤16) enforced at the frontend "Nom en jeu" and the
  backend slot-YAML save endpoints; underscore now preserved across PHP/TS/Python; default
  `Player{number}` still valid.
- Tests across all three layers; API/frontend/runner gates green (runner mypy: changed file clean,
  pre-existing unrelated errors untouched).

### File List

- `api/src/Shared/Domain/SlotName.php`
- `api/src/Sessions/Application/SlotNameGenerator.php`
- `api/src/PersonalRuns/Application/PersonalRunGameSelection.php`
- `api/src/Registrations/Application/RegistrationGameSelection.php`
- `api/tests/Unit/Shared/SlotNameTest.php`
- `api/tests/Unit/Sessions/SlotNameGeneratorTest.php`
- `api/tests/Functional/RegistrationSlotYamlTest.php`
- `api/tests/Functional/PlayerStateTest.php`
- `frontend/src/lib/archipelago-yaml.ts`
- `frontend/src/features/events/yaml-option-editor.tsx`
- `frontend/src/features/admin/admin-session-page.tsx`
- `frontend/src/lib/archipelago-slot-name.test.ts`
- `runner/app/slot_names.py`
- `runner/tests/test_preflight.py`

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-28 | Created + implemented. Slot/player name validation: `[A-Za-z0-9_]` + AP {number}/{player}, ≤16, rejected at the frontend field and the backend slot-YAML save endpoints; underscore preserved in the generator/runner/admin preview. Tests across api/frontend/runner; gates green. Status → review. |
