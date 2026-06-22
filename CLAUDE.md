# archilan.fr - Agent Standards (root)

## Project layout

| Directory    | Stack                                        | Standards file        |
|-------------|----------------------------------------------|-----------------------|
| `api/`      | Symfony 7, PHP 8.3, DDD/CQRS, PHPUnit        | `api/CLAUDE.md`       |
| `frontend/` | Next.js 15 App Router, TypeScript, TanStack  | `frontend/AGENTS.md`  |
| `bridge/`   | Python 3.10, REST bridge to Archipelago      | -                     |

**Read the relevant sub-file before touching code in that layer.**  
Both files are loaded automatically by the agent runtime. Do not skip them.

---

## Quality gates - must all pass before any task is marked complete

```
# API
vendor/bin/phpstan analyse src tests    → 0 errors
vendor/bin/php-cs-fixer check src       → 0 violations
php bin/phpunit                         → all tests green, 0 notices/deprecations/warnings
php bin/console app:architecture:ddd    → exit 0

# Frontend
pnpm typecheck    → 0 errors
pnpm lint         → 0 errors / 0 warnings
pnpm build        → clean build
```

Failing any gate is a **blocker**. Do not mark tasks complete, do not move to the next story.

---

## BMAD workflow - mandatory before any implementation

Every feature or non-trivial change MUST trace to an existing story file in
`_bmad-output/implementation-artifacts/`. No story = no code.

### Before writing implementation code

1. Find the story file: `_bmad-output/implementation-artifacts/<epic>/<story>.md`
2. If no story exists, **stop and tell the user** - do not write implementation code.
   Offer to create the story first (using BMAD methods) or ask the user to provide the story.
3. Read the story's Acceptance Criteria and Tasks before touching any file.

### Exemptions (no story required)

- Fixing a failing quality gate (compilation error, test regression, linter violation).
- Adding/fixing tests for existing behaviour.
- Updating documentation, CLAUDE.md, or memory files.
- Refactoring that does not change observable behaviour and is contained within a single layer.

### Quality gates - auto-run on Stop

A Stop hook runs `.claude/quality-gates.sh` automatically after every session.
If a gate fails the hook reports it; **self-correct before the next session ends**.
Never mark a story complete while any gate is red.

---

## Git workflow - Gitflow

### Branch structure

| Branche        | Rôle                                              | Merge vers              |
|----------------|---------------------------------------------------|-------------------------|
| `main`         | Code en production - commits de release seulement | -                       |
| `develop`      | Intégration continue - cible des features         | `main` (via release/PR) |
| `feature/xxx`  | Une story BMAD = une branche                      | `develop`               |
| `hotfix/xxx`   | Correctif urgent sur prod                         | `main` + `develop`      |
| `release/x.y`  | Stabilisation avant mise en prod (optionnel)       | `main` + `develop`      |

### Règles impératives

- **Ne jamais commiter directement sur `main`** - toujours via PR depuis `release/*` ou `hotfix/*`.
- **Ne jamais commiter directement sur `develop`** - toujours via PR depuis `feature/*`.
- Chaque branche `feature/` doit partir de `develop` : `git checkout -b feature/xxx develop`.
- Chaque branche `hotfix/` doit partir de `main` : `git checkout -b hotfix/xxx main`.

### Nommage des branches

```
feature/epic-{N}-story-{M}-{slug-court}   # ex: feature/epic-25-story-1-orchestrateur-apworld
hotfix/{slug-court}                        # ex: hotfix/fix-jwt-expiry
release/{vX.Y.Z}                           # ex: release/v2.1.0
```

### Workflow par type de session

**Feature (cas normal) :**
1. `git checkout -b feature/epic-N-story-M-slug develop`
2. Implémenter la story → quality gates verts
3. `git push -u origin feature/...`
4. Ouvrir une PR vers `develop`

**Hotfix :**
1. `git checkout -b hotfix/slug main`
2. Corriger → quality gates verts
3. PR vers `main` ET cherry-pick / PR vers `develop`

### Sessions parallèles - un worktree par agent

Plusieurs agents/sessions peuvent travailler en parallèle sur ce poste. Par défaut le dépôt n'a qu'un seul working tree : **ne jamais bosser à plusieurs dans le même dossier** - les `git checkout` et `git stash` se télescopent, et le WIP non commité d'un agent « suit » le checkout d'un autre.

**Règle : chaque session parallèle opère dans son propre `git worktree`.**

```bash
./scripts/setup-worktree.sh <name> [branch]
# ex: ./scripts/setup-worktree.sh avatar feature/epic-30-story-27-avatar-upload
```

Le script crée `../archilan-<name>` (working tree + branche isolés) et une base de test Postgres dédiée (`archilan_test_<name>`) via le hook `TEST_TOKEN` de Doctrine. Postgres, Docker, MinIO et les serveurs dev restent **partagés**.

- Avant tout `git checkout` dans un tree partagé : **commit ou stash *nommé* d'abord**.
- Fin de session : `git worktree remove ../archilan-<name>`.
- Options du script (`--base`, `--no-frontend`, `--help`) : voir son en-tête.

---

## Cross-cutting rules

### No side effects at boundaries

- Domain entities: pure methods only. No logger, no dispatcher, no HTTP, no DB calls inside a domain method.
- React components: pure render functions. No mutation of external state during render.
- Handlers / Application services: one unit of work = one DB transaction. Side effects (emails, events, realtime) dispatched **after** the transaction commits, never inside.

### Dependency direction

```
Domain ← Application ← Infrastructure
                     ← Presentation
```

- Lower layers never import higher layers.
- Domain imports nothing from the project (only PHP stdlib and framework-agnostic libraries).
- Presentation only calls Application services - never Domain entities directly, never DB infrastructure.

### No magic, no global state

- No static mutable properties anywhere.
- No `$_SESSION`, no `$_GLOBALS`, no Symfony container accessed at runtime (only constructor injection).
- No `date()`, `time()`, `rand()` in domain or application logic - inject a `ClockInterface` or pass as parameter.

### Naming that communicates intent

- Commands (writes): verb + noun - `RegisterUser`, `MarkSlotReleased`, `PublishEvent`
- Queries (reads): noun + context - `PlayerProfileQuery`, `LeaderboardQuery`, `PublicEventCatalog`
- Events: past tense - `UserRegistered`, `SessionFinished`
- DTOs: suffix `DTO` or named record - `LeaderboardEntryDTO`

### Typography - never use em-dashes

- Never use em-dashes (`—`) or en-dashes (`–`) anywhere: code, comments, markdown docs, commit messages, PR bodies, chat. Use a plain hyphen `-` (spaced ` - ` between clauses) or rephrase.
- Local editor tooling normalizes em-dashes to hyphens, producing noisy repo-wide churn diffs. Emitting hyphens only keeps diffs clean.
