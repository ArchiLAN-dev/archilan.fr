# Story 35.1: Typed failures - foundation + Content (api/)

Status: done

## Story

As a maintainer of the API write path,
I want a shared typed-failure hierarchy plus a central kernel listener that maps them to HTTP responses,
proven by converting the Content context off its outcome-array return,
so that command services signal failure by throwing a typed exception (not an `['outcome' => ...]` discriminant),
controllers stay thin, and every context can adopt the same pattern in later stories.

## Context

Epic 35, Stage 1 (typed failures), first story. Today ~60 public methods across the 72
`Application/Command/` classes return outcome arrays (`['outcome' => 'not_found', 'errors' => [...],
'data' => ...]`), and **each controller branches on the discriminant** to build a
`{ error: { code, message } }` response (23 such envelopes). There is **no central exception listener** -
the 3 existing ad-hoc Application exceptions (`CannotKudosOwnContentException`, `GithubRateLimitException`,
`SteamApiException`) are caught per-controller with `try/catch`.

This story builds the shared foundation once and converts the smallest context (Content: a single command,
`UploadPostCoverImageCommand`) as the first proof. Subsequent stories convert the other contexts using the
same foundation (see the epic's Stage 1 breakdown).

The exact Content mapping to preserve (from `AdminPostCoverImageController`):

| Current outcome | HTTP | code | message |
|---|---|---|---|
| `not_found` | 404 | `not_found` | Article introuvable. |
| `storage_error` | 503 | `storage_unavailable` | Le stockage est indisponible. |
| `ok` | 200 | - | `{ data: <admin post payload> }` |

## Acceptance Criteria

1. **AC1 - Typed-failure foundation.** `Shared\Application\Exception`: an `ApplicationFailure` interface
   (`statusCode(): int`, `errorCode(): string`, `clientMessage(): string`, `details(): array`), a shared
   `ApplicationFailureTrait` (getters), and concrete **final** exceptions `NotFoundException` (404),
   `ValidationException` (422, carries a field-map in `details`), `ConflictException` (409),
   `ServiceUnavailableException` (503), each `extends \RuntimeException implements ApplicationFailure`
   using the trait. Each carries a client message and an overridable error code. **A trait, not an abstract
   base**: the DDD validator (AC-A1) requires Application classes to be final, and traits pass untouched.
2. **AC2 - Central listener.** `Shared\Infrastructure\Http\ApplicationFailureListener` (a
   `kernel.exception` listener) maps any thrown `ApplicationFailure` to
   `new JsonResponse(['error' => ['code', 'message', ...('details' when non-empty)]], statusCode)`.
   Non-`ApplicationFailure` throwables are left to Symfony's default handling. It runs before the framework
   ErrorListener so it wins.
3. **AC3 - Content converted.** `UploadPostCoverImageCommand::execute` **throws** `NotFoundException`
   (post missing) and `ServiceUnavailableException('...', 'storage_unavailable')` (upload failure) instead
   of returning an outcome array; on success it returns the admin post payload. `AdminPostCoverImageController`
   drops the outcome branching - it calls `execute(...)` and returns `{ data: <payload> }` (AC-P3/P4:
   thinner controller). The HTTP contract (statuses, codes, messages, body) is **unchanged**.
4. **AC4 - Tests.** Unit tests for the listener (each exception -> correct status + envelope; a non-mappable
   throwable is ignored) and for the exception hierarchy (status/code/details). The existing
   `AdminPostCoverImageTest` (functional) stays green unchanged - proof the HTTP contract is preserved.
5. **AC5 - Gates.** `composer gates` green (phpstan max, cs-fixer, ddd, rector, phpunit). No behaviour change
   outside Content; the DDD validator stays green (exceptions in `Application/Exception/`, listener in
   `Infrastructure/Http/`).

## Tasks / Subtasks

- [x] Task 1: exception foundation (AC: 1) - `Shared/Application/Exception/`:
  - [x] `ApplicationFailure` (interface), `ApplicationException` (abstract base, message + errorCode +
        details), `NotFoundException`, `ValidationException`, `ConflictException`, `ServiceUnavailableException`.
- [x] Task 2: kernel listener (AC: 2) - `Shared/Infrastructure/Http/ApplicationFailureListener` with
      `#[AsEventListener(event: KernelEvents::EXCEPTION)]`, autoconfigured. Builds the envelope; ignores
      non-`ApplicationFailure`.
- [x] Task 3: convert Content (AC: 3) - `UploadPostCoverImageCommand` throws; `AdminPostCoverImageController`
      simplified. Update the command's return docblock/type (payload, not `array{outcome,data}`).
- [x] Task 4: tests (AC: 4) - `tests/Unit/Shared/ApplicationFailureListenerTest.php` +
      `tests/Unit/Shared/ApplicationExceptionTest.php`. Confirm `AdminPostCoverImageTest` unchanged + green.
- [x] Task 5: verify + ship (AC: 5) - `composer gates` (static locally + isolated phpunit for the affected
      functional test). Branch `feature/epic-35-story-1-typed-failures` from `develop`, PR to `develop`.

## Dev Notes

### Placement (DDD taxonomy)

- Exceptions -> `Shared/Application/Exception/` (the `*Exception` validator rule already forces this; the
  non-suffixed `ApplicationFailure` interface sits alongside, which the validator permits).
- Listener -> `Shared/Infrastructure/Http/` (framework glue that builds an HTTP response; `Http/` already
  holds `ApiAccessGuard`). Not a controller, so not Presentation.
- The listener is registered by autoconfiguration (`services.yaml` `_defaults.autoconfigure: true`) via the
  `#[AsEventListener]` attribute - no manual service tag. Symfony 7 supports the attribute.

### Listener ordering

`kernel.exception` runs higher-priority listeners first; Symfony's framework `ErrorListener` is at -128, so
the default priority (0) makes ours win - it sets the response and the framework listener then sees a
response already set. In the functional test, `WebTestCase` catches exceptions by default, so the kernel
runs our listener and the client sees the mapped 404/503 (not a raw exception).

### Exception shape

```php
interface ApplicationFailure {
    public function statusCode(): int;
    public function errorCode(): string;
    public function clientMessage(): string;
    /** @return array<string, mixed> */
    public function details(): array;
}
trait ApplicationFailureTrait { /* errorCode(), clientMessage(), details() over private props */ }
final class NotFoundException extends \RuntimeException implements ApplicationFailure {
    use ApplicationFailureTrait; /* ctor sets code/message; statusCode() = 404 */
}
```

`ValidationException` carries a `details` field-map (`['field' => ['msg', ...]]`) mapped into
`error.details` (the shape existing validation controllers already emit, e.g. `error.details.coverImageUrl`).
Content does not use `ValidationException` yet, but it is defined + unit-tested so later contexts have it.

### Content conversion - preserve the exact contract

`UploadPostCoverImageCommand::execute` becomes: post missing -> `throw new NotFoundException('Article
introuvable.')`; `upload(...)` throws -> `throw new ServiceUnavailableException('Le stockage est
indisponible.', 'storage_unavailable')`; success -> `return $this->adminPostCatalog->get($postId)`.
Controller drops the two `if ('...' === $result['outcome'])` blocks. Same statuses/codes/messages, so
`AdminPostCoverImageTest` needs no change (that is the regression proof).

### Scope discipline

- **Content only.** Do NOT convert the other 3 existing ad-hoc exceptions or any other context - they land
  in their own Stage 1 stories (35.2+). The foundation is shared; the conversions are incremental.
- No Stage 2 (typed result records) here - the command still returns an array payload on success; only the
  *failure* path becomes typed.
- No new validator rule yet (that is a later Stage-1-complete / Stage-2 change).

### House rules (api/CLAUDE.md)

- AC-A2 (no `EntityManager`/`Connection` in Application - unchanged), AC-P3/P4 (thinner controller - better),
  AC-A3 (no entity returned - the payload is an array, fine). phpstan level max: type the `details` arrays
  (`array<string, mixed>` / `array<string, list<string>>`), Yoda comparisons, `declare(strict_types=1)`.
- `composer gates`; run the affected functional test isolated (`scripts/test-isolated.sh`) - full phpunit
  local is flaky (shared DB), CI is authoritative.

### References

- Epic: `_bmad-output/planning-artifacts/epics/epic-35-strict-cqrs-write-path.md` (Stage 1 breakdown).
- Convert: `src/Content/Application/Command/UploadPostCoverImageCommand.php`,
  `src/Content/Presentation/Controller/AdminPostCoverImageController.php`.
- Existing exceptions (pattern, not converted here): `src/Community/Application/Exception/CannotKudosOwnContentException.php`.
- Regression proof: `tests/Functional/AdminPostCoverImageTest.php`.
- Standards: `api/CLAUDE.md` (DDD layers, gates); root `CLAUDE.md` (Gitflow, no em-dashes).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List

- Foundation in `Shared/Application/Exception/`: `ApplicationFailure` interface + `ApplicationFailureTrait`
  + 4 final exceptions (`NotFound` 404, `Validation` 422, `Conflict` 409, `ServiceUnavailable` 503).
  **Design pivot**: the DDD validator (AC-A1) rejects an abstract Application base ("must be final"), so
  the shared logic is a **trait** (validator passes traits untouched), not an abstract base.
- `Shared/Infrastructure/Http/ApplicationFailureListener` (`#[AsEventListener(KernelEvents::EXCEPTION)]`,
  autoconfigured) maps any `ApplicationFailure` to `{ error: { code, message, details? } }`; ignores other
  throwables (framework default handling wins for them).
- Content converted: `UploadPostCoverImageCommand` throws `NotFoundException` / `ServiceUnavailableException`
  (code `storage_unavailable`) instead of an outcome array; returns the payload on success.
  `AdminPostCoverImageController` dropped both outcome branches (thinner - AC-P3/P4). HTTP contract identical.
- Rector applied `NewMethodCallWithoutParenthesesRector` (PHP 8.4 `new X()->m()`) to the exception test.
- `composer gates` green: phpstan max 0, cs-fixer 0, `app:architecture:ddd` OK, rector OK, **phpunit 1541
  tests / 10535 assertions** (isolated DB), including the unchanged `AdminPostCoverImageTest` (contract
  regression proof) + 8 new unit tests. The listener is global but scoped to `ApplicationFailure`, so the
  full suite is unaffected outside Content.

### File List

- `api/src/Shared/Application/Exception/ApplicationFailure.php` (new, interface)
- `api/src/Shared/Application/Exception/ApplicationFailureTrait.php` (new)
- `api/src/Shared/Application/Exception/{NotFoundException,ValidationException,ConflictException,ServiceUnavailableException}.php` (new)
- `api/src/Shared/Infrastructure/Http/ApplicationFailureListener.php` (new)
- `api/src/Content/Application/Command/UploadPostCoverImageCommand.php` (throws instead of outcome array)
- `api/src/Content/Presentation/Controller/AdminPostCoverImageController.php` (dropped outcome branching)
- `api/tests/Unit/Shared/ApplicationExceptionTest.php`, `api/tests/Unit/Shared/ApplicationFailureListenerTest.php` (new)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-16 | Story created (epic 35 Stage 1, first story). Foundation (typed-failure hierarchy + kernel listener) + Content conversion as the first proof. Grounded in the current outcome-array/per-controller-catch pattern, the exact Content HTTP contract, and DDD placement. Status: ready-for-dev. |
| 2026-07-16 | Implemented: interface + trait + 4 final exceptions + kernel listener; Content converted; 8 unit tests. Design pivot to a trait (validator rejects abstract Application base). `composer gates` green (1541 tests). Status: done. |
