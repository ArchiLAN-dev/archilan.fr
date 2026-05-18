# Story 22.1: Membership Domain Model & Core Application Services

## Story

**As a** developer,
**I want** a `Membership` bounded context with an entity, two command services (`ActivateMembership`, `ExpireMembership`), and Discord sync dispatch,
**So that** membership lifecycle transitions are encapsulated in a single place and can be triggered from multiple entry points (HelloAsso webhook, scheduler, admin action).

## Status

review

## Acceptance Criteria

**AC1:** Directories `src/Membership/{Domain,Application,Infrastructure,Presentation}/` created. `DddArchitectureValidator::CONTEXTS` includes `'Membership'`. `services.yaml` excludes `App\Membership\Domain\` from autowiring. Doctrine mapping configured for `src/Membership/Domain/`.

**AC2:** `Membership` entity with all required fields and ORM annotations. Migration adds `memberships` table with all indexes and unique constraint on `helloasso_order_id`. `ActivateMembership` enforces one active membership per `userId` (renews existing row if present).

**AC3:** `UserRoleGatewayInterface` declares `promoteToMember(string $userId): void`, `demoteToUser(string $userId): void`, and `getUserDiscordInfo(string $userId): array`. `UserRoleGateway` implements it using `EntityManagerInterface` to load `Identity\Domain\User`.

**AC4:** `ActivateMembership::activate()` - renews existing active membership or creates new one, calls gateway (which flushes both entities in one unit of work), dispatches `SyncDiscordRoleMessage` after flush.

**AC5:** `ExpireMembership::expire()` - no-ops if already expired or not found, sets status='expired', calls gateway, dispatches `SyncDiscordRoleMessage` after flush.

**AC6:** All four API quality gates pass (PHPStan level max, CS Fixer, phpunit, DDD validator).

## Tasks / Subtasks

- [x] Task 1: Create bounded context directories and update DDD validator, services.yaml, doctrine.yaml
- [x] Task 2: Create `Membership` entity and Doctrine migration
- [x] Task 3: Create `UserRoleGatewayInterface` and `UserRoleGateway`
- [x] Task 4: Create `ActivateMembership` and `ExpireMembership` application services
- [x] Task 5: Write unit tests (8 tests covering all scenarios)
- [x] Task 6: Run all four quality gates - all green (797 tests OK)

## Dev Notes

### Bounded context structure

- `src/Membership/Domain/Membership.php` - entity
- `src/Membership/Application/UserRoleGatewayInterface.php` - interface
- `src/Membership/Application/ActivateMembership.php` - activate service
- `src/Membership/Application/ExpireMembership.php` - expire service
- `src/Membership/Infrastructure/UserRoleGateway.php` - gateway implementation

### Flush strategy (NFR-ME4)

The gateway's `promoteToMember()` and `demoteToUser()` always call `$em->flush()` at the end - including no-op cases (ROLE_ADMIN guard). The application service ensures the Membership entity is in the UoW before calling the gateway, so one `flush()` call commits both entities in the same DB transaction.

### Discord sync

After the gateway flush, each service calls `gateway->getUserDiscordInfo($userId)` which reads the User from the identity map (no extra DB query since User is already loaded). The `SyncDiscordRoleMessage` is dispatched only when `discordId !== null`. Dispatch failures are logged and swallowed.

### Cross-context import rule

`UserRoleGateway` (in `Membership/Infrastructure/`) is the only Membership class that imports `App\Identity\Domain\User`. Application services depend only on `UserRoleGatewayInterface`, keeping the Application layer free of cross-context imports.

## File List

- `api/src/Membership/Domain/Membership.php` - new
- `api/src/Membership/Application/UserRoleGatewayInterface.php` - new
- `api/src/Membership/Application/ActivateMembership.php` - new
- `api/src/Membership/Application/ExpireMembership.php` - new
- `api/src/Membership/Infrastructure/UserRoleGateway.php` - new
- `api/migrations/Version20260516200000.php` - new
- `api/src/Shared/Application/DddArchitectureValidator.php` - modified (added 'Membership' to CONTEXTS)
- `api/config/services.yaml` - modified (Domain exclusion + UserRoleGatewayInterface binding)
- `api/config/packages/doctrine.yaml` - modified (Membership mapping)
- `api/tests/Unit/Membership/ActivateMembershipTest.php` - new
- `api/tests/Unit/Membership/ExpireMembershipTest.php` - new
- `api/tests/Unit/DddArchitectureValidatorTest.php` - modified (added 'Membership' to fixture contexts)

## Change Log

| Date       | Change                                                                                         |
|------------|------------------------------------------------------------------------------------------------|
| 2026-05-16 | Story created                                                                                  |
| 2026-05-16 | Implemented: bounded context, entity, gateway, 2 services, 8 unit tests. All quality gates green (797 tests OK). |
