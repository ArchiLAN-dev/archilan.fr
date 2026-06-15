# Epic 19: DDD Architecture Enforcement & Code Quality

Enforce DDD layer boundaries machine-checkably and eliminate the structural repetition patterns identified by a systematic audit of the API codebase. Stories 19.1-19.5 (existing, in review) extend the `app:architecture:ddd` validator, refactor CQRS violations out of controllers, and wire the validator into CI. Stories 19.6-19.10 (new) eliminate duplicated boilerplate across tests and application code without introducing new behaviour.

## New Requirements

### Non-Functional Requirements

NFR-CQ1: Each test entity factory (`createUser`, `createEvent`, `createGame`, `createRegistration`) is defined exactly once in `FunctionalTestCase`; no functional test file retains its own copy.
NFR-CQ2: Controller auth guard logic is implemented in a single shared location; no controller retains an inline copy of the guard pattern.
NFR-CQ3: Null-check + not-found throw for entity lookups is encapsulated in one place; individual application services do not repeat the pattern inline.
NFR-CQ4: DBAL pagination (`setFirstResult` / `setMaxResults`) is encapsulated in one helper; query services do not inline the calculation.
NFR-CQ5: All four quality gates (PHPStan level max, CS Fixer, `phpunit`, DDD validator) pass green after every story in this epic.

### NFR Coverage Map Additions

NFR-CQ1: Story 19.6 - test entity factories centralised in `FunctionalTestCase`.
NFR-CQ2: Story 19.7 - controller auth guard extracted to shared trait/base class.
NFR-CQ3: Story 19.8 - entity resolution helper in application layer.
NFR-CQ4: Story 19.9 - DBAL pagination helper.
NFR-CQ5: All stories 19.6-19.10 - quality gates enforced at every step.

---

## Story 19.1: Extend `DddArchitectureValidator` - Missing Contexts + CQRS Rules

*(Implementation artifact: `19-1-extend-ddd-validator-missing-contexts-cqrs-rules.md` - status: review)*

As a developer,
I want the `app:architecture:ddd` command to detect all CQRS boundary violations and know about every bounded context,
So that no future controller can bypass the architecture without a CI failure.

---

## Story 19.2: Extract SQL Reads from Epic 18 Controllers

*(Implementation artifact: `19-2-extract-reads-epic18-controllers.md` - status: review)*

As a developer,
I want the 5 Epic 18 public-API controllers to delegate all SQL reads to dedicated Application query classes,
So that the Presentation layer contains zero DB infrastructure and the CQRS boundary is enforced end-to-end.

---

## Story 19.3: Extract SQL Reads from Sessions Presentation Controllers

*(Implementation artifact: `19-3-extract-reads-sessions-controllers.md` - status: review)*

As a developer,
I want the Sessions presentation controllers to delegate all SQL reads to Application query classes,
So that the Presentation layer is free of direct DB access and the DDD validator reports 0 violations.

---

## Story 19.4: Extract Remaining CQRS Violations from 9 Controllers

*(Implementation artifact: `19-4-extract-reads-writes-remaining-controllers.md` - status: review)*

As a developer,
I want the remaining controllers with DB infrastructure violations refactored to use Application services,
So that `app:architecture:ddd` exits 0 on the full codebase.

---

## Story 19.5: CI Integration - `app:architecture:ddd` in Quality Gates

*(Implementation artifact: `19-5-ci-integration-architecture-validator.md` - status: review)*

As a developer,
I want the architecture validator to run in CI and in local quality gates,
So that no future PR can introduce a CQRS or DDD layer violation undetected.

---

## Story 19.6: Centralise Test Entity Factories in FunctionalTestCase

As a developer,
I want `createUser()`, `createEvent()`, `createGame()`, and `createRegistration()` defined once in `FunctionalTestCase`,
So that test files share a single, type-safe implementation and local copies cannot silently diverge.

**Acceptance Criteria:**

**Given** the existing `FunctionalTestCase` base class
**When** this story is complete
**Then** `FunctionalTestCase` exposes at minimum four protected factory methods:
- `createUser(string $email, array $roles = ['ROLE_USER'], ?string $displayName = null, ?string $slug = null): User`
- `createEvent(string $title, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, int $capacity = 50): Event`
- `createGame(string $name, string $slug): ArchipelagoGame`
- `createRegistration(string $eventId, string $userId, string $status = Registration::STATUS_RESERVED): Registration`
**And** each factory persists and flushes its entity via `$this->entityManager`
**And** each factory is typed such that PHPStan level max reports 0 errors on call sites

**Given** a functional test file that previously defined its own local `createUser()` (or `makeUser()`, `buildUser()`, or any equivalent) helper
**When** the migration to `FunctionalTestCase` is applied to that file
**Then** the local helper is removed and calls are replaced with `$this->createUser(...)` using equivalent arguments
**And** the test suite for that file passes without modification to assertions

**Given** a test file that passed extra arguments not covered by the base factory signature
**When** that file is migrated
**Then** the base factory is extended with a default parameter, or the file retains only an override that calls the shared factory internally - no file retains a full duplicate of the shared logic

**And** PHPStan level max, CS Fixer, and `phpunit` all pass after the migration
**And** the DDD validator exits 0 (factories live in `tests/Functional/`, not in `src/`)

---

## Story 19.7: Extract Controller Auth Guard to a Shared Trait

As a developer,
I want the auth guard boilerplate extracted from individual controllers into a single reusable location,
So that the pattern is enforced consistently and each controller action stays focused on its business purpose.

**Acceptance Criteria:**

**Given** the repeated pattern across 13+ controllers:
```php
$user = $this->requireUser($request);
if (!$user instanceof User) {
    return $user;
}
```
**When** this story is complete
**Then** a `RequiresAuthTrait` PHP trait (or an abstract `AuthenticatedController` base class) is created in `src/Shared/Presentation/`
**And** it exposes a single protected method:
```php
/** @return User|JsonResponse */
protected function requireAuthenticatedUser(Request $request): User|JsonResponse;
```
**And** the method returns the authenticated `User` on success and a `JsonResponse` (401 or 403) on failure
**And** all controllers that previously inlined this check use the shared method instead - no controller retains an inline copy

**Given** PHPStan analyses the controllers post-migration
**When** a call site does `$user = $this->requireAuthenticatedUser($request); if ($user instanceof JsonResponse) { return $user; }`
**Then** PHPStan level max reports 0 errors - the return type union is correctly narrowed

**And** CS Fixer reports 0 violations
**And** all existing functional tests for the affected controllers still pass

---

## Story 19.8: Named Entity Resolution Helper in Application Services

As a developer,
I want a shared `findOrFail` helper for entity lookups in application services,
So that the null-check + exception-throw boilerplate is written once and services remain readable.

**Acceptance Criteria:**

**Given** the pattern repeated 30+ times across application services:
```php
$entity = $this->entityManager->find(SomeEntity::class, $id);
if (null === $entity) {
    throw new \RuntimeException("not found: $id");
}
```
**When** this story is complete
**Then** an `EntityFinderTrait` is added to `src/Shared/Application/` exposing:
```php
/**
 * @template T of object
 * @param class-string<T> $class
 * @return T
 */
protected function findOrFail(string $class, string $id): object;
```
**And** the method throws a consistent exception when the entity is not found
**And** application services that previously inlined the pattern are migrated to `findOrFail()`

**Given** PHPStan analyses the migrated services
**When** a service calls `$slot = $this->findOrFail(SessionSlot::class, $id)`
**Then** PHPStan infers `$slot` as `SessionSlot` via the `@template` annotation
**And** PHPStan level max reports 0 errors

**Given** a service that previously threw a context-specific exception
**When** that service is migrated
**Then** it wraps `findOrFail()` with a catch+rethrow - the inline null-check boilerplate is eliminated in all cases

**And** CS Fixer reports 0 violations, `phpunit` passes, DDD validator exits 0

---

## Story 19.9: Encapsulate DBAL Pagination in a Query Helper

As a developer,
I want the `setFirstResult` / `setMaxResults` DBAL pagination calculation in one place,
So that the formula `($page - 1) * $limit` is not copy-pasted across paginated DBAL query services.

**Acceptance Criteria:**

**Given** the `($page - 1) * $limit` pagination formula used in DBAL QueryBuilder query services
**When** this story is complete
**Then** a `PaginationHelper` (final class, no state) is added to `src/Shared/Application/` exposing:
```php
public static function applyTo(QueryBuilder $qb, int $page, int $limit, int $minLimit = 1, int $maxLimit = 100): void;
```
**And** the method clamps `$limit` to `[$minLimit, $maxLimit]` before applying
**And** `PublicGameCatalog` (the only Application service that used `setFirstResult(($page-1)*$limit)->setMaxResults($limit)` via a `QueryBuilder`) is migrated to `PaginationHelper::applyTo()`

> **Scope note (added during implementation):** The original AC estimated "8+ query services". Codebase audit found exactly 1 service with a `Doctrine\DBAL\Query\QueryBuilder` + `($page-1)*$limit` pattern (`PublicGameCatalog`, migrated from ORM QB to DBAL QB as part of this story). `LeaderboardQuery` and `PlayerHistoryQuery` paginate via raw SQL heredocs / PHP `array_slice` - they do not use a `QueryBuilder` and are out of scope without a larger architectural change.

**Given** PHPStan analyses `PaginationHelper`
**When** it is passed a non-DBAL `QueryBuilder`
**Then** PHPStan reports a type error - the parameter is `Doctrine\DBAL\Query\QueryBuilder`, not a generic object

**And** CS Fixer reports 0 violations, `phpunit` passes

---

## Story 19.10: Extract Message Handler Error Logging Pattern

As a developer,
I want the try/catch/log boilerplate in message handlers centralised in a shared trait,
So that error handling is consistent and handlers remain focused on their command logic.

**Acceptance Criteria:**

**Given** the pattern repeated in 5+ message handlers:
```php
try {
    // handler logic
} catch (\Throwable $e) {
    $this->logger->error('handler_name failed: '.$e->getMessage(), ['exception' => $e]);
}
```
**When** this story is complete
**Then** a `LogsHandlerErrors` PHP trait is added to `src/Shared/Application/Handler/` exposing:
```php
protected function executeWithLogging(string $context, \Closure $fn): void;
```
**And** the method wraps `$fn()` in a try/catch, logs any `\Throwable` at `error` level with `['exception' => $e]` context, and re-throws so Messenger can retry or route to the failure transport
**And** all 5+ handlers that previously inlined the pattern use `executeWithLogging()` instead

**Given** a handler that previously swallowed exceptions (no re-throw)
**When** it is migrated
**Then** the behaviour change (swallow -> re-throw) is noted explicitly in the PR description

**Given** PHPStan analyses the trait
**When** a handler using `LogsHandlerErrors` does not declare a `LoggerInterface $logger` property
**Then** PHPStan reports an error - the trait includes a `@property-read LoggerInterface $logger` annotation to make the assumption explicit

**And** CS Fixer reports 0 violations, `phpunit` passes, DDD validator exits 0

---
