# Story 0.7: CI Hardening — Security Scanning, Postgres-Backed Tests, Dependency Automation

## Story

**As** the ArchiLAN maintainers,
**I want** the monorepo CI extended with dependency automation, security scanning, Postgres-backed backend tests, image scanning, and run hygiene,
**So that** known-vulnerable dependencies, Postgres-only bugs, and image CVEs are caught automatically — closing gaps that the current SQLite-based, scan-less pipeline misses.

## Status

approved

## Context

The monorepo already has solid CI (`.github/workflows/`):

- **`backend.yml`** — composer validate, PHPStan, CS Fixer, DDD validator, PHPUnit + coverage (clover + HTML artifact), composer cache. Runs on `api/**`.
- **`frontend.yml`** — lint, typecheck, test (`--if-present`), build, bundle analysis, pnpm cache. Runs on `frontend/**`.
- **`docker-publish.yml`** — builds + pushes `api-web`, `api-worker`, `frontend` images to ghcr on `main`/`develop`, build-only on PR, GHA layer cache.

Gaps this story closes:

1. **No dependency automation** — no Dependabot; composer/pnpm/Actions/Docker base images drift and accrue silent CVEs.
2. **No security scanning** — no `composer audit`/`pnpm audit`, no SAST (CodeQL), no image CVE scan on the published images.
3. **Backend tests run on SQLite** while prod is **Postgres** — Postgres-only behaviour is untested. This bit us directly (the `search` filter uses `ILIKE`, which SQLite rejects, so that path is currently untestable — see story 23.7 AC2 caveat).
4. **Run hygiene** — no `concurrency` (superseded runs keep burning minutes); coverage is produced but no floor; PHP tested only on 8.4 though `composer.json` allows 8.3.

Out of scope (tracked, not done here):
- **Microservices CI** — `bridge`, `orchestrateur`, `archipelago` are now standalone gitignored repos; their CI lives in their own repos (bridge: ruff/mypy/pytest; orchestrateur: `go test`/`go vet`; archipelago: image build). Noted for follow-up per-repo.
- **Branch protection** on `develop`/`main` — a GitHub repo setting, not a workflow file (checklist item in Dev Notes).

## Acceptance Criteria

**AC1 — Dependabot:** `.github/dependabot.yml` enables weekly updates for `composer` (`/api`), `npm` (`/frontend`, pnpm-compatible), `github-actions` (`/`), and `docker` (`/api`, `/frontend`) ecosystems, grouped sensibly to limit PR noise.

**AC2 — Concurrency:** all three workflows declare `concurrency: { group: <workflow>-${{ github.ref }}, cancel-in-progress: true }` so a new push to a branch/PR cancels its in-flight runs.

**AC3 — Dependency audit:** `backend.yml` runs `composer audit` and `frontend.yml` runs `pnpm audit` (failing on actionable advisories; document any accepted-risk allowlist inline).

**AC4 — SAST (CodeQL):** a `codeql.yml` workflow scans `php` and `javascript-typescript`, on PR + push to `develop`/`main` + a weekly schedule, with results in the Security tab.

**AC5 — Postgres-backed backend tests:** `backend.yml` runs PHPUnit against a `postgres:17` service container (`DATABASE_URL`/test env pointed at it), so functional tests exercise real Postgres. The previously-untestable `ILIKE` search paths (23.7) are re-enabled and asserted. SQLite may remain only for fast unit-only runs if justified.

**AC6 — Image scanning:** `docker-publish.yml` scans each built image with Trivy (or equivalent), failing on `HIGH`/`CRITICAL` fixable CVEs (with a documented ignore policy), uploading SARIF to the Security tab.

**AC7 — Coverage floor:** backend coverage fails under an agreed threshold (start conservative, e.g. current % − margin) — either via a PHPUnit/coverage-check step or Codecov with a PR status. No silent regression.

**AC8 — PHP matrix:** `backend.yml` runs the gate on PHP **8.3 and 8.4** (matrix), matching `composer.json`'s minimum.

**AC9 — Green everywhere:** all workflows pass on a representative PR; no increase in flake; total wall-clock stays reasonable (caching + concurrency offset the new jobs).

## Tasks / Subtasks

- [x] Task 1: Add `.github/dependabot.yml` (composer, npm, github-actions, docker; grouped; weekly). — **lot 1**
- [x] Task 2: Add `concurrency` blocks to `backend.yml`, `frontend.yml`, `docker-publish.yml`. — **lot 1**
- [~] Task 3: Add `composer audit` (backend) and `pnpm audit --audit-level high` (frontend) steps. — **lot 1, warn-only** (`continue-on-error: true`); flip to hard gate once Dependabot clears the backlog (Next.js <16.2.5 high, symfony/yaml low). AC3 not fully met until then.
- [ ] Task 4: CodeQL — **deferred** (removed from lot 1). Findings while attempting it: CodeQL has **no PHP** extractor (PHP stays on PHPStan), and code scanning on a **private** repo requires **GitHub Advanced Security / Code Security** (paid, Team/Enterprise) — the run failed with *"Advanced Security must be enabled… to use code scanning."* On a **public** repo it's free. Re-add (`javascript-typescript` only, with `permissions: actions: read`) once the repo is public or Code Security is enabled.
- [x] Task 5: **lot 2 — done.** `postgres:17` service in `backend.yml` (+ `pdo_pgsql`/`pgsql` ext); `.env.test` → Postgres (parity everywhere); functional tests refactored to a full-schema base (`FunctionalTestCase` does `DROP SCHEMA CASCADE` + `createSchema(getAllMetadata())`), per-class `SchemaTool` subsets removed from ~85 files; `ILIKE` search assertions re-enabled in `AdminGameLibraryTest`. Whole suite **912/912 green on Postgres**.
- [x] Task 6: **lot 3.** Trivy image scan in each `docker-publish.yml` job (load-build + scan, HIGH/CRITICAL, `ignore-unfixed`, table). **Warn-only** (`continue-on-error`); **no SARIF upload** — code scanning needs GHAS on this private repo (same constraint as CodeQL), so results are in logs only.
- [x] Task 7: **lot 3.** Backend coverage floor — a `Coverage floor` step parses the clover and fails under `MIN`. Shipped warn-only with `MIN=0` to read the baseline from the first CI run, then set `MIN` = baseline − margin and drop `continue-on-error`.
- [x] Task 8: **lot 3.** `backend.yml` matrix over PHP **8.3 + 8.4** (coverage report/floor gated to 8.4; composer cache keyed per PHP version).
- [ ] Task 9: Verify on a throwaway PR; tune thresholds/ignore lists; update `CLAUDE.md` quality-gate notes if commands change.

## Dev Notes

### Postgres service container (AC5) — the highest-value item

```yaml
services:
  postgres:
    image: postgres:17-alpine
    env:
      POSTGRES_DB: archilan_test
      POSTGRES_USER: archilan
      POSTGRES_PASSWORD: archilan
    ports: ['5432:5432']
    options: >-
      --health-cmd "pg_isready -U archilan" --health-interval 5s
      --health-timeout 5s --health-retries 10
```

Point the test env at it (`DATABASE_URL=postgresql://archilan:archilan@127.0.0.1:5432/archilan_test?serverVersion=17&charset=utf8`), run migrations or `SchemaTool` against it. Then drop the SQLite-only skips: notably `AdminGameLibraryTest` can assert the `apworld_ready` + `search` (`ILIKE`) combination that SQLite rejected (23.7 AC2). Confirm `FunctionalTestCase` builds the schema per-class regardless of driver.

### Dependabot grouping (AC1)

Group dev-dependency and minor/patch bumps to avoid PR spam; keep major bumps individual. Example ecosystems: `composer` (`/api`), `npm` (`/frontend`), `github-actions` (`/`), `docker` (`/api`, `/frontend`).

### CodeQL (AC4)

Use `github/codeql-action` with a language matrix `['php', 'javascript-typescript']`. PHP CodeQL is supported; scope paths to `api/` and `frontend/` to cut noise.

### Trivy (AC6)

`aquasecurity/trivy-action` on the locally-built image ref (scan before/after push). `exit-code: 1` on `HIGH,CRITICAL`, `ignore-unfixed: true`, plus a `.trivyignore` for accepted CVEs. Upload SARIF via `github/codeql-action/upload-sarif`.

### Run hygiene (AC2, AC7, AC8)

`concurrency` is one block per workflow. Coverage floor: simplest is a PHPUnit `--coverage-text` + a grep/threshold check, or adopt Codecov (`codecov/codecov-action`, already producing clover). PHP matrix: `strategy.matrix.php: ['8.3','8.4']` on the existing `setup-php` step.

### Branch protection (checklist — GitHub settings, not a file)

After these land, set required status checks on `develop`/`main`: Backend, Frontend, CodeQL, Docker builds; require 1 review; dismiss stale approvals. Capture in repo settings (can't be committed).

### Suggested PR slicing

1. **Quick wins** (low risk): Dependabot + concurrency + composer/pnpm audit + CodeQL (Tasks 1-4).
2. **Postgres tests** (structural): Task 5 + re-enabling 23.7 assertions.
3. **Image + coverage + matrix**: Tasks 6-8.

## File List

- `.github/dependabot.yml` — new
- `.github/workflows/codeql.yml` — new
- `.github/workflows/backend.yml` — modified (concurrency, composer audit, Postgres service, coverage floor, PHP matrix)
- `.github/workflows/frontend.yml` — modified (concurrency, pnpm audit)
- `.github/workflows/docker-publish.yml` — modified (concurrency, Trivy scan + SARIF)
- `api/tests/Functional/AdminGameLibraryTest.php` — modified (re-enable ILIKE/search assertions under Postgres)
- `api/.trivyignore` — new (if needed)
- `CLAUDE.md` — modified only if gate commands change

## Change Log

| Date       | Change                                                                 |
|------------|------------------------------------------------------------------------|
| 2026-06-06 | Story drafted. Covers dependency automation (Dependabot), security scanning (composer/pnpm audit, CodeQL, Trivy), Postgres-backed backend tests (re-enabling the ILIKE search paths deferred in 23.7), and run hygiene (concurrency, coverage floor, PHP 8.3/8.4 matrix). Microservices CI and branch protection noted as out-of-scope follow-ups. |
| 2026-06-06 | Approved. **Lot 1 (quick wins)** implemented: Dependabot, `concurrency` on all 3 workflows, and composer/pnpm audits (warn-only — real advisories found: Next.js <16.2.5 high, symfony/yaml low; Dependabot will open bumps, then flip audits to blocking). **CodeQL removed and deferred**: no PHP support, and private-repo code scanning needs paid GHAS/Code Security (public repo = free). Lot 1 shipped via PR #18 (other 4 checks green). Lots 2 (Postgres tests) and 3 (Trivy/coverage/matrix) pending. |
| 2026-06-06 | **Lot 2 (Postgres-backed tests)** done. Root finding: per-class `SchemaTool` subsets only worked on SQLite via table leakage; on Postgres (strict FK) they failed. Refactored `FunctionalTestCase` to build the FULL schema each test (`DROP SCHEMA CASCADE` + `createSchema(getAllMetadata())`) and removed the per-class subset blocks from ~85 files (3 `KernelTestCase` tests converted to `FunctionalTestCase`). `.env.test` → Postgres (parity local+CI; needs `archilan_test` DB, `doctrine:database:create --env=test`); `backend.yml` gains a `postgres:17` service + `pdo_pgsql`. `ILIKE` search assertions re-enabled (closes 23.7 AC2). Suite: **912/912 on Postgres** (~3 min; full-schema-per-test is the cost — DAMA transactional tests a possible future optimisation). |
