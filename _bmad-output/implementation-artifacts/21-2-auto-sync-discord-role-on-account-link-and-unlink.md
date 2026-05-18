# Story 21.2: Auto-Sync Discord Role on Account Link & Unlink

## Story

**As a** user who links or unlinks their Discord account,
**I want** my Discord server role to be automatically assigned or removed within seconds,
**So that** my ArchiLAN status is always reflected on the Discord server without any manual step.

## Status

done

## Acceptance Criteria

**AC1:** A Doctrine migration adds nullable columns `discord_role_synced_at` (`datetimetz_immutable`) and `discord_sync_error` (`string`, 500 chars) to the `user` table. Migration follows the project timestamp convention and is reversible (`down()` drops the columns).

**AC2:** `User::markDiscordSyncSuccess(\DateTimeImmutable $at): void` sets `$this->discordRoleSyncedAt = $at` and sets `$this->discordSyncError = null`.

**AC3:** `User::markDiscordSyncFailure(string $error, \DateTimeImmutable $at): void` sets `$this->discordSyncError = $error` and leaves `$this->discordRoleSyncedAt` unchanged.

**AC4:** `LinkDiscordToAccount::link()` dispatches `SyncDiscordRoleMessage` to the async bus **after** a successful `flush()` when the outcome is `linked`. Message carries `discordUserId = $user->getDiscordId()`, `archilanRoles = $user->getRoles()`, `removeAll = false`. If the user was already linked to a different Discord ID, the old Discord ID is captured before overwrite and a `removeAll=true` message is dispatched for the old account before the new sync message.

**AC5:** `UnlinkDiscordFromAccount::unlink()` captures the Discord user ID **before** calling `unlinkDiscord()`, then dispatches `SyncDiscordRoleMessage` with `removeAll = true` **after** a successful `flush()`. Dispatch failures are logged and never surfaced to the user because the link/unlink database change has already succeeded.

**AC6:** `SyncDiscordRoleMessageHandler` injects `EntityManagerInterface`. For non-`removeAll` messages it reloads the current `User`, skips stale messages when the current `discordId` no longer matches `$message->discordUserId`, and derives roles from the current `User::getRoles()` instead of trusting the message snapshot. On success: if the current `User` still matches the message Discord ID, calls `markDiscordSyncSuccess(new \DateTimeImmutable())`, and flushes. On exception: if the current `User` still matches the message Discord ID, calls `markDiscordSyncFailure($e->getMessage(), new \DateTimeImmutable())`, flushes, then rethrows.

**AC7:** All four quality gates pass: `phpstan analyse src tests` (level max, 0 errors), `php-cs-fixer check src` (0 violations), `php bin/phpunit` (all green), `php bin/console app:architecture:ddd` (exit 0).

## Tasks / Subtasks

- [x] Task 1: Create Doctrine migration for `discord_role_synced_at` and `discord_sync_error` columns
- [x] Task 2: Add ORM columns + `markDiscordSyncSuccess` / `markDiscordSyncFailure` to `User`
- [x] Task 3: Update `LinkDiscordToAccount` to dispatch `SyncDiscordRoleMessage` after flush and remove roles from a previously linked Discord account on relink
- [x] Task 4: Update `UnlinkDiscordFromAccount` to dispatch `SyncDiscordRoleMessage(removeAll=true)` after flush without surfacing dispatch failures
- [x] Task 5: Update `SyncDiscordRoleMessageHandler` to persist sync state on success/failure and skip stale messages
- [x] Task 6: Write unit tests for `User::markDiscordSyncSuccess`, `markDiscordSyncFailure`, link dispatch, relink cleanup, unlink dispatch, and stale message handling
- [x] Task 7: Run all four quality gates and fix any issues

## Dev Notes

### Migration

Filename: `Version20260516123616.php` (one second after the last migration `Version20260516123615.php`).

```sql
-- up
ALTER TABLE "user" ADD discord_role_synced_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL;
ALTER TABLE "user" ADD discord_sync_error VARCHAR(500) DEFAULT NULL;

-- down
ALTER TABLE "user" DROP COLUMN discord_role_synced_at;
ALTER TABLE "user" DROP COLUMN discord_sync_error;
```

### User ORM columns

```php
#[ORM\Column(name: 'discord_role_synced_at', type: 'datetimetz_immutable', nullable: true)]
private ?\DateTimeImmutable $discordRoleSyncedAt = null,
#[ORM\Column(name: 'discord_sync_error', type: 'string', length: 500, nullable: true)]
private ?string $discordSyncError = null,
```

### Dispatch pattern

Capture Discord ID **before** any domain method that nullifies it:

```php
// UnlinkDiscordFromAccount
$discordId = $user->getDiscordId(); // capture before unlinkDiscord()
$user->unlinkDiscord($now);
$this->entityManager->flush();
$this->dispatchDiscordSync(new SyncDiscordRoleMessage($user->getId(), $discordId, [], removeAll: true));
```

### Handler: persist sync state

```php
// On success
$user = $this->entityManager->find(User::class, $message->userId);
if ($user instanceof User) {
    $user->markDiscordSyncSuccess(new \DateTimeImmutable());
    $this->entityManager->flush();
}

// On exception (before rethrow)
$user = $this->entityManager->find(User::class, $message->userId);
if ($user instanceof User) {
    $user->markDiscordSyncFailure($e->getMessage(), new \DateTimeImmutable());
    $this->entityManager->flush();
}
```

For non-`removeAll` messages, the handler reloads the current `User` and ignores stale messages whose `discordUserId` no longer matches the user's current linked Discord ID.

## File List

- `api/migrations/Version20260516123616.php` - new
- `api/src/Identity/Domain/User.php` - modified (2 ORM columns + 2 domain methods)
- `api/src/Identity/Application/LinkDiscordToAccount.php` - modified (inject bus, dispatch after flush)
- `api/src/Identity/Application/UnlinkDiscordFromAccount.php` - modified (inject bus, dispatch after flush)
- `api/src/Identity/Application/Message/SyncDiscordRoleMessageHandler.php` - modified (inject EM, persist sync state)
- `api/tests/Unit/Identity/UserDiscordSyncTest.php` - new
- `api/tests/Unit/Identity/DiscordLinkSyncDispatchTest.php` - new

## Change Log

| Date       | Change          |
|------------|-----------------|
| 2026-05-16 | Story created   |
