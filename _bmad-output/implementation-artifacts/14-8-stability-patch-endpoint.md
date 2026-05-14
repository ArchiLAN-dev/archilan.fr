# Story 14.8: Stability patch endpoint

**Status:** review
**Epic:** 14 - APWorld Community Catalogue & Update Tracker
**Date:** 2026-05-11

---

## Story

As an admin,
I want to apply a sheet-driven stability change to an existing game,
So that `availability` stays consistent with the community source of truth.

---

## Endpoint

`PATCH /api/v1/admin/games/{id}` (existing)

---

## Acceptance Criteria

1. `availability`, `adult_content`, `availability_locked`, and all Story 14.1 fields are patchable (except `apworld_latest_version` and `apworld_checked_at`, managed by the version checker).
2. `apworld_source_url` validated and normalized on save (same rules as Story 14.7): canonical `https://github.com/{owner}/{repo}`, accepted variants stripped, non-conforming input → 422.
3. `apworld_deployed_version` patchable (admin may set the deployed version manually after uploading a file).
4. `updatedAt` on the entity is always touched when any field changes.
5. Games with `availability_locked = true` excluded from `stabilityChanged` in Story 14.3 - admin can still patch manually without restriction.
6. Change logged via PSR-3 info (not `RunAuditLog`, which is reserved for session operations).

---

## Tasks

- [x] Extend game PATCH request DTO with 14.1 fields (exclude `apworld_latest_version`, `apworld_checked_at`)
- [x] Validate + normalize `apworld_source_url` (same rules as 14.7)
- [x] Map patchable fields to entity; always update `updatedAt`
- [x] Log changes via PSR-3 info logger
- [x] Run `cs-fixer`

---

## File List

- `src/GameSelection/Application/AdminGameLibrary.php` (modified - `parse()` + `update()`)
- `tests/Functional/AdminGameLibraryTest.php` (modified - 3 new tests)

---

## Change Log

- 2026-05-11: Story implemented - PATCH endpoint now persists catalogue fields; `apworld_deployed_version` patchable; 574/574 tests passing.
- 2026-05-11: Blocking fix - PATCH now preserves omitted catalogue fields via `array_key_exists()` guard; regression test added.

---

## Dev Agent Record

### Completion Notes

- `parse()` extended with `apworldDeployedVersion` (from `apworld_deployed_version`, trimmed string → null if empty).
- `update()` now calls `updateCatalogueMetadata()` after `game->update()` with all 7 catalogue fields (including `deployedVersion`). `updatedAt` already touched by `game->update()` - AC4 satisfied without extra change.
- AC2 (`apworld_source_url` validation): already handled by `validate()` from story 14.7 - no new code needed.
- AC6 (PSR-3 logging): `$this->logger->info('game.updated', ...)` already present - no change needed.
- 3 functional tests added: patch all catalogue fields, URL normalization on PATCH, invalid URL → 422.
- Blocking review fix: `update()` uses `array_key_exists($rawKey, $input)` on the raw payload to decide whether to use the parsed value or the entity's existing value for each catalogue field. Ensures a PATCH with only basic fields (name, slug, etc.) never resets `adultContent`, `bundledWithAp`, `availabilityLocked`, `catalogSheetName`, `apworldSourceUrl`, `apworldDeployedVersion`, `igdbId`. Regression test added (`testPatchGameWithOnlyBasicFieldsPreservesCatalogueMetadata`).
