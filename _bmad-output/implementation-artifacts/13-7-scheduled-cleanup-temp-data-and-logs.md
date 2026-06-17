# Story 13.7: Scheduled Cleanup of Expired Tokens & Old Operational Logs

**Status:** done
**Epic:** 13 - Secure Token Lifecycle / Data Retention
**Date:** 2026-06-09

---

## Story

As an operator,
I want the scheduler to periodically purge expired/consumed auth tokens and old operational logs from the database,
So that ephemeral tables don't grow unbounded (cost, backups, GDPR data minimisation) - the way refresh tokens are already pruned (story 13.6).

---

## Context

The Symfony Scheduler (`api/src/Schedule.php`) already prunes **refresh tokens** daily (03:00) via
`CleanupRefreshTokensMessage` → `CleanupRefreshTokensHandler` →
`RefreshTokenRepositoryInterface::deleteStale()`. That is the pattern to extend here.

Several other ephemeral tables are written on every signup / reset / sync / private-access attempt and are
**never deleted** today:

| Target | Entity | Table (derive via metadata) | Cut-off column(s) |
|--------|--------|------------------------------|-------------------|
| Email confirmation tokens | `App\Identity\Domain\EmailConfirmationToken` | `identity_email_confirmation_tokens` | `expires_at`, `confirmed_at` |
| Password reset tokens | `App\Identity\Domain\PasswordResetToken` | `identity_password_reset_tokens` | `expires_at`, `used_at` |
| HelloAsso sync log | `App\Payments\Domain\HelloAssoSyncLog` | `helloasso_sync_log` (confirm) | `attempt_at` |
| Event private-access log | `App\Events\Domain\EventPrivateAccessLog` | `event_private_access_log` (confirm) | `created_at` |

Their current repositories only `findByTokenHash` / `save` / `revokeExistingForUser` (tokens) - **no delete path**.

### Out of scope - DO NOT purge

- **`GameCatalogSync`** (`App\GameSelection\Domain\GameCatalogSync`): despite the "Sync" name this is **persistent
  per-game state** (deployed apworld version, `apworld_checked_at`, adult/bundled flags, IGDB id), one row per
  game joined to `game` with `ON DELETE CASCADE`. Deleting rows would destroy catalog state. Leave untouched.
- **Audit / compliance tables**: `AdminCreationAudit`, `DeletionAudit`, `RoleChangeAudit`, `PrivacyRightsRequest`
  (Identity), `RunAuditLog` (Sessions). These are retained for security/legal traceability - never auto-purged.
- **Refresh tokens**: already handled by 13.6. Do not duplicate.
- **Discord OAuth state token** (`App\Identity\Application\DiscordStateToken`): stateless, HMAC-signed
  (`hash_hmac`) - nothing persisted, nothing to clean.

---

## Acceptance Criteria

1. Expired/consumed **email confirmation tokens** are deleted: rows where `expires_at < now()`
   **OR** (`confirmed_at IS NOT NULL` AND `confirmed_at < now() - <grace>`).
2. Expired/consumed **password reset tokens** are deleted: rows where `expires_at < now()`
   **OR** (`used_at IS NOT NULL` AND `used_at < now() - <grace>`).
3. **HelloAsso sync log** rows with `attempt_at < now() - <retention>` are deleted.
4. **Event private-access log** rows with `created_at < now() - <retention>` are deleted.
5. Each retention/grace window is **configurable** (parameter bound from an env var) with sane defaults:
   token consumed grace = 7 days, `helloasso_sync_log` = 90 days, `event_private_access_log` = 365 days.
6. Cleanup runs automatically: new `RecurringMessage::cron` entries added to `Schedule.php`, on a nightly slot
   **staggered** from the 03:00 refresh-token job (e.g. 03:15 / 03:30).
7. Each cleanup logs the number of deleted rows at `info` via `LoggerInterface` (one structured log key per
   target, e.g. `auth.cleanup_email_confirmation_tokens`, `data.cleanup_helloasso_sync_log`).
8. A console command allows a manual run on demand (mirrors `app:auth:cleanup-refresh-tokens`).
9. `GameCatalogSync`, the audit/compliance tables, and any **active/recent** rows (non-expired tokens,
   unconfirmed-but-not-expired tokens, recent logs) are **never** deleted - proven by tests.
10. All quality gates green: `phpstan` (max), `php-cs-fixer`, `phpunit`, `app:architecture:ddd`.

---

## Tasks / Subtasks

- [ ] **Domain - repository delete contracts** (AC: 1–4, DDD)
  - [ ] `EmailConfirmationTokenRepositoryInterface::deleteStale(\DateTimeImmutable $now, \DateTimeImmutable $consumedGrace): int`
  - [ ] `PasswordResetTokenRepositoryInterface::deleteStale(\DateTimeImmutable $now, \DateTimeImmutable $consumedGrace): int`
  - [ ] New `HelloAssoSyncLogRepositoryInterface` (Payments/Domain) with `deleteOlderThan(\DateTimeImmutable $threshold): int` - or add to an existing Payments repo interface if one already writes the log
  - [ ] New `EventPrivateAccessLogRepositoryInterface` (Events/Domain) with `deleteOlderThan(\DateTimeImmutable $threshold): int` - or extend the existing repo that persists the log

- [ ] **Infrastructure - DBAL DELETE implementations** (AC: 1–4, DDD)
  - [ ] Mirror `DoctrineRefreshTokenRepository`: inject DBAL `Connection`, resolve table via
    `EntityManagerInterface::getClassMetadata(X::class)->getTableName()`, use
    `$this->connection->createQueryBuilder()->delete($this->table)->where(...)->setParameter(...)->executeStatement()`
  - [ ] Single `DELETE ... WHERE` per call (no per-row loads, no full-table lock) - safe under concurrent load
  - [ ] Extend the existing `DoctrineEmailConfirmationTokenRepository` / `DoctrinePasswordResetTokenRepository`
    (currently ORM-only) by adding the DBAL `Connection` dependency for the delete method

- [ ] **Application - messages + handlers** (AC: 5, 7; CQRS)
  - [ ] One marker `*Message` + `#[AsMessageHandler]` `*Handler` per target under `Application/Message/`
    (mirror `CleanupRefreshTokensMessage`/`Handler`), in the owning context (Identity / Payments / Events)
  - [ ] Handlers inject the **Domain repository interface** + `LoggerInterface` + their retention/grace value;
    compute the threshold from an injected `now` (do NOT call `new \DateTimeImmutable()` in Domain - handlers may
    build it) and call the repo delete; log the deleted count
  - [ ] **No `Connection`/`EntityManagerInterface` in Application** (AC-A2)

- [ ] **Config - retention windows** (AC: 5)
  - [ ] Add parameters bound from env (e.g. `CLEANUP_TOKEN_CONSUMED_GRACE_DAYS=7`,
    `CLEANUP_HELLOASSO_SYNC_LOG_RETENTION_DAYS=90`, `CLEANUP_EVENT_ACCESS_LOG_RETENTION_DAYS=365`)
  - [ ] Document them in `.env` / `.env.example` (if present) and the handler service bindings

- [ ] **Scheduler** (AC: 6)
  - [ ] Add `RecurringMessage::cron(...)` entries to `Schedule.php` for each new message, staggered after 03:00

- [ ] **Presentation - manual command** (AC: 8)
  - [ ] A console command (e.g. `app:data:cleanup` running all targets, or per-target commands) mirroring
    `CleanupRefreshTokensCommand`; auto-wired, returns `Command::SUCCESS`, writes deleted counts to output

- [ ] **Tests** (AC: 1–4, 9)
  - [ ] Unit handler tests: mock the repo interface, assert the delete method is called with the correctly
    computed threshold and the count is logged
  - [ ] Functional tests per target: seed expired/consumed/old rows **and** active/recent rows, run the
    command/handler, assert only the stale rows are deleted and recent/active rows remain
  - [ ] A guard test asserting a `GameCatalogSync` row and an audit-table row are untouched (regression guard)

- [ ] **Quality gates** (AC: 10): `composer phpstan`, `composer cs-fixer`, `composer test`, `php bin/console app:architecture:ddd`

---

## Dev Notes

### Canonical pattern to mirror

`api/src/Identity/Infrastructure/DoctrineRefreshTokenRepository.php`:

```php
public function __construct(
    private EntityManagerInterface $entityManager,
    private Connection $connection,
) {
    $this->table = $entityManager->getClassMetadata(RefreshToken::class)->getTableName();
}

public function deleteStale(\DateTimeImmutable $now): int
{
    $qb = $this->connection->createQueryBuilder();
    return (int) $qb->delete($this->table)
        ->where(/* expires_at < :now OR (revoked_at IS NOT NULL AND revoked_at < :grace) */)
        ->setParameter('now', $now, Types::DATETIMETZ_IMMUTABLE)
        ->executeStatement();
}
```

Application side: `CleanupRefreshTokensMessage` (empty marker) + `CleanupRefreshTokensHandler`
(`#[AsMessageHandler]`, injects `RefreshTokenRepositoryInterface` + `LoggerInterface`, logs deleted count).
Schedule: `RecurringMessage::cron('0 3 * * *', new CleanupRefreshTokensMessage())` in `Schedule.php`.

### Exact columns (verified)

- `EmailConfirmationToken`: `expires_at` (non-null), `created_at`, `confirmed_at` (nullable) → consumed = `confirmed_at` set.
- `PasswordResetToken`: `expires_at` (non-null), `created_at`, `used_at` (nullable) → consumed = `used_at` set.
- `HelloAssoSyncLog`: `attempt_at` (non-null), `form_slug`, `error_message` (nullable) - append-only log.
- `EventPrivateAccessLog`: `event_id`, `user_id`, `created_at` (non-null) - append-only log.

Column types are `datetimetz_immutable`; bind parameters with `Types::DATETIMETZ_IMMUTABLE` to match.
Resolve table names from metadata (don't hardcode) - confirm `helloasso_sync_log` / `event_private_access_log`.

### Existing repos to extend / add

- `DoctrineEmailConfirmationTokenRepository` and `DoctrinePasswordResetTokenRepository` are currently ORM-only
  (`getRepository(...)->findOneBy(...)`). Add a DBAL `Connection` dependency for the bulk delete (keep the ORM
  reads as they are). The repo already resolves the table name via `getClassMetadata(...)->getTableName()`.
- Check whether `HelloAssoSyncLog` and `EventPrivateAccessLog` already have repository interfaces/impls that
  persist them; add the `deleteOlderThan` there, or create a minimal cleanup repo interface in the context's
  Domain + a Doctrine impl in Infrastructure if none exists.

### DDD guardrails (api/CLAUDE.md)

- Delete logic lives in **Infrastructure** behind a **Domain** repository interface. No `Connection` /
  `EntityManagerInterface` in Application (AC-A2). Handlers/commands depend only on the interface.
- No `date()`/`time()` in Domain or Application logic beyond constructing `now` at the handler boundary; pass
  the threshold as a parameter to the repo method.
- Commands live in `Presentation/` (CLI is a delivery mechanism, same layer as HTTP controllers), auto-wired.

### Retention defaults & rationale

- Token consumed grace **7 days** (matches the refresh-token grace) - keep a short audit trail after use.
- `helloasso_sync_log` **90 days** - operational troubleshooting window.
- `event_private_access_log` **365 days** - access trail kept ~1 year (configurable; do not go to 0 silently).

Expired-but-never-consumed tokens are deleted as soon as `expires_at < now()` (no grace needed - they're dead).

### References

- [Source: api/src/Schedule.php] - scheduler, existing cron entries
- [Source: api/src/Identity/Application/Message/CleanupRefreshTokensMessage.php + CleanupRefreshTokensHandler.php]
- [Source: api/src/Identity/Infrastructure/DoctrineRefreshTokenRepository.php] - DBAL delete pattern + table-from-metadata
- [Source: api/src/Identity/Presentation/CleanupRefreshTokensCommand.php] - command pattern
- [Source: _bmad-output/implementation-artifacts/13-6-expired-token-cleanup-command.md] - prior cleanup story
- [Source: api/src/Identity/Domain/EmailConfirmationToken.php, PasswordResetToken.php] - columns
- [Source: api/src/Payments/Domain/HelloAssoSyncLog.php, api/src/Events/Domain/EventPrivateAccessLog.php] - log columns
- [Source: api/CLAUDE.md] - DDD layer rules (AC-A2, DBAL QueryBuilder, command placement)

### Project Structure Notes

New/changed files (indicative):
- Domain: `*/Domain/*RepositoryInterface.php` (+delete methods / new interfaces)
- Infrastructure: `*/Infrastructure/Doctrine*Repository.php` (DBAL delete impls)
- Application: `*/Application/Message/Cleanup*Message.php` + `Cleanup*Handler.php`
- Presentation: `*/Presentation/*CleanupCommand.php`
- `api/src/Schedule.php` (cron entries), config/services + `.env(.example)` (retention params)
- `api/tests/Unit/...` + `api/tests/Functional/...`

---

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Debug Log References

- Test fake IDs initially exceeded `varchar(32)` (SQLSTATE 22001) → switched seed user/event IDs to `bin2hex(random_bytes(16))` (32 chars).
- Full suite showed 1 transient error at schema creation for an unrelated test (`AdminEventPrivateAccessTest`); passes in isolation and on a clean re-run (977/977) - the documented local `archilan_test` schema-race flakiness, not this change. CI on fresh Postgres is authoritative.

### Completion Notes List

- Deletes implemented in **Infrastructure** behind **Domain** interfaces using DBAL `Connection->createQueryBuilder()->delete($table)->executeStatement()` (table resolved via ORM metadata), mirroring `DoctrineRefreshTokenRepository`. Added `Connection` to the two log repos (were ORM-only).
- One marker `*Message` + `#[AsMessageHandler]` `*Handler` per target (Identity x2, Payments, Events); handlers inject the Domain interface + `LoggerInterface` + an injected retention/grace int (no `Connection`/`EM` in Application - AC-A2 respected, `app:architecture:ddd` green).
- Retention windows configurable via `services.yaml` binds with env overrides + parameter defaults: token consumed grace 7 d, helloasso_sync_log 90 d, event_private_access_log 365 d. Documented (commented) in `api/.env`.
- Scheduled in `Schedule.php` (cron 03:15 / 03:20 / 03:25 / 03:30, staggered after the 03:00 refresh-token job); messages routed to `async` in `messenger.yaml`.
- Manual run: `app:auth:cleanup-tokens` (Identity, both token types), `app:payments:cleanup-sync-log`, `app:events:cleanup-access-log` - per context to respect bounded-context boundaries.
- **GameCatalogSync** deliberately untouched (it is per-game state, not a log) and audit/compliance tables excluded; cleanup code never references them.
- Tests: 4 functional (seed stale + fresh, assert only stale deleted, survivors remain) + 3 unit (handler delegation + grace/retention applied + structured log key). New: 8 tests / 38 assertions green; full suite 977 green.

### Validation Results

- `phpstan analyse src tests`: 0 errors
- `php-cs-fixer fix`: clean
- `app:architecture:ddd`: boundaries respected
- `phpunit`: 977/977 green (8 pre-existing notices)

### File List

- Domain (interfaces + delete contracts): `Identity/Domain/EmailConfirmationTokenRepositoryInterface.php`, `Identity/Domain/PasswordResetTokenRepositoryInterface.php`, `Payments/Domain/HelloAssoSyncLogRepositoryInterface.php`, `Events/Domain/EventPrivateAccessLogRepositoryInterface.php`
- Infrastructure (DBAL deletes): `Identity/Infrastructure/DoctrineEmailConfirmationTokenRepository.php`, `Identity/Infrastructure/DoctrinePasswordResetTokenRepository.php`, `Payments/Infrastructure/DoctrineHelloAssoSyncLogRepository.php`, `Events/Infrastructure/DoctrineEventPrivateAccessLogRepository.php`
- Application (messages + handlers): `Identity/Application/Message/CleanupEmailConfirmationTokens{Message,Handler}.php`, `Identity/Application/Message/CleanupPasswordResetTokens{Message,Handler}.php`, `Payments/Application/Message/CleanupHelloAssoSyncLog{Message,Handler}.php`, `Events/Application/Message/CleanupEventPrivateAccessLog{Message,Handler}.php`
- Presentation (commands): `Identity/Presentation/CleanupAuthTokensCommand.php`, `Payments/Presentation/CleanupHelloAssoSyncLogCommand.php`, `Events/Presentation/CleanupEventPrivateAccessLogCommand.php`
- Wiring/config: `src/Schedule.php`, `config/packages/messenger.yaml`, `config/services.yaml`, `.env`
- Tests: `tests/Functional/DataRetentionCleanupTest.php`, `tests/Unit/Identity/CleanupTokenHandlersTest.php`, `tests/Unit/Payments/CleanupHelloAssoSyncLogHandlerTest.php`, `tests/Unit/Events/CleanupEventPrivateAccessLogHandlerTest.php`, plus `tests/Functional/HelloAssoSyncHandlerTest.php` (constructor arg)

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-09 | Story created (scope confirmed with Jean: email-confirmation + password-reset tokens + helloasso_sync_log + event_private_access_log; GameCatalogSync and audit tables explicitly excluded; refresh tokens already covered by 13.6). Status → ready-for-dev. |
| 2026-06-10 | Implemented: Domain delete contracts + DBAL deletes + per-target messages/handlers + 3 console commands + Schedule/messenger/config wiring + 7 tests. All gates green. Status → review. |
| 2026-06-10 | Merged via PR #80 (CI green, incl. full backend suite on fresh Postgres). Status → done. |
