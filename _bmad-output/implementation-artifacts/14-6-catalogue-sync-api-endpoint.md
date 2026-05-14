# Story 14.6: Catalogue sync API endpoint

**Status:** review
**Epic:** 14 - APWorld Community Catalogue & Update Tracker
**Date:** 2026-05-11

---

## Story

As an admin,
I want a single endpoint returning the sheet diff and already-computed APWorld update status,
So that the catalogue page loads without blocking on external calls.

---

## Endpoint

`GET /api/v1/admin/catalog-sync`

---

## API Contract

**Request:** no body (GET). **Response:** camelCase JSON keys throughout.
Depends on Story 14.5 (`ApworldVersionChecker`) being implemented first.

## Acceptance Criteria

1. Admin only.
2. Response shape (all keys camelCase):
   ```json
   {
     "cachedAt": "ISO8601|null",
     "googleApiAvailable": true,
     "githubChecksAvailable": false,
     "newGames": [...],
     "stabilityChanged": [...],
     "removedFromSheet": [...],
     "apworldUpdates": [
       { "gameId": "...", "gameName": "...", "deployedVersion": "...|null",
         "latestVersion": "...", "releaseUrl": "...", "publishedAt": "...",
         "updateStatus": "update_available|up_to_date|unknown|not_tracked" }
     ]
   }
   ```
3. `?force=true` invalidates the sheet cache - does not re-trigger GitHub checks. If the sheet fetch fails after cache invalidation: `503` (no fallback to stale data - caller explicitly requested a fresh fetch).
4. Normal load (no `force=true`) + valid cache hit → return cached data regardless of whether the sheet is currently reachable.
5. Normal load + cache expired + sheet unreachable → `503` with explicit error message.
6. `githubChecksAvailable: false` when `GITHUB_TOKEN` absent.
7. `apworldUpdates` reads from DB only - never triggers live GitHub calls. `updateStatus: not_tracked` assigned by this endpoint when `ApworldVersionChecker::check()` returns null (no GitHub URL). All four statuses (`not_tracked`, `unknown`, `up_to_date`, `update_available`) are produced here, not in Story 14.5.
8. `apworldUpdates` is always included in the response, even when `githubChecksAvailable: false` - so DB-computed statuses already known are still visible. Only the "Check updates" button is disabled client-side when checks are unavailable.

---

## Tasks

- [x] Add `CatalogSyncController` with `GET /api/v1/admin/catalog-sync` (admin firewall)
- [x] Inject `CatalogSyncService` (14.2 + 14.3) + `ApworldVersionChecker` (14.5)
- [x] Implement `?force=true` cache invalidation
- [x] Return `503` when: cache expired + sheet unreachable, OR `force=true` + sheet unreachable (no stale fallback in either case)
- [x] Map `ApworldVersionChecker::check()` returning null → `updateStatus: not_tracked` in response
- [x] Serialize response with all required fields
- [x] Run `cs-fixer`

---

## File List

- `src/GameSelection/Domain/ArchipelagoGame.php` (modified - add `apworldReleaseUrl` field, getter, update `recordApworldCheck()`)
- `migrations/Version20260511110000.php` (new - add `apworld_release_url` column)
- `src/CatalogSync/Application/CatalogSyncService.php` (modified - `invalidateCache()`, `getCachedAt()`, `isGoogleApiAvailable()`, cache format includes `cachedAt`)
- `src/CatalogSync/Application/ApworldVersionChecker.php` (modified - `isAvailable()`, pass `releaseUrl` to `recordApworldCheck()`)
- `src/CatalogSync/Presentation/CatalogSyncController.php` (new)
- `tests/Functional/CatalogSyncEndpointTest.php` (new - 6 tests)
- `config/packages/test/cache.yaml` (new - ArrayAdapter for test env)
- `.env.test` (modified - add `CATALOG_SPREADSHEET_ID`)

---

## Change Log

- 2026-05-11: Story implemented - `CatalogSyncController`, `apworld_release_url` migration, cache metadata, 6 functional tests (550/550 passing).

---

## Dev Agent Record

### Completion Notes

- `CatalogSyncController::catalogSync()`: admin guard → optional `invalidateCache()` on `?force=true` → `fetchSheet()` wrapped in try/catch → 503 on any `\Throwable` → EntityManager query for all games → `computeDiff()` → serialize full response.
- `apworld_release_url` column added to `ArchipelagoGame` entity (new nullable field); `recordApworldCheck()` now takes optional `?string $releaseUrl = null`; `ApworldVersionChecker::check()` passes the GitHub release `html_url`.
- `CatalogSyncService` cache format changed from `list<CatalogEntry>` to `array{entries, cachedAt}` (ISO 8601 string). `fetchSheet()` extracts `entries`; `getCachedAt()` returns `?DateTimeImmutable`; `invalidateCache()` calls `cache->delete()`. `isGoogleApiAvailable()` exposes token presence.
- `ApworldVersionChecker::isAvailable()` returns `'' !== $this->githubToken` - used by controller for `githubChecksAvailable` field.
- AC7 (`not_tracked` when no GitHub URL): fully handled by `ArchipelagoGame::computeApworldUpdateStatus()` - the controller never calls `ApworldVersionChecker::check()`.
- Test env cache: added `config/packages/test/cache.yaml` with `app: cache.adapter.array` - prevents filesystem cache from leaking across test kernel restarts within one PHPUnit run.
- `CATALOG_SPREADSHEET_ID=test-spreadsheet-id` added to `.env.test` so the container can instantiate `CatalogSyncService` in functional tests.
- cs-fixer: clean (0 files to fix).
