# Blind Hunter Review Prompt - Story 0.1

You are the Blind Hunter. You receive only this diff context, with no project spec and no repository context. Review adversarially for bugs, risky defaults, security issues, portability problems, and maintainability concerns.

Output findings as a Markdown list. Each finding must include:
- one-line title;
- severity: High / Medium / Low;
- evidence from the diff;
- why it matters;
- suggested fix.

## Diff Summary

New files:
- `README.md`
- `.editorconfig`
- `.gitignore`
- `.env.example`
- `docker-compose.yml`

## Diff

```diff
diff --git a/README.md b/README.md
new file mode 100644
--- /dev/null
+++ b/README.md
@@
+# archilan.fr
+archilan.fr is the future public community hub and internal event ERP for ArchiLAN, a Clermont-Ferrand association focused on Archipelago Multi World Randomizer events.
+The project is currently in Epic 0 setup. Planning artifacts are complete enough to guide implementation, but business-domain code must not start until the frontend, API, local environment, and quality gates are initialized.
+## Target Monorepo Structure
+archilan.fr/
+├── frontend/      # Next.js App Router, TypeScript, Tailwind, shadcn/ui
+├── api/           # Symfony LTS REST API, DDD + N-Tier
+├── _bmad-output/  # Planning and implementation artifacts
+├── _bmad/         # BMAD configuration
+├── .agents/       # Local BMAD skills
+└── .claude/       # Claude local settings and skills
+`frontend/` and `api/` are intentionally not initialized by Story 0.1.
+## Key Planning Artifacts
+- Product requirements: `_bmad-output/planning-artifacts/prd.md`
+- Architecture: `_bmad-output/planning-artifacts/architecture.md`
+- UX design specification: `_bmad-output/planning-artifacts/ux-design-specification.md`
+- Epics and stories: `_bmad-output/planning-artifacts/epics.md`
+## Implementation Rule
+Do not add business-domain code before Epic 0 setup is complete.
+## Local Services
+`docker-compose.yml` currently defines development service placeholders for PostgreSQL and optional Mercure.
+Copy `.env.example` values into your local environment as needed. Never commit real secrets.

diff --git a/.editorconfig b/.editorconfig
new file mode 100644
--- /dev/null
+++ b/.editorconfig
@@
+root = true
+[*]
+charset = utf-8
+end_of_line = lf
+insert_final_newline = true
+trim_trailing_whitespace = true
+[*.md]
+trim_trailing_whitespace = false
+[*.{yml,yaml,json,md,ts,tsx,css,scss,mjs,js}]
+indent_style = space
+indent_size = 2
+[*.php]
+indent_style = space
+indent_size = 4
+[Makefile]
+indent_style = tab

diff --git a/.gitignore b/.gitignore
new file mode 100644
--- /dev/null
+++ b/.gitignore
@@
+# Environment files
+.env
+.env.*
+!.env.example
+!frontend/.env.example
+!api/.env.example
+# Dependencies
+node_modules/
+frontend/node_modules/
+api/vendor/
+vendor/
+# Frontend build outputs
+.next/
+frontend/.next/
+out/
+frontend/out/
+dist/
+build/
+coverage/
+frontend/coverage/
+# Symfony runtime outputs
+var/cache/
+var/log/
+api/var/cache/
+api/var/log/
+# Logs and temporary files
+*.log
+logs/
+tmp/
+temp/
+*.tmp
+# OS files
+.DS_Store
+Thumbs.db
+# IDE/editor local files
+.vscode/
+*.swp
+*.swo
+# Docker local artifacts
+.docker/
+docker-data/
+postgres-data/
+mercure-data/

diff --git a/.env.example b/.env.example
new file mode 100644
--- /dev/null
+++ b/.env.example
@@
+COMPOSE_PROJECT_NAME=archilan
+POSTGRES_DB=archilan
+POSTGRES_USER=archilan
+POSTGRES_PASSWORD=archilan_dev_password
+POSTGRES_PORT=5432

diff --git a/docker-compose.yml b/docker-compose.yml
new file mode 100644
--- /dev/null
+++ b/docker-compose.yml
@@
+name: archilan
+services:
+  postgres:
+    image: postgres:18-alpine
+    container_name: archilan-postgres
+    restart: unless-stopped
+    environment:
+      POSTGRES_DB: ${POSTGRES_DB:-archilan}
+      POSTGRES_USER: ${POSTGRES_USER:-archilan}
+      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD:-archilan_dev_password}
+    ports:
+      - "${POSTGRES_PORT:-5432}:5432"
+    volumes:
+      - postgres-data:/var/lib/postgresql/data
+    healthcheck:
+      test: ["CMD-SHELL", "pg_isready -U ${POSTGRES_USER:-archilan} -d ${POSTGRES_DB:-archilan}"]
+      interval: 10s
+      timeout: 5s
+      retries: 5
+  mercure:
+    image: dunglas/mercure
+    container_name: archilan-mercure
+    restart: unless-stopped
+    profiles:
+      - realtime
+    environment:
+      SERVER_NAME: ":3001"
+      MERCURE_PUBLISHER_JWT_KEY: dev_mercure_publisher_key
+      MERCURE_SUBSCRIBER_JWT_KEY: dev_mercure_subscriber_key
+      MERCURE_EXTRA_DIRECTIVES: |
+        cors_origins http://localhost:3000
+        anonymous
+    ports:
+      - "3001:3001"
+volumes:
+  postgres-data:
```
