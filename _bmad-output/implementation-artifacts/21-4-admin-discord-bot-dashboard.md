# Story 21.4: Admin Discord Bot Dashboard - Status, User Table & Bulk Resync

## Story

**As an** admin,
**I want** a backoffice panel showing the bot's connection status, a per-user sync table, and a one-click bulk resync button,
**So that** I can verify the integration is healthy and fix drift without using the command line.

## Status

done

## Acceptance Criteria

**AC1:** `GET /api/v1/admin/discord-bot/status` returns `200 { data: { botOnline, guildName, memberCount, managedRoleIds } }`. If `fetchGuildInfo()` throws, returns degraded `{ botOnline: false, ... null }` - no 500.

**AC2:** `GET /api/v1/admin/discord-bot/users?page=1&limit=50` returns `200 { data: [...], meta: { page, limit, total } }`. Every entry has `discord_id IS NOT NULL`.

**AC3:** `POST /api/v1/admin/discord-bot/resync` dispatches `SyncDiscordRoleMessage` for every user with `discord_id IS NOT NULL` and returns `202 { data: { queued: N } }`.

**AC4:** Non-admin requests to all three endpoints return `403`.

**AC5:** `/admin/discord` page shows bot status card, user table with sync info, and resync button.

**AC6:** All four API quality gates pass + `pnpm typecheck`, `pnpm lint`, `pnpm build` clean.

## Tasks / Subtasks

- [x] Task 1: Create `DiscordBotStatusQuery` Application service
- [x] Task 2: Create `DiscordBotUsersQuery` Application service
- [x] Task 3: Create `DiscordResyncAllUsers` Application service
- [x] Task 4: Create three controllers (`DiscordBotStatusController`, `DiscordBotUsersController`, `DiscordBotResyncController`)
- [x] Task 5: Run API quality gates (phpstan, cs-fixer, phpunit, ddd)
- [x] Task 6: Create `features/admin/discord-bot-api.ts` with fetch functions and type guards
- [x] Task 7: Create `features/admin/admin-discord-dashboard.tsx` client component
- [x] Task 8: Create `app/(admin)/admin/discord/page.tsx` and add Discord Bot entry to admin dashboard
- [x] Task 9: Run frontend quality gates (typecheck, lint, build)

## Dev Notes

### DiscordBotStatusQuery

Injects `DiscordBotClientInterface` + `$guildId`, `$roleIdAdmin`, `$roleIdMember`, `$roleIdUser`. On success: extracts `name` (guildName) and `approximate_member_count` (memberCount). On exception: returns degraded payload.

`managedRoleIds` = list of non-empty role IDs.

### DiscordBotUsersQuery

Injects `Connection` + `EntityManagerInterface` (for `getTableName()`). DBAL `SELECT ... WHERE discord_id IS NOT NULL`. Paginated via `LIMIT/OFFSET`. Total via `COUNT(*)` subquery or second query.

Return shape per row: `{id, email, displayName, roles, discordId, discordUsername, discordRoleSyncedAt, discordSyncError}`.

### DiscordResyncAllUsers

Injects `Connection`, `EntityManagerInterface`, `MessageBusInterface`. DBAL cursor to iterate all users with `discord_id IS NOT NULL`. If `!$dryRun`: dispatches `SyncDiscordRoleMessage` per user. Returns count.

### Controllers

All three live in `Identity/Presentation/` (no Admin/ subdirectory, consistent with existing pattern). Use `RequiresAuthTrait` + `$this->requireAuthenticatedAdmin($request)`.

### Frontend

Follow `community-api.ts` pattern: functions return `T | null`, type guards inline, no `any`.

Admin dashboard page (`app/(admin)/admin/page.tsx`): add `{ href: '/admin/discord', icon: BotIcon, label: 'Discord Bot', ... }` to sections array.

## File List

- `api/src/Identity/Application/DiscordBotStatusQuery.php` - new
- `api/src/Identity/Application/DiscordBotUsersQuery.php` - new
- `api/src/Identity/Application/DiscordResyncAllUsers.php` - new
- `api/src/Identity/Presentation/DiscordBotStatusController.php` - new
- `api/src/Identity/Presentation/DiscordBotUsersController.php` - new
- `api/src/Identity/Presentation/DiscordBotResyncController.php` - new
- `frontend/src/features/admin/discord-bot-api.ts` - new
- `frontend/src/features/admin/admin-discord-dashboard.tsx` - new
- `frontend/src/app/(admin)/admin/discord/page.tsx` - new
- `frontend/src/app/(admin)/admin/page.tsx` - modified (add Discord Bot section)

## Change Log

| Date       | Change          |
|------------|-----------------|
| 2026-05-16 | Story created   |
