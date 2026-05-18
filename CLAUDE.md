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
php bin/phpunit                         → all tests green
php bin/console app:architecture:ddd    → exit 0

# Frontend
pnpm typecheck    → 0 errors
pnpm lint         → 0 errors / 0 warnings
pnpm build        → clean build
```

Failing any gate is a **blocker**. Do not mark tasks complete, do not move to the next story.

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
