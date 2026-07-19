# Story 35.10: Typed result record - Identity slug ack (api/)

Status: done

## Story

As a maintainer continuing epic 35 Stage 2,
I want `ChangeUserSlug::change` to return a `final readonly` result record (`SlugChangeResult`) instead of an
`array{slug: string}`, so that the self-service slug write path drops its `array{...}` ack shape with the HTTP
contract unchanged.

## Context

Epic 35, Stage 2, third story (first Identity conversion). `ChangeUserSlug::change` returns
`array{slug: string}` - a one-field acknowledgement the controller echoes into `data.slug`. All slug failures
already throw a typed `ValidationException` (Stage 1); the only success path returns the new slug string.

Identity has six other array-returning commands, scoped to later stories, not this one:

- `AdminChangeUserRole`, `AdminCreateAdminAccount`, `CreatePrivacyRightsRequest`, `RegisterUser` - read-payload
  records (**35.11**).
- `LinkDiscordToAccount`, `HandleDiscordAuthCallback` - record + routing enum (**35.12**).

This story is deliberately the smallest Identity slice (single one-field ack) to keep the Identity roll-out
incremental, mirroring the 35.8 pilot discipline.

## Acceptance Criteria

1. **AC1 - Record.** Add `SlugChangeResult` (`final readonly`, single `string $slug`), colocated in
   `Identity/Application/Command/`.
2. **AC2 - change() converted.** `ChangeUserSlug::change` returns `SlugChangeResult`; the single success return
   builds the record; `@return array{slug: string}` docblock dropped, `@throws ValidationException` kept.
   Failure throws unchanged (Stage 1). `sanitize()` (static, unrelated) untouched.
3. **AC3 - Controller reads the record.** `ProfileController::updateSlug` reads `$result->slug` (was
   `$result['slug']`). The response body (`data.slug`, same key) is byte-identical.
4. **AC4 - Gates.** `composer gates` green. Profile/slug functional + unit suites green unchanged.

## Tasks / Subtasks

- [x] Task 1: `SlugChangeResult` record (AC: 1).
- [x] Task 2: convert `change()` return + signature + docblock (AC: 2).
- [x] Task 3: `ProfileController::updateSlug` reads the record (AC: 3).
- [x] Task 4: verify + ship (AC: 4) - static gates + isolated full suite. PR to `develop`.

## Dev Notes

- **One-field ack, still a record.** Even a single-field return becomes a named record under the Stage 2 rule
  ("command returns void or a `final readonly` record, never an array") - no enum needed here (no discriminant,
  just the confirmed value).
- **Colocation.** `SlugChangeResult` sits in `Application/Command/` with `ChangeUserSlug` (taxonomy rule); the
  command references it in the same namespace (no import). The controller reads `->slug` (no import needed - the
  type is inferred, no named reference).
- **No test edits.** `ChangeUserSlugTest` (unit) only exercises the static `sanitize()`; `ProfileSlugTest`
  (functional) asserts the JSON body `data.slug` (unchanged). No test reads the command result shape.
- House rules: `final readonly`, strict types, phpstan max. `composer gates`.

### References

- Convention source: 35.8 (`RunLifecycleResult`), 35.9 (`ReservationResult`), epic Stage 2 breakdown.
- Convert: `src/Identity/Application/Command/ChangeUserSlug.php` +
  `src/Identity/Presentation/Controller/ProfileController.php`.
- New: `src/Identity/Application/Command/SlugChangeResult.php`.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List

- `SlugChangeResult` (`final readonly`, single `string $slug`) colocated in `Application/Command/`.
- `change()` returns `SlugChangeResult`; `@return array{slug: string}` docblock dropped, `@throws` kept. Stage 1
  typed throws untouched. Static `sanitize()` untouched.
- `ProfileController::updateSlug` reads `$result->slug`. Response body byte-identical.
- Contract unchanged: slug functional + unit suites green unchanged; full suite green (isolated).
- `composer gates` green: phpstan max 0 (at `src tests` scope), cs-fixer 0, ddd OK, rector OK, phpunit green.

### File List

- `api/src/Identity/Application/Command/SlugChangeResult.php` (new)
- `api/src/Identity/Application/Command/ChangeUserSlug.php` (returns `SlugChangeResult`)
- `api/src/Identity/Presentation/Controller/ProfileController.php` (reads the record)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-18 | Story created + implemented (epic 35 Stage 2). `SlugChangeResult` record; `change()` + controller converted. `composer gates` green. Status: done. |
