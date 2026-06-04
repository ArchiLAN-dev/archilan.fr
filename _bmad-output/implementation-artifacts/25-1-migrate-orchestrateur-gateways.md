# Story 25.1 — Migrate orchestrateur gateways to OrchestratorClient

**Epic:** 25 — Intégration des clients PHP (orchestrateur-client, bridge-client-bundle)  
**Branch:** `feature/epic-25-story-1-migrate-orchestrateur-gateways`  
**Status:** Done

---

## Context

The API contained two HTTP gateways calling the orchestrateur using raw `HttpClientInterface` + `x-api-key`:
- `Sessions\Infrastructure\RunnerGateway` — apworld upload and preflight
- `WeeklyRuns\Infrastructure\HttpWeeklyRunnerGateway` — weekly run lifecycle

Both are replaced by `archilan/orchestrateur-client` (OrchestratorClient) using `Authorization: Bearer`.

---

## Acceptance Criteria

- **AC1 (Done):** Full mapping completed before coding — see table below.
- **AC2 (Done):** `RunnerGateway` migrated: `preflight` → `sessions()->preflight()`, `uploadApworld` → `apworlds()->upload()` + `list()` + `getYamlTemplate()`.
- **AC3 (Done):** `HttpWeeklyRunnerGateway::terminate()` → `sessions()->delete()`.
- **AC4 (Done):** Dead method signatures removed from `RunnerGatewayInterface` and `NullRunnerGateway`.
- **AC5 (Done):** `NullRunnerGateway` static test-control mechanism (`$apworldUploadResult`) preserved.
- **AC6 (Done):** `services.yaml` wiring updated — `RunnerGateway` uses `@archilan.orchestrateur_client`; `HttpWeeklyRunnerGateway` adds `@archilan.orchestrateur_client`.
- **AC7 (Done):** `launchFromSeed` gap flagged — old HTTP client kept for this method pending dedicated story.
- **AC8 (Done):** All 4 quality gates green.

---

## Mapping table

| Old method | New | Notes |
|---|---|---|
| `RunnerGateway::preflight(eventId, slots[])` | `sessions()->preflight(eventId, PreflightRequest)` | Slot arrays → `PreflightSlot` objects; result converted back to array |
| `RunnerGateway::uploadApworld(contents, filename)` | `apworlds()->upload()` + `list()` + `getYamlTemplate()` | `UploadApworldResult` lacks `archipelagoGameName` → resolved via `list()`; `defaultYaml` → `getYamlTemplate(hash)`; `storageKey` = `hash.'.apworld'` |
| `RunnerGateway::writeYamls()` | **DEAD** — never called | Removed from interface and NullRunnerGateway |
| `RunnerGateway::generate()` | **DEAD** — handlers use DockerSocketClient | Removed |
| `RunnerGateway::launch()` | **DEAD** — handlers use DockerSocketClient | Removed |
| `RunnerGateway::restart()` | **DEAD** — handlers use `docker restart` directly | Removed |
| `RunnerGateway::stop()` | **DEAD** — handlers use `docker stop` directly | Removed |
| `RunnerGateway::getYamlsZip()` | **DEAD** — `SessionOrchestrator::getYamlsZip()` returns null | Removed |
| `HttpWeeklyRunnerGateway::terminate(sessionId)` | `sessions()->delete(sessionId)` | Old DELETE → new `deleteVoid` |
| `HttpWeeklyRunnerGateway::launchFromSeed(id, path)` | **GAP** — see below | Old HTTP client kept |

---

## Known gaps

### `launchFromSeed` — fundamental API incompatibility

**Old API (legacy runner):**
- `POST /sessions/{id}/launch-from-file`
- Body: `{ outputFile: "<server-side-path>", bridgeConfig: { RUN_ID, SYMFONY_INTERNAL_URL, MERCURE_HUB_URL, CENTRAL_API_SECRET, BRIDGE_INTERNAL_TOKEN } }`
- Response: sync, returns `containerPort`, `serverPassword`, `containerBridgePort`

**New API (orchestrateur-client):**
- `SessionsClient::launchFromFile(id, fileContents, filename, adminPassword, serverPassword?)`
- Body: multipart file contents
- Response: async `202 Accepted` — no port/password in response

**Implications:**
1. File must be read into memory and streamed, not passed by path
2. `adminPassword` is required (no concept in the old API)
3. Connection info (`port`, `bridgePort`, `password`) no longer available synchronously
4. `bridgeConfig` env-var injection mechanism is gone — bridge configuration must happen differently

**Decision:** Keep old HTTP client for `launchFromSeed` with an in-code `MIGRATION GAP` comment. Dedicated story needed before weekly runs can be migrated.
