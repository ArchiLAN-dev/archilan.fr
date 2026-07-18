# Story 35.12: Typed result records - Identity Discord routing (api/)

Status: done

## Story

As a maintainer continuing epic 35 Stage 2,
I want the two Discord OAuth commands (`LinkDiscordToAccount::link`, `HandleDiscordAuthCallback::handle`) to
return typed routing values (a string-backed enum, and a record wrapping an outcome enum + the authenticated
user id) instead of associative `outcome` arrays - and in particular `HandleDiscordAuthCallback` to stop
leaking a raw Doctrine `User` entity - so that the last array-returning Identity commands drop their `array{...}`
shapes with the OAuth redirect behaviour unchanged.

## Context

Epic 35, Stage 2, fifth story - the last Identity conversions. These two commands are the Stage 1 "legitimate
non-failure" carve-outs: their result drives a `RedirectResponse ?discord_error=...` (OAuth callback routing),
not an HTTP error, so they were never converted to typed throws. Stage 2 still types their **return**:

- `LinkDiscordToAccount::link` returned `array{outcome: 'linked'|'discord_already_used'|'no_verified_email'|
  'discord_error'}` - a **pure routing discriminant, no payload**. The controller `match`es on it.
- `HandleDiscordAuthCallback::handle` returned `array{outcome, user: User}` for the two success cases and
  `array{outcome}` for failures - i.e. a **raw Doctrine entity leak** on success. The controller only reads
  `$user->getId()`.

## Acceptance Criteria

1. **AC1 - Link enum.** Add `DiscordLinkOutcome` (string-backed: `Linked`, `DiscordAlreadyUsed`,
   `NoVerifiedEmail`, `DiscordError`), colocated in `Identity/Application/Command/`. `link()` returns it
   directly (no payload to wrap); `@return array{...}` docblock dropped.
2. **AC2 - Auth enum + record.** Add `DiscordAuthOutcome` (string-backed: `LoggedIn`, `Registered`,
   `EmailConflict`, `NoVerifiedEmail`, `DiscordError`) and `DiscordAuthResult` (`final readonly`,
   `DiscordAuthOutcome $outcome` + `?string $userId`). `handle()` returns `DiscordAuthResult`; success cases
   carry `$user->getId()` (not the entity), failures carry `null`.
3. **AC3 - Link controller.** `DiscordLinkController::linkCallback` `match`es the enum
   (`DiscordLinkOutcome::Linked` / `::DiscordAlreadyUsed` / default). Redirects byte-identical.
4. **AC4 - Auth controller.** `DiscordAuthController::callback` reads `$result->outcome` and `$result->userId`;
   `null === $userId` routes to the generic error (covers `no_verified_email` + `discord_error`), which also
   gives phpstan the non-null narrowing for the sign-in path. Uses `$userId` for the refresh token + session
   cookie (was `$user->getId()`). Redirects + cookies byte-identical.
5. **AC5 - Gates.** `composer gates` green. Discord functional/unit suites green;
   `DiscordLinkSyncDispatchTest` asserts `DiscordLinkOutcome::Linked`.

## Tasks / Subtasks

- [x] Task 1: `DiscordLinkOutcome` + `DiscordAuthOutcome` enums + `DiscordAuthResult` record (AC: 1, 2).
- [x] Task 2: convert `link()` (enum) and `handle()` (record) returns + signatures + docblocks (AC: 1, 2).
- [x] Task 3: both controllers read the typed values (AC: 3, 4).
- [x] Task 4: `DiscordLinkSyncDispatchTest` asserts the enum (AC: 5).
- [x] Task 5: verify + ship (AC: 5) - static gates + isolated full suite. PR to `develop`.

## Dev Notes

- **Enum vs record, by payload.** `link()` has only a routing discriminant, so it returns the bare
  `DiscordLinkOutcome` enum - wrapping one enum in a single-field record would be pure ceremony. `handle()`
  carries data on success (the user id), so it returns a `DiscordAuthResult` record with the outcome enum + an
  optional id. Same rule as 35.9 (`ReservationOutcome` enum inside `ReservationResult`).
- **Entity leak killed.** `handle()` no longer returns the `User`; `DiscordAuthResult` carries the `?string
  $userId`, which is all the controller needs (`refreshTokenFactory->issue($userId, ...)`,
  `authSessionSigner->sign($userId)`). AC-A3.
- **Cleaner controller guard.** Because only `LoggedIn`/`Registered` set `userId`, the auth controller's
  post-`email_conflict` check collapses to `null === $userId → generic`, replacing the two-outcome string
  comparison and narrowing `$userId` to non-null for the sign-in path in one step.
- **Colocation.** Enums + record sit in `Application/Command/` with their commands (taxonomy rule, matching
  `ReservationOutcome` in 35.9).
- **Stage 1 carve-out honoured.** These commands still route (no typed throws) - Stage 2 only types the return
  value; the OAuth redirect contract is untouched.
- House rules: `final readonly`, strict types, phpstan max. `composer gates`.

### References

- Convention source: 35.9 (`ReservationOutcome`/`ReservationResult`), 35.8-35.11, epic Stage 2 breakdown.
- Convert: `src/Identity/Application/Command/{LinkDiscordToAccount,HandleDiscordAuthCallback}.php` +
  controllers `{DiscordLinkController,DiscordAuthController}`.
- New: `src/Identity/Application/Command/{DiscordLinkOutcome,DiscordAuthOutcome,DiscordAuthResult}.php`.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List

- `DiscordLinkOutcome` (4 cases) + `DiscordAuthOutcome` (5 cases) enums + `DiscordAuthResult` (outcome + ?userId)
  record, all colocated in `Application/Command/`.
- `link()` returns `DiscordLinkOutcome` directly; `handle()` returns `DiscordAuthResult` (success carries the
  user id, not the entity - AC-A3 leak removed). `@return array{...}` docblocks dropped.
- Controllers: link controller `match`es the enum; auth controller reads `$result->outcome`/`->userId` with a
  `null === $userId` generic-error guard. Redirects + cookies byte-identical.
- `DiscordLinkSyncDispatchTest` asserts `DiscordLinkOutcome::Linked` (3 sites).
- `composer gates` green: phpstan max 0 (at `src tests` scope), cs-fixer 0, ddd OK, rector OK, phpunit green
  (full isolated suite). Note: a second `email_conflict` return (the save() catch) was caught by phpstan
  return.type at gate scope, not narrow scope - fixed before shipping.

### File List

- `api/src/Identity/Application/Command/DiscordLinkOutcome.php` (new)
- `api/src/Identity/Application/Command/DiscordAuthOutcome.php` (new)
- `api/src/Identity/Application/Command/DiscordAuthResult.php` (new)
- `api/src/Identity/Application/Command/LinkDiscordToAccount.php` (returns `DiscordLinkOutcome`)
- `api/src/Identity/Application/Command/HandleDiscordAuthCallback.php` (returns `DiscordAuthResult`, no entity leak)
- `api/src/Identity/Presentation/Controller/DiscordLinkController.php` (matches the enum)
- `api/src/Identity/Presentation/Controller/DiscordAuthController.php` (reads the record)
- `api/tests/Unit/Identity/DiscordLinkSyncDispatchTest.php` (asserts `DiscordLinkOutcome::Linked`)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-18 | Story created + implemented (epic 35 Stage 2). `DiscordLinkOutcome` enum + `DiscordAuthOutcome` enum + `DiscordAuthResult` record; `link()`/`handle()` + both controllers converted; entity leak removed. `composer gates` green. Status: done. |
