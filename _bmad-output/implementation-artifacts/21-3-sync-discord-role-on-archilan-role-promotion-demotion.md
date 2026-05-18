# Story 21.3: Sync Discord Role on ArchiLAN Role Promotion / Demotion

## Story

**As an** admin who promotes or demotes a user,
**I want** the user's Discord server role to reflect the new ArchiLAN role automatically,
**So that** Discord membership always matches the current ArchiLAN tier without manual intervention.

## Status

done

## Acceptance Criteria

**AC1:** `AdminChangeUserRole` injects `MessageBusInterface`. After a successful `flush()`, if `$target->getDiscordId()` is non-null, `SyncDiscordRoleMessage` is dispatched with the target's **post-change** roles and `removeAll = false`. Dispatch failures are logged and never make the already-persisted role change fail.

**AC2:** If `$target->getDiscordId()` is null, no message is dispatched.

**AC3:** The dispatch happens strictly after `flush()` - never before persistence succeeds.

**AC4:** If the requested role is already the target user's current primary role, the service returns the current payload without creating an audit entry, flushing, or dispatching Discord sync.

**AC5:** Unit tests cover: promotion dispatch when Discord is linked, demotion dispatch when Discord is linked, no dispatch when not linked, no-op role changes, dispatch failure after flush, and flush-before-dispatch ordering.

**AC6:** All four quality gates pass.

## Tasks / Subtasks

- [x] Task 1: Update `AdminChangeUserRole` to inject `MessageBusInterface` and dispatch after flush
- [x] Task 2: Write unit tests for dispatch, no-dispatch, demotion, no-op, dispatch failure, and flush-before-dispatch ordering
- [x] Task 3: Run all four quality gates and fix any issues

## Dev Notes

### Change pattern

```php
// After flush() success, at the end of change():
$discordId = $target->getDiscordId();
if (null !== $discordId) {
    $this->dispatchDiscordSync(new SyncDiscordRoleMessage(
        $target->getId(),
        $discordId,
        $target->getRoles(),
    ));
}
```

The message carries the **new** roles (post `promoteToMember()` / `demoteToUser()`), captured after the domain method.
`dispatchDiscordSync()` catches `Throwable`, logs `discord.sync_dispatch_failed`, and does not rethrow because the role change has already been committed.

If `$previousRole === $normalizedRole`, the service returns the existing user payload immediately and does not persist an audit or dispatch sync.

### Handler already handles sync state

`SyncDiscordRoleMessageHandler` (updated in 21.2) already calls `markDiscordSyncSuccess/Failure` and skips stale messages, so no handler changes are needed.

## File List

- `api/src/Identity/Application/AdminChangeUserRole.php` - modified
- `api/tests/Unit/Identity/AdminChangeUserRoleDiscordSyncTest.php` - new

## Change Log

| Date       | Change          |
|------------|-----------------|
| 2026-05-16 | Story created   |
