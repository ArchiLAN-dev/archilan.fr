# Story 9.17: Orchestrateur PHP Client Library (Standalone Composer Package)

## Story

**As a** PHP application (Symfony API, CLI script, or any future PHP consumer),
**I want** a typed, standalone Composer package that wraps the orchestrateur REST API,
**So that** I can manage Archipelago sessions, containers, and apworlds through clean PHP objects, without embedding raw HTTP logic or array shapes in application code.

## Status

done

## Acceptance Criteria

**AC1 - Package scaffold:**
Directory `packages/orchestrateur-client/` exists in the monorepo root with:
- `composer.json` declaring `name: "archilan/orchestrateur-client"`, `type: "library"`, PHP `^8.2`, dependencies: `symfony/http-client-contracts: ^3.0`, `symfony/mime: ^6.4|^7.0`. PSR-4 autoload root `Archilan\\OrchestratorClient\\` → `src/`.
- `api/composer.json` adds a `path` repository pointing to `../packages/orchestrateur-client` and requires `archilan/orchestrateur-client: *`.

**AC2 - Entry point:**
`OrchestratorClient` is the single user-facing entry point:
```php
$client = new OrchestratorClient(
    baseUrl: 'http://orchestrateur:8000',
    apiKey: 'my-secret-key',
    httpClient: $symfonyHttpClient,   // Symfony\Contracts\HttpClient\HttpClientInterface
);
$client->sessions()   // SessionsClient
$client->containers() // ContainersClient
$client->apworlds()   // ApworldsClient
```
No global state; all config passed at construction. Internally, `OrchestratorClient` builds one `HttpTransport` instance and injects it into each sub-client.

**AC2b - Internal `HttpTransport`:**
`HttpTransport` (internal class, not part of the public API) centralises all HTTP plumbing so sub-clients contain zero HTTP logic:
- Injects `Authorization: Bearer {apiKey}` header on every request.
- Exposes `postVoid()`, `postJson()`, `postMultipart()`, `getJson()`, `getRaw()`, `deleteVoid()` - each maps the response to the correct return type or throws.
- Single `mapError()` private method: reads HTTP status + JSON `error` field and throws the appropriate `OrchestratorException` subtype. **All exception mapping lives here and nowhere else.**
- Adding a new endpoint to an existing sub-client = one method call to `$this->transport->*()`.
- Adding a new group of endpoints = new `FooClient(HttpTransport $transport)` + one `->foo(): FooClient` getter on `OrchestratorClient`.

**AC3 - Sessions API - all 8 endpoints covered:**
`SessionsClient` methods (all throw `OrchestratorException` on non-2xx):
- `generate(string $sessionId, string $adminPassword, string $seed = ''): void` → `POST /sessions/{id}/generate` (202)
- `launch(string $sessionId, string $adminPassword, ?string $serverPassword = null): void` → `POST /sessions/{id}/launch` (202)
- `launchFromFile(string $sessionId, string $fileContents, string $filename, string $adminPassword, ?string $serverPassword = null): void` → `POST /sessions/{id}/launch-from-file` multipart (202)
- `stop(string $sessionId): void` → `POST /sessions/{id}/stop` (204)
- `restart(string $sessionId): void` → `POST /sessions/{id}/restart` (202)
- `get(string $sessionId): SessionResponse` → `GET /sessions/{id}` (200)
- `delete(string $sessionId): void` → `DELETE /sessions/{id}` (204)
- `preflight(string $sessionId, PreflightRequest $request): PreflightResult` → `POST /sessions/{id}/preflight` (200)

**AC4 - Containers API - all 6 endpoints covered:**
`ContainersClient` methods:
- `create(string $sessionId, string $adminPassword, string $serverPassword = ''): CreateContainerResult` → `POST /containers` (202)
- `list(): ContainerResponse[]` → `GET /containers` (200)
- `get(string $sessionId): ContainerResponse` → `GET /containers/{id}` (200)
- `stop(string $sessionId): void` → `POST /containers/{id}/stop` (204)
- `reload(string $sessionId): void` → `POST /containers/{id}/reload` (204)
- `remove(string $sessionId): void` → `DELETE /containers/{id}` (204)

**AC5 - Apworlds API - both endpoints covered:**
`ApworldsClient` methods:
- `upload(string $fileContents, string $filename): UploadApworldResult` → `POST /apworlds` multipart (201)
- `getYamlTemplate(string $hash): string` → `GET /apworlds/{hash}/yaml` (200, returns raw YAML string)

**AC6 - Typed response DTOs:**
All response objects are `final readonly` classes (no public setters). Fields map 1-to-1 to the Go `types.go` JSON shapes:
- `SessionResponse`: `sessionId`, `status` (string), `bridgePort` (?int), `apPort` (?int), `serverPassword` (?string), `outputFile` (?string), `createdAt` (string), `updatedAt` (string).
- `ContainerResponse`: `sessionId`, `port` (int), `status` (string), `containerId` (?string), `image` (string), `createdAt` (string), `updatedAt` (string).
- `CreateContainerResult`: `sessionId`, `port` (int), `status` (string).
- `UploadApworldResult`: `hash` (string), `yaml` (string).
- `PreflightResult`: `valid` (bool), `slots` (PreflightSlotResult[]).
- `PreflightSlotResult`: `slotId` (string), `proposedName` (string), `errors` (string[]).

**AC7 - Typed request DTOs:**
- `PreflightRequest` holds `slots` (`PreflightSlot[]`).
- `PreflightSlot`: `slotId`, `playerName`, `archipelagoGameName`, `options` (`SlotOption[]`), `apworldStorageKey`, `playerYaml` - all optional except `slotId`.
- `SlotOption`: `key` (string), `required` (bool), `currentValue` (mixed), `defaultValue` (mixed).

**AC8 - Exception hierarchy:**
All exceptions extend `OrchestratorException`. Specific subtypes:
- `SessionNotFoundException` - HTTP 404
- `ConflictException` - HTTP 409 (carries `errorCode` string from the `error` JSON field: `"already_in_progress"` or `"not_ready"`)
- `ServiceUnavailableException` - HTTP 503 (port pool exhausted, storage not configured)
- `TransportException` - network error or timeout (wraps `\Throwable $previous`)

The client never returns `['error' => ...]` arrays - it always throws.

**AC9 - Authentication:**
All requests include `Authorization: Bearer {apiKey}` header. No endpoint is called without it.

**AC10 - Unit tests:**
`tests/` directory with tests for all three clients using a mock `HttpClientInterface`. Cover: happy path, 404 → `SessionNotFoundException`, 409 → `ConflictException`, 503 → `ServiceUnavailableException`, transport error → `TransportException`. PHPUnit ^11, PHPStan level 9, no CS Fixer required in the library itself (the API layer's gates don't scan `packages/`).

**AC11 - Symfony API wiring:**
`api/config/services.yaml` adds a named service `archilan.orchestrateur_client` of type `OrchestratorClient`, constructed from `%env(ORCHESTRATEUR_BASE_URL)%` and `%env(ORCHESTRATEUR_API_KEY)%`. Existing `RunnerGateway` and `HttpWeeklyRunnerGateway` are NOT yet migrated in this story - they remain unchanged. The new client is available for injection in future stories.

## Tasks / Subtasks

- [ ] Task 1: Create `packages/orchestrateur-client/` with `composer.json`, `src/` PSR-4 structure, `tests/` directory
- [ ] Task 2: Implement `HttpTransport` (internal) - `postVoid`, `postJson`, `postMultipart`, `getJson`, `getRaw`, `deleteVoid`, `mapError`
- [ ] Task 3: Add `OrchestratorClient` entry point - constructs `HttpTransport`, exposes `sessions()`, `containers()`, `apworlds()` getters
- [ ] Task 4: Create all response DTOs (`SessionResponse`, `ContainerResponse`, `CreateContainerResult`, `UploadApworldResult`, `PreflightResult`, `PreflightSlotResult`)
- [ ] Task 5: Create request DTOs (`PreflightRequest`, `PreflightSlot`, `SlotOption`)
- [ ] Task 6: Create exception hierarchy (`OrchestratorException`, `SessionNotFoundException`, `ConflictException`, `ServiceUnavailableException`, `TransportException`)
- [ ] Task 7: Implement `SessionsClient` (all 8 methods - each delegates to `HttpTransport`)
- [ ] Task 8: Implement `ContainersClient` (all 6 methods)
- [ ] Task 9: Implement `ApworldsClient` (upload multipart + getYamlTemplate)
- [ ] Task 10: Write unit tests - mock `HttpClientInterface` at the `HttpTransport` level; cover happy path + all 4 exception types for each client
- [ ] Task 11: Add `path` repository + `require` entry to `api/composer.json`; register `archilan.orchestrateur_client` in `api/config/services.yaml`
- [ ] Task 12: Run API quality gates (`phpstan`, `php-cs-fixer`, `phpunit`) - fix any issues

## Dev Notes

### Package location in the monorepo

```
archilan.fr/
  packages/
    orchestrateur-client/
      composer.json           (name: archilan/orchestrateur-client)
      src/
        OrchestratorClient.php
        Http/
          HttpTransport.php   ← internal; not part of public API
        Sessions/
          SessionsClient.php
          Request/
            PreflightRequest.php
            PreflightSlot.php
            SlotOption.php
          Response/
            SessionResponse.php
            PreflightResult.php
            PreflightSlotResult.php
        Containers/
          ContainersClient.php
          Response/
            ContainerResponse.php
            CreateContainerResult.php
        Apworlds/
          ApworldsClient.php
          Response/
            UploadApworldResult.php
        Exception/
          OrchestratorException.php
          SessionNotFoundException.php
          ConflictException.php
          ServiceUnavailableException.php
          TransportException.php
      tests/
        Http/
          HttpTransportTest.php   ← error mapping tests live here
        Sessions/
          SessionsClientTest.php
        Containers/
          ContainersClientTest.php
        Apworlds/
          ApworldsClientTest.php
```

### HTTP dependency rationale

The library depends on `symfony/http-client-contracts` (not PSR-18) because:
1. The Symfony API already injects `HttpClientInterface` everywhere.
2. `symfony/http-client` is installable standalone for CLI consumers.
3. Avoids a PSR-17 factory dependency that adds friction for simple use cases.

For multipart (apworld upload, launch-from-file), use `Symfony\Component\Mime\Part\DataPart` + `Symfony\Component\Mime\Part\Multipart\FormDataPart` - the same pattern already used in `RunnerGateway.php`.

### HttpTransport - extensibility pattern

`HttpTransport` is `final` and `@internal`. Sub-clients receive it via constructor injection. Its public surface:

```php
// Returns decoded JSON array - throws on non-2xx
/** @return array<string, mixed> */
public function postJson(string $path, mixed $body = []): array {}

// Returns nothing - throws on non-2xx
public function postVoid(string $path, mixed $body = []): void {}

// Sends multipart/form-data - throws on non-2xx
public function postMultipart(string $path, FormDataPart $form): array {}

// GET → decoded JSON - throws on non-2xx
/** @return array<string, mixed> */
public function getJson(string $path): array {}

// GET → raw string (for YAML) - throws on non-2xx
public function getRaw(string $path): string {}

// DELETE - throws on non-2xx
public function deleteVoid(string $path): void {}
```

**Adding a new sub-client later:**
```php
// 1. New file: src/Snapshots/SnapshotsClient.php
final class SnapshotsClient {
    public function __construct(private readonly HttpTransport $transport) {}

    public function create(string $sessionId): SnapshotResponse {
        $data = $this->transport->postJson("/sessions/{$sessionId}/snapshots");
        return SnapshotResponse::fromArray($data);
    }
}

// 2. One line in OrchestratorClient:
public function snapshots(): SnapshotsClient { return $this->snapshotsClient; }
```

No error-handling code to write, no auth to wire - `HttpTransport` handles it all.

### Authentication header

The orchestrateur uses `Authorization: Bearer {apiKey}` (confirmed from `orchestrateur/internal/api/middleware.go`). Do **not** confuse with legacy gateways in `api/` that used `x-api-key`.

### Status codes contract (from Go source)

| Endpoint | Success | Notable errors |
|---|---|---|
| `generate` | 202 | 400 (missing adminPassword), 409 (already_in_progress), 503 (storage) |
| `launch` | 202 | 400, 404, 409 (not_ready) |
| `launch-from-file` | 202 | 400, 500 |
| `stop` | 204 | 404, 500 |
| `restart` | 202 | 404, 409 (not_ready), 500 |
| `get` | 200 | 404 |
| `delete` | 204 | 404, 500 |
| `preflight` | 200 | 400 |
| `POST /containers` | 202 | 400, 409 (already exists), 503 (port exhausted) |
| `DELETE /containers/{id}` | 204 | 404, 500 |
| `POST /apworlds` | 201 | 400, 500, 503 |

### Future migration (not in scope here)

Once this library is available, future stories should migrate `RunnerGateway.php` and `HttpWeeklyRunnerGateway.php` to use it internally, removing their duplicated raw HTTP logic. This story only introduces the package and registers it - it does not change existing gateways.

### Symfony service registration example

```yaml
# api/config/services.yaml
services:
  archilan.orchestrateur_client:
    class: Archilan\OrchestratorClient\OrchestratorClient
    arguments:
      $baseUrl: '%env(ORCHESTRATEUR_BASE_URL)%'
      $apiKey: '%env(ORCHESTRATEUR_API_KEY)%'
      $httpClient: '@http_client'
```
