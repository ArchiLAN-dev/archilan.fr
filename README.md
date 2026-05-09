# archilan.fr

archilan.fr is the future public community hub and internal event ERP for ArchiLAN, a Clermont-Ferrand association focused on Archipelago Multi World Randomizer events.

Epic 0 setup is complete. Business-domain implementation begins at Epic 1.

## Target Monorepo Structure

```text
archilan.fr/
├── frontend/      # Next.js App Router, TypeScript, Tailwind, shadcn/ui
├── api/           # Symfony LTS REST API, DDD + N-Tier
├── _bmad-output/  # Planning and implementation artifacts
├── _bmad/         # BMAD configuration
├── .agents/       # Local BMAD skills
└── .claude/       # Claude local settings and skills
```

`frontend/` and `api/` are initialized and functional. Epic 0 setup stories are complete through Story 0.5.

## Key Planning Artifacts

- Product requirements: `_bmad-output/planning-artifacts/prd.md`
- Architecture: `_bmad-output/planning-artifacts/architecture.md`
- UX design specification: `_bmad-output/planning-artifacts/ux-design-specification.md`
- Epics and stories: `_bmad-output/planning-artifacts/epics.md`
- Implementation stories: `_bmad-output/implementation-artifacts/` (Epic 0 complete through Story 0.6)

## Implementation Rule

Do not add business-domain code before Epic 0 setup is complete:

1. repository baseline;
2. Next.js frontend starter;
3. Symfony API starter;
4. agreed project structure;
5. quality gates and CI;
6. local development environment.

## Local Services

`docker-compose.yml` currently defines development service placeholders for PostgreSQL and optional Mercure. These support future setup stories and are safe for local development only.

```bash
# Start PostgreSQL only
docker compose up -d

# Start PostgreSQL + Mercure (SSE hub)
docker compose --profile realtime up -d
```

Copy environment examples before starting local development:

```bash
# Root Docker Compose defaults
cp .env.example .env

# Frontend local overrides
cp frontend/.env.example frontend/.env.local

# Symfony local overrides
cp api/.env.example api/.env.local
```

Never commit real secrets or local override files.

Start the frontend:

```bash
cd frontend
pnpm install
pnpm dev
```

The frontend runs at `http://localhost:3000`.

Start the Symfony API:

```bash
cd api
composer install
symfony server:start --port=8000
```

The API runs at `http://localhost:8000`. Application endpoints will be versioned under `/api/v1` when product stories add controllers.

## Quality Gates

Frontend checks run from `frontend/`:

```bash
pnpm install --frozen-lockfile
pnpm lint
pnpm typecheck
pnpm build
```

Frontend CI also checks for a `test` script. No frontend test script exists yet, so that step is skipped with an explicit message until a later story adds tests.

Backend checks run from `api/`:

```bash
composer install --no-interaction --prefer-dist --no-progress
composer validate
composer phpstan
composer cs-fixer
composer test
```

GitHub Actions workflows live in `.github/workflows/frontend.yml` and `.github/workflows/backend.yml`.
