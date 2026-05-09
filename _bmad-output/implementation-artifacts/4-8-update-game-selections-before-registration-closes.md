# Story 4.8 - Update Game Selections Before Registration Closes

**Status:** done  
**Validation:** RegistrationGameSelectionTest + RegistrationGameOptionsTest 28 tests, 186 assertions - PHPStan level max: 0 errors - PHP CS Fixer clean on touched backend files

## Review Findings

- [x] [Review][Patch] Game selection updates accepted duplicate game IDs, allowing an API client to persist a selection state the UI cannot produce [api/src/Registrations/Application/RegistrationGameSelection.php:239]

## Fix

- Added duplicate game ID validation in `RegistrationGameSelection::validateGameIds()`.
- Added `RegistrationGameSelectionTest::testPutRejectsDuplicateGameIds()` to lock the behavior.
