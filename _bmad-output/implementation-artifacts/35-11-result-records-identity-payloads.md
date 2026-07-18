# Story 35.11: Typed result records - Identity read-payloads (api/)

Status: done

## Story

As a maintainer continuing epic 35 Stage 2,
I want the four remaining array-returning Identity commands (`AdminChangeUserRole`, `AdminCreateAdminAccount`,
`CreatePrivacyRightsRequest`, `RegisterUser`) to return `final readonly` result records instead of associative
arrays - and in particular `RegisterUser` to stop leaking a raw Doctrine `User` entity - so that the Identity
write path drops its remaining `array{...}` return shapes with every HTTP contract unchanged.

## Context

Epic 35, Stage 2, fourth story. After 35.10 (`ChangeUserSlug` ack), four Identity commands still return arrays:

- `AdminChangeUserRole::change` and `AdminCreateAdminAccount::create` return the **identical** admin user payload
  (`array{id, email, displayName, role, roles, status, createdAt, updatedAt, deletedAt}`) via a shared
  `userPayload()` helper - a single canonical `AdminUserView` record serves both.
- `CreatePrivacyRightsRequest::create` returns `array{id, rightType, status, handlingMode, submittedAt}`.
- `RegisterUser::register` returns `array{user: User}` - a **raw Doctrine entity wrapped in an array**, the
  single worst AC-A3 offender in Identity. The controller only renders `{id, email, roles}`.

**RegisterUser is also a test fixture.** ~10 functional tests call `register()` and read `$result['user']` to
get a `User` handle for login/setup. Converting the return to a lean `RegisteredUser {id, email, roles}` record
severs that coupling; those tests move to a new `FunctionalTestCase::registerUser()` helper that registers
through the real command and returns the persisted entity re-fetched by id. Decided in-session (Jean, "tout dans
35.11") to do all four conversions + the test decoupling in one story.

## Acceptance Criteria

1. **AC1 - Records.** Add three `final readonly` records colocated in `Identity/Application/Command/`:
   `AdminUserView` (id, email, ?displayName, role, list<string> roles, status, createdAt, updatedAt,
   ?deletedAt), `PrivacyRightsResult` (id, rightType, status, handlingMode, submittedAt), `RegisteredUser`
   (id, email, list<string> roles). Property order matches the current array key order (byte-identical JSON).
2. **AC2 - Admin commands.** `AdminChangeUserRole::change` and `AdminCreateAdminAccount::create` both return
   `AdminUserView` (shared record); their `userPayload()` helpers build it. `@return array{...}` docblocks
   dropped, `@throws` kept.
3. **AC3 - Privacy command.** `CreatePrivacyRightsRequest::create` returns `PrivacyRightsResult`.
4. **AC4 - RegisterUser.** `RegisterUser::register` returns `RegisteredUser` (no entity leak);
   `RegisterUserController` reads `$result->{id,email,roles}`.
5. **AC5 - Controllers byte-identical.** The three admin/privacy controllers pass the record straight to
   `['data' => $result]` (json_encode serializes the public props in declaration order = the former array); the
   register controller builds `{id, email, roles}` from the record. All response bodies byte-identical.
6. **AC6 - Test decoupling.** `FunctionalTestCase::registerUser(): User` helper added; all ~10 fixture call
   sites converted (read sites take the entity from the helper; side-effect-only sites discard it; the 3
   dead-`$result` sites drop the assignment). `AdminChangeUserRoleDiscordSyncTest` reads `$result->role`.
7. **AC7 - Gates.** `composer gates` green. Full isolated suite green.

## Tasks / Subtasks

- [x] Task 1: `AdminUserView` + `PrivacyRightsResult` + `RegisteredUser` records (AC: 1).
- [x] Task 2: convert the four commands' returns + signatures + docblocks (AC: 2, 3, 4).
- [x] Task 3: controllers read the records (AC: 4, 5).
- [x] Task 4: `registerUser()` helper + convert the ~10 fixture sites + the unit test (AC: 6).
- [x] Task 5: verify + ship (AC: 7) - static gates + isolated full suite. PR to `develop`.

## Dev Notes

- **One shared `AdminUserView`.** The two admin commands emit an identical payload; a single record (not two
  twins) is the clean outcome. `role` is `'admin'` (literal) for account creation, `primaryRole($user)` for role
  change - the command computes it, the record just holds a `string`.
- **Records passed straight to `JsonResponse`.** For the admin/privacy controllers the record *is* the `data`
  payload, so `['data' => $result]` (unchanged for two of them) serializes via `json_encode` - public props in
  declaration order reproduce the former array exactly. This keeps controllers a pure serialize step (AC-P3).
- **`RegisteredUser` carries only what the controller renders** (`id, email, roles`), not the `User`. This is
  the deliberate AC-A3 fix: a command result must never expose a raw Doctrine entity.
- **Test fixture decoupling.** `register()` was abused as an entity factory across ~10 tests. The new
  `FunctionalTestCase::registerUser()` registers through the real command (still exercising hashing, slug gen,
  confirmation dispatch) and returns the entity re-fetched by `$result->id` - the correct seam. Side-effect-only
  callers keep a bare `$this->registerUser(...)`; the 3 sites that assigned an unused `$result` drop the dead
  assignment.
- House rules: `final readonly`, strict types, phpstan max. `composer gates`.

### References

- Convention source: 35.8-35.10 records, epic Stage 2 breakdown.
- Convert: `src/Identity/Application/Command/{AdminChangeUserRole,AdminCreateAdminAccount,CreatePrivacyRightsRequest,RegisterUser}.php`
  + controllers `{AdminUserRoleController,RegisterUserController}` (the other two already dumped `['data' => $result]`).
- New: `src/Identity/Application/Command/{AdminUserView,PrivacyRightsResult,RegisteredUser}.php`.
- Tests: `tests/Functional/FunctionalTestCase.php` (helper) + ~10 fixture call sites + `AdminChangeUserRoleDiscordSyncTest`.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List

- Three records colocated in `Application/Command/`: `AdminUserView` (shared by the two admin commands),
  `PrivacyRightsResult`, `RegisteredUser` (lean {id,email,roles} - no entity leak).
- Four commands return records; `@return array{...}` docblocks dropped, `@throws` kept. Stage 1 typed throws
  untouched.
- Controllers: admin/privacy pass the record to `['data' => $result]` (json_encode = former array, byte-identical);
  register controller reads `$result->{id,email,roles}`.
- Test decoupling: `FunctionalTestCase::registerUser(): User` helper; ~10 fixture sites converted;
  `AdminChangeUserRoleDiscordSyncTest` reads `$result->role`. cs-fixer removed the now-unused `RegisterUser`/`User`
  imports from the touched test files.
- `composer gates` green: phpstan max 0 (at `src tests` scope), cs-fixer 0, ddd OK, rector OK, phpunit green
  (full isolated suite).

### File List

- `api/src/Identity/Application/Command/AdminUserView.php` (new)
- `api/src/Identity/Application/Command/PrivacyRightsResult.php` (new)
- `api/src/Identity/Application/Command/RegisteredUser.php` (new)
- `api/src/Identity/Application/Command/AdminChangeUserRole.php` (returns `AdminUserView`)
- `api/src/Identity/Application/Command/AdminCreateAdminAccount.php` (returns `AdminUserView`)
- `api/src/Identity/Application/Command/CreatePrivacyRightsRequest.php` (returns `PrivacyRightsResult`)
- `api/src/Identity/Application/Command/RegisterUser.php` (returns `RegisteredUser`, no entity leak)
- `api/src/Identity/Presentation/Controller/AdminUserRoleController.php` (reads the record)
- `api/src/Identity/Presentation/Controller/RegisterUserController.php` (reads the record)
- `api/tests/Functional/FunctionalTestCase.php` (`registerUser()` helper)
- `api/tests/Functional/{AccountDeletionTest,AccountModerationTest,AuthCleanupCommandTest,AuthLogoutTest,AuthRefreshTest,AuthSessionTest,EmailConfirmationTest,PasswordResetTest,ProfileTest,RefreshTokenRepositoryTest,YamlTemplateTest}.php` (fixture sites → helper)
- `api/tests/Unit/Identity/AdminChangeUserRoleDiscordSyncTest.php` (`$result->role`)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-18 | Story created + implemented (epic 35 Stage 2). Four Identity commands → records (`AdminUserView` shared, `PrivacyRightsResult`, `RegisteredUser`); `RegisterUser` entity leak removed; `registerUser()` test helper + ~10 fixture sites decoupled. `composer gates` green. Status: done. |
