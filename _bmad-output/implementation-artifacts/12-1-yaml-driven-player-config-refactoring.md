# Story 12.1: YAML-Driven Player Configuration - Remove randomizerOptions Infrastructure

**Status:** review  
**Epic:** 12 - YAML-Driven Game Configuration  
**Date:** 2026-05-04

---

## Story

As a platform administrator,  
I want game configuration for players to be driven entirely by the `defaultYaml` extracted from the `.apworld` file,  
so that I never need to manually define option schemas per game, and players can freely configure their slot YAML without admin intervention at generation time.

---

## Context & Motivation

Story 3.6 introduced `randomizerOptions`/`optionSchemaVersion` - a manually maintained option schema admins had to define per game to generate a player-facing form. Story 4.5 built the `GameOptionPanel` that renders that form during registration.

Story 3.10 introduced the `.apworld` upload pipeline: `defaultYaml` is auto-extracted at upload time and contains the full Archipelago template with all valid options and weights. Story 3.10 also added `playerYaml` per registration slot and `PUT /registrations/{id}/slots/{slotId}/yaml` - a YAML save endpoint.

**The `randomizerOptions` system is now redundant.** This story removes it entirely and replaces the player options form with a YAML textarea pre-filled from `defaultYaml`.

### What already exists (do NOT reimplement)

- `PUT /api/v1/registrations/{registrationId}/slots/{slotId}/yaml` - already implemented in `RegistrationController::saveSlotYaml` and `RegistrationGameSelection::saveSlotYaml`
- `Registration::setSlotPlayerYaml()` - already exists on domain entity
- `defaultYaml` field on `ArchipelagoGame` - already stored, already in `detailPayload()`
- `playerYaml` / `apworldHash` per slot in `gameSlots` JSON - already stored

### What was removed in the previous conversation (do NOT redo)

- `configureYamlTemplate()` and `defaultYamlValues` field - already removed from domain, application, controller, and frontend
- `YamlTemplateSection` component in admin game editor - already removed
- `OptionsSection` component in admin game editor - **already removed**
- `PATCH /admin/games/{gameId}/yaml` route - already removed
- `defaultYamlValues`/`yamlPreview` from API payload - already removed

---

## Acceptance Criteria

1. `ArchipelagoGame` no longer has `randomizerOptions`, `optionSchemaVersion`, `OPTION_VISIBILITY_BASIC`, `OPTION_VISIBILITY_ADVANCED`, `configureRandomizerOptions()`, `getRandomizerOptions()`, `getOptionSchemaVersion()`, `supportedOptionInputTypes()`, `supportedOptionVisibilities()`.
2. `PATCH /api/v1/admin/games/{gameId}/options` no longer exists; 404 on call.
3. The event game selection config (`game_selection_config` JSON) no longer stores `visibleOptionKeys` per game; the shape is `list<{gameId: string}>`. Existing DB rows are unaffected (code ignores the old key on read).
4. `GET /api/v1/admin/events/{eventId}/game-selection` no longer returns `allOptions`, `visibleOptionKeys`, `randomizerOptions`, or `optionSchemaVersion`.
5. `PATCH /api/v1/admin/events/{eventId}/game-selection` no longer accepts or validates `visibleOptionKeys`.
6. `GET /api/v1/registrations/{id}/game-selection` returns, per available game: `isApworldReady: bool` and `defaultYaml: string|null`. Each slot returns `playerYaml: string|null` and `apworldHash: string|null`. The legacy `options` / `selectedGamesWithOptions` fields are removed from the response.
7. `PUT /api/v1/registrations/{id}/games/{gameId}/options` no longer exists; 404 on call.
8. The player registration game step shows a YAML textarea pre-filled with `slot.playerYaml ?? game.defaultYaml` for each selected slot (replacing `GameOptionPanel`). Saving calls `PUT /registrations/{id}/slots/{slotId}/yaml`.
9. The admin event dashboard "Jeux" dialog no longer shows per-game option key selection.
10. All tests pass; no regressions in session generation, registration export, admin registration inspection.

---

## Tasks

### Backend: ArchipelagoGame entity cleanup

- [x] Remove from `api/src/GameSelection/Domain/ArchipelagoGame.php` (already done in prior cleanup):
  - Constants: `OPTION_VISIBILITY_BASIC`, `OPTION_VISIBILITY_ADVANCED`
  - Constructor param + ORM column: `$randomizerOptions` (`randomizer_options`, JSON)
  - Constructor param + ORM column: `$optionSchemaVersion` (`option_schema_version`, int)
  - Method: `configureRandomizerOptions()`
  - Getters: `getRandomizerOptions()`, `getOptionSchemaVersion()`
  - Static methods: `supportedOptionInputTypes()`, `supportedOptionVisibilities()`

- [x] Create migration `api/migrations/Version20260504140000.php` (already existed)

### Backend: AdminGameLibrary cleanup

- [x] Remove from `api/src/GameSelection/Application/AdminGameLibrary.php` (already done): `configureOptions()`, `parseOptions()`, `validateOptions()`, payload keys
- [x] Remove from `api/src/GameSelection/Presentation/AdminGameLibraryController.php` (already done): `configureOptions()` route

### Backend: AdminEventGameSelection cleanup

- [x] `api/src/Events/Application/AdminEventGameSelection.php` - already clean (verified)
- [x] `api/src/Events/Domain/Event.php` - already clean (verified)

### Backend: Registration flow cleanup

- [x] `api/src/Registrations/Application/RegistrationGameSelection.php` - `getSelection()` enriched: `isApworldReady`, `defaultYaml` per game; slots always include `playerYaml`, `apworldHash`, `gameName`
- [x] `api/src/Registrations/Domain/Registration.php` - `setSlotOptions()` and `game_options` column already removed
- [x] `api/src/Registrations/Presentation/RegistrationController.php` - `saveGameOptions()` already removed; `saveSlotYaml()` exists
- [x] `api/src/Registrations/Application/AdminRegistrationModification.php` - already clean
- [x] `api/src/Registrations/Application/RegistrationSubmission.php` - already clean
- [x] `api/src/Registrations/Application/AdminRegistrationExporter.php` - already clean
- [x] `api/src/Registrations/Application/AdminRegistrationDashboard.php` - already clean
- [x] `api/src/Registrations/Application/AdminRegistrationInspector.php` - already clean
- [x] Create migration `api/migrations/Version20260504150000.php` (already existed)

### Backend: Tests

- [x] `api/tests/Functional/AdminGameLibraryTest.php` - randomizer tests already removed (verified)
- [x] `api/tests/Functional/RbacEnforcementTest.php` - old options routes absent; `slots/yaml` route listed (verified)
- [x] `api/tests/Functional/RegistrationGameOptionsTest.php` - file did not exist (already deleted in prior cleanup)
- [x] `api/tests/Functional/RegistrationSlotYamlTest.php` - NEW: 5 tests covering anonymous 401, wrong user 404, non-apworld 422, valid save stores playerYaml in DB, read-back via getSelection shows playerYaml + defaultYaml + isApworldReady
- [x] `api/tests/Functional/AdminEventGameSelectionTest.php` - already clean (verified)
- [x] `api/tests/Functional/AdminRegistrationDashboardTest.php` - already clean (verified)
- [x] `api/tests/Functional/RegistrationGameSelectionTest.php` - added assertions on `isApworldReady`, `defaultYaml` per game; added `testGetSelectionIncludesSlotDetails` asserting `slotId`, `playerYaml`, `gameName` keys
- [x] `api/tests/Functional/RegistrationSubmitTest.php` - already clean (verified)
- [x] `api/tests/Functional/AdminRegistrationDetailTest.php`, `AdminRegistrationCancellationTest.php`, `AdminRegistrationExportTest.php` - already clean (verified)
- [x] `api/tests/Functional/SessionLifecycleTest.php` - `createGame()` already uses `configureApworld()` (verified)

### Frontend: Admin event dashboard

- [x] `frontend/src/features/admin/admin-event-dashboard.tsx` - already clean (no `randomizerOptions`, `visibleOptionKeys`, `RandomizerOption` - verified)
- [x] `frontend/src/features/admin/admin-game-library-dashboard.tsx` - already clean (verified)

### Frontend: Player registration game step

Target file: `frontend/src/features/events/game-selection-gate.tsx`

- [x] Legacy types (`GameOptionPanel`, `OptionField`, `OptionValue`, `OptionSchema`, `selectedGamesWithOptions`) already removed in prior cleanup
- [x] Added `SlotYamlState` type and enriched `SelectionData.slots` to `SlotYamlState[]`
- [x] Added `loadKey` state to trigger data reload after saves
- [x] Updated `parseSelectionData()` to parse `slotId`, `gameName`, `playerYaml` from slot API response
- [x] Added `YamlSlotPanel` component with YAML textarea, save button, per-slot save state (idle/saving/saved/error)
- [x] Added "Configuration des jeux" section between "Ma sélection" and the catalog - shows `YamlSlotPanel` for apworld-ready games, message for non-apworld games
- [x] After game selection save and after YAML save, `loadKey` is incremented to re-fetch updated slots

---

## Dev Notes

### Constructor arg position in ArchipelagoGame::create()

The `create()` factory passes positional args to `new self(...)`. After removing `$randomizerOptions` (was `[]`) and `$optionSchemaVersion` (was `1`), verify the arg count matches the constructor exactly. Check `SessionLifecycleTest` and any other test helpers that call `ArchipelagoGame::create()` directly - they must not pass extra positional args.

### game_selection_config JSON backward compat

`event.game_selection_config` rows already in DB have shape `[{gameId, visibleOptionKeys}]`. The updated PHP code simply doesn't read `visibleOptionKeys` anymore. No data migration needed - old key is silently ignored. **Do not run a data migration to strip old keys** - unnecessary risk.

### game_options column vs gameSlots JSON

`game_options` is a separate top-level JSONB column on `registrations_registrations` (stores `{gameId: {key: value}}`). It is separate from the per-slot `options` field inside the `game_slots` JSON column. Both are removed. The `game_slots` JSON already supports `playerYaml` and `apworldHash` per slot.

### saveSlotYaml validation

`RegistrationGameSelection::saveSlotYaml()` already validates `$game->isApworldReady()` and returns an error if not. The error code returned by the controller is `validation_failed` with `errors['game']`.

### Frontend API contract (new shape)

```
GET /api/v1/registrations/{id}/game-selection
→ {
    data: {
      ...,
      availableGames: [{ id, name, slug, ..., isApworldReady, defaultYaml }],
      slots: [{ slotId, gameId, gameName, slotName, playerYaml, apworldHash, slotOrder }]
    }
  }
```

---

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

None

### Completion Notes List

- All `randomizerOptions`/`optionSchemaVersion` infrastructure was already removed in prior sessions. This session verified the backend is clean and focused on the missing pieces.
- `RegistrationGameSelection.getSelection()` was updated to always include `playerYaml`, `apworldHash`, and `gameName` in each slot entry (these keys were conditionally present before, causing assertion failures).
- Created `RegistrationSlotYamlTest.php` with 5 tests covering the full YAML save flow.
- Updated `RegistrationGameSelectionTest.php` to assert on `isApworldReady`, `defaultYaml`, and slot detail keys.
- Frontend `game-selection-gate.tsx`: added `SlotYamlState` type, `loadKey` pattern, `YamlSlotPanel` component, and the "Configuration des jeux" section.
- Full test suite: 498 tests, 4188 assertions - all green.
- TypeScript build and typecheck: clean.

### File List

- `api/src/Registrations/Application/RegistrationGameSelection.php` - enriched slot response (playerYaml, apworldHash, gameName always present)
- `api/tests/Functional/RegistrationSlotYamlTest.php` - NEW (5 tests)
- `api/tests/Functional/RegistrationGameSelectionTest.php` - added assertions + new test
- `frontend/src/features/events/game-selection-gate.tsx` - SlotYamlState, loadKey, YamlSlotPanel, YAML config section
