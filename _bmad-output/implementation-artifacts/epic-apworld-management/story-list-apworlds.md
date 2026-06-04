# Story: List uploaded apworlds

**Epic:** apworld-management  
**Story ID:** story-2-list-apworlds  
**Status:** in-progress

## Goal

Add a `GET /apworlds` endpoint to the orchestrateur that returns all uploaded apworlds
with their hash, game name, and version (when available).
Add the matching PHP client method `apworlds()->list()`.

## Acceptance Criteria

- `GET /apworlds` returns `{"apworlds": [{"hash": "...", "game": "...", "version": "..."}]}`
- `version` is omitted from the JSON when unknown/empty
- The endpoint is protected by Bearer auth
- On upload, a `{hash}.json` metadata file is stored alongside `{hash}` and `{hash}.yaml`
- Game name is extracted from the generated YAML template (`game:` line)
- Version is extracted from `{pkg}/__init__.py` in the apworld ZIP (`__version__ = "x.y.z"`)
- PHP `ApworldEntry` has `hash`, `game`, `version ?string`
- PHP `ApworldsClient::list()` returns `ApworldEntry[]`
- Apworlds uploaded before this feature (no `.json` sidecar) are silently omitted from the list

## Tasks

- [x] `storage.ApworldMeta` struct + `UploadApworldMeta()` + `ListApworlds()`
- [x] `service.UploadApworld()` stores metadata after template generation
- [x] `service.ListApworlds()` delegates to storage
- [x] `api.ApworldEntry` + `ApworldListResponse` types
- [x] `handleListApworlds()` handler
- [x] `GET /apworlds` route
- [x] PHP `ApworldEntry` DTO
- [x] PHP `ApworldsClient::list()`
- [x] `test.php` list section
