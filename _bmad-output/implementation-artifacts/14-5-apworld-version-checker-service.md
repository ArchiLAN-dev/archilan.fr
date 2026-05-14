# Story 14.5: APWorld version checker service (GitHub)

**Status:** review
**Epic:** 14 - APWorld Community Catalogue & Update Tracker
**Date:** 2026-05-11

---

## Story

As an admin,
I want to know when a newer version of an APWorld is available on GitHub,
So that I can update the stored APWorld at the right time.

---

## Acceptance Criteria

1. `ApworldVersionChecker::check(ArchipelagoGame $game)` calls `GET /repos/{owner}/{repo}/releases/latest`.
2. Owner/repo extracted from the stored (already-normalized) `apworld_source_url` - always in canonical form `https://github.com/{owner}/{repo}` after validation at write time (Story 14.7/14.8).
3. If `apworld_source_url` is null or not a GitHub URL: no-op, returns `null`. Caller (Story 14.6) maps a `null` result to `updateStatus: not_tracked` in the API response - this service does not produce that value.
4. If `GITHUB_TOKEN` absent: disabled, returns null, PSR-3 warning logged.
5. Returns: `latestTag`, `publishedAt`, `releaseUrl`, `assetName` (first `.apworld` asset or null), `isNewer` (bool).
6. Tag normalization: strip leading `v` before any comparison or storage (`v1.2.0` and `1.2.0` are equivalent). Store the normalized tag.
7. `ApworldVersionInfo` DTO returned on a successful check includes `updateStatus`:
   - `up_to_date` - normalized `latestTag == apworld_deployed_version`
   - `update_available` - they differ (both non-null)
   - `unknown` - `apworld_deployed_version` is null (no .apworld uploaded yet)
   `not_tracked` is NOT produced by this service - it is assigned by Story 14.6 when this service returns `null`.
8. Updates `apworld_latest_version` (normalized, no leading `v`) and `apworld_checked_at` after each successful check. Never modifies `apworld_deployed_version`.
9. Never synchronous on page load - triggered via `POST /api/v1/admin/catalog-sync/check-updates` or Symfony command `app:check-apworld-updates`.
10. Rate limit: `X-RateLimit-Remaining` logged; batch stops if <= 10 remaining, PSR-3 warning.
11. Unit-tested with HTTP mock: release exists (tag with `v` prefix vs without), no release, non-GitHub URL, missing token.

---

## Tasks

- [x] Create `ApworldVersionChecker` service
- [x] Normalize tag: strip leading `v` before comparison and storage
- [x] Call GitHub Releases API; map response to `ApworldVersionInfo` DTO
- [x] Persist `apworld_latest_version` + `apworld_checked_at` after each successful check (never touch `apworld_deployed_version`)
- [x] Implement rate-limit guard (`X-RateLimit-Remaining` <= 10 → stop + warn)
- [x] Add `POST /api/v1/admin/catalog-sync/check-updates` endpoint (admin only)
- [x] Add `app:check-apworld-updates` Symfony console command
- [x] Write unit tests with HTTP mock (5 scenarios: exists+v-prefix, exists+no-prefix, no release, non-GitHub URL, missing token)
- [x] Run `cs-fixer`

---

## File List

- `src/CatalogSync/Application/GithubRateLimitException.php` (new)
- `src/CatalogSync/Application/ApworldVersionInfo.php` (new)
- `src/CatalogSync/Application/ApworldVersionChecker.php` (new)
- `src/CatalogSync/Application/CheckApworldUpdatesService.php` (new)
- `src/CatalogSync/Presentation/CheckApworldUpdatesCommand.php` (new)
- `src/CatalogSync/Presentation/AdminCatalogSyncController.php` (modified - add POST /check-updates)
- `tests/Unit/CatalogSync/ApworldVersionCheckerTest.php` (new - 6 tests)
- `tests/Functional/AdminCatalogSyncTest.php` (modified - schema + 2 new tests)
- `config/services.yaml` (modified - `parameters: env(GITHUB_TOKEN)/env(GOOGLE_API_KEY)` defaults, `$githubToken` arg)
- `.env.test` (modified - `GITHUB_TOKEN=` added)

---

## Change Log

- 2026-05-11: Story implemented - `GithubRateLimitException`, `ApworldVersionInfo`, `ApworldVersionChecker`, `CheckApworldUpdatesService`, `CheckApworldUpdatesCommand`, 6 unit tests + 3 functional tests (544/544 passing).
- 2026-05-11: Review fixes - added `public bool $isNewer` to `ApworldVersionInfo` (AC5); added happy-path functional test verifying a tracked game triggers the checker, increments `checked`, and persists `apworldLatestVersion`/`apworldCheckedAt` via `MockHttpClient`.

---

## Dev Agent Record

### Completion Notes

- `ApworldVersionChecker::check()` extracts owner/repo from `apworld_source_url`, calls GitHub API with Bearer token, normalizes tag with `ltrim($tag, 'v')`, finds first `.apworld` asset, calls `$game->recordApworldCheck()` (entity method), computes `updateStatus` from deployed vs latest version, throws `GithubRateLimitException` if `X-RateLimit-Remaining <= 10` (after recording current game's result).
- `CheckApworldUpdatesService::checkAll()` queries games with `apworld_source_url LIKE 'https://github.com/%'`, iterates, catches `GithubRateLimitException` to stop batch, always flushes EntityManager at end.
- `POST /api/v1/admin/catalog-sync/check-updates` returns `{data: {checked: N, rateLimitHit: bool}}`.
- Symfony env var fix: `default::VAR` processor returns null for empty-string values (line 134: `'' !== $env`). Fixed by adding `parameters: env(GITHUB_TOKEN): ''` and `parameters: env(GOOGLE_API_KEY): ''` in `services.yaml` - these serve as compile-time defaults when the env var is absent. Also fixed the existing `CatalogSyncService` which used `default::GOOGLE_API_KEY`.
- Rate-limit test uses `try/catch` pattern to verify both the exception AND the entity state after the throw.
- `isNewer: bool` computed as `UPDATE_STATUS_UPDATE_AVAILABLE === $updateStatus`. `false` for both `unknown` and `up_to_date`.
- Happy-path functional test uses `MockHttpClient::setResponseFactory()` (public service in test container) to inject a GitHub release response; verifies `checked: 1` in response and `apworldLatestVersion`/`apworldCheckedAt` persisted via `entityManager->refresh($game)`.
- `GITHUB_TOKEN=test-github-token` in `.env.test` allows the checker to proceed to the HTTP call in functional tests (empty token triggers the "missing token" guard and returns null).
- cs-fixer: clean (0 files to fix after `GithubRateLimitException` brace style fix).
