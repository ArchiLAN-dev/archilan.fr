# Story 14.7: Game creation endpoint - catalogue fields

**Status:** review
**Epic:** 14 - APWorld Community Catalogue & Update Tracker
**Date:** 2026-05-11

---

## Story

As an admin,
I want to create a game from the catalogue with all metadata in a single call,
So that sheet and IGDB data are applied from the start.

---

## Endpoint

`POST /api/v1/admin/games` (extension of existing endpoint)

---

## API Contract

**Request body:** snake_case JSON keys (matching existing `POST /api/v1/admin/games` convention).
**Response:** camelCase JSON keys (matching existing admin game response).

## Acceptance Criteria

1. Accepts new fields (snake_case in request body): `adult_content`, `bundled_with_ap`, `availability_locked`, `catalog_sheet_name`, `apworld_source_url`, `igdb_id`.
2. `apworld_source_url` validated and normalized on save:
   - Accepted input formats: `https://github.com/{owner}/{repo}`, with optional trailing `/`, `/releases`, `/releases/latest`, `/releases/tag/{tag}`, `/tree/{branch}` (all stripped to canonical `https://github.com/{owner}/{repo}`).
   - Rejected: non-https, no scheme, GitHub Enterprise, query params, paths beyond `/{owner}/{repo}/...` that don't match the above patterns → 422.
   - Stored as canonical `https://github.com/{owner}/{repo}`.
3. `apworld_latest_version`, `apworld_deployed_version`, and `apworld_checked_at` initialised to null at creation - never synchronous here.
4. `catalog_sheet_name` stored to anchor future matching (Story 14.3).
5. `cs-fixer` passes.

---

## File List

- `src/GameSelection/Domain/ArchipelagoGame.php` (modified - add static `normalizeApworldSourceUrl()`)
- `src/GameSelection/Application/AdminGameLibrary.php` (modified - `parse()`, `validate()`, `create()`)
- `tests/Unit/GameSelection/ArchipelagoGameApworldUrlNormalizationTest.php` (new - 17 tests via data providers)
- `tests/Functional/AdminGameLibraryTest.php` (modified - 3 new tests)

---

## Change Log

- 2026-05-11: Story implemented - `normalizeApworldSourceUrl()` static helper on entity, catalogue fields accepted and persisted at creation, 420 → 422 on invalid GitHub URL, 570/570 tests passing.

---

## Dev Agent Record

### Completion Notes

- `ArchipelagoGame::normalizeApworldSourceUrl(string $url): ?string` - static domain helper. Accepts `https://github.com/{owner}/{repo}` with optional trailing `/`, `/releases`, `/releases/latest`, `/releases/tag/{tag}`, `/tree/{branch}`. Strips suffix, returns canonical form. Returns null for non-https, no scheme, enterprise domain, query params, fragments, or unrecognized path patterns.
- `AdminGameLibrary::parse()` - extended with 7 new keys (snake_case input → camelCase internal): `adultContent`, `bundledWithAp`, `availabilityLocked`, `catalogSheetName`, `apworldSourceUrl` (normalized), `apworldSourceUrlProvided` (flag), `igdbId`.
- `AdminGameLibrary::validate()` - added: if URL was provided (`apworldSourceUrlProvided`) but normalization returned null → add `apworld_source_url` error → 422.
- `AdminGameLibrary::create()` - after `ArchipelagoGame::create()`, calls `updateCatalogueMetadata()` with named arguments for the 5 catalogue fields. `apworld_latest_version`, `apworld_deployed_version`, `apworld_checked_at` remain null (AC3 - no change needed, `create()` never set them).
- AC5 (expose in response): `payload()` already serialized all these fields since Story 14.3/14.4 - no change needed.
- cs-fixer: fixed array alignment in new unit test file (1 file fixed).

---

## Tasks

- [x] Extend game creation request DTO/form with new fields
- [x] Map new fields to `ArchipelagoGame` entity on creation
- [x] Validate + normalize `apworld_source_url` to canonical `https://github.com/{owner}/{repo}` (strip /releases, /tree/*, trailing slash); 422 on invalid input
- [x] Ensure `apworld_latest_version`, `apworld_deployed_version`, `apworld_checked_at` default to null
- [x] Expose new fields in creation response (serializer/DTO)
- [x] Run `cs-fixer`
