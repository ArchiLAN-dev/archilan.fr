# Story 9.2 - Game YAML Template Management

Status: done

## Review

Status: corrected after review.

Acceptance criteria reviewed:

- Admins can configure `archipelagoGameName` and default YAML values for a game.
- New games are flagged as not ready for session generation when no Archipelago game name is configured.
- Removing an already configured Archipelago game name is rejected.
- Admin UI includes a live YAML preview in the template dialog.
- Functional tests cover template persistence.

Findings:

- Default YAML values were accepted for arbitrary keys instead of being validated against the game's randomizer option schema.
- YAML preview existed only in the frontend helper; the API did not return a canonical preview to test against the same backend data that session generation will use.
- The frontend preview formatting was too loose for scalar values that need YAML-safe quoting.

## Corrections

- Added backend validation that each default YAML key exists in the game's randomizer option schema.
- Added backend validation that default YAML values match the configured option input type.
- Added `yamlPreview` to the game payload.
- Added canonical backend preview formatting for booleans, numbers, nulls, simple strings, and quoted strings.
- Added functional assertions for preview YAML output.
- Added functional coverage for unknown YAML keys and type mismatches.
- Aligned the admin UI preview scalar formatting with the backend preview behavior.

## Validation

- `php bin/phpunit tests/Functional/AdminGameLibraryTest.php`
- `vendor/bin/phpstan analyse src/GameSelection/Domain/ArchipelagoGame.php src/GameSelection/Application/AdminGameLibrary.php src/GameSelection/Presentation/AdminGameLibraryController.php tests/Functional/AdminGameLibraryTest.php --level=6`
- `vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php src/GameSelection/Application/AdminGameLibrary.php tests/Functional/AdminGameLibraryTest.php`
- `pnpm lint -- src/features/admin/admin-game-library-dashboard.tsx`
- `pnpm typecheck`

