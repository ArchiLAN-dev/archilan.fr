# Story 14.4: IGDB enrichment service

**Status:** review
**Epic:** 14 - APWorld Community Catalogue & Update Tracker
**Date:** 2026-05-11

---

## Story

As an admin,
I want game description and cover image pre-filled from IGDB when creating a game,
So that I do not have to search for this information manually.

---

## Acceptance Criteria

1. `IgdbEnrichmentService::search(string $gameName)` wraps the existing `IgdbHttpClientInterface` with `limit=3`; returns up to 3 ranked candidates: `igdbId`, `name`, `summary`, `coverUrl` (cover_big, 264×374).
2. OAuth2 + token cache handled entirely by the existing `IgdbHttpClient` - no duplication.
3. If `TWITCH_CLIENT_ID` / `TWITCH_CLIENT_SECRET` absent: `IgdbHttpClient` is not wired (`StubIgdbHttpClient` used), service returns `[]`, PSR-3 warning logged, no exception thrown.
4. `GET /api/v1/admin/catalog-sync/igdb-preview?name=...` (admin only) returns up to 3 candidates.
5. Endpoint returns raw candidate data only: `igdbId`, `name`, `summary`, `coverUrl`. `coverImageAlt` is NOT returned by the API - it is generated client-side by the creation form (Story 14.10) when the admin selects a candidate.

---

## Tasks

- [x] Create `IgdbEnrichmentService` injecting `IgdbHttpClientInterface` (reuse existing)
- [x] Implement `search()` - delegate to `IgdbHttpClientInterface::searchGames($name, 3)`, map to `IgdbCandidate` DTOs
- [x] Graceful no-op when stub is injected (PSR-3 warning in service)
- [x] Add `GET /api/v1/admin/catalog-sync/igdb-preview` admin endpoint
- [x] Run `cs-fixer`

---

## File List

- `src/CatalogSync/Application/IgdbCandidate.php` (new)
- `src/CatalogSync/Application/IgdbEnrichmentService.php` (new)
- `src/CatalogSync/Presentation/AdminCatalogSyncController.php` (new)
- `tests/Unit/CatalogSync/IgdbEnrichmentServiceTest.php` (new - 4 tests)
- `tests/Functional/AdminCatalogSyncTest.php` (new - 4 tests)

---

## Change Log

- 2026-05-11: Story implemented - `IgdbCandidate` DTO, `IgdbEnrichmentService`, `AdminCatalogSyncController` with IGDB preview endpoint, 8 tests (535/535 passing).

---

## Dev Agent Record

### Completion Notes

- `IgdbEnrichmentService` wraps `IgdbHttpClientInterface::searchGames($name, 3)` - OAuth2 + token cache entirely in existing `IgdbHttpClient`, no duplication.
- Graceful no-op: service catches `\Throwable` from `searchGames`, logs PSR-3 warning (`igdb_enrichment.search_unavailable`), returns `[]`. Covers `IgdbAuthException` (missing credentials) and `IgdbSearchException`.
- Functional test `testIgdbPreviewReturnsEmptyArrayWhenStubConfiguredToFail` uses `StubIgdbHttpClient::$authFails = true` to simulate missing TWITCH credentials → endpoint returns `data: []` without error.
- `coverImageAlt` absent from response (AC5): verified by `assertArrayNotHasKey('coverImageAlt', $candidate)` in functional test.
- PHPUnit notice fix applied: tests that use stubs without expectations now use `createStub` instead of `createMock`; logger assertion tests use inline `AbstractLogger` spy class instead of mocking `LoggerInterface` directly.
- cs-fixer: clean (0 files to fix).
