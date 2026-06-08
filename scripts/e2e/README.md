# Weekly Run — live E2E smoke test

`scripts/e2e/weekly-smoke.sh` exercises the **real** Weekly Run flow against the running
`archilan` stack, to catch cross-service contract regressions that the unit/functional
suites (which use `Spy`/`Null` gateways) cannot. Background: epic 23 retrospective,
action item #1 (story 23.13).

## What it checks

1. **Generation** — triggers `POST /admin/weekly-runs/generate` and waits until the
   newest run for the template reports `hasOutput: true` (i.e. the orchestrateur generated,
   the `session.generated` webhook landed, `MarkWeeklyRunGenerated` set the output key).
2. **Artifact contract** — downloads `GET /admin/weekly-runs/{runId}/output` and asserts:
   - HTTP 200, `Content-Disposition: filename="weekly-run-{runId}.zip"`, zip magic `PK\x03\x04`;
   - the zip is **flat** and contains a `*.archipelago` (multidata) **and** at least one
     per-player patch (non-`.archipelago`, non-`_spoiler`);
   - **no** nested `*.zip` (zip-in-zip) and **not** a lone multidata.
3. **Launch restore** — opt-in + launch an entry, then asserts the session volume
   `/data/output` holds the loose files (multidata + patch) and the member patch listing
   (`/weekly-runs/{runId}/entries/{entryId}/patches`) exposes the patch.
4. **Seed validity** — asserts the launched ap-server is hosting the seed.

It provisions its own throwaway admin and **cleans everything up** (idempotent).

## Prerequisites

- The dev stack is up: `docker compose up -d` (postgres, `archilan-orchestrateur`,
  `archilan-minio`, rabbitmq, mercure) and the API + worker running.
- The `archipelago:latest` image is built (generation/launch need it).
- An **active weekly template** exists (or pass `E2E_TEMPLATE_ID`), with its game's
  apworld uploaded (`apworld_hash` set) — required for launch.
- Tools on PATH: `bash`, `curl`, `docker`, and the API's `php`/`bin/console`.

## Run

```bash
make e2e-weekly
# or
bash scripts/e2e/weekly-smoke.sh
```

Artifact-only run (skip launch/volume/seed, e.g. no Docker exec access):

```bash
E2E_SKIP_LAUNCH=1 bash scripts/e2e/weekly-smoke.sh
```

## Configuration (env)

| Var | Default | Notes |
|-----|---------|-------|
| `E2E_API_URL` | `http://localhost:8000/api/v1` | API base. |
| `E2E_CONSOLE` | `php bin/console` (run from `api/`) | DB/console runner. For a containerized API: `docker compose -f docker-compose.prod.yml exec -T api-web php bin/console`. |
| `E2E_ADMIN_EMAIL` / `E2E_ADMIN_PASSWORD` | `e2e-admin@archilan.local` / `E2eSmoke!pass1` | Throwaway admin the script creates + deletes. |
| `E2E_TEMPLATE_ID` | _(auto: first active template)_ | Pin a specific template. |
| `E2E_MINIO_CONTAINER` | `archilan-minio` | — |
| `E2E_SESSIONS_BUCKET` | `sessions` | — |
| `E2E_GEN_TIMEOUT` / `E2E_LAUNCH_TIMEOUT` | `120` / `90` | seconds. |
| `E2E_SKIP_LAUNCH` | `0` | `1` = artifact-only. |

## Notes & known limitations

- The session cookie is `Secure`; the script extracts the `Set-Cookie` value from the login
  response and sends it manually, so it works over plain `http://localhost` (where `curl`
  would otherwise refuse to send a `Secure` cookie).
- **CI wiring is a follow-up.** A full CI job needs Docker-in-Docker + a (large) archipelago
  image build; per story 23.13 AC8 this ships first as a local runbook + `make e2e-weekly`.
- Windows/git-bash: Docker path args are prefixed with `MSYS_NO_PATHCONV=1` where needed; on
  Linux (CI) this is a no-op.
