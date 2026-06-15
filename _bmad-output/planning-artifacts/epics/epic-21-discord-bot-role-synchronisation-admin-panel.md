# Epic 21: Discord Bot - Role Synchronisation & Admin Panel

Users with linked Discord accounts automatically receive the Discord server role matching their ArchiLAN role (Admin / Membre / User). Admins can monitor bot health and trigger resyncs from the backoffice.

## Story 21.1: Discord Bot Client Interface & Sync Message Infrastructure

As a developer,
I want a `DiscordBotClientInterface` with a real HTTP client and a null stub, plus a `SyncDiscordRoleMessage` Messenger pipeline,
So that role-sync side effects can be dispatched asynchronously without coupling business logic to Discord's API.

**Context:**
The Discord Bot REST API (`PATCH /guilds/{guildId}/members/{userId}/roles/{roleId}` / `DELETE` variant) requires a bot token (`Authorization: Bot <token>`), distinct from the OAuth2 access token used for user linking. Three new env vars are needed: `DISCORD_BOT_TOKEN`, `DISCORD_GUILD_ID`, and one role ID per ArchiLAN tier (`DISCORD_ROLE_ID_ADMIN`, `DISCORD_ROLE_ID_MEMBER`, `DISCORD_ROLE_ID_USER`). The `NullDiscordBotClient` is registered under `when@test:` in `services.yaml` so CI passes without a real bot token. The message handler maps ArchiLAN roles to Discord role IDs from config parameters.

**Acceptance Criteria:**

**Given** the Identity bounded context exists
**When** story 21.1 is implemented
**Then** `src/Identity/Application/DiscordBotClientInterface.php` is created with methods:
```php
public function assignRole(string $guildId, string $discordUserId, string $roleId): void;
public function removeRole(string $guildId, string $discordUserId, string $roleId): void;
/** @return array<string, mixed> */
public function fetchGuildInfo(string $guildId): array;
```
**And** `src/Identity/Infrastructure/DiscordBotClient.php` implements the Application interface using Symfony `HttpClientInterface`, setting `Authorization: Bot {token}` on every request, and throws unless role assign/remove calls return `204`; callers narrow each field from `array<string, mixed>` with `is_string()` / `is_int()` before use (no direct casts)
**And** `src/Identity/Infrastructure/NullDiscordBotClient.php` implements the Application interface as a no-op stub; `fetchGuildInfo()` returns `['online' => false]`
**And** `src/Identity/Application/Message/SyncDiscordRoleMessage.php` is a readonly class with properties: `userId` (ArchiLAN UUID), `discordUserId` (Discord snowflake), `archilanRoles` (`list<string>`), `removeAll` (`bool`, default `false`)
**And** `src/Identity/Application/Handler/SyncDiscordRoleMessageHandler.php` implements `__invoke(SyncDiscordRoleMessage)`:
- Resolves Discord role IDs from injected config params
- If `removeAll`: calls `removeRole()` for each managed role ID the user might hold
- Else: reloads the current `User` by `userId`, uses the current `discordId` and roles as the source of truth, selects exactly one Discord role by ArchiLAN priority (`ROLE_ADMIN` > `ROLE_MEMBER` > `ROLE_USER`), calls `assignRole()` for that role, and `removeRole()` for all other managed roles
**And** `services.yaml` registers `DiscordBotClientInterface → DiscordBotClient` with `$botToken`, `$guildId` from parameters; `when@test:` overrides with `NullDiscordBotClient`
**And** `api/.env` and the environment example/documentation add `DISCORD_BOT_TOKEN=`, `DISCORD_GUILD_ID=`, `DISCORD_ROLE_ID_ADMIN=`, `DISCORD_ROLE_ID_MEMBER=`, `DISCORD_ROLE_ID_USER=`
**And** the role ID mapping documents the Discord role hierarchy requirement: the bot role must be above every managed role, otherwise sync fails with a logged configuration error
**And** all four quality gates pass (PHPStan level max, CS Fixer, phpunit, DDD validator)

---

## Story 21.2: Auto-Sync Discord Role on Account Link & Unlink

As a user who links or unlinks their Discord account,
I want my Discord server role to be automatically assigned or removed within seconds,
So that my ArchiLAN status is always reflected on the Discord server without any manual step.

**Context:**
`User::$discordId` (the Discord snowflake) already exists on the entity - no new migration is needed to identify the user to the bot. However, the sync state (timestamp + last error) must be tracked to power the admin dashboard in Story 21.4. Two nullable columns are added: `discord_role_synced_at` (datetime_immutable) and `discord_sync_error` (text). The handler updates these columns after each sync attempt. `LinkDiscordToAccount::link()` and `UnlinkDiscordFromAccount::unlink()` inject `MessageBusInterface` and dispatch `SyncDiscordRoleMessage` **after a successful flush in the application service**. The Messenger transport must be async (existing `async` transport is used).
Dispatch failures after a successful `flush()` are logged and never surfaced to the user; link/unlink remains successful even if the sync queue is temporarily unavailable.

**Acceptance Criteria:**

**Given** a migration is applied
**When** story 21.2 begins
**Then** a new Doctrine migration following the project's timestamp convention adds nullable columns `discord_role_synced_at` (datetime_immutable) and `discord_sync_error` (text, 500 chars) to the `users` table
**And** `User::markDiscordSyncSuccess(\DateTimeImmutable $at): void` sets `discordRoleSyncedAt = $at` and clears `discordSyncError`
**And** `User::markDiscordSyncFailure(string $error, \DateTimeImmutable $at): void` sets `discordSyncError` and leaves `discordRoleSyncedAt` unchanged

**Given** `LinkDiscordToAccount::link()` completes with outcome `linked`
**When** the method returns
**Then** `SyncDiscordRoleMessage` is dispatched to the async Messenger bus with `discordUserId = $user->getDiscordId()`, `archilanRoles = $user->getRoles()`, `removeAll = false`
**And** the dispatch happens after the service's successful `flush()` - never before
**And** if the user was already linked to a different Discord ID, a `removeAll = true` message is dispatched for the previous Discord ID before the new sync message

**Given** `UnlinkDiscordFromAccount::unlink()` is called for a user with a linked Discord account
**When** the method resolves
**Then** `SyncDiscordRoleMessage` is dispatched with `removeAll = true` and the Discord user ID captured **before** `unlinkDiscord()` nullifies it
**And** the dispatch happens after the service's successful `flush()`
**And** a dispatch exception is logged but does not change the unlink response

**Given** `SyncDiscordRoleMessageHandler` processes the message successfully
**When** the handler returns
**Then** `User::markDiscordSyncSuccess()` is called and flushed

**Given** `SyncDiscordRoleMessageHandler` catches a Discord API or configuration exception during an attempt
**When** the handler cannot complete the sync
**Then** `User::markDiscordSyncFailure(message, new \DateTimeImmutable())` is called and flushed before the exception is rethrown for Messenger retry handling
**And** the exception is logged via `LoggerInterface` at `error` level with `userId`, `discordUserId`, and `removeAll` context
**And** the error is never surfaced to the end user (the link/unlink API response is unaffected)

**And** all four quality gates pass

---

## Story 21.3: Sync Discord Role on ArchiLAN Role Promotion / Demotion

As an admin who promotes or demotes a user,
I want the user's Discord server role to reflect the new ArchiLAN role automatically,
So that Discord membership always matches the current ArchiLAN tier without manual intervention.

**Context:**
`AdminChangeUserRole::change()` already performs the role change and calls `flush()`. Injecting `MessageBusInterface` and dispatching `SyncDiscordRoleMessage` after a successful `flush()` - but only when the target user has a non-null `discordId` - is the only change required. The message carries the user's **new** roles (post-change), not the old ones. `User::markDiscordSyncSuccess()` and `markDiscordSyncFailure()` introduced in Story 21.2 are used by the existing handler - no new domain methods are needed here.

**Acceptance Criteria:**

**Given** `AdminChangeUserRole` is updated
**When** `change()` completes with a successful role transition (no errors returned)
**Then** if `$target->getDiscordId()` is non-null, `SyncDiscordRoleMessage` is dispatched after the service's successful `flush()` with the target user's updated roles and `removeAll = false`
**And** if `$target->getDiscordId()` is null, no message is dispatched (user has no linked Discord account)
**And** the dispatch never happens before persistence succeeds; if a stricter post-commit guarantee is required later, the implementation must introduce an outbox/post-commit dispatcher instead of relying on `flush()` alone

**Given** the Messenger handler processes the sync message for a role change
**When** the handler completes
**Then** all managed Discord roles inconsistent with the new ArchiLAN role are removed and the correct role is assigned in a single sequence of bot API calls
**And** `User::markDiscordSyncSuccess()` is called and flushed on success

**And** all four quality gates pass

---

## Story 21.4: Admin Discord Bot Dashboard - Status, User Table & Bulk Resync

As an admin,
I want a backoffice panel showing the bot's connection status, a per-user sync table, and a one-click bulk resync button,
So that I can verify the integration is healthy and fix drift without using the command line.

**Context:**
Three new API endpoints are added under the `Identity/Presentation/Admin/` namespace. Controllers delegate to Application services only; they do not inject DBAL, EntityManager, Messenger, or the Discord client directly.
- `GET /api/v1/admin/discord-bot/status` - calls an Application query service wrapping `DiscordBotClientInterface::fetchGuildInfo()` and returns `{ botOnline, guildName, memberCount, managedRoleIds }`. Returns a degraded `{ botOnline: false }` payload if the bot API is unreachable, so the UI can show a warning without throwing.
- `GET /api/v1/admin/discord-bot/users` - calls an Application query service that uses DBAL internally to read `users` filtered to `discord_id IS NOT NULL`, paginated, returning `{ id, email, displayName, roles, discordId, discordUsername, discordRoleSyncedAt, discordSyncError }`.
- `POST /api/v1/admin/discord-bot/resync` - calls the Application command service `DiscordResyncAllUsers::run(bool $dryRun = false): int`. With `$dryRun = false` it dispatches `SyncDiscordRoleMessage` for every user with a non-null `discordId` and returns the count. The endpoint always calls with `$dryRun = false` and responds `202 { data: { queued: N } }`. The same service is reused by the console command in Story 21.5 with `$dryRun = true`.

The frontend adds a new `/admin/discord` page (Next.js App Router). The server page fetches initial status/table data; a child client component owns the bulk resync button, loading state, mutation call, and refresh after completion. The existing admin sidebar navigation must be updated to include a "Discord Bot" entry pointing to `/admin/discord`.

**Acceptance Criteria:**

**Given** an admin is authenticated
**When** `GET /api/v1/admin/discord-bot/status` is called
**Then** the response is `200` with `{ data: { botOnline: bool, guildName: string|null, memberCount: int|null, managedRoleIds: string[] } }`
**And** if `fetchGuildInfo()` throws, `botOnline` is `false` and other fields are `null` - no 500 is returned

**Given** an admin is authenticated
**When** `GET /api/v1/admin/discord-bot/users?page=1&limit=50` is called
**Then** the response is `200` with `{ data: [...], meta: { page: 1, limit: 50, total: int } }`
**And** every entry has `discord_id IS NOT NULL` and contains `id`, `email`, `displayName`, `roles`, `discordId`, `discordUsername`, `discordRoleSyncedAt`, `discordSyncError`

**Given** an admin is authenticated
**When** `POST /api/v1/admin/discord-bot/resync` is called
**Then** the controller calls `DiscordResyncAllUsers`
**And** `SyncDiscordRoleMessage` is dispatched for every user with a non-null `discordId`
**And** the response is `202` with `{ data: { queued: N } }`

**Given** a non-admin user is authenticated
**When** any of the three endpoints is called
**Then** the response is `403 Forbidden`

**Given** the admin visits `/admin/discord`
**When** the page loads
**Then** the bot status card shows connection status (green checkmark or red warning), guild name, and member count
**And** the per-user table shows email, Discord username, last sync timestamp, and a sync error badge when `discordSyncError` is non-null
**And** the "Resync tout" button dispatches `POST /api/v1/admin/discord-bot/resync`, shows a loading state, and displays the queued count on completion
**And** `pnpm typecheck`, `pnpm lint`, and `pnpm build` are clean
**And** all four API quality gates pass

---

## Story 21.5: Console Command `app:discord:resync-roles`

As a developer or system administrator,
I want a Symfony console command to trigger a full Discord role resync for all linked accounts,
So that I can recover from drift after migrations, bot downtime, or initial setup without going through the admin UI.

**Context:**
The command reuses `DiscordResyncAllUsers` introduced in Story 21.4. `DiscordResyncAllUsers::run(bool $dryRun = false): int` iterates all `User` rows where `discord_id IS NOT NULL` via DBAL (never `EntityManagerInterface::findAll()`) and either dispatches one `SyncDiscordRoleMessage` per row (`$dryRun = false`) or only counts them (`$dryRun = true`), returning the count in both cases. The command passes `--dry-run` to the service, then formats the output. The command lives in `src/Identity/Presentation/Command/` and calls at most one Application service (AC-P4).

**Acceptance Criteria:**

**Given** users with non-null `discord_id` exist in the database
**When** `php bin/console app:discord:resync-roles` is executed
**Then** one `SyncDiscordRoleMessage` per matching user is dispatched to the async Messenger bus
**And** the command outputs `"Dispatched N sync messages."` and exits 0

**Given** `--dry-run` is passed
**When** the command runs
**Then** no messages are dispatched
**And** the command outputs `"[DRY-RUN] Would dispatch N sync messages."` and exits 0

**Given** no users have a linked Discord account
**When** the command runs
**Then** the command outputs `"No linked Discord accounts found."` and exits 0

**Given** the `DiscordBotClientInterface` is the null stub (test environment)
**When** the command runs in the test environment
**Then** messages are dispatched to the bus with no real HTTP calls made
**And** `pnpm typecheck`, `pnpm lint`, `pnpm build` are unaffected (frontend-only gates)
**And** all four API quality gates pass (PHPStan, CS Fixer, phpunit, DDD validator)

---
