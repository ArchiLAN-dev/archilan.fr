# Story 4.14: Location Fields Autocomplete

Status: done

> **Note (2026-07-19):** Débloquée et livrée. Le pipeline d'extraction est désormais câblé de bout en bout : archipelago `introspect_options.py` émet `{options, locations}`, l'orchestrateur expose `GET /apworlds/{hash}/locations`, le SDK `orchestrator-client` v1.3.0 ajoute `getLocations`, et le monorepo persiste `Game.locationNames`. Livré via PR #357 (develop) + archipelago #8 + orchestrateur #13.

## Story

As a registrant,
I want to get location name suggestions when typing in location-related fields,
So that I can configure priority_locations, exclude_locations, start_hints, and start_location_hints without having to look up exact location names manually.

## Acceptance Criteria

1. **Given** a user is typing in a location field (priority_locations, exclude_locations, start_location_hints) **When** they type at least 2 characters **Then** a dropdown shows matching location names from the game's static location list, filterable by input.

2. **Given** suggestions appear **When** the user selects one **Then** the input is filled with the exact location name and the dropdown closes.

3. **Given** the user types a name not in the suggestion list **When** they press Enter or blur the field **Then** the value is accepted as-is (free text - no hard validation). The field is never a strict select.

4. **Given** no location data is available for the game **When** the user types in a location field **Then** the field behaves as a plain text input with no suggestions (graceful degradation).

5. **Given** a location list was extracted from the game's apworld **When** an apworld is updated with a new version **Then** the location list is refreshed accordingly.

## Scope

Fields affected:
- `priority_locations` - force a progression item on these checks
- `exclude_locations` - force filler/trap items on these checks
- `start_location_hints` - reveal these check locations at game start
- `start_hints` (items, not locations - out of scope for this story)

## Important UX Decision

**Always free text, never a strict select.** The location list extracted from the apworld is the *static* list - it does not account for options-dependent locations (some games add/remove checks based on player settings). The suggestions are a convenience hint, not a source of truth. The user must be able to type any string.

## Tasks / Subtasks

- [x] Task 1 - Backend: extract and store location list per game during apworld upload
  - [x] 1.1 Extraction déléguée au repo archipelago (`introspect_options.py`) : après le build des options, lit `world_cls.location_name_to_id` et émet `{options, locations}` dans le sidecar d'introspection. L'orchestrateur relit ce sidecar via `GetApworldLocations` et l'expose sur `GET /apworlds/{hash}/locations`.
  - [x] 1.2 Colonne nullable `Game.locationNames` (`json`) + `recordLocationNames`
  - [x] 1.3 Re-extraction à chaque upload (`RunnerGateway.uploadApworld` renvoie `locationNames`) + commande de backfill `app:games:backfill-locations` (`BackfillGameLocations`)
  - [x] 1.4 Migration `Version20260719100000` + PHPStan max/strict + tests unitaires (`BackfillGameLocationsTest`)

- [x] Task 2 - API: expose location names per game
  - [x] 2.1 `locationNames` ajouté aux 3 payloads slot-config (admin weekly / personal runs / registrations)
  - [x] 2.2 Aucun nouvel endpoint : la valeur voyage dans les payloads de sélection de jeu existants
  - [x] 2.3 `null` tant qu'aucun apworld n'a été introspecté (dégradation gracieuse)

- [x] Task 3 - Frontend: autocomplete component for location fields
  - [x] 3.1 Composant `LocationAutocompleteInput` (input + dropdown flottant, substring case-insensitive, min 2 chars, max 50 suggestions)
  - [x] 3.2 `ListField` utilise `LocationAutocompleteInput`, suggestions passées uniquement pour les clés `priority_locations` / `exclude_locations` / `start_location_hints`
  - [x] 3.3 Prop `locationNames` threadée sur `YamlOptionEditor` → `OptionField` → `ListField`, câblée sur tous les consommateurs (events, personal runs, admin weekly)
  - [x] 3.4 Navigation clavier (↑↓ Enter Escape) + a11y combobox (`aria-controls`/`aria-expanded`/`role=listbox`)
  - [x] 3.5 `suggestions === null` → input simple (comportement pré-4.14 inchangé)

- [x] Task 4 - Quality gates
  - [x] 4.1 `vendor/bin/phpstan analyse` → 0 errors
  - [x] 4.2 `vendor/bin/php-cs-fixer check` → 0 violations (+ DDD + rector verts)
  - [x] 4.3 `php bin/phpunit` (isolé) → 1545 tests / 10615 assertions verts
  - [x] 4.4 `pnpm typecheck` → 0 errors
  - [x] 4.5 `pnpm lint` → 0 errors (warning résiduel préexistant hors périmètre)
  - [x] 4.6 `pnpm build` → clean

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

`start_hints` holds *item* names (not locations) - separate concern, out of scope.

### Apworld Extraction

The apworld is a zip file containing Python source. Location extraction can be done with the Archipelago Python library already used in the pipeline:

```python
# Pseudocode - exact implementation depends on pipeline architecture
from worlds.AutoWorld import AutoWorldRegister
world_class = AutoWorldRegister.world_types[game_name]
location_names = list(world_class.location_name_to_id.keys())
```

The extracted list should be stored as a `jsonb` array on the `Game` entity.

### Frontend Architecture

`LocationAutocompleteInput` should be self-contained. It takes:
- `value: string` - current text value
- `onChange: (val: string) => void`
- `suggestions: string[] | null` - null = no suggestions, renders plain input
- `disabled?: boolean`
- `placeholder?: string`

The `YamlOptionEditor` needs a new optional prop `locationNames: string[] | null` threaded down to `ListField` for location-typed keys. Use key matching (`priority_locations`, `exclude_locations`, `start_location_hints`) to pass suggestions.

### References

- [Source: frontend/src/features/events/yaml-option-editor.tsx#ListField] - replace input for location fields
- [Source: frontend/src/lib/archipelago-yaml.ts] - no changes needed (FreeformListOption already correct)
- [Archipelago Network Protocol](https://alwaysintreble.github.io/Archipelago/network%20protocol.html) - DataPackage structure
- [Story 9.11 / 3.10] - apworld upload pipeline (prerequisite for Task 1)
- [Story 4.12] - `_bmad-output/implementation-artifacts/4-12-plando-items-advanced-configuration.md`

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code, 1M context).

### Debug Log References

### Completion Notes List

- Pipeline 5 couches livrée. L'extraction est le rôle du repo archipelago (pas du monorepo) : `introspect_options.py` réutilise le sidecar d'introspection déjà produit après upload, étendu de `{options}` à `{options, locations}`. L'orchestrateur relit le même sidecar (pas de seconde introspection).
- Liste statique = indice, jamais contrainte : texte libre toujours accepté, jamais de validation stricte (le sous-ensemble réel de locations dépend des options et est inconnu au moment de la config). AC-3 / UX Decision respectés.
- Le bridge n'est pas touché : sa lecture de `location_name_to_id` (`core/ap_client.py`) est runtime-only (traduction ID→nom dans les events temps réel), sans rapport avec la config.
- AC-5 (refresh sur nouvelle version d'apworld) : couvert par la ré-extraction à chaque upload + `app:games:backfill-locations` pour les jeux existants.
- Versions upstream : archipelago master (PR #8), orchestrateur master (PR #13), orchestrator-client v1.3.0 (`getLocations`).

### File List

**Monorepo (PR #357)**
- `api/src/GameSelection/Domain/Entity/Game.php` (colonne `locationNames` + `recordLocationNames`/`getLocationNames`)
- `api/migrations/Version20260719100000.php`
- `api/src/Sessions/Application/Port/RunnerGatewayInterface.php` (`fetchLocationNames`)
- `api/src/Sessions/Infrastructure/Http/RunnerGateway.php`
- `api/src/Sessions/Infrastructure/Double/NullRunnerGateway.php`
- `api/src/GameSelection/Application/Service/AdminGameLibrary.php`
- `api/src/PersonalRuns/Application/Service/PersonalRunGameSelection.php`
- `api/src/Registrations/Application/Service/RegistrationGameSelection.php`
- `api/src/GameSelection/Application/Command/BackfillGameLocations.php`
- `api/src/GameSelection/Presentation/Command/BackfillGameLocationsCommand.php`
- `api/tests/Unit/GameSelection/BackfillGameLocationsTest.php`
- `api/composer.json` / `composer.lock` (orchestrator-client v1.3.0)
- `frontend/src/features/events/location-autocomplete-input.tsx` (nouveau)
- `frontend/src/features/events/yaml-option-editor.tsx`
- `frontend/src/lib/archipelago-yaml.ts` (`asLocationNames`)
- `frontend/src/lib/archipelago-yaml-bounds.test.ts` (tests `asLocationNames`)
- `frontend/src/features/events/events-api.ts`
- `frontend/src/features/events/slot-yaml-gate.tsx`
- `frontend/src/features/personal-runs/personal-runs-api.ts`
- `frontend/src/features/personal-runs/personal-run-slot-yaml-page.tsx`
- `frontend/src/features/admin/admin-weekly-runs-api.ts`
- `frontend/src/features/admin/admin-weekly-template-form.tsx`

**Repos de service (hors monorepo)**
- archipelago `introspect_options.py` (PR #8 → master)
- orchestrateur `internal/api|service` + `/apworlds/{hash}/locations` (PR #13 → master)
- orchestrator-client `src/Apworlds/ApworldsClient.php` `getLocations` (v1.3.0)

### Change Log

| Date       | Change |
|------------|--------|
| 2026-07-19 | Débloquée depuis backlog et livrée de bout en bout (pipeline 5 couches). Autocomplete statique-indice pour `priority_locations` / `exclude_locations` / `start_location_hints` dans l'éditeur YAML de slot. Gates verts des deux côtés. Status → done. |
