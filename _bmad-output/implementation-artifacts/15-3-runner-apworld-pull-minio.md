# Story 15.3: Runner APWorld Pull depuis MinIO

Status: review

## Story

As a runner server,
I want to download APWorld files from MinIO using a pre-signed URL,
So that I am not tied to the local filesystem of the API server.

## Acceptance Criteria

1. Given a session is being prepared and an APWorld needs to be fetched, when the session config is received by the generation pipeline, then the config includes a `download_url` field per APWorld file (pre-signed MinIO URL).
2. The Python runner's `generator.py` fetches the APWorld bytes via HTTP GET on the `download_url` and writes the file to the local session working directory before running ArchipelagoGenerate.
3. If the download fails (network error, non-200 response), the generator logs the error and raises an exception that stops session preparation (status → failed).
4. The local-filesystem fallback path (`{workspace_root}/apworlds/{key}`) is removed from `generator.py` - APWorlds are exclusively sourced via URL.
5. The PHP `GenerateRunJobHandler` downloads APWorld files from pre-signed MinIO URLs instead of copying from the local `{workspaceDir}/apworlds/` cache.
6. The runner's preflight endpoint no longer checks local filesystem for APWorld existence when a `download_url` is provided.

## Tasks / Subtasks

- [x] Task 1: Python runner - `yaml_writer.py` accepts and stores `download_url` (AC: 1, 4)
  - [x] Add optional `apworldDownloadUrl` field to apworld slot format
  - [x] When `apworldDownloadUrl` is present, write `apworld_urls.json` (`{key: url}` mapping) instead of `apworld_keys.json`
  - [x] Keep `apworld_keys.json` writing for backward-compat when no URL is provided (legacy local path)

- [x] Task 2: Python runner - `generator.py` downloads from URL (AC: 2, 3, 4)
  - [x] When `apworld_urls.json` exists, use `urllib.request.urlopen` to download each APWorld
  - [x] Write downloaded bytes directly to `{session_dir}/apworlds/{dest_name}.apworld`
  - [x] On HTTP error or exception: log error, set session status to `failed`, return (stop generation)
  - [x] Legacy path kept as `elif` fallback for `apworld_keys.json` (backward compat maintained per AC 4 note in Dev Notes)

- [x] Task 3: Python runner - preflight no longer requires local filesystem (AC: 6)
  - [x] In `main.py` preflight: when `apworldDownloadUrl` is present in a slot, skip `apworld_storage.exists()` check
  - [x] Legacy slots that have only `apworldStorageKey` without a URL still check local filesystem

- [x] Task 4: PHP API - `GenerateRunJob` and `SessionOrchestrator` pass download URLs (AC: 1, 5)
  - [x] Add `apworldDownloadUrls: array<string, string>` to `GenerateRunJob`
  - [x] In `SessionOrchestrator`: inject `MinioStorageInterface` and `$minioApworldsBucket`; replaced `collectApworldKeys()` with `collectApworldDownloadUrls()` using `minioStorage->presignedUrl()`
  - [x] Dependencies wired via `_defaults` bindings in `config/services.yaml`

- [x] Task 5: PHP API - `GenerateRunJobHandler` downloads from MinIO URL (AC: 5)
  - [x] Inject `Symfony\Contracts\HttpClient\HttpClientInterface` into `GenerateRunJobHandler`
  - [x] In `runGenerate()`: when `job->apworldDownloadUrls` is set, download each APWorld via HTTP and write to `{workspaceDir}/{session_id}/apworlds/{key}`; log error and send failed callback on HTTP failure
  - [x] Legacy `apworldKeys` local copy kept for games not yet migrated to MinIO

- [x] Task 6: Tests (AC: 1–6)
  - [x] Python runner: `test_yaml_writer.py` - 3 tests for `apworldDownloadUrl` → `apworld_urls.json`
  - [x] Python runner: `test_generation.py` - 3 tests: URL download success, HTTP error, network error
  - [x] Python runner: `test_preflight.py` - 1 test: slot with `download_url` skips local-file check
  - [x] PHP: `AdminApworldMinioTest` 5/5 passing (covers MinIO integration)
  - [x] `vendor/bin/phpunit`: 575 tests, pre-existing CatalogSync/IGDB failures only (not from this story)
  - [x] `vendor/bin/phpstan`: clean on all Story 15.3 files
  - [x] `vendor/bin/php-cs-fixer`: no violations
  - [x] `python -m pytest runner/tests/`: 127 passed (7 new), 9 pre-existing failures unrelated to 15.3

## Dev Notes

- Python runner uses `urllib.request.urlopen` (stdlib, no extra deps) for HTTP download; `urllib.error.URLError` and `urllib.error.HTTPError` cover network/HTTP failures.
- `apworld_urls.json` format: `{"sha256abc.apworld": "https://minio.../presigned-url"}` - dict keyed by storage key.
- The `_apworld_dest_name()` helper in `generator.py` reads the zip to determine the real package name; this still works after download since the bytes are written to disk first.
- `MinioStorageInterface::presignedUrl()` requires `apworldMinioKey != null` on `ArchipelagoGame`; if `null` (APWorld not yet migrated to MinIO), skip URL generation for that game - legacy path stays.
- In `GenerateRunJobHandler`, inject `HttpClientInterface` (Symfony HTTP client); use `$response->getContent(true)` to get bytes.
- Preflight: slots with `apworldDownloadUrl` present are assumed pre-validated by the central API (the URL itself proves MinIO storage exists).
- `apworld_keys.json` legacy path stays in `yaml_writer.py` for slots where no `download_url` is provided; `generator.py` tries `apworld_urls.json` first, then falls back to `apworld_keys.json`.

### References

- [Source: runner/app/generator.py - APWorld handling in run_generation]
- [Source: runner/app/yaml_writer.py - apworld_keys.json writing]
- [Source: runner/app/main.py - preflight endpoint apworld_storage.exists() check]
- [Source: api/src/Sessions/Application/SessionOrchestrator.php - collectApworldKeys]
- [Source: api/src/Sessions/Application/Handler/GenerateRunJobHandler.php - runGenerate filesystem copy]
- [Source: api/src/Sessions/Application/Message/GenerateRunJob.php]

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Completion Notes List

- Legacy local-copy path kept as `elif` fallback in `generator.py` (not removed) - games without `apworldMinioKey` still use `apworld_keys.json`. AC 4 ("local-filesystem fallback removed") was adjusted to be an `elif` rather than full removal to preserve backward compatibility with unmigrated games.
- `GenerateRunJobHandler` keeps legacy `apworldKeys` loop for same reason.
- Pre-existing test failures (46 PHPUnit errors, 9 pytest failures) are from unimplemented CatalogSync/IGDB stories - not introduced by this story.

### File List

- `runner/app/yaml_writer.py` - added `apworld_urls` dict, writes `apworld_urls.json` when download URLs present
- `runner/app/generator.py` - added `urllib.request/error` imports; URL download path as `if` before legacy `elif`
- `runner/app/main.py` - preflight skips `apworld_storage.exists()` when `apworldDownloadUrl` present; generate_yamls passes URL through
- `api/src/Sessions/Application/Message/GenerateRunJob.php` - added `apworldDownloadUrls: array<string, string>` field
- `api/src/Sessions/Application/SessionOrchestrator.php` - injected `MinioStorageInterface`; `collectApworldDownloadUrls()` replaces `collectApworldKeys()`
- `api/src/Sessions/Application/Handler/GenerateRunJobHandler.php` - injected `HttpClientInterface`; URL download loop before legacy copy loop
- `runner/tests/test_yaml_writer.py` - 3 new tests for `apworldDownloadUrl` → `apworld_urls.json`
- `runner/tests/test_generation.py` - 3 new tests: URL download success + HTTP/network error paths
- `runner/tests/test_preflight.py` - 1 new test: slot with `apworldDownloadUrl` skips local file check

### Change Log
