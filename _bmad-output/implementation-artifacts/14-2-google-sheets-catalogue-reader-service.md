# Story 14.2: Google Sheets catalogue reader service

**Status:** review
**Epic:** 14 - APWorld Community Catalogue & Update Tracker
**Date:** 2026-05-11

---

## Story

As an admin,
I want the system to read the community sheet via Google Sheets API v4,
So that I get real link URLs, not just text labels from the CSV export.

---

## Namespace

`App\CatalogSync\Application\CatalogSyncService` (new bounded context `CatalogSync\`).
HTTP adapters in `App\CatalogSync\Infrastructure\`.

---

## Acceptance Criteria

1. `CatalogSyncService::fetchSheet()` reads `gid=58422002` (main list) and `gid=1675722515` (bundled).
2. Returns typed `CatalogEntry` objects: `name`, `availability`, `prStatus`, `adultContent`, `notes`, `links[]` (label + url nullable), `bundledWithAp`.
3. If `GOOGLE_API_KEY` set: use `spreadsheets.get` with `includeGridData=true` and a targeted `fields` mask (`sheets.data.rowData.values.textFormatRuns,sheets.data.rowData.values.userEnteredValue`). Do NOT use `spreadsheets.values.get` - it returns a `ValueRange` with cell values only and loses all cell metadata (hyperlinks live in `textFormatRuns[].format.link.uri` or `userEnteredFormat.textFormat.link.uri`).
4. If `GOOGLE_API_KEY` absent: silent fallback to CSV export, `url = null` on all links, PSR-3 warning logged, no exception thrown.
5. Bundled games: `bundledWithAp = true`, `availability = available` (no stability column on that tab).
6. `prStatus` included in `CatalogEntry` but never persisted to DB.
7. Stability mapping: `Stable -> available`, `Unstable -> experimental`, `Broken on Main -> unavailable`.
8. Results cached via Symfony Cache (PSR-6, configurable adapter), TTL 1h.
9. Unit-tested with a 10-row CSV fixture (normal case + missing API key).
10. Unit-tested with a Sheets API v4 mock response containing hyperlinks via `textFormatRuns[].format.link.uri` - verifies that real URLs are extracted correctly from the Sheets API path.

---

## Tasks

- [x] Create `CatalogEntry` DTO with all mapped fields
- [x] Implement `CatalogSyncService::fetchSheet()` - `spreadsheets.get` with `includeGridData=true` + targeted `fields` mask (NOT `spreadsheets.values.get`)
- [x] Implement CSV fallback path with PSR-3 warning
- [x] Map stability strings to `availability` enum values
- [x] Wire Symfony Cache PSR-6 with configurable TTL
- [x] Write unit tests with 10-row CSV fixture (normal + missing API key)
- [x] Write unit test with Sheets API v4 mock response (hyperlink via `textFormatRuns[].format.link.uri`)
- [x] Run `cs-fixer`

---

## File List

- `src/CatalogSync/Domain/CatalogEntry.php` (new)
- `src/CatalogSync/Application/CatalogSyncService.php` (new)
- `src/Shared/Application/DddArchitectureValidator.php` (modified - added `CatalogSync` context)
- `tests/Unit/DddArchitectureValidatorTest.php` (modified - added `CatalogSync` to fixture)
- `config/services.yaml` (modified - Domain exclusion + service arguments)
- `tests/Unit/CatalogSync/CatalogSyncServiceTest.php` (new - 5 tests)

---

## Change Log

- 2026-05-11: Story implemented - CatalogSync bounded context created, `CatalogEntry` DTO, `CatalogSyncService` with Sheets API v4 + CSV fallback, 5 unit tests (513/513 passing).
- 2026-05-11: Review fix - `FIELDS_MASK` extended with `userEnteredFormat.textFormat.link`; `getCellLink()` falls back to `userEnteredFormat.textFormat.link.uri` when no `textFormatRuns` present; `makeService()` now uses `NullLogger` to eliminate PHPUnit notices; 1 test added for cell-level hyperlink path (514/514 passing).

---

## Dev Agent Record

### Completion Notes

- `CatalogSyncService` uses `spreadsheets.get?includeGridData=true&fields=<mask>` for the API path; the fields mask includes `sheets.properties.sheetId` (not in story spec but required to identify which sheet is which) plus the two AC3 fields.
- CSV fallback uses `fgetcsv($handle, 0, ',', '"', '')` with explicit escape parameter to suppress PHP 8.4 deprecation.
- Stability default for unknown values (empty or unrecognized): `'experimental'` (cautious default).
- `CatalogSync` added to `DddArchitectureValidator::CONTEXTS` and its test fixture; `../src/CatalogSync/Domain/` added to `services.yaml` excludes.
- `CATALOG_SPREADSHEET_ID` (required) and `GOOGLE_API_KEY` (optional, empty string default via `env(default::...)`) added to `services.yaml`.
- All 513 existing tests pass; 5 new tests added.
- cs-fixer: clean (0 files to fix).
