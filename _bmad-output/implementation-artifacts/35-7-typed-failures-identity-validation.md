# Story 35.7: Typed failures - Identity validation-shaped commands (api/)

Status: done

## Story

As a maintainer continuing epic 35 Stage 1,
I want the three validation-shaped Identity commands (admin role change, admin account creation, GDPR request)
to throw `ValidationException` instead of returning `{X?, errors}` arrays,
so that they adopt the 35.1 foundation and their controllers get thinner, HTTP unchanged.

## Context

Epic 35, Stage 1, story 35.7 - first slice of the Identity context (8 outcome-array commands). This story
converts the **3 uniform `{X?, errors}` validation-shaped** commands; the other 5 are deferred (see Scope).
No new exception type.

All three share the same shape: every failure returns `['errors' => <field map>]`; success returns
`['X' => <payload>, 'errors' => []]`; the controller maps `errors` to a 422 `validation_failed` with a
per-command message, and echoes the payload on success (with a defensive 500 for the impossible
errors-empty-but-no-payload state).

| Command | success payload | 422 message |
|---|---|---|
| `AdminChangeUserRole::change` | user payload | Le changement de rôle est invalide. |
| `AdminCreateAdminAccount::create` | user payload (201) | Le formulaire contient des erreurs. |
| `CreatePrivacyRightsRequest::create` | request payload (201) | La demande RGPD contient des erreurs. |

## Acceptance Criteria

1. **AC1 - Commands throw.** Each command's error returns become `throw new ValidationException(<message>,
   <field map>)`; on success each returns its payload array directly (no more `{X?, errors}` wrapper).
2. **AC2 - Controllers thinned.** `AdminUserRoleController`, `AdminCreateAdminAccountController`,
   `PrivacyRightsRequestController` drop the `errors` branch **and** the defensive 500, and return
   `{ data: <payload>, ... }` from the command's return value.
3. **AC3 - Contract unchanged.** Statuses/codes/messages/bodies identical. The `AdminChangeUserRoleDiscordSyncTest`
   unit test (the only one asserting the old shape) updated to the payload return; all other tests unchanged.
4. **AC4 - Gates.** `composer gates` green.

## Tasks / Subtasks

- [x] Task 1: convert the 3 commands (error returns -> `throw ValidationException`; success -> payload array).
- [x] Task 2: thin the 3 controllers (drop errors branch + defensive 500; return the payload).
- [x] Task 3: update `AdminChangeUserRoleDiscordSyncTest` (payload return; no `errors` key).
- [x] Task 4: verify + ship - `composer gates` (static + isolated Identity functional). PR to `develop`.

## Dev Notes

### Scope - 5 Identity commands deferred

- **`RegisterUser` (35.7b)**: `register()` is the pervasive test user-creation helper returning
  `{user: User}`; converting it ripples into ~12 test files that read `$result['user']`/`$result['errors']`.
  It's a clean conversion in itself but the test churn warrants its own PR.
- **`ChangeUserSlug`, `LinkDiscordToAccount`, `HandleDiscordAuthCallback`, `SaveSteamAccount` (35.7c)**: each
  has a per-command nuance - slug rate-limit (`nextAllowedAt` detail) + code->message mapping done
  controller-side; discord link's `discord_error` (a 502); the auth callback is a **dual-success**
  (`logged_in`/`registered`); steam's `not_found`. These need individual care, so they land together in 35.7c.

### The defensive 500 disappears (safely)

Each controller had `if (null === $payload) return 500 <failed>` after the errors check - an impossible state
(the command returns the payload whenever errors is empty). With the command returning the payload directly on
success and throwing otherwise, that branch is unreachable and dropped. No behaviour change (it never fired).

### AdminChangeUserRole's phpstan-narrowing guard

`change()` keeps its `if (!$target instanceof User || null === $normalizedRole)` guard (now `throw` instead of
`return`) purely for phpstan narrowing; it is unreachable (any null already threw above).

- House rules: `ValidationException` from `Shared/Application/Exception/`. phpstan max, Yoda, strict types.
  `composer gates`; isolated phpunit for the Identity tests.

### References

- Foundation: 35.1-35.6b. Convert:
  `src/Identity/Application/Command/{AdminChangeUserRole,AdminCreateAdminAccount,CreatePrivacyRightsRequest}.php`
  + `src/Identity/Presentation/Controller/{AdminUserRoleController,AdminCreateAdminAccountController,PrivacyRightsRequestController}.php`.
- Epic: `_bmad-output/planning-artifacts/epics/epic-35-strict-cqrs-write-path.md` (Stage 1 breakdown).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List

- The 3 validation-shaped commands now throw `ValidationException(<per-command message>, <field map>)` on
  every failure and return their payload array on success (no `{X?, errors}` wrapper). No new exception type.
- The 3 controllers dropped the `errors` branch + the defensive 500, and return `{ data: <payload>, ... }`
  straight from the command result.
- `AdminChangeUserRoleDiscordSyncTest` updated: the success assertions read the payload directly (no `errors`
  key, `$result` is the payload). All other tests unchanged.
- Deferred `RegisterUser` (35.7b, ~12 test files ripple) and the 4 discriminant commands (35.7c, per-command
  nuances) - epic breakdown updated.
- `composer gates` green: phpstan max 0, cs-fixer 0, ddd OK, rector OK, **phpunit 1543 tests / 10535
  assertions** (isolated DB; one transient shared-schema flake in an unrelated Realtime test confirmed by a
  clean re-run).

### File List

- `api/src/Identity/Application/Command/AdminChangeUserRole.php`
- `api/src/Identity/Application/Command/AdminCreateAdminAccount.php`
- `api/src/Identity/Application/Command/CreatePrivacyRightsRequest.php`
- `api/src/Identity/Presentation/Controller/AdminUserRoleController.php`
- `api/src/Identity/Presentation/Controller/AdminCreateAdminAccountController.php`
- `api/src/Identity/Presentation/Controller/PrivacyRightsRequestController.php`
- `api/tests/Unit/Identity/AdminChangeUserRoleDiscordSyncTest.php`

## Change Log

| Date | Change |
|------|--------|
| 2026-07-17 | Story created + implemented (epic 35 Stage 1, 35.7). Identity: the 3 validation-shaped admin/privacy commands converted; RegisterUser (35.7b) + 4 discriminant (35.7c) deferred. `composer gates` green (1543 tests). Status: done. |
