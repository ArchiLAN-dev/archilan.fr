# Story 21.1: Discord Bot Client Interface & Sync Message Infrastructure

## Story

**As a** developer,
**I want** a `DiscordBotClientInterface` with a real HTTP client and a null stub, plus a `SyncDiscordRoleMessage` Messenger pipeline,
**So that** role-sync side effects can be dispatched asynchronously without coupling business logic to Discord's API.

## Status

done

## Acceptance Criteria

**AC1:** `src/Identity/Application/DiscordBotClientInterface.php` exists in `Identity/Application/` with methods:
```php
public function assignRole(string $guildId, string $discordUserId, string $roleId): void;
public function removeRole(string $guildId, string $discordUserId, string $roleId): void;
/** @return array<string, mixed> */
public function fetchGuildInfo(string $guildId): array;
```

**AC2:** `src/Identity/Infrastructure/DiscordBotClient.php` implements the Application interface using Symfony `HttpClientInterface`. Every request carries `Authorization: Bot {token}`. `assignRole` calls `PUT /api/v10/guilds/{guildId}/members/{userId}/roles/{roleId}` and throws unless Discord returns 204. `removeRole` calls `DELETE` on the same URL and throws unless Discord returns 204. `fetchGuildInfo` calls `GET /api/v10/guilds/{guildId}` and returns the decoded body as `array<string, mixed>`.

**AC3:** `src/Identity/Infrastructure/NullDiscordBotClient.php` implements the Application interface as a no-op stub. `fetchGuildInfo()` returns `['online' => false]`.

**AC4:** `src/Identity/Application/Message/SyncDiscordRoleMessage.php` is a `final readonly` class with constructor properties: `userId` (string - ArchiLAN UUID), `discordUserId` (string - Discord snowflake), `archilanRoles` (`list<string>`), `removeAll` (bool, defaults to `false`).

**AC5:** `src/Identity/Application/Message/SyncDiscordRoleMessageHandler.php` implements `#[AsMessageHandler]` with `__invoke(SyncDiscordRoleMessage): void`. It injects `DiscordBotClientInterface`, `LoggerInterface`, and config params `$guildId`, `$roleIdAdmin`, `$roleIdMember`, `$roleIdUser`. Logic:
- Builds a map `['ROLE_ADMIN' => $roleIdAdmin, 'ROLE_MEMBER' => $roleIdMember, 'ROLE_USER' => $roleIdUser]`, skipping entries where the role ID is an empty string.
- If `removeAll`: calls `removeRole()` for every non-empty managed role ID.
- Else: selects exactly one ArchiLAN role by priority (`ROLE_ADMIN` > `ROLE_MEMBER` > `ROLE_USER`), calls `assignRole()` only for that role, and calls `removeRole()` for all other managed roles.
- Logs `discord_bot.role_synced` at `info` level on success, `discord_bot.role_sync_failed` at `error` level on exception (then rethrows for Messenger retry).

**AC6:** `services.yaml` registers:
- `App\Identity\Application\DiscordBotClientInterface → DiscordBotClient` with `$botToken: '%env(DISCORD_BOT_TOKEN)%'`
- `SyncDiscordRoleMessageHandler` with `$guildId`, `$roleIdAdmin`, `$roleIdMember`, `$roleIdUser` from env
- `when@test:` overrides `DiscordBotClientInterface` with class `NullDiscordBotClient`

**AC7:** `config/packages/messenger.yaml` routes `App\Identity\Application\Message\SyncDiscordRoleMessage: async`.

**AC8:** `api/.env` adds `DISCORD_BOT_TOKEN=`, `DISCORD_GUILD_ID=`, `DISCORD_ROLE_ID_ADMIN=`, `DISCORD_ROLE_ID_MEMBER=`, `DISCORD_ROLE_ID_USER=`.

**AC9:** A unit test `tests/Unit/Identity/SyncDiscordRoleMessageHandlerTest.php` covers:
- `removeAll=true` → `removeRole()` called for every non-empty managed role ID, `assignRole()` never called
- `archilanRoles=['ROLE_USER', 'ROLE_MEMBER']` → `assignRole()` for member role only, `removeRole()` for admin and user roles
- `archilanRoles=['ROLE_USER', 'ROLE_MEMBER', 'ROLE_ADMIN']` → `assignRole()` for admin role only, `removeRole()` for member and user roles
- Empty role ID in config → that specific role is silently skipped (no call)
- HTTP client throws when Discord role assign/remove does not return 204

**AC10:** All four quality gates pass: `phpstan analyse src tests` (level max, 0 errors), `php-cs-fixer check src` (0 violations), `php bin/phpunit` (all green), `php bin/console app:architecture:ddd` (exit 0).

## Tasks / Subtasks

- [x] Task 1: Create `DiscordBotClientInterface`
- [x] Task 2: Create `DiscordBotClient` (real HTTP implementation)
- [x] Task 3: Create `NullDiscordBotClient` (test stub)
- [x] Task 4: Create `SyncDiscordRoleMessage`
- [x] Task 5: Create `SyncDiscordRoleMessageHandler`
- [x] Task 6: Wire `services.yaml` (interface binding, handler args, `when@test:` override)
- [x] Task 7: Add Messenger routing in `messenger.yaml`
- [x] Task 8: Add env vars to `api/.env`
- [x] Task 9: Write unit tests for `SyncDiscordRoleMessageHandler`
- [x] Task 10: Run all four quality gates and fix any issues

## Dev Notes

### Interface placement convention

`DiscordBotClientInterface` lives in `Identity/Application/`. The real and null clients live in `Identity/Infrastructure/`, implementing the Application interface so Application code does not import Infrastructure.

### Discord REST API endpoints

Base URL: `https://discord.com/api/v10`

| Method | Endpoint | Expected response |
|--------|----------|-------------------|
| PUT | `/guilds/{guildId}/members/{userId}/roles/{roleId}` | 204 No Content |
| DELETE | `/guilds/{guildId}/members/{userId}/roles/{roleId}` | 204 No Content |
| GET | `/guilds/{guildId}` | 200 JSON |

Auth header: `Authorization: Bot {botToken}` on every request.

The `HttpClientInterface` to inject is Symfony's `Symfony\Contracts\HttpClient\HttpClientInterface`. In test env it is overridden with `MockHttpClient` globally - `NullDiscordBotClient` takes precedence via the `when@test:` interface override, so `DiscordBotClient` is never instantiated in tests.

### SyncDiscordRoleMessageHandler - empty role ID guard

Discord role IDs are Discord snowflakes (numeric strings). A missing/unconfigured role (empty string) must be silently skipped to support deployments that don't use all three tiers. The guard is:
```php
if ('' === $roleId) { continue; }
```

### Messenger retry

The handler rethrows on exception - Symfony Messenger retry middleware handles it. The async transport in test env is `in-memory://` so messages are never actually sent in tests.

### services.yaml pattern (reference)

```yaml
App\Identity\Application\DiscordBotClientInterface: '@App\Identity\Infrastructure\DiscordBotClient'

App\Identity\Infrastructure\DiscordBotClient:
    arguments:
        $botToken: '%env(DISCORD_BOT_TOKEN)%'
        $guildId: '%env(DISCORD_GUILD_ID)%'

App\Identity\Application\Message\SyncDiscordRoleMessageHandler:
    arguments:
        $guildId: '%env(DISCORD_GUILD_ID)%'
        $roleIdAdmin: '%env(DISCORD_ROLE_ID_ADMIN)%'
        $roleIdMember: '%env(DISCORD_ROLE_ID_MEMBER)%'
        $roleIdUser: '%env(DISCORD_ROLE_ID_USER)%'

when@test:
    services:
        App\Identity\Application\DiscordBotClientInterface:
            class: App\Identity\Infrastructure\NullDiscordBotClient
```

## File List

- `api/src/Identity/Application/DiscordBotClientInterface.php` - new
- `api/src/Identity/Infrastructure/DiscordBotClient.php` - new
- `api/src/Identity/Infrastructure/NullDiscordBotClient.php` - new
- `api/src/Identity/Application/Message/SyncDiscordRoleMessage.php` - new
- `api/src/Identity/Application/Message/SyncDiscordRoleMessageHandler.php` - new
- `api/config/services.yaml` - modified (interface binding + handler args + when@test)
- `api/config/packages/messenger.yaml` - modified (routing entry)
- `api/.env` - modified (5 new env vars)
- `api/tests/Unit/Identity/SyncDiscordRoleMessageHandlerTest.php` - new
- `api/tests/Unit/Identity/DiscordBotClientTest.php` - new

## Change Log

| Date       | Change          |
|------------|-----------------|
| 2026-05-16 | Story created   |
