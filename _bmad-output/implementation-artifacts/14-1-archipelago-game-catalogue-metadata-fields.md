# Story 14.1: ArchipelagoGame entity - catalogue metadata fields

**Status:** review
**Epic:** 14 - APWorld Community Catalogue & Update Tracker
**Date:** 2026-05-11

---

## Story

As an admin,
I want the `ArchipelagoGame` entity to carry community catalogue metadata,
So that game entries track their APWorld lifecycle without orphaned data.

---

## Acceptance Criteria

1. Migration adds the following columns to `games`:

| Column | Type | Default |
|--------|------|---------|
| `adult_content` | `tinyint(1)` | `0` |
| `bundled_with_ap` | `tinyint(1)` | `0` |
| `availability_locked` | `tinyint(1)` | `0` |
| `catalog_sheet_name` | `varchar(160)` nullable | `null` |
| `apworld_source_url` | `varchar(500)` nullable | `null` |
| `apworld_deployed_version` | `varchar(50)` nullable | `null` |
| `apworld_latest_version` | `varchar(50)` nullable | `null` |
| `apworld_checked_at` | `datetime` nullable | `null` |
| `igdb_id` | `int` nullable | `null` |

2. Migration is reversible.
3. APWorld update status is a computed enum exposed in API responses - never stored as a boolean column:
   - `not_tracked` - `apworld_source_url` is null or not a recognized GitHub URL
   - `unknown` - GitHub URL set but `apworld_checked_at` is null (never checked)
   - `up_to_date` - `apworld_latest_version == apworld_deployed_version` (normalized, see Story 14.5)
   - `update_available` - `apworld_latest_version != apworld_deployed_version` (both non-null)
   `apworld_deployed_version` = version tag of the .apworld file currently in storage; `apworld_latest_version` = latest GitHub release tag from the last check.
4. New fields exposed in admin game API endpoints.
5. `cs-fixer` passes.

---

## Tasks

- [x] Generate Doctrine migration
- [x] Add properties + getters/setters to `ArchipelagoGame` entity
- [x] Expose new fields in admin game response (serializer/DTO)
- [x] Run `cs-fixer`
- [x] Verify migration is reversible (`doctrine:migrations:execute --down`)

---

## File List

- `migrations/Version20260511000001.php` (new)
- `src/GameSelection/Domain/ArchipelagoGame.php` (modified)
- `src/GameSelection/Application/AdminGameLibrary.php` (modified)
- `tests/Unit/GameSelection/ArchipelagoGameUpdateStatusTest.php` (new - 8 tests)
- `tests/Functional/AdminGameLibraryTest.php` (modified)

---

## Change Log

- 2026-05-11: Story implemented - migration, entity fields, payload, unit + functional tests (507/507 passing).
- 2026-05-11: Review fix - `computeApworldUpdateStatus()` now returns `not_tracked` for non-GitHub URLs (e.g. `https://example.com/...`); test added (508/508 passing).

---

## Dev Agent Record

### Completion Notes

- Migration uses PostgreSQL-native types (`BOOLEAN`, `TIMESTAMPTZ`) consistent with the existing codebase (MySQL types in the story spec were adapted).
- Entity `computeApworldUpdateStatus()` normalizes version tags by stripping leading `v` before comparison so `v1.2.0 == 1.2.0`.
- `updateCatalogueMetadata()` is a dedicated mutator for catalogue fields; `recordApworldCheck()` updates `apworld_latest_version` + `apworld_checked_at` only (used by story 14.5).
- `UPDATE_STATUS_*` constants added to the entity for type-safe usage across stories 14.5, 14.6.
- All 507 existing tests pass; 8 new tests added (7 unit + 1 functional).
- cs-fixer: clean (0 files to fix).
- Migration reversibility verified with `--up` / `--down` / `--up` cycle against live PostgreSQL.
