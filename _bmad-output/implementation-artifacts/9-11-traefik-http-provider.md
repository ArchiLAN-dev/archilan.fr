# Story 9.11: Traefik HTTP Provider - Dynamic WS Routing

Status: done

## Story

As the Archipelago platform operator,
I want Symfony to expose a Traefik-compatible HTTP provider endpoint for Archipelago sessions,
So that WebSocket connections are routed automatically to the correct runner without manual Traefik configuration.

## Acceptance Criteria

1. `GET /api/v1/internal/traefik` returns a valid Traefik HTTP provider JSON config with one router + one service entry per Run in `running` status.
2. Each router entry: rule `Host(`{runId}.ws.archilan.fr`)`, entryPoints `["websecure"]`, TLS enabled.
3. Each service entry: `loadBalancer.servers[0].url = "http://{host}:{port}"`.
4. Requests with missing or incorrect `X-Traefik-Token` header return 401.
5. When no runs are running, returns a valid empty config (routers: `{}`, services: `{}`), not an error.
6. `GET /api/v1/internal/sessions/{runId}/publisher-token` returns a Mercure publisher JWT scoped to `runs/{runId}/*` with ~1h TTL.
7. Publisher-token endpoint returns 401 without or with wrong `X-Internal-Secret` header, 404 for unknown session.

## Implementation

### New Files

- `src/Sessions/Application/TraefikConfigBuilder.php` - queries running sessions, builds Traefik HTTP provider config.
- `src/Sessions/Presentation/TraefikConfigController.php` - `GET /api/v1/internal/traefik`, X-Traefik-Token auth.
- `src/Sessions/Presentation/PublisherTokenController.php` - `GET /api/v1/internal/sessions/{runId}/publisher-token`, X-Internal-Secret auth.
- `tests/Functional/TraefikAndPublisherTokenTest.php` - 10 tests covering auth, empty config, excludes non-running, includes running (router rule, entrypoints, service URL), multiple running sessions, publisher token lifecycle.

### Config

`config/services.yaml` - added `$wsDomain` arg for `TraefikConfigBuilder`, `$traefikToken` arg for `TraefikConfigController`.

`.env` / `.env.test` - added `TRAEFIK_TOKEN`, `WS_DOMAIN`.

## Tests

10 / 10 passing.
