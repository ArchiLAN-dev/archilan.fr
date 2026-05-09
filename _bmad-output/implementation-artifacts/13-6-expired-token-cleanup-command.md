# Story 13.6: Expired Token Cleanup Command

**Status:** review
**Epic:** 13 - Secure Token Lifecycle - Refresh Token
**Date:** 2026-05-05

---

## Story

As an operator,
I want a Symfony console command to prune stale refresh token records,
So that the `identity_refresh_tokens` table does not grow indefinitely.

---

## Acceptance Criteria

1. Command `app:auth:cleanup-refresh-tokens` exists and is executable.
2. Deletes all rows where `expires_at < now()` OR (`revoked_at IS NOT NULL` AND `revoked_at < now() - 7 days`).
3. Logs the number of deleted rows at `info` level via `LoggerInterface`.
4. Safe to run under concurrent production load (DELETE with WHERE, no full-table lock).
5. A cron entry is documented in `docker-compose.yml` to run the command daily.

---

## Tasks / Subtasks

- [x] Add `deleteStale(\DateTimeImmutable $now): int` to `RefreshTokenRepository` (AC: 2, 4)
  - [x] DQL DELETE combining both conditions in a single WHERE clause

- [x] Create `CleanupRefreshTokensCommand` in `Identity/Presentation/` (AC: 1, 2, 3)
  - [x] Name: `app:auth:cleanup-refresh-tokens`
  - [x] Inject `RefreshTokenRepository` + `LoggerInterface`
  - [x] Call `deleteStale(new \DateTimeImmutable())`, log count at `info`, write to output, return `Command::SUCCESS`

- [x] Document cron in `docker-compose.yml` (AC: 5)
  - [x] Add comment with the daily cron entry for the command

- [x] Write functional tests (AC: 1, 2, 3)
  - [x] `api/tests/Functional/AuthCleanupCommandTest.php`
  - [x] Seed: expired token, old-revoked token, recently-revoked token, active token
  - [x] Run command, assert expired + old-revoked are deleted, recently-revoked + active remain

- [x] Run quality checks
  - [x] `composer phpstan`
  - [x] `composer cs-fixer`
  - [x] `composer test`

---

## Dev Notes

### Repository Method

```php
public function deleteStale(\DateTimeImmutable $now): int
{
    $grace = $now->modify('-7 days');
    $result = $this->entityManager->createQueryBuilder()
        ->delete(RefreshToken::class, 'rt')
        ->where('rt.expiresAt < :now OR (rt.revokedAt IS NOT NULL AND rt.revokedAt < :grace)')
        ->setParameter('now', $now)
        ->setParameter('grace', $grace)
        ->getQuery()
        ->execute();

    return is_int($result) ? $result : 0;
}
```

### Command Skeleton

```php
#[AsCommand(name: 'app:auth:cleanup-refresh-tokens', description: 'Prune stale refresh token records.')]
final class CleanupRefreshTokensCommand extends Command
{
    public function __construct(
        private readonly RefreshTokenRepository $repository,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted = $this->repository->deleteStale(new \DateTimeImmutable());
        $this->logger->info('auth.cleanup_refresh_tokens', ['deleted' => $deleted]);
        $output->writeln(sprintf('Deleted %d stale refresh token(s).', $deleted));
        return Command::SUCCESS;
    }
}
```

### Cron Note

No API container exists in docker-compose.yml. Document the cron entry as a comment block at the bottom of the file.

### References

- `api/src/Identity/Application/RefreshTokenRepository.php` - `deleteExpiredBefore()` pattern to follow
- `api/src/Identity/Domain/RefreshToken.php` - `expiresAt`, `revokedAt` property names for DQL

---

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

None.

### Completion Notes List

- `deleteStale(\DateTimeImmutable $now): int` in `RefreshTokenRepository` uses a single DQL DELETE with the OR condition so the DB executes one DELETE WHERE statement - safe under concurrent load, no full-table lock.
- `CleanupRefreshTokensCommand` placed in `Identity/Presentation/` (CLI is a delivery mechanism, same layer as HTTP controllers). Auto-wired by Symfony - no service config needed.
- Cron documented as a comment at the bottom of `docker-compose.yml` (no API container exists in the compose file, so a crontab entry pointing at the API host is the appropriate form).
- Test seeds 4 tokens: expired, old-revoked (>7 days), recently-revoked (<7 days), active. Asserts exactly 2 are deleted and the correct 2 remain. Also tests the empty-table case (0 deleted).

### Validation Results

- `composer phpstan`: 0 errors
- `composer cs-fixer`: 0 new errors (pre-existing CRLF issues unaffected)
- `composer test`: 394/394 tests pass, 3639 assertions

### File List

- `api/src/Identity/Application/RefreshTokenRepository.php` (modified)
- `api/src/Identity/Presentation/CleanupRefreshTokensCommand.php` (new)
- `docker-compose.yml` (modified - cron comment)
- `api/tests/Functional/AuthCleanupCommandTest.php` (new)

### Change Log

- **2026-05-05**: Story implemented in full. Added `deleteStale()` to `RefreshTokenRepository`, created `CleanupRefreshTokensCommand`, documented daily cron in `docker-compose.yml`, wrote 2 functional tests. PHPStan clean, 394/394 tests pass.
