# Story 9.1 - Multi-Slot Registration Model

## Review

Status: done

Acceptance criteria reviewed:

- Registration domain stores ordered `game_slots`, allowing duplicate `gameId` values with independent `slotId` and `options`.
- Game selection API accepts duplicate game ids and preserves slot order.
- Slot options are saved per `slotId`, so the same game can have different option values per slot.
- Admin registration detail exposes slot id, slot order, game name, completion state, warnings, and per-slot option details.
- Existing schema uses `game_slots` as the registration persistence field; the current consolidated migration creates this shape directly.
- Functional tests already cover duplicate game ids in player selection.

Finding:

- Admin export still exposed slot data only nested inside each registration. That preserved data, but did not provide a direct one-row-per-slot export surface required by the story.

## Corrections

- Added a top-level `slots` export collection with one row per selected slot.
- Each slot export row includes registration metadata, participant data, private-access flag, completion flag, slot id, slot order, game id/name, raw options, and option details.
- Preserved the existing `registrations` grouped export for backward compatibility with downloaded JSON consumers.
- Added functional coverage for two slots of the same game with different option values.

## Validation

- `php bin/phpunit tests/Functional/AdminRegistrationExportTest.php tests/Functional/RegistrationGameSelectionTest.php`
- `vendor/bin/phpstan analyse src/Registrations/Application/AdminRegistrationExporter.php src/Registrations/Application/RegistrationGameSelection.php src/Registrations/Domain/Registration.php tests/Functional/AdminRegistrationExportTest.php tests/Functional/RegistrationGameSelectionTest.php --level=6`
- `vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php src/Registrations/Application/AdminRegistrationExporter.php tests/Functional/AdminRegistrationExportTest.php`

