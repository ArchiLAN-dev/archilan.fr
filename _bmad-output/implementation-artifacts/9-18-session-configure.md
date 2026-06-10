# Story 9.18: Session Configure - Draft Creation with Apworld Validation

## Story

**As a** PHP consumer of the orchestrateur API,
**I want** a single `POST /sessions/{id}/configure` endpoint that receives slot definitions (apworld hash + player YAML), validates that each apworld exists, uploads the data to storage, and creates the session in a `draft` state,
**So that** I can prepare a session and get immediate validation feedback before triggering generation, without relying on the Symfony API to pre-populate Minio.

## Status

done

## Acceptance Criteria

**AC1 - New endpoint:**
`POST /sessions/{sessionId}/configure` (authenticated) accepts:
```json
{
  "slots": [
    {
      "slotId": "slot-1",
      "playerName": "Jean",
      "apworldHash": "0fd8936...",
      "archipelagoGameName": "A Link to the Past",
      "playerYaml": "Game: A Link to the Past\n..."
    }
  ]
}
```
Returns 400 if body is malformed or `slots` is empty or any `slotId` is missing.
Returns 409 if the session is already in `generating`, `launching`, or `running` state.
Returns 503 if storage (Minio) is not configured.
Returns 200 with a preflight-style validation result otherwise.

**AC2 - Validation logic:**
For each slot:
- `apworldHash` must be non-empty and the binary must exist in the `apworlds` Minio bucket; field error if not found.
- `playerYaml` must be non-empty; field error if missing.
- `playerName` defaults to `"Player"` if empty.
- `archipelagoGameName` defaults to `"Custom"` if empty (used for slot name abbreviation only).

**AC3 - Persistence on valid configure:**
If and only if ALL slots pass validation:
- Each `playerYaml` is uploaded to `sessions/{sessionId}/yamls/{slotId}.yaml`.
- A `manifest.json` is uploaded to `sessions/{sessionId}/manifest.json` with deduplicated apworld refs (`{hash, filename}` where `filename = hash + ".apworld"`).
- The session is upserted in the DB with status `"draft"` (insert if new; update if in a terminal or draft state).

**AC4 - No persistence on invalid configure:**
If any slot fails validation, 200 is returned with `valid: false` and slot errors. Nothing is written to Minio or the DB.

**AC5 - Response shape (reuses preflight format):**
```json
{
  "valid": true,
  "slots": [
    { "slotId": "slot-1", "proposedName": "Jean_LP1", "errors": [] }
  ]
}
```

**AC6 - Session state machine:**
`draft` is a new state preceding `pending`. The existing `generate` endpoint already allows re-generation from any non-active state, so no changes to `generate` are required. State machine: `draft → (generate) → pending → generating → generated → launching → running → stopped/crashed`.

**AC7 - PHP client:**
`SessionsClient::configure(string $sessionId, ConfigureRequest $request): PreflightResult`
- `ConfigureRequest` holds `ConfigureSlot[]`.
- `ConfigureSlot`: `slotId` (required), `playerName`, `apworldHash` (required), `archipelagoGameName`, `playerYaml` (required).
- Returns existing `PreflightResult` (response shape is identical to preflight).

## Tasks / Subtasks

- [ ] Task 1: Add `ApworldExists`, `UploadSessionYaml`, `UploadManifest` to `storage/client.go`
- [ ] Task 2: Add `ConfigureSession` service method + input/output types to `service/session.go`
- [ ] Task 3: Add `ConfigureSessionRequest`, `ConfigureSlotEntry` to `api/types.go`
- [ ] Task 4: Add `handleConfigureSession` to `api/session_handlers.go`
- [ ] Task 5: Register route `POST /sessions/{sessionId}/configure` in `api/router.go`
- [ ] Task 6: Rebuild orchestrateur Docker image and restart container
- [ ] Task 7: Add PHP client DTOs (`ConfigureRequest`, `ConfigureSlot`) and `configure()` method to `SessionsClient`

## Dev Notes

### Why not persist on validation failure?
Partial Minio state (some yamls uploaded, manifest missing or incomplete) would leave the session in an inconsistent state that could be silently used by `generate`. An atomic approach is safer: either everything is valid and committed, or nothing is written.

### Apworld filename in manifest
The apworld binary is stored in Minio as `{hash}` (no extension). The manifest stores it with `filename = hash + ".apworld"` so that Archipelago's world loader recognizes the `.apworld` extension when the tar is extracted into `/data/worlds/`.

### State transition safety
`generate` already skips sessions in `generating`, `launching`, `running` states. `draft` is treated the same as any other non-active state - `generate` will proceed and call `buildDataTar` which reads the manifest written by `configure`.
