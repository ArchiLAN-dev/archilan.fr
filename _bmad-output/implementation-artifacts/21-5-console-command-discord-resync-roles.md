# Story 21.5: Console Command `app:discord:resync-roles`

## Story

**As a** developer or system administrator,
**I want** a Symfony console command to trigger a full Discord role resync for all linked accounts,
**So that** I can recover from drift after migrations, bot downtime, or initial setup without going through the admin UI.

## Status

done

## Acceptance Criteria

**AC1:** `php bin/console app:discord:resync-roles` dispatches one `SyncDiscordRoleMessage` per user with `discord_id IS NOT NULL`, outputs `"Dispatched N sync messages."`, and exits 0.

**AC2:** With `--dry-run`, no messages are dispatched, outputs `"[DRY-RUN] Would dispatch N sync messages."`, exits 0.

**AC3:** When no users have a linked Discord account, outputs `"No linked Discord accounts found."` and exits 0.

**AC4:** All four API quality gates pass (PHPStan level max, CS Fixer, phpunit, DDD validator).

## Tasks / Subtasks

- [x] Task 1: Create `ResyncDiscordRolesCommand` in `src/Identity/Presentation/Command/`
- [x] Task 2: Write unit test `tests/Unit/Identity/ResyncDiscordRolesCommandTest.php`
- [x] Task 3: Run all four API quality gates (phpstan, cs-fixer, phpunit, ddd)

## Dev Notes

### Command class

- Namespace: `App\Identity\Presentation\Command`
- File: `src/Identity/Presentation/Command/ResyncDiscordRolesCommand.php`
- `#[AsCommand(name: 'app:discord:resync-roles')]`
- Injects `DiscordResyncAllUsers $discordResyncAllUsers`
- Defines `--dry-run` option (no value required, boolean flag)
- Calls `$this->discordResyncAllUsers->run(dryRun: $input->getOption('dry-run'))` - returns `int $count`
- Output:
  - `$count === 0` → `"No linked Discord accounts found."` → exit 0
  - `$dryRun === true` → `"[DRY-RUN] Would dispatch {$count} sync messages."` → exit 0
  - else → `"Dispatched {$count} sync messages."` → exit 0

### `DiscordResyncAllUsers`

Already exists from Story 21.4: `src/Identity/Application/DiscordResyncAllUsers.php`.
Signature: `public function run(bool $dryRun = false): int`

### DDD layer

- Commands live in `Presentation/Command/` - this is the Presentation layer (AC-P4: at most one Application service call).
- No controller logic here - just deserialize CLI input → call service → format output.

### Testing

- Unit test: mock `DiscordResyncAllUsers` using `createMock()`.
- Three cases: N > 0 no dry-run, N > 0 dry-run, N = 0.
- Use `CommandTester` from `symfony/console`.

## File List

- `api/src/Identity/Application/DiscordResyncAllUsersInterface.php` - new
- `api/src/Identity/Application/DiscordResyncAllUsers.php` - modified (implements interface, linter added LoggerInterface + iterateAssociative + error handling)
- `api/src/Identity/Presentation/Command/ResyncDiscordRolesCommand.php` - new
- `api/tests/Unit/Identity/ResyncDiscordRolesCommandTest.php` - new

## Change Log

| Date       | Change                                                                                 |
|------------|----------------------------------------------------------------------------------------|
| 2026-05-16 | Story created                                                                          |
| 2026-05-16 | Implemented: interface, command, 4 unit tests. All quality gates green (781 tests OK). |
