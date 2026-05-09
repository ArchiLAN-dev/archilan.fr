---
stepsCompleted: ["step-01-init", "step-02-context", "step-03-starter", "step-04-decisions", "step-05-patterns", "step-06-structure", "step-07-validation", "step-08-complete"]
inputDocuments:
  - "_bmad-output/planning-artifacts/prd.md"
  - "_bmad-output/planning-artifacts/ux-design-specification.md"
  - "_bmad-output/planning-artifacts/product-brief-archilan.fr.md"
  - "_bmad-output/planning-artifacts/product-brief-archilan.fr-distillate.md"
workflowType: 'architecture'
project_name: 'archilan.fr'
user_name: 'Jean'
date: '2026-04-24'
lastStep: 8
status: 'complete'
completedAt: '2026-04-24'
---

# Architecture Decision Document

_This document builds collaboratively through step-by-step discovery. Sections are appended as we work through each architectural decision together._

---

## Project Context Analysis

### Requirements Overview

**Functional Requirements - 54 FRs, 8 catégories :**

| Catégorie | FRs | Composantes architecturales |
|---|---|---|
| Community Hub & Content | FR1–FR9 | SSR public pages, CMS léger, Twitch embed conditionnel, OG tags |
| Event Management | FR10–FR20 | Backoffice CRUD, cycle draft→published→in-progress→completed, game library admin, export data |
| Event Participation | FR21–FR28 | Game selection intake (workflow multi-étapes), atomic capacity check, SSE seat counter |
| User & Access Management | FR29–FR37 | Comptes publics, RBAC 3 rôles (lambda/membre/admin), gestion profil, RGPD erasure |
| Payments & Commerce | FR38–FR42 | HelloAsso embedded checkout × 3 (tickets, cotisation, boutique), sync ERP automatique |
| Real-Time Updates | FR43–FR45 | SSE × 3 canaux (seat counter, admin feed, Twitch status), polling fallback 30s |
| Communications | FR46–FR48 | Emails transactionnels (confirmation, notification capacité), messagerie admin→participant |
| Legal & Compliance | FR49–FR54 | Pages légales statiques (ML, PC, CGV, CGU), cookie consent persistant, withdrawal control |

**Non-Functional Requirements - implications architecturales critiques :**

- **Performance :** LCP < 2.5s sur public → SSR/ISR Next.js obligatoire ; API p95 < 200ms public, < 500ms auth → Symfony profiling + index DB soignés
- **Sécurité :** JWT httpOnly + Secure + SameSite ; Argon2id ; RBAC à 100% côté Symfony API ; CSRF ; CORS restreint à l'origine Next.js ; atomic capacity (lock serveur)
- **Scalabilité :** API stateless (scaling horizontal sans session affinity) ; SSE proportionnel aux connexions actives ; schéma DB capable de milliers d'utilisateurs
- **Accessibilité :** WCAG 2.1 AA → composants Radix UI (shadcn/ui) avec sémantique ARIA correcte, contrastes AA vérifiés, navigation clavier complète
- **Fiabilité des intégrations :** chaque intégration externe (HelloAsso, email, SSE, Twitch) a un chemin de dégradation explicite défini dans le PRD

**Scale & Complexity :**

- **Domaine primaire :** Full-stack web application (Next.js SSR/CSR + Symfony LTS REST API)
- **Niveau de complexité :** Medium - pas d'enterprise multi-tenancy, pas de ML, mais cumul de : RGPD+LCEN, OAuth2 tiers, SSE temps-réel, workflow domaine-spécifique, RBAC multi-couche, atomic concurrency
- **Composantes architecturales estimées :** ~12 bounded contexts/modules côté Symfony ; ~8 domaines de pages côté Next.js ; 3 canaux SSE indépendants

---

### Technical Constraints & Dependencies

| Contrainte | Nature | Impact architectural |
|---|---|---|
| **HelloAsso OAuth2 REST API** | Intégration externe imposée (association française, standard HelloAsso) | BFF Next.js pour proxy OAuth2 ; adapter pattern côté Symfony ; circuit breaker + retry pour sync ERP |
| **Twitch embed** | Contrainte UX (stream live détecté sans action utilisateur) | Polling Twitch Helix API côté serveur ou SSE dédié ; consentement cookie requis avant iframe |
| **SSE (Server-Sent Events)** | Choix PRD (temps-réel sans WebSocket) | Symfony EventSource endpoint ; long-running connections → nécessite serveur async ou reverse proxy configuré (nginx keep-alive) |
| **RGPD / CNIL / LCEN** | Obligations légales françaises | Pages statiques (ML, PC, CGV, CGU) ; cookie consent actif dès le premier rendu ; data retention enforced par scheduled tasks ; account deletion supprime toutes les données personnelles |
| **DDD + N-Tier** | Décision d'architecture préalable | Domain layer isolé de delivery et infrastructure ; Symfony comme framework = couche infrastructure ; Next.js = delivery layer uniquement |
| **Loi 1901 (ASBL)** | Contrainte métier | Processus d'adhésion aligné sur les statuts ; site ne peut pas simuler une activité commerciale |
| **Atomic capacity check** | NFR sécurité/fiabilité | Verrou pessimiste (SELECT FOR UPDATE) ou optimistic locking sur `Registration.capacity` ; jamais confié au frontend |

---

### Cross-Cutting Concerns Identified

1. **Authentification & RBAC** - Toutes les routes API et pages backoffice. JWT httpOnly géré côté Symfony ; middleware Next.js pour redirections. Les 3 rôles traversent Event Management, User Management, Payments et Legal.

2. **Real-time (SSE)** - 3 features indépendantes (seat counter, admin feed, Twitch status) partageant la même infrastructure de push. Connexion SSE long-polling = contrainte sur configuration serveur commune.

3. **Conformité RGPD** - Transversal à User Management (erasure, portability), Payments (retention HelloAsso), Communications (consentement email), Legal (pages + banner). Schéma de données doit encoder les `retention_periods` par type de donnée.

4. **Intégration HelloAsso** - Traverse Payments (checkout), User Management (membre sync), Event Management (payment status sur inscriptions). Chemin de dégradation unique à définir une seule fois et réutilisé.

5. **Gestion des erreurs & dégradation gracieuse** - Chaque intégration externe (HelloAsso, email, SSE, Twitch) a un état de fallback. Architecture doit exposer des interfaces d'intégration testables avec des mocks sandbox.

6. **Game Selection Intake** - Workflow domaine-spécifique le plus complexe. Traverse Event Management (config par événement), Event Participation (saisie multi-étapes), et Export (admin). Bounded context à part entière avec son propre modèle de domaine.

---

## Starter Template Evaluation

### Primary Technology Domain

Full-stack web application - Next.js SSR/CSR (frontend) + Symfony 7.4 LTS REST API (backend). Structure monorepo dans le dépôt `archilan.fr/` existant.

### Structure du monorepo

```
archilan.fr/
├── frontend/      ← Next.js App Router (TypeScript, Tailwind, shadcn/ui)
├── api/           ← Symfony 7.4 LTS REST API (DDD + N-Tier)
├── _bmad-output/
└── .claude/
```

Choix monorepo : cohérence des revues (Jean + IA voient les deux couches simultanément), déploiement indépendant possible, pas d'overhead multi-repo pour une équipe de 10 bénévoles.

### Starter 1 - Next.js frontend

**Commande d'initialisation :**

```bash
pnpm create next-app@latest frontend \
  --typescript \
  --tailwind \
  --eslint \
  --app \
  --src-dir \
  --import-alias "@/*"
```

**Décisions architecturales couvertes par le starter :**

| Dimension | Décision | Rationale |
|---|---|---|
| Language | TypeScript strict | Cohérence avec DDD côté API, sécurité typage sur les DTOs |
| Router | App Router (Next.js 15) | SSR/ISR natif, Server Components, layouts - requis par NFR LCP |
| Styling | Tailwind CSS v4 | Stack décidé ; intégration shadcn/ui native |
| Linting | ESLint + `@next/eslint-plugin-next` | Qualité automatique après chaque batch IA |
| Structure | `src/` directory | Séparation propre entre sources et config à la racine |
| Import alias | `@/*` → `./src/*` | Imports absolus cohérents dans toutes les couches |
| Build | Turbopack (dev) / Webpack (prod) | Turbopack par défaut en dev, production reste stable |

**Post-init - ajouts obligatoires :**

```bash
cd frontend
pnpm dlx shadcn@latest init    # design system (Radix UI + tokens)
pnpm add next-themes           # dark mode system
pnpm add @tanstack/react-query # data fetching + cache client-side
```

### Starter 2 - Symfony 7.4 LTS API

**Commande d'initialisation :**

```bash
symfony new api --version=lts
```

**Décisions architecturales couvertes par le starter :**

| Dimension | Décision | Rationale |
|---|---|---|
| Version | Symfony 7.4 LTS | Support bug-fix nov. 2028 - durée de vie projet alignée |
| PHP requis | PHP 8.3+ | Readonly properties, typed class constants - DDD expressif |
| Kernel | MicroKernel (skeleton) | Uniquement les bundles nécessaires, pas de full-stack Symfony |
| Config | YAML + PHP attributes | Annotations dépréciées ; attributes natifs PHP 8 |

**Post-init - bundles requis :**

```bash
cd api
composer require symfony/orm-pack
composer require symfony/security-bundle
composer require lexik/jwt-authentication-bundle
composer require symfony/serializer-pack
composer require symfony/messenger
composer require symfony/mailer
composer require --dev phpstan/phpstan
composer require --dev friendsofphp/php-cs-fixer
composer require --dev symfony/test-pack
```

---

## Core Architectural Decisions

### Decision Priority Analysis

**Critical Decisions (Block Implementation):**
- Database: PostgreSQL 18, with Doctrine ORM and Doctrine Migrations.
- API style: Symfony REST API under `/api/v1`, no GraphQL for v1.
- Authentication: Symfony Security + LexikJWTAuthenticationBundle, JWT stored in httpOnly Secure SameSite cookies.
- Realtime: Mercure/SSE for live counters and admin feeds, with 30s polling fallback.
- Frontend state: React Server Components for public SSR where possible, TanStack Query for client-side API state.

**Important Decisions (Shape Architecture):**
- DDD modules organized by bounded context, not by technical layer alone.
- API Platform is deferred by default for v1 unless a later implementation story proves a concrete need.
- PostgreSQL row-level locking / transactional service for event capacity.
- Messenger for async jobs: HelloAsso sync retries, emails, post-registration notifications.
- CI must run backend and frontend quality gates before merge.

**Deferred Decisions (Post-MVP):**
- Automated Archipelago server deployment: separate future service / project.
- Public paid membership self-service: v2 workflow.
- Community voting and advanced member perks: v2.
- Full observability stack provider: choose during deployment planning.

### Data Architecture

**Database:**
PostgreSQL 18 is the target relational database. It fits the project's need for transactional integrity, capacity locking, relational reporting, JSON columns where useful for game options, and long-term scalability.

**Persistence:**
Doctrine ORM is used for persistence, with Doctrine Migrations as the only schema migration mechanism. Migrations must be generated and reviewed, not hand-written ad hoc unless the schema change requires custom SQL.

**DDD Data Modeling:**
Symfony backend is organized around bounded contexts:
- Identity & Access
- Events
- Registrations
- Game Selection
- Content
- Payments / HelloAsso
- Realtime
- Communications
- Legal / Consent

Domain rules live in Application/Domain services, not controllers. Controllers only translate HTTP requests into commands/queries.

**Validation:**
- Frontend validation improves UX only.
- Backend validation is authoritative.
- Domain invariants enforce business rules such as capacity, registration windows, role permissions, and private event access.

**Caching:**
- No Redis required for MVP baseline.
- HTTP caching / ISR on public Next.js pages.
- Symfony cache for low-risk reference data such as game library metadata.
- Add Redis only when Messenger transport, locks, or cache pressure justify it.

### Authentication & Security

**Authentication:**
Symfony Security + LexikJWTAuthenticationBundle. Tokens are issued by the API and stored in httpOnly Secure SameSite cookies. No JWT in localStorage.

**Authorization:**
RBAC is enforced in Symfony API for every protected route:
- `ROLE_USER` / lambda
- `ROLE_MEMBER`
- `ROLE_ADMIN`

Frontend route guards are UX only and never security boundaries.

**Password Security:**
Passwords use Symfony's current password hasher configuration with Argon2id where available.

**CSRF / CORS:**
- CORS restricted to the Next.js origin.
- CSRF protection required for cookie-authenticated mutations.
- API accepts JSON only for application endpoints.

**Auditability:**
Admin actions that mutate roles, events, registrations, payments, or private access must be logged.

### API & Communication Patterns

**API Style:**
REST API, versioned under `/api/v1`.

**API Platform:**
Deferred for v1. Rationale: the project needs explicit DDD use cases and controlled response contracts more than automatic CRUD exposure. It may be added later for admin-oriented resources if it does not leak domain complexity.

**Response Format:**
- Success responses return explicit JSON DTOs.
- Errors use a consistent problem-details style shape with stable machine-readable codes.
- Dates are ISO 8601 strings in UTC.

**Realtime:**
Mercure/SSE handles:
- public event seat counter;
- admin registration feed;
- Twitch live/offline state if server-side polling is used.

Fallback: client polling every 30 seconds when SSE disconnects.

**Async Communication:**
Symfony Messenger handles:
- confirmation emails;
- HelloAsso sync retries;
- admin notifications;
- non-blocking post-registration processing.

### Frontend Architecture

**Rendering:**
- Landing, event detail, content/news, legal pages: SSR/SSG/ISR.
- Registration flow and backoffice: CSR where interactivity dominates.
- Public pages remain SEO-first.

**State Management:**
- Server state: TanStack Query.
- Local UI state: React state / reducers.
- Global client state is avoided unless a feature proves the need.

**Component Strategy:**
- shadcn/ui and Radix primitives for accessible base components.
- Feature components grouped by domain.
- Shared UI primitives stay generic and domain-free.

**Forms:**
Use React Hook Form + schema validation if added during implementation; backend remains authoritative.

**Performance:**
- Twitch embed lazy-loaded and gated by cookie consent.
- Public assets use Next Image where appropriate.
- No heavy client bundle on public explainer sections unless interaction is required.

### Infrastructure & Deployment

**Repository:**
Monorepo with independently deployable `frontend/` and `api/`.

**Environment Configuration:**
Each app owns its `.env.example`. Secrets never appear in committed files.

**CI Quality Gates:**
Backend:
- Composer validate
- PHPStan max practical level
- PHP CS Fixer dry-run
- PHPUnit

Frontend:
- pnpm install frozen lockfile
- ESLint
- TypeScript check
- tests
- build

**Deployment Shape:**
- Next.js deployed as web frontend.
- Symfony API deployed as stateless backend.
- PostgreSQL managed database.
- Mercure hub can be introduced as a separate service when SSE features are implemented.
- Messenger workers deployed separately from HTTP API when async jobs begin.

### Decision Impact Analysis

**Implementation Sequence:**
1. Initialize `frontend/` and `api/`.
2. Configure quality gates and CI.
3. Establish API contract, auth baseline, and project structure.
4. Implement Identity & RBAC before backoffice.
5. Implement Events and Registration with transactional capacity.
6. Add Game Selection Intake.
7. Add HelloAsso, email, SSE, Twitch, and legal consent layers.

**Cross-Component Dependencies:**
- Registration depends on Identity, Events, Game Selection, and capacity locking.
- HelloAsso sync depends on Registration and Payments.
- Backoffice depends on RBAC and admin-safe DTOs.
- SSE depends on Events/Registration state changes.
- Legal consent affects Twitch embed, analytics, and account flows.

---

## Implementation Patterns & Consistency Rules

### Pattern Categories Defined

**Critical Conflict Points Identified:**
AI agents could diverge on naming, DDD boundaries, API formats, frontend state, test locations, error handling, async events, and validation ownership. These rules are mandatory for consistent implementation.

### Naming Patterns

**Database Naming Conventions:**
- Tables: plural `snake_case`, e.g. `users`, `event_registrations`, `game_options`.
- Columns: `snake_case`, e.g. `created_at`, `registration_window_starts_at`.
- Foreign keys: `{table_singular}_id`, e.g. `user_id`, `event_id`.
- Indexes: `idx_{table}_{columns}`, e.g. `idx_users_email`.
- Unique constraints: `uniq_{table}_{columns}`.

**API Naming Conventions:**
- REST endpoints use plural nouns: `/api/v1/events`, `/api/v1/users`.
- Nested resources only when ownership is clear: `/api/v1/events/{eventId}/registrations`.
- Route params use camelCase in frontend route builders, Symfony route placeholders may use `{id}` or `{eventId}`.
- Query params use camelCase externally: `registrationStatus`, `eventType`.

**Code Naming Conventions:**
- PHP classes: PascalCase, one class per file.
- PHP namespaces follow bounded contexts: `App\Events\Application`, `App\Registrations\Domain`.
- TypeScript components: PascalCase files for components, e.g. `EventCard.tsx`.
- Non-component TS files: kebab-case or domain noun, e.g. `event-api.ts`, `registration-schema.ts`.
- Variables/functions: camelCase in TS, camelCase in PHP methods/properties.

### Structure Patterns

**Backend Organization:**
Each bounded context follows:
- `Domain/` for entities, value objects, domain services, repository interfaces.
- `Application/` for commands, queries, handlers, use cases, DTOs.
- `Infrastructure/` for Doctrine repositories, external adapters, Messenger handlers.
- `Presentation/` for controllers and request/response mapping.

Controllers must not contain business rules.

**Frontend Organization:**
- `src/app/` contains routes only.
- `src/features/{feature}/` contains feature components, hooks, schemas, API clients.
- `src/components/ui/` contains shadcn/ui primitives only.
- `src/lib/` contains framework-level utilities.
- `src/types/` contains shared DTO and API types.

**Tests:**
- Backend tests live under `api/tests/{Unit,Functional,Integration}`.
- Frontend tests live near features or under `frontend/tests`, but each feature must keep one consistent pattern once started.
- E2E tests live under `frontend/e2e`.

### Format Patterns

**API Response Formats:**
- Success collection: `{ "data": [...], "meta": { ... } }`.
- Success item: `{ "data": { ... } }`.
- Empty success: HTTP 204 when no body is needed.
- Error: `{ "error": { "code": "...", "message": "...", "details": [...] } }`.

**Data Exchange Formats:**
- JSON fields exposed to frontend use camelCase.
- Database fields use snake_case.
- Dates are ISO 8601 UTC strings.
- Money values use integer cents plus currency when needed.
- IDs are strings in API responses, even when backed by UUIDs.

### Communication Patterns

**Domain Events:**
- Event names use past tense: `RegistrationConfirmed`, `EventCapacityReached`.
- Async integration events are handled through Messenger.
- Event payloads must include stable identifiers and occurred-at timestamp.
- Events must not expose raw Doctrine entities.

**Frontend State:**
- TanStack Query owns server state.
- React local state owns UI state.
- No global store unless approved in architecture updates.
- Mutations invalidate precise query keys, not broad app-wide caches.

### Process Patterns

**Error Handling:**
- Backend throws domain-specific exceptions and maps them centrally to API errors.
- Frontend displays user-facing messages from known error codes.
- Unknown errors use a generic fallback and are logged.
- Validation errors are field-addressable.

**Loading States:**
- Page-level loading only for route transitions or first render.
- Button-level loading for submissions.
- Skeletons for async lists/cards.
- SSE disconnect is a subtle stale-state indicator, not a blocking modal.

### Enforcement Guidelines

**All AI Agents MUST:**
- Respect bounded context ownership.
- Keep controllers thin.
- Keep frontend feature code inside `src/features/{feature}`.
- Use the documented API response and error format.
- Add or update tests with each behavior change.
- Run the relevant quality gate before considering work complete.

**Pattern Enforcement:**
- PHPStan, PHP CS Fixer, PHPUnit for backend.
- ESLint, TypeScript check, frontend tests, build for frontend.
- Architecture deviations must be documented in `architecture.md` before implementation.

### Pattern Examples

**Good Examples:**
- `App\Registrations\Application\Command\RegisterForEventCommand`
- `App\Events\Domain\EventCapacity`
- `/api/v1/events/{eventId}/registrations`
- `src/features/events/components/EventCard.tsx`
- `idx_event_registrations_event_id_user_id`

**Anti-Patterns:**
- Business rules inside Symfony controllers.
- Frontend calling HelloAsso directly from browser.
- JWT stored in localStorage.
- Mixed `snake_case` and `camelCase` in the same API response.
- Shared UI components importing feature-specific logic.

---

## Project Structure & Boundaries

### Complete Project Directory Structure

```text
archilan.fr/
├── README.md
├── .gitignore
├── .editorconfig
├── .env.example
├── docker-compose.yml
├── .github/
│   └── workflows/
│       ├── ci.yml
│       ├── frontend.yml
│       └── api.yml
├── docs/
│   ├── architecture/
│   ├── decisions/
│   └── operations/
├── _bmad/
├── _bmad-output/
│   ├── planning-artifacts/
│   └── implementation-artifacts/
├── frontend/
│   ├── package.json
│   ├── pnpm-lock.yaml
│   ├── next.config.ts
│   ├── tsconfig.json
│   ├── eslint.config.mjs
│   ├── postcss.config.mjs
│   ├── components.json
│   ├── .env.example
│   ├── public/
│   │   ├── images/
│   │   ├── icons/
│   │   └── social/
│   ├── e2e/
│   │   ├── public-discovery.spec.ts
│   │   ├── registration-flow.spec.ts
│   │   └── backoffice-events.spec.ts
│   ├── tests/
│   │   ├── setup.ts
│   │   └── accessibility/
│   └── src/
│       ├── app/
│       │   ├── layout.tsx
│       │   ├── page.tsx
│       │   ├── globals.css
│       │   ├── events/
│       │   │   ├── page.tsx
│       │   │   └── [eventSlug]/
│       │   │       ├── page.tsx
│       │   │       └── register/
│       │   │           └── page.tsx
│       │   ├── news/
│       │   │   ├── page.tsx
│       │   │   └── [slug]/
│       │   │       └── page.tsx
│       │   ├── account/
│       │   │   ├── page.tsx
│       │   │   └── settings/
│       │   │       └── page.tsx
│       │   ├── login/
│       │   │   └── page.tsx
│       │   ├── signup/
│       │   │   └── page.tsx
│       │   ├── admin/
│       │   │   ├── layout.tsx
│       │   │   ├── page.tsx
│       │   │   ├── events/
│       │   │   ├── registrations/
│       │   │   ├── users/
│       │   │   ├── content/
│       │   │   └── payments/
│       │   ├── legal/
│       │   │   ├── mentions-legales/page.tsx
│       │   │   ├── confidentialite/page.tsx
│       │   │   ├── cgv/page.tsx
│       │   │   └── cgu/page.tsx
│       │   └── api/
│       │       └── bff/
│       │           └── helloasso/
│       ├── components/
│       │   ├── ui/
│       │   ├── layout/
│       │   └── feedback/
│       ├── features/
│       │   ├── auth/
│       │   ├── events/
│       │   ├── registrations/
│       │   ├── game-selection/
│       │   ├── content/
│       │   ├── twitch/
│       │   ├── helloasso/
│       │   ├── legal-consent/
│       │   └── admin/
│       ├── lib/
│       │   ├── api-client.ts
│       │   ├── query-client.ts
│       │   ├── env.ts
│       │   └── routes.ts
│       ├── providers/
│       │   ├── AppProviders.tsx
│       │   └── QueryProvider.tsx
│       └── types/
│           ├── api.ts
│           ├── event.ts
│           ├── registration.ts
│           └── user.ts
└── api/
    ├── composer.json
    ├── composer.lock
    ├── symfony.lock
    ├── phpstan.neon
    ├── .php-cs-fixer.dist.php
    ├── phpunit.xml.dist
    ├── .env.example
    ├── bin/
    │   ├── console
    │   └── phpunit
    ├── config/
    │   ├── packages/
    │   ├── routes/
    │   ├── services.yaml
    │   └── jwt/
    ├── migrations/
    ├── public/
    │   └── index.php
    ├── src/
    │   ├── Kernel.php
    │   ├── Shared/
    │   │   ├── Domain/
    │   │   ├── Application/
    │   │   ├── Infrastructure/
    │   │   └── Presentation/
    │   ├── Identity/
    │   │   ├── Domain/
    │   │   ├── Application/
    │   │   ├── Infrastructure/
    │   │   └── Presentation/
    │   ├── Events/
    │   │   ├── Domain/
    │   │   ├── Application/
    │   │   ├── Infrastructure/
    │   │   └── Presentation/
    │   ├── Registrations/
    │   │   ├── Domain/
    │   │   ├── Application/
    │   │   ├── Infrastructure/
    │   │   └── Presentation/
    │   ├── GameSelection/
    │   │   ├── Domain/
    │   │   ├── Application/
    │   │   ├── Infrastructure/
    │   │   └── Presentation/
    │   ├── Content/
    │   ├── Payments/
    │   ├── Realtime/
    │   ├── Communications/
    │   └── Legal/
    └── tests/
        ├── Unit/
        ├── Functional/
        ├── Integration/
        └── Fixtures/
```

### Architectural Boundaries

**API Boundaries:**
- Public REST API lives under `/api/v1`.
- Next.js BFF routes are allowed only for frontend-specific proxy needs, especially HelloAsso/CORS concerns.
- Business logic never lives in Next.js BFF routes.
- Symfony controllers map HTTP to application commands/queries only.

**Component Boundaries:**
- Public pages compose feature components.
- `components/ui` is design-system-only and must not import features.
- Feature modules may import shared UI and `lib`, but shared UI must not import feature code.

**Service Boundaries:**
- External services are wrapped by infrastructure adapters:
  - HelloAsso adapter in `Payments/Infrastructure`.
  - Twitch adapter in `Realtime` or `Content` depending on final use.
  - Mail provider adapter in `Communications/Infrastructure`.
  - Mercure publisher in `Realtime/Infrastructure`.

**Data Boundaries:**
- Doctrine entities stay inside backend domain/infrastructure boundaries.
- API responses expose DTOs, never Doctrine entities.
- Frontend types mirror API DTOs, not database tables.

### Requirements to Structure Mapping

**Community Hub & Content (FR1-FR9):**
- Frontend: `src/app`, `src/features/content`, `src/features/twitch`
- Backend: `Content`, `Events`, `Realtime`
- Public SEO and OG tags live in Next.js route metadata.

**Event Management (FR10-FR20):**
- Frontend: `src/app/admin/events`, `src/features/events`, `src/features/admin`
- Backend: `Events`, `GameSelection`, `Registrations`

**Event Participation (FR21-FR28):**
- Frontend: `src/app/events/[eventSlug]/register`, `src/features/registrations`, `src/features/game-selection`
- Backend: `Registrations`, `Events`, `GameSelection`

**User & Access Management (FR29-FR37):**
- Frontend: `src/features/auth`, `src/app/account`, `src/app/admin/users`
- Backend: `Identity`

**Payments & Commerce (FR38-FR42):**
- Frontend: `src/features/helloasso`, `src/app/api/bff/helloasso`
- Backend: `Payments`

**Real-Time Updates (FR43-FR45):**
- Frontend: `src/features/events`, `src/features/admin`, `src/features/twitch`
- Backend: `Realtime`, `Registrations`, `Events`

**Communications (FR46-FR48):**
- Frontend: admin messaging UI in `src/features/admin`
- Backend: `Communications`

**Legal & Compliance (FR49-FR54):**
- Frontend: `src/app/legal`, `src/features/legal-consent`
- Backend: `Legal`, `Identity`

### Integration Points

**Internal Communication:**
- Frontend communicates with Symfony through typed API client functions.
- Symfony application handlers coordinate use cases.
- Domain events trigger Messenger async handlers.
- Realtime updates are published from backend use cases after successful transactions.

**External Integrations:**
- HelloAsso: backend OAuth/API sync plus frontend embedded checkout/BFF proxy where required.
- Twitch: server-side live status detection plus consent-gated iframe embed.
- Email: Symfony Mailer through `Communications`.
- Mercure: backend publishes topics, frontend subscribes through EventSource-compatible client.

**Data Flow:**
1. User action in Next.js feature component.
2. Typed API client sends request to Symfony `/api/v1`.
3. Controller maps request to command/query.
4. Application handler enforces use case.
5. Domain validates invariants.
6. Infrastructure persists and publishes async/realtime events.
7. DTO response returns to frontend.
8. TanStack Query updates or invalidates relevant cache keys.

### File Organization Patterns

**Configuration Files:**
- Root contains repo-level config and orchestration.
- `frontend/` owns frontend env, lint, TS, Next config.
- `api/` owns Symfony env, PHPStan, CS Fixer, PHPUnit config.

**Source Organization:**
- Backend source is bounded-context-first.
- Frontend source is route-first in `app/`, feature-first in `features/`.

**Test Organization:**
- Backend unit tests target domain/application rules.
- Backend functional tests target API endpoints.
- Backend integration tests target persistence and external adapters.
- Frontend tests target feature behavior and accessibility.
- E2E tests target critical user journeys.

**Asset Organization:**
- Static public assets live in `frontend/public`.
- Generated or uploaded runtime assets are not stored in Git.
- Social preview images live under `frontend/public/social`.

### Development Workflow Integration

**Development Server Structure:**
- `frontend` runs Next.js.
- `api` runs Symfony.
- PostgreSQL and optional Mercure run through Docker Compose.

**Build Process Structure:**
- Frontend builds independently with `pnpm build`.
- API validates independently with Composer/PHP tooling.
- CI runs both tracks independently and then reports aggregate status.

**Deployment Structure:**
- Frontend and API can deploy separately.
- Database migrations run as an explicit deployment step.
- Messenger workers and Mercure hub are separate runtime processes when enabled.

---

## Architecture Validation Results

### Coherence Validation

**Decision Compatibility:**
The architecture is coherent. Next.js App Router, Symfony 7.4 LTS, PostgreSQL 18, Doctrine ORM, Messenger, LexikJWTAuthenticationBundle, TanStack Query, and Mercure/SSE work together without identified version or responsibility conflicts.

The major boundaries are explicit:
- Next.js owns public rendering, UX, and client orchestration.
- Symfony owns business rules, persistence, authentication, authorization, and integrations.
- PostgreSQL owns transactional consistency.
- Messenger owns async workflows.
- Mercure/SSE owns realtime delivery.

**Pattern Consistency:**
The implementation patterns support the architectural decisions:
- Backend DDD contexts match the project structure.
- API response formats match the frontend typed-client strategy.
- Naming rules cover database, API, PHP, and TypeScript.
- Error handling keeps domain exceptions out of controllers and raw backend details out of UI copy.

**Structure Alignment:**
The proposed monorepo structure supports all core decisions:
- `frontend/` and `api/` can be developed and deployed independently.
- Bounded contexts are visible at the filesystem level.
- Shared frontend UI is separated from feature logic.
- External integrations have clear backend adapter locations.

### Requirements Coverage Validation

**Feature Coverage:**
All MVP feature categories are architecturally supported:
- Community hub and content: `frontend/src/app`, `Content`, `Events`, `Realtime`.
- Event management: `Events`, `Registrations`, `GameSelection`, admin frontend.
- Event participation: registration flow, game selection feature, backend registration context.
- User and access management: `Identity`, auth frontend, admin users.
- Payments and commerce: `Payments`, HelloAsso feature, BFF proxy where required.
- Realtime: `Realtime`, Mercure/SSE, polling fallback.
- Communications: `Communications`, Symfony Mailer, Messenger.
- Legal and compliance: `Legal`, consent frontend, static legal pages.

**Functional Requirements Coverage:**
FR1-FR54 are covered by mapped modules and integration points. No functional requirement is architecturally orphaned.

**Non-Functional Requirements Coverage:**
- Performance: SSR/SSG/ISR for public pages, lazy Twitch embed, typed API access.
- Security: httpOnly cookie JWT, Symfony RBAC, CSRF/CORS constraints, Argon2id password hashing.
- Scalability: stateless API, managed PostgreSQL path, separate workers, Mercure as separate runtime.
- Accessibility: shadcn/Radix base components and frontend test locations support WCAG work.
- Integration reliability: Messenger retry paths and adapter boundaries cover HelloAsso/email flows.
- Reliability: transactional registration and capacity locking are explicit decisions.

### Implementation Readiness Validation

**Decision Completeness:**
All implementation-blocking decisions are documented:
- starter stack;
- database;
- API style;
- authentication;
- realtime;
- async jobs;
- state management;
- CI quality gates.

**Structure Completeness:**
The structure is specific enough for AI agents to start Epic 0 setup without inventing layout conventions. It defines root config, frontend config, backend config, source organization, tests, and deployment-facing runtime boundaries.

**Pattern Completeness:**
The most likely AI-agent divergence points are covered:
- naming;
- backend DDD layering;
- frontend feature structure;
- API response shape;
- error handling;
- loading states;
- domain events;
- test locations.

### Gap Analysis Results

**Critical Gaps:**
None identified. The architecture is ready to guide setup and early implementation.

**Important Gaps:**
- Exact deployment provider is not chosen. This is acceptable before implementation and should be decided during deployment planning.
- Exact email provider is not chosen. Symfony Mailer adapter boundary keeps this non-blocking.
- Exact analytics tool is not chosen. Cookie consent architecture supports adding one later.
- Exact game option schema for Archipelago intake remains product/domain work, not architecture-blocking.

**Nice-to-Have Gaps:**
- Add ADR files in `docs/decisions/` for future deviations.
- Add an OpenAPI generation strategy once initial endpoints exist.
- Add a local `Makefile` or task runner after starter initialization if repeated commands become noisy.

### Validation Issues Addressed

No blocking validation issue found.

One documentation concern remains: some existing planning artifacts display encoding corruption in rendered output. This does not invalidate the architecture, but before publication or formal handoff the markdown files should be normalized to UTF-8 and reviewed for broken accented characters.

### Architecture Completeness Checklist

**Requirements Analysis**
- [x] Project context thoroughly analyzed
- [x] Scale and complexity assessed
- [x] Technical constraints identified
- [x] Cross-cutting concerns mapped

**Architectural Decisions**
- [x] Critical decisions documented with versions
- [x] Technology stack fully specified
- [x] Integration patterns defined
- [x] Performance considerations addressed

**Implementation Patterns**
- [x] Naming conventions established
- [x] Structure patterns defined
- [x] Communication patterns specified
- [x] Process patterns documented

**Project Structure**
- [x] Complete directory structure defined
- [x] Component boundaries established
- [x] Integration points mapped
- [x] Requirements to structure mapping complete

### Architecture Readiness Assessment

**Overall Status:** READY FOR IMPLEMENTATION

**Confidence Level:** High

**Key Strengths:**
- Clear DDD bounded contexts.
- Strong separation between public UX, backend business rules, and external integrations.
- Explicit quality gates for AI-generated code.
- Realtime, payment, legal, and registration capacity risks addressed before coding.
- Project structure is concrete enough for Claude/Codex handoff.

**Areas for Future Enhancement:**
- Deployment provider decision.
- Observability/logging provider decision.
- OpenAPI documentation automation.
- Detailed Archipelago game option schema.
- Public paid membership workflow for v2.

### Implementation Handoff

**AI Agent Guidelines:**
- Follow all architectural decisions exactly as documented.
- Use implementation patterns consistently across all components.
- Respect project structure and bounded context ownership.
- Do not introduce API Platform, Redis, a global frontend store, or GraphQL without updating this architecture first.
- Run relevant quality gates after every implementation batch.

**First Implementation Priority:**
Epic 0 - Setup:
1. Initialize `frontend/` with `pnpm create next-app@latest`.
2. Initialize `api/` with `symfony new api --version=lts`.
3. Add required frontend and backend dependencies.
4. Configure quality gates.
5. Add CI.
6. Only then begin business-domain implementation.

### Note d'implémentation

---

## Architecture Completion & Handoff

The architecture workflow is complete. The document now contains:
- project context analysis;
- starter template decisions;
- core architectural decisions;
- implementation patterns and consistency rules;
- project structure and boundaries;
- architecture validation and implementation readiness assessment.

The architecture is the technical source of truth for implementation. AI agents must follow the documented decisions, patterns, boundaries, and quality gates unless the architecture is explicitly updated first.

### Recommended Next Step

Run Epic 0 setup as the first implementation activity:
1. initialize the Next.js frontend in `frontend/`;
2. initialize the Symfony API in `api/`;
3. install required dependencies;
4. configure linting, static analysis, tests, and CI;
5. verify both projects are clean before starting business features.

L'initialisation des deux starters constitue la **première story d'implémentation** (Epic 0 - Setup). Aucun code métier avant que les deux projets soient initialisés, les quality gates opérationnels (PHPStan level max, CS Fixer, tests verts), et la CI configurée.
