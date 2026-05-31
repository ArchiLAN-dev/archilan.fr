# Story 4.14: Location Fields Autocomplete (Backlog)

Status: backlog

> **Note:** Cette story est volontairement en backlog. L'implémentation nécessite une extraction préalable des locations depuis les apworlds (pipeline backend non encore câblé). La complexité est élevée (3 couches : apworld parsing → API → frontend) pour un ROI modéré. À reprendre quand le pipeline apworld sera stabilisé.

## Story

As a registrant,
I want to get location name suggestions when typing in location-related fields,
So that I can configure priority_locations, exclude_locations, start_hints, and start_location_hints without having to look up exact location names manually.

## Acceptance Criteria

1. **Given** a user is typing in a location field (priority_locations, exclude_locations, start_location_hints) **When** they type at least 2 characters **Then** a dropdown shows matching location names from the game's static location list, filterable by input.

2. **Given** suggestions appear **When** the user selects one **Then** the input is filled with the exact location name and the dropdown closes.

3. **Given** the user types a name not in the suggestion list **When** they press Enter or blur the field **Then** the value is accepted as-is (free text — no hard validation). The field is never a strict select.

4. **Given** no location data is available for the game **When** the user types in a location field **Then** the field behaves as a plain text input with no suggestions (graceful degradation).

5. **Given** a location list was extracted from the game's apworld **When** an apworld is updated with a new version **Then** the location list is refreshed accordingly.

## Scope

Fields affected:
- `priority_locations` — force a progression item on these checks
- `exclude_locations` — force filler/trap items on these checks
- `start_location_hints` — reveal these check locations at game start
- `start_hints` (items, not locations — out of scope for this story)

## Important UX Decision

**Always free text, never a strict select.** The location list extracted from the apworld is the *static* list — it does not account for options-dependent locations (some games add/remove checks based on player settings). The suggestions are a convenience hint, not a source of truth. The user must be able to type any string.

## Tasks / Subtasks

- [ ] Task 1 — Backend: extract and store location list per game during apworld upload
  - [ ] 1.1 In the apworld processing pipeline (story 9.11 / 3.10), after parsing the apworld zip, extract `location_name_to_id` from the game's World class
  - [ ] 1.2 Store the location list (array of strings) on the `Game` entity / table — new nullable column `location_names: string[]`
  - [ ] 1.3 Re-extract on apworld update/replacement
  - [ ] 1.4 Migration + PHPStan + tests

- [ ] Task 2 — API: expose location names per game
  - [ ] 2.1 Add `locationNames: string[] | null` to the existing game detail payload (or slot config payload)
  - [ ] 2.2 The slot config page already fetches game info — include `locationNames` there; no new endpoint needed
  - [ ] 2.3 Return `null` when no apworld has been parsed yet (graceful degradation)

- [ ] Task 3 — Frontend: autocomplete component for location fields
  - [ ] 3.1 Create reusable `LocationAutocompleteInput` component: text input + floating dropdown, filtered by typed value (case-insensitive substring match)
  - [ ] 3.2 Replace plain `<input>` in `ListField` for location-type options with `LocationAutocompleteInput` when `locationNames` prop is provided
  - [ ] 3.3 Pass `locationNames` from the parsed YAML editor context down to location fields — needs a new prop on `YamlOptionEditor`
  - [ ] 3.4 Keyboard navigation in dropdown (↑↓ Enter Escape)
  - [ ] 3.5 Graceful degradation: when `locationNames` is null, render plain `<input>` (no change to existing behavior)

- [ ] Task 4 — Quality gates
  - [ ] 4.1 `vendor/bin/phpstan analyse` → 0 errors
  - [ ] 4.2 `vendor/bin/php-cs-fixer check` → 0 violations
  - [ ] 4.3 `php bin/phpunit` → all green
  - [ ] 4.4 `pnpm typecheck` → 0 errors
  - [ ] 4.5 `pnpm lint` → 0 errors / 0 warnings
  - [ ] 4.6 `pnpm build` → clean

## Dev Notes

### Why Not a Strict Select

Location lists from apworlds are **statically extracted** but many games have **options-dependent locations** (e.g., ALttP dungeon count, Pokémon gym count, Paint canvas size). The generated check list can differ from the static list. A strict `<select>` would:
- Block valid location names from dynamic games
- Confuse users who see "no option" for a check they know exists

A **free-text input with suggestions** is the correct UX pattern here.

### Affected Options in YAML

These options hold location names (string arrays):

| YAML key | Currently | After this story |
|----------|-----------|-----------------|
| `priority_locations` | `FreeformListOption` → `ListField` (plain inputs) | same + autocomplete |
| `exclude_locations` | `FreeformListOption` → `ListField` (plain inputs) | same + autocomplete |
| `start_location_hints` | `FreeformListOption` → `ListField` (plain inputs) | same + autocomplete |

`start_hints` holds *item* names (not locations) — separate concern, out of scope.

### Apworld Extraction

The apworld is a zip file containing Python source. Location extraction can be done with the Archipelago Python library already used in the pipeline:

```python
# Pseudocode — exact implementation depends on pipeline architecture
from worlds.AutoWorld import AutoWorldRegister
world_class = AutoWorldRegister.world_types[game_name]
location_names = list(world_class.location_name_to_id.keys())
```

The extracted list should be stored as a `jsonb` array on the `Game` entity.

### Frontend Architecture

`LocationAutocompleteInput` should be self-contained. It takes:
- `value: string` — current text value
- `onChange: (val: string) => void`
- `suggestions: string[] | null` — null = no suggestions, renders plain input
- `disabled?: boolean`
- `placeholder?: string`

The `YamlOptionEditor` needs a new optional prop `locationNames: string[] | null` threaded down to `ListField` for location-typed keys. Use key matching (`priority_locations`, `exclude_locations`, `start_location_hints`) to pass suggestions.

### References

- [Source: frontend/src/features/events/yaml-option-editor.tsx#ListField] — replace input for location fields
- [Source: frontend/src/lib/archipelago-yaml.ts] — no changes needed (FreeformListOption already correct)
- [Archipelago Network Protocol](https://alwaysintreble.github.io/Archipelago/network%20protocol.html) — DataPackage structure
- [Story 9.11 / 3.10] — apworld upload pipeline (prerequisite for Task 1)
- [Story 4.12] — `_bmad-output/implementation-artifacts/4-12-plando-items-advanced-configuration.md`

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

### Completion Notes List

### File List
