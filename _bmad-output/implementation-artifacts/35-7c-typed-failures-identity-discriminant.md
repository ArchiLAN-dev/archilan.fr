# Story 35.7c: Typed failures - Identity discriminant (slug + steam) (api/)

Status: done

## Story

As a maintainer finishing epic 35 Stage 1,
I want the two genuine failure-discriminant Identity commands (`ChangeUserSlug`, `SaveSteamAccount`) to throw
typed failures, while leaving the OAuth-callback commands as routing discriminants,
so that Stage 1 covers every command that maps a failure to an HTTP error code.

## Context

Epic 35, Stage 1, final story - the Identity discriminant slice. Of the 4 discriminant Identity commands 35.7
deferred, **only 2 are HTTP-failure discriminants**:

- `ChangeUserSlug::change` - every non-`ok` outcome is a 422 the controller builds from a code->message map.
- `SaveSteamAccount::save` - `invalid_input` -> 422, `not_found` -> (unreachable, user is authenticated).

The other 2 are **OAuth callback commands whose outcome drives a `RedirectResponse`**, not an HTTP error:
`LinkDiscordToAccount` and `HandleDiscordAuthCallback` map their outcome to `?discord_error=...` redirects.
That is routing, not a typed failure, so they are **out of Stage 1 scope** and stay as-is (documented in the
epic).

Mappings preserved:

| Command | mapping |
|---|---|
| `ChangeUserSlug::change` | every error outcome -> 422 with `code` = the slug error and `message` from the (former controller) map; `slug_cooldown` also carries `details: { nextAllowedAt: [...] }`; success -> `{ slug }` |
| `SaveSteamAccount::save` | `not_found` -> 404 `not_found`; `invalid_input` -> 422 `steam_invalid_input` "Profil Steam non reconnu."; `saved` -> void |

## Acceptance Criteria

1. **AC1 - Slug.** `ChangeUserSlug::change` throws `ValidationException` for every error (via a private
   `failSlug(code, details): never` carrying the code->message match moved out of `ProfileController`), and
   returns `{ slug }` on success. `slug_cooldown` passes `['nextAllowedAt' => [...]]` as details.
   `ProfileController` drops the error branch **and** its `slugErrorMessage()` helper, returning `{ data: {
   slug } }`.
2. **AC2 - Steam.** `SaveSteamAccount::save` throws `NotFoundException` (`not_found`, unreachable) /
   `ValidationException` (`invalid_input`), returns void on success. `SteamAccountController` drops the
   `invalid_input` branch (keeps its own empty-input request validation).
3. **AC3 - Discord out of scope.** `LinkDiscordToAccount` + `HandleDiscordAuthCallback` are left unchanged
   (their outcome is OAuth redirect routing). Recorded in the epic.
4. **AC4 - Contract unchanged.** Statuses/codes/messages/bodies identical. `SaveSteamAccountTest` (the only
   unit test asserting the old shape) updated to expect the thrown exceptions; slug is covered at the HTTP
   level (unchanged).
5. **AC5 - Gates.** `composer gates` green. **Stage 1 is complete.**

## Tasks / Subtasks

- [x] Task 1: `ChangeUserSlug` throws via `failSlug()`; `ProfileController` thinned (+ removed `slugErrorMessage`).
- [x] Task 2: `SaveSteamAccount` throws NotFound/Validation + void; `SteamAccountController` thinned.
- [x] Task 3: leave the 2 Discord OAuth-callback commands unchanged; document in the epic.
- [x] Task 4: update `SaveSteamAccountTest` (expect the exceptions).
- [x] Task 5: verify + ship - `composer gates` (isolated full suite). PR to `develop`.

## Dev Notes

- **`failSlug(code, details): never`** carries the exact `slug_taken`/`slug_reserved`/`slug_reserved_word`/
  `slug_cooldown`/`slug_unchanged`/default message map that lived in `ProfileController::slugErrorMessage`.
  `not_found` and `slug_invalid` both use the default message (as before - the controller mapped everything
  non-`ok` to 422). The `: never` return lets phpstan narrow after each call.
- **Steam `not_found` -> `NotFoundException`**: the old controller let `not_found` fall through to a 200
  success (unreachable, since the route requires an authenticated user). Throwing 404 is the correct semantic
  and never fires in practice.
- **Why the Discord callbacks stay**: their controllers return `new RedirectResponse('.../compte/securite?discord_link_error=...')`
  chosen by a `match($result['outcome'])`. The outcome is a routing signal, not a failure mapped to a status
  code, so Stage 1 (typed *failures*) does not apply. Converting would be wrong.
- House rules: exceptions in `Shared/Application/Exception/`. `composer gates`.

### References

- Predecessor: 35.7, 35.7b. Foundation: 35.1-35.7b. Convert:
  `src/Identity/Application/Command/{ChangeUserSlug,SaveSteamAccount}.php` +
  `src/Identity/Presentation/Controller/{ProfileController,SteamAccountController}.php`.
- Left as-is: `src/Identity/Application/Command/{LinkDiscordToAccount,HandleDiscordAuthCallback}.php`.
- Epic: `_bmad-output/planning-artifacts/epics/epic-35-strict-cqrs-write-path.md` (Stage 1 done).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List

- `ChangeUserSlug::change` -> `{ slug }` on success, throwing `ValidationException` for all 7 slug error codes
  via `failSlug()` (the code->message map moved out of the controller); `slug_cooldown` keeps its
  `nextAllowedAt` detail. `ProfileController` dropped the error branch + `slugErrorMessage()`.
- `SaveSteamAccount::save` -> void, throwing `NotFoundException` (unreachable `not_found`) / `ValidationException`
  (`steam_invalid_input`). `SteamAccountController` dropped the `invalid_input` branch.
- `LinkDiscordToAccount` + `HandleDiscordAuthCallback` left unchanged - their outcome drives an OAuth
  `RedirectResponse`, not an HTTP error; documented in the epic as out of Stage 1 scope.
- `SaveSteamAccountTest` updated: the 3 methods expect the thrown exceptions (saved -> void + profile assert).
- `composer gates` green: phpstan max 0, cs-fixer 0, ddd OK, rector OK, **phpunit 1543 tests / 10517
  assertions** (isolated DB). **Stage 1 complete.**

### File List

- `api/src/Identity/Application/Command/ChangeUserSlug.php`
- `api/src/Identity/Application/Command/SaveSteamAccount.php`
- `api/src/Identity/Presentation/Controller/ProfileController.php` (dropped `slugErrorMessage`)
- `api/src/Identity/Presentation/Controller/SteamAccountController.php`
- `api/tests/Unit/Identity/SaveSteamAccountTest.php`
- `_bmad-output/planning-artifacts/epics/epic-35-strict-cqrs-write-path.md` (Stage 1 done)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-17 | Story created + implemented (epic 35 Stage 1, 35.7c - final). ChangeUserSlug + SaveSteamAccount converted; the 2 Discord OAuth-callback commands left as routing discriminants (out of scope). `composer gates` green (1543 tests). **Stage 1 complete.** Status: done. |
