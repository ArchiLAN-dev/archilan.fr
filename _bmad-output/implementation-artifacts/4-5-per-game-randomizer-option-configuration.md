# Story 4.5 - Per-Game Randomizer Option Configuration

**Status:** done  
**Validation:** RegistrationGameOptionsTest 12 tests, 91 assertions - PHPStan level max, tsc --noEmit: 0 errors, ESLint game-selection-gate clean, PHP CS Fixer clean on touched backend files

## Changes

### Backend

**`api/src/Registrations/Domain/Registration.php`**
- Added `gameOptions` as trailing optional JSON constructor parameter (`array<string, array<string, bool|float|int|string|null>>`, default `[]`)
- Added `getGameOptions(string $gameId): array` getter
- Added `setGameOptions(string $gameId, array $options, \DateTimeImmutable $now): void` with `isReserved()` domain guard

**`api/migrations/Version20260501003000.php`**
- Adds `game_options JSONB NOT NULL DEFAULT '{}'` to `registrations_registrations`

**`api/src/Registrations/Application/RegistrationGameSelection.php`**
- `getSelection()` return type extended with `selectedGamesWithOptions` - per-game option list with current values from `gameOptions`
- Added `saveGameOptions(string $registrationId, string $userId, string $gameId, array $rawValues): ?array` - validates keys against visible schema, validates value types, persists partial saves
- Added private `buildSelectedGamesWithOptions()` helper - queries selected games, respects `visibleOptionKeys` filter (empty = all visible), merges current values
- Added private `buildVisibleOptions()` helper
- Added private `normalizeOptionValue(mixed $value, string $inputType): bool|float|int|string|null` - handles boolean/number/text/select types
- All three guard conditions (`getSelection`, `saveSelection`, `saveGameOptions`) now include `!$registration->isReserved()` - cancelled registrations return 404

**`api/src/Registrations/Presentation/RegistrationController.php`**
- New `PUT /api/v1/registrations/{registrationId}/games/{gameId}/options` endpoint → `saveGameOptions`

### Tests

**`api/tests/Functional/RegistrationGameOptionsTest.php`** (new - 10 tests, 72 assertions)
- Anonymous 401, unknown 404, wrong-user 404, selection-disabled 422, game-not-in-selection 422
- Invalid option key 422, invalid value type 422 (number field with string value)
- Valid save 200 with DB verification
- GET game-selection includes `selectedGamesWithOptions` with correct option structure
- `visibleOptionKeys` filter reduces options to only the listed keys

**`api/tests/Functional/RbacEnforcementTest.php`**
- Added `PUT /api/v1/registrations/nonexistent/games/nonexistent/options` to protected endpoints

### Frontend

**`frontend/src/features/events/game-selection-gate.tsx`**
- Added `OptionValue`, `OptionSchema`, `SelectedGameWithOptions` types
- Updated `SelectionData` with `selectedGamesWithOptions`
- `loadKey` state: incremented after successful game selection save to trigger re-fetch (keeps options in sync)
- Added `GameOptionPanel` component: basic options visible, advanced options in `<details>` accordion, per-game save button with idle/saving/saved/error state
- Added `OptionField` component: renders `checkbox` (boolean), `number`, or `text` input based on `inputType`; `select` inputType rendered as text in v1
- Added `parseOption()`, `parseSelectedGameWithOptions()` parsers - lenient, filter invalid entries
- Updated `parseSelectionData()` to include `selectedGamesWithOptions`
- Options panels shown only for games that are selected (local state) AND have at least one option

## API contract

```
GET /api/v1/registrations/{id}/game-selection
→ { data: { ..., selectedGamesWithOptions: [{ gameId, gameName, options: [{ key, label, inputType, description, required, defaultValue, visibility, currentValue }] }] } }

PUT /api/v1/registrations/{id}/games/{gameId}/options
Body: { [key: string]: boolean | number | string | null }
→ 200 { data: { savedOptions: { ... } }, meta: {} }
→ 422 { error: { code, message, details: { [key]: string[] } } }
→ 404 if registration not found / wrong user / cancelled
```

### Review Findings

- [x] [Review][Patch] Partial option saves replace the whole saved option map for a game instead of merging with existing values [api/src/Registrations/Application/RegistrationGameSelection.php:193]
- [x] [Review][Patch] The continue button can remain enabled after local option edits because option panel saves do not invalidate the global saved selection state [frontend/src/features/events/game-selection-gate.tsx:234]
