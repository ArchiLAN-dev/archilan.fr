# API - PHP / Symfony / DDD Standards

## Quality gates (non-negotiable)

```bash
vendor/bin/phpstan analyse src tests   # level max - 0 errors
vendor/bin/php-cs-fixer check src      # @Symfony ruleset - 0 violations
php bin/phpunit                        # all suites green - 0 notices/deprecations/warnings
php bin/console app:architecture:ddd   # exit 0 - no layer violations
```

Run all four before marking any task complete. Fix failures immediately; never skip with `--no-verify` or suppression annotations.

**Zero PHPUnit notices is a validation prerequisite.** `phpunit.xml.dist` sets `failOnNotice`,
`failOnDeprecation` and `failOnWarning` to `true`, so any notice/deprecation/warning makes
`php bin/phpunit` exit non-zero - the gate is red even if every test "passes". The display
(`OK, but there were issues!`) is **not** a pass. Capture the exact message with
`php bin/phpunit --log-events-text php://stdout` and fix the root cause, e.g.:

- *"No expectations were configured for the mock object … consider a test stub"* → the mock has no
  `->expects(...)`; replace `createMock(X::class)` with `createStub(X::class)` (a stub for pure
  return-value fakes, a mock only when you assert calls). Never silence it with
  `#[AllowMockObjectsWithoutExpectations]`.

---

## DDD layer rules

### Bounded contexts

Every PHP class lives under `src/{Context}/{Layer}/`. Known contexts:

`Identity` · `Events` · `Registrations` · `GameSelection` · `Content` · `Payments` · `Realtime` · `Communications` · `Sessions` · `PersonalRuns` · `CatalogSync` · `Streaming` · `Shared`

Adding a new context requires: (1) create the four layer directories, (2) add to `DddArchitectureValidator::CONTEXTS`, (3) add Domain exclusion to `services.yaml`, (4) add Doctrine mapping if the domain contains entities.

### Domain layer (`Domain/`)

**AC-D1:** Domain classes MUST NOT import any Symfony component (`Symfony\Component\*`, `Symfony\Contracts\*`).  
**AC-D2:** Domain classes MUST NOT import Application, Infrastructure, or Presentation classes from the same or another context.  
**AC-D3:** Domain methods MUST be pure - no HTTP calls, no DB access, no logging, no clock reads, no randomness. Pass all external values as parameters.  
**AC-D4:** Aggregates are `final` classes. Value objects are `final readonly` classes.  
**AC-D5:** No public setters on aggregates. State changes happen only through named business methods (`markAsReleased()`, `publish()`, `cancel()`…).  
**AC-D6:** ORM annotations (`#[ORM\Entity]`, `#[ORM\Column]`, etc.) are allowed **only** in Domain entities - never in Application or Presentation.

### Application layer (`Application/`)

**AC-A1:** Application services are `final` - no extension, no inheritance hierarchies.  
**AC-A2:** Application services MUST NOT inject `EntityManagerInterface` or `Connection`. All entity operations go through **repository interfaces** defined in Domain (`{Entity}RepositoryInterface`). All DTO/read queries go through **query interfaces** defined in Application (`{Name}QueryInterface`). Infrastructure classes implement these interfaces using DBAL QueryBuilder (for queries) or Doctrine ORM (for repositories).  
**AC-A3:** Command services (writes) return `void`. Query services (reads) return typed DTOs or PHP arrays. Never return raw Doctrine entities from a query.  
**AC-A4:** A command service performs exactly one unit of work. Side effects after the DB commit (emails via Messenger, Mercure publishes) are dispatched asynchronously - never inline before `flush()`.  
**AC-A5:** No `new` on infrastructure dependencies inside Application. Always inject the interface (`RunnerGatewayInterface`, `MinioStorageInterface`, etc.).  
**AC-A6:** No HTTP responses, no `Request`, no `Response` in Application classes.

### Infrastructure layer (`Infrastructure/`)

**AC-I1:** All external calls (HTTP clients, file system, Docker socket, MinIO, Twitch API) live here.  
**AC-I2:** Infrastructure implements interfaces defined in Application or Shared. The concrete class is never referenced by Application code.  
**AC-I3:** Null/stub implementations for testing live in `Infrastructure/` (e.g. `NullRunnerGateway`, `StubIgdbHttpClient`). They are registered only in `when@test` in `services.yaml`.

### Presentation layer (`Controllers/`)

**AC-P1:** Controllers MUST NOT inject `EntityManagerInterface` or `Doctrine\DBAL\Connection`.  
**AC-P2:** Controllers MUST NOT call: `fetchAllAssociative`, `fetchOne`, `executeQuery`, `createQueryBuilder`, `createQuery`, `getRepository`, `createNativeQuery`.  
**AC-P3:** Controllers MUST NOT contain business logic. The pattern is exactly: deserialize request → validate input → call Application service → serialize response.  
**AC-P4:** A controller action MUST have at most one Application service call (one read query OR one command). If more are needed, extract a facade service in Application.  
**AC-P5:** Return types are always `JsonResponse`. No template rendering in API controllers.

---

## CQRS naming

| Type | Naming | Returns | Lives in |
|---|---|---|---|
| Command service | `VerbNoun` (`RegisterUser`, `PublishEvent`) | `void` | `Application/` |
| Query service | `NounContext` (`PlayerProfileQuery`, `LeaderboardQuery`) | typed DTO / array | `Application/` |
| Message (async) | `VerbNounJob` or `VerbNounMessage` | - | `Application/Message/` |
| Message handler | same name + `Handler` suffix | `void` | `Application/Handler/` |

---

## PHPStan rules (level max)

- Never cast `mixed` directly: `(string) $mixed` → **banned**. Use `is_string()` narrowing.
- `fetchAllAssociative()` returns `list<array<string, mixed>>` - always narrow each column before use.
- `fetchOne()` returns `mixed` (or `false`) - always `false !== $result` before use.
- Null-check all `->find()`, `->findOneBy()` results before accessing methods.
- `array_keys()` on a `list<T>` already returns a `list<int>` - `array_values()` is redundant, PHPStan flags it.

---

## CS Fixer rules (@Symfony preset)

- String comparisons: Yoda style - `null === $x`, `'' !== $slug`, `true === $flag`.
- `declare(strict_types=1)` at the top of every file.
- No trailing whitespace, Unix line endings.
- Single blank line between methods; no extra blank line before closing brace.

---

## Testing standards

### Unit tests

**AC-T1:** Unit tests extend `PHPUnit\Framework\TestCase` - no Symfony kernel, no DB.  
**AC-T2:** Construct domain entities directly (no factory mocks needed - they're plain PHP).  
**AC-T3:** Application services are unit-tested by injecting interface mocks (`$this->createMock(RunnerGatewayInterface::class)`).  
**AC-T4:** One test class per domain class. File: `tests/Unit/{Context}/{ClassName}Test.php`.  
**AC-T5:** Test method names: `test{scenario}_{expectedOutcome}` - e.g. `testMarkAsReleased_setsFlag`, `testMarkAsReleased_isNoOpWhenGoalAlreadyReached`.

### Functional tests

**AC-T6:** Functional tests extend `Symfony\Bundle\FrameworkBundle\Test\WebTestCase`.  
**AC-T7:** Schema created per test class with `SchemaTool::createSchema([...entity classes...])`.  
**AC-T8:** Include all entity classes needed by the feature being tested in the schema array - missing classes cause silent FK failures.  
**AC-T9:** No `$this->markTestSkipped()` unless the feature is explicitly behind a feature flag.  
**AC-T10:** Assert HTTP status codes explicitly before asserting body content.

### What NOT to test

- Doctrine ORM mapping (trust the migration).
- Symfony routing (trust the attribute).
- Pure getters with no logic.

---

## Migration standards

- File naming: `Version{YYYYMMDD}{HHMMSS}.php` - use a timestamp one second after the last migration.
- `up()`: always reversible - pair every `ALTER TABLE ADD` with a `down()` `DROP`.
- `postUp()`: allowed for data backfills. Keep simple - one SELECT batch, update in PHP, no raw JOIN updates.
- Never modify an existing migration once merged to main.

---

## Forbidden patterns (never do these)

```php
// ❌ Raw SQL string - use DBAL QueryBuilder instead
$rows = $conn->fetchAllAssociative('SELECT * FROM session WHERE status = :s', ['s' => 'finished']);

// ❌ DQL - use DBAL QueryBuilder instead
$this->entityManager->createQuery('SELECT u FROM App\Identity\Domain\User u WHERE u.slug = :slug');

// ❌ ORM QueryBuilder (generates DQL) - use DBAL QueryBuilder instead
$this->entityManager->createQueryBuilder()->select('u')->from(User::class, 'u');

// ✅ DBAL QueryBuilder - always this
$qb = $this->connection->createQueryBuilder();
$rows = $qb->select('s.id', 'e.title')
    ->from('session', 's')
    ->join('s', 'event', 'e', $qb->expr()->eq('e.id', 's.event_id'))
    ->where($qb->expr()->eq('s.status', ':status'))
    ->setParameter('status', 'finished')
    ->executeQuery()
    ->fetchAllAssociative();

// ❌ Static mutable state
private static array $cache = [];

// ❌ Domain entity with infrastructure dependency
class User {
    public function __construct(private LoggerInterface $logger) {}
}

// ❌ Controller with SQL
class MyController {
    public function __invoke(Connection $conn): JsonResponse {
        $rows = $conn->fetchAllAssociative('SELECT ...');
    }
}

// ❌ PHPStan bypass via cast
$name = (string) $row['name']; // use is_string() narrowing instead

// ❌ Suppressing PHPStan via @phpstan-ignore without a comment explaining why
/** @phpstan-ignore-next-line */

// ❌ new on infrastructure inside Application
class MyService {
    public function handle(): void {
        $client = new HttpClient(); // inject HttpClientInterface instead
    }
}

// ❌ DB infrastructure injected into Application - use repository/query interfaces
class MyAppService {
    public function __construct(
        private EntityManagerInterface $em,  // ❌ forbidden in Application
        private Connection $conn,            // ❌ forbidden in Application
    ) {}
}

// ✅ Repository interface in Domain, query interface in Application
class MyAppService {
    public function __construct(
        private MyEntityRepositoryInterface $repository,  // entity ops → Domain interface
        private MyDataQueryInterface $query,              // read queries → Application interface
    ) {}
}
```

---

## Membership access control

`ROLE_MEMBER` is a **persistent role** on the `User` entity. It is set when a membership is purchased but is **not automatically removed** when the membership expires. It is therefore **stale-prone** and must never be used to gate access.

### Rules

**AC-M1:** Never use `isGranted('ROLE_MEMBER')`, `#[IsGranted('ROLE_MEMBER')]`, or `in_array('ROLE_MEMBER', $user->getRoles())` to protect an endpoint or feature.

**AC-M2:** Always use `ApiAccessGuard::requireAuthenticatedMember()` in controllers, or `isGranted('IS_MEMBER')` via `MembershipVoter`. Both query the `memberships` table with `expires_at >= now` - they are always up to date.

**AC-M3:** `ROLE_MEMBER` may only be used for **display and filtering** (admin user directory, Discord role sync) where slight staleness is acceptable.

```php
// ❌ Stale - ROLE_MEMBER survives membership expiry in the JWT and the DB row
if (in_array('ROLE_MEMBER', $user->getRoles(), true)) { ... }
$this->denyAccessUnlessGranted('ROLE_MEMBER');

// ✅ Live query - ApiAccessGuard for controllers
$user = $this->apiAccessGuard->requireAuthenticatedMember($request);

// ✅ Live query - Voter for non-controller contexts
$this->denyAccessUnlessGranted('IS_MEMBER');
// or: $this->authorizationChecker->isGranted('IS_MEMBER')
```
