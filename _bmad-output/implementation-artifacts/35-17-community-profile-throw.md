# Story 35.17: Typed failure - Community UpdateCommunityProfile (api/)

Status: done

## Story

As a maintainer continuing epic 35 Stage 2,
I want `UpdateCommunityProfile::update` to throw a typed `ValidationException` on invalid input and return `void`
instead of returning an `array{errorCode, errors}` discriminant, so that the last Community command-return array
is gone and its controller drops the outcome branching, with the 422 HTTP response unchanged.

## Context

Epic 35, split from 35.16. `UpdateCommunityProfile::update` returned `array{errorCode: string|null, errors}` -
a **validation-outcome discriminant**, not a result payload. So unlike the 35.16 record conversions, the clean
form here is the Stage-1 pattern: throw a typed `ValidationException` (already mapped centrally to a 422 by
`ApplicationFailureListener`) and return `void`. This is a Community leftover the Stage 1 breakdown had noted
would be converted "when Community is converted (a future need, not required for Stage 1)".

The command already validated everything into a `ValidationErrors` field map; it just returned it as a
discriminant instead of throwing. The controller branched on `errorCode` to call `errorResponse('validation_failed',
'Profil invalide.', 422, $errors)`.

## Acceptance Criteria

1. **AC1 - Throw + void.** `UpdateCommunityProfile::update` throws `ValidationException('Profil invalide.',
   $errorsArray)` (default code `validation_failed`, status 422) when the field map is non-empty, and returns
   `void` on success. `@return array{...}` docblock -> `@throws ValidationException`; class docblock updated
   ("throws on bad input", was "never throws").
2. **AC2 - Controller drops branching.** `CommunityProfileController::updateMyProfile` calls
   `$this->updateProfile->update(...)` (no result inspection) and returns the success payload; the
   `if (null !== $result['errorCode'])` branch is removed. The `ValidationException` is mapped to HTTP by
   `ApplicationFailureListener`.
3. **AC3 - Response byte-identical.** The 422 body (`{error: {code: 'validation_failed', message: 'Profil
   invalide.', details: <field map>}}`) is unchanged - the listener reproduces exactly what
   `ApiAccessGuard::errorResponse` emitted.
4. **AC4 - Gates.** `composer gates` green. Community profile functional suite green (the invalid-input case now
   exercises the thrown-then-mapped path).

## Tasks / Subtasks

- [x] Task 1: throw `ValidationException` + `void` return + docblocks (AC: 1).
- [x] Task 2: controller drops the `errorCode` branch (AC: 2).
- [x] Task 3: verify + ship (AC: 3, 4) - static gates + isolated full suite. PR to `develop`.

## Dev Notes

- **Discriminant, not payload -> throw, not record.** The 35.16 remainder were success payloads (id/status/…)
  that became records; this one was a *failure* discriminant, so the clean form is the Stage 1 typed throw. The
  epic breakdown split it out of 35.16 for exactly this reason.
- **Default code fits.** `ValidationException`'s constructor defaults `errorCode` to `validation_failed`, which
  is the code the controller emitted - so `new ValidationException('Profil invalide.', $errorsArray)` is a
  byte-for-byte match (message + code + details) once the listener maps it.
- **Two Application calls note.** `updateMyProfile` still calls `update()` (command) then
  `profileView->editableForUser()` (query) to render the fresh profile - a pre-existing shape, unchanged by this
  story (Stage 2 does not introduce the read).
- House rules: strict types, phpstan max. `composer gates`.

### References

- Convention source: Stage 1 typed failures (`ApplicationFailure` + `ApplicationFailureListener`); epic Stage 2
  breakdown (35.17 = Community split).
- Convert: `src/Community/Application/Command/UpdateCommunityProfile.php` +
  `src/Community/Presentation/Controller/CommunityProfileController.php`.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List

- `UpdateCommunityProfile::update` throws `ValidationException('Profil invalide.', $errorsArray)` on invalid
  input, returns `void` on success. Docblocks updated.
- `CommunityProfileController::updateMyProfile` drops the `errorCode` branch; the exception is mapped by
  `ApplicationFailureListener`. 422 body byte-identical.
- No tests read the command result (confirmed); functional suite exercises the thrown-then-mapped 422 path.
- `composer gates` green: phpstan max 0 (at `src tests` scope), cs-fixer 0, ddd OK, rector OK, phpunit green
  (full isolated suite).

### File List

- `api/src/Community/Application/Command/UpdateCommunityProfile.php` (throws + void)
- `api/src/Community/Presentation/Controller/CommunityProfileController.php` (drops the branch)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-18 | Story created + implemented (epic 35 Stage 2, split from 35.16). `UpdateCommunityProfile` throws `ValidationException` + returns void; controller drops outcome branching. `composer gates` green. Status: done. |
