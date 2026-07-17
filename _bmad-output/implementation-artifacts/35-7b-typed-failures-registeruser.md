# Story 35.7b: Typed failures - RegisterUser (api/)

Status: done

## Story

As a maintainer continuing epic 35 Stage 1,
I want `RegisterUser::register` to throw `ValidationException` instead of returning `{user?, errors}`,
so that it adopts the 35.1 foundation, with its ~12 test callers updated in one focused PR.

## Context

Epic 35, Stage 1, story 35.7b - the RegisterUser slice 35.7 deferred. `register()` is the pervasive test
user-creation helper: ~12 functional tests call it directly and read `$result['user']`/`$result['errors']`.
It's a clean conversion in itself but the test churn warranted its own PR. No new exception type.

Mapping preserved (via `RegisterUserController`): validation errors -> 422 `validation_failed` "Le formulaire
contient des erreurs." (+ field map); success -> `{ data: { id, email, roles }, meta }` 201.

## Acceptance Criteria

1. **AC1 - Command throws.** `register()` throws `ValidationException('Le formulaire contient des erreurs.',
   <field map>)` on validation failure (and on the unique-email race); on success returns `array{user: User}`
   (the `errors` key dropped, `user` kept so success-path test callers are undisturbed).
2. **AC2 - Controller thinned.** `RegisterUserController` drops the `errors` branch and the
   `LogicException('Registration succeeded without a user.')` guard; it reads `$result['user']` and returns
   `{ data: { id, email, roles }, meta }` 201.
3. **AC3 - Test callers updated.** The ~12 direct `register()` callers drop the now-vestigial
   `assertSame([], $result['errors'])` and the redundant `$result['user'] ?? null` (all are success-path
   setup registrations). No error-path caller exists among them (registration errors are covered at the HTTP
   level in `RegisterUserTest`).
4. **AC4 - Gates.** `composer gates` green.

## Tasks / Subtasks

- [x] Task 1: `register()` throws + returns `array{user: User}`; controller thinned.
- [x] Task 2: update the ~12 direct callers (drop `['errors']` asserts + `?? null`).
- [x] Task 3: verify + ship - `composer gates` (isolated full suite). PR to `develop`.

## Dev Notes

- **`array{user: User}` (not a plain array / not `: User`)**: keeping the `user` key means the success-path
  test callers (`$result['user']`) are untouched. Returning the `User` entity *directly* (`: User`) would trip
  the no-entity-return validator (AC-A3); the array-wrapped shape is the same one the code returned before
  (minus `errors`) and passes the validator.
- **All 12 callers were success-path** (valid registration for test setup); their `assertSame([],
  $result['errors'])` was defensive and is dropped, and `$result['user'] ?? null` becomes `$result['user']`.
  Registration *error* responses are asserted at the HTTP level (`RegisterUserTest`), unaffected.
- House rules: `ValidationException` from `Shared/Application/Exception/`. `composer gates`.

### References

- Predecessor: 35.7 (deferred RegisterUser). Foundation: 35.1-35.7.
- Convert: `src/Identity/Application/Command/RegisterUser.php` +
  `src/Identity/Presentation/Controller/RegisterUserController.php`; callers under `tests/Functional/`.
- Epic: `_bmad-output/planning-artifacts/epics/epic-35-strict-cqrs-write-path.md` (Stage 1 breakdown).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List

- `RegisterUser::register` -> `array{user: User}`, throwing `ValidationException` on validation failure and
  the unique-email race. Controller dropped the errors branch + the `LogicException` guard.
- 12 direct test callers updated (AccountDeletion, AccountModeration, AuthCleanupCommand, AuthLogout,
  AuthRefresh, AuthSession, EmailConfirmation, Profile, RefreshTokenRepository, YamlTemplate): removed the
  vestigial `['errors']` assertions and the redundant `?? null`. No error-path caller among them.
- HTTP contract identical; `RegisterUserTest` (HTTP-level, incl. the 422 error path) unchanged.
- `composer gates` green: phpstan max 0, cs-fixer 0, ddd OK, rector OK, **phpunit 1543 tests / 10518
  assertions** (isolated DB; fewer assertions only because the vestigial `['errors']` checks were removed).

### File List

- `api/src/Identity/Application/Command/RegisterUser.php`
- `api/src/Identity/Presentation/Controller/RegisterUserController.php`
- `api/tests/Functional/{AccountDeletionTest,AccountModerationTest,AuthCleanupCommandTest,AuthLogoutTest,AuthRefreshTest,AuthSessionTest,EmailConfirmationTest,ProfileTest,RefreshTokenRepositoryTest,YamlTemplateTest}.php`

## Change Log

| Date | Change |
|------|--------|
| 2026-07-17 | Story created + implemented (epic 35 Stage 1, 35.7b). RegisterUser converted to throw; 12 direct test callers updated. `composer gates` green (1543 tests). Identity 4/8. Status: done. |
