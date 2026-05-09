# Story 13.2: Dual-Cookie Token Issuance on Authentication

**Status:** review
**Epic:** 13 - Secure Token Lifecycle - Refresh Token
**Date:** 2026-05-05

---

## Story

As a registered user,
I want login to issue both a short-lived access token and a long-lived refresh token,
so that my session stays active without re-entering credentials frequently.

---

## Acceptance Criteria

1. On successful `POST /api/v1/auth/login`, two httpOnly Secure SameSite=Lax cookies are set: the existing access token cookie (`__Host-archilan_session`, TTL 15 minutes, `Path=/`) and a new opaque refresh token cookie (`__Secure-archilan_refresh`, TTL 30 days, `Path=/api/v1/auth/refresh`).
2. The access token TTL is reduced from 7 days to 15 minutes in `AuthSessionSigner`.
3. The raw refresh token is hashed (SHA-256) before being persisted in `identity_refresh_tokens` via `RefreshTokenFactory` and `RefreshTokenRepository`.
4. No token value is returned in the response body.
5. Invalid credential and error behaviour is unchanged.
6. All existing auth tests continue to pass with updated schema setup (including `RefreshToken` entity in `SchemaTool`).

---

## Tasks / Subtasks

- [x] Reduce access token TTL to 15 min (AC: 2)
  - [x] Added `AuthSessionSigner::ACCESS_TOKEN_TTL = 900`
  - [x] Replaced `7 * 24 * 60 * 60` with `self::ACCESS_TOKEN_TTL`

- [x] Extend `AuthController` to issue refresh cookie on login (AC: 1, 3, 4)
  - [x] Added `REFRESH_COOKIE_NAME = '__Secure-archilan_refresh'` and `REFRESH_COOKIE_PATH = '/api/v1/auth/refresh'`
  - [x] Injected `RefreshTokenFactory` and `RefreshTokenRepository`
  - [x] `login()` calls `RefreshTokenFactory::issue()`, persists token, sets both cookies
  - [x] `refreshCookie()` uses `withExpires()` (no `withMaxAge()` in this Symfony version)
  - [x] Response body unchanged (user data only)

- [x] Update `AuthSessionTest` (AC: 6)
  - [x] Schema setup includes `RefreshToken::class` (dropped in reverse FK order)
  - [x] `testLoginSetsTwoCookiesWithoutReturningToken`: asserts exactly 2 cookies, both with correct attributes
  - [x] `testAccessTokenCookieHas15MinuteTTL`: asserts `getMaxAge()` between 840s and 960s

- [x] Run quality checks (AC: 6)
  - [x] PHPStan: 0 errors
  - [x] CS Fixer: clean
  - [x] Full suite: 384 tests, 3565 assertions, 0 regressions

---

## Dev Notes

### Current Access Token Architecture

`AuthSessionSigner::sign(string $userId)` builds a HMAC-SHA256 signed opaque cookie payload with a 7-day TTL. It must be shortened to 15 minutes (900 s). No other changes to `AuthSessionSigner`; `verify()` is unchanged.

### Refresh Cookie Constraints

- Cookie prefix `__Host-` requires `Path=/`. Since the refresh cookie uses `Path=/api/v1/auth/refresh`, it must use `__Secure-` prefix instead (only enforces `Secure=true`, no domain restriction).
- The `MaxAge` of the refresh cookie should equal `RefreshTokenFactory::TOKEN_TTL_DAYS * 86400` seconds (2592000 s) so cookie and DB record expire at the same time.
- Browser only sends the refresh cookie when the request URL starts with `/api/v1/auth/refresh` - this limits exposure to only the refresh endpoint.

### `AuthController` Dependency Injection

The controller is `final readonly`. Adding two new dependencies requires adding them to the constructor. Symfony autowires them automatically - no `services.yaml` changes needed.

```php
public function __construct(
    private AuthenticateUser $authenticateUser,
    private AuthSessionSigner $authSessionSigner,
    private CurrentUserProvider $currentUserProvider,
    private RefreshTokenFactory $refreshTokenFactory,
    private RefreshTokenRepository $refreshTokenRepository,
) {}
```

### User-Agent Propagation

Pass `$request->headers->get('User-Agent')` as the `$userAgent` argument to `RefreshTokenFactory::issue()` so the DB record captures the client browser.

### Test Schema Setup

Existing `AuthSessionTest::setUp()` uses `SchemaTool` with only `User::class`. Since `RefreshToken` has an FK to `identity_users`, the schema must be created in order: first `User`, then `RefreshToken`:

```php
$metadata = [
    $this->entityManager->getClassMetadata(User::class),
    $this->entityManager->getClassMetadata(RefreshToken::class),
];
$schemaTool->dropSchema(array_reverse($metadata));
$schemaTool->createSchema($metadata);
```

Drop order must be reversed (children before parents) to avoid FK violations.

### References

- `api/src/Identity/Application/AuthSessionSigner.php` - access token signer (modify TTL only)
- `api/src/Identity/Presentation/AuthController.php` - extend login
- `api/src/Identity/Domain/RefreshToken.php` - entity from 13.1
- `api/src/Identity/Application/RefreshTokenFactory.php` - factory from 13.1
- `api/src/Identity/Application/RefreshTokenRepository.php` - repository from 13.1
- `api/tests/Functional/AuthSessionTest.php` - update schema and add cookie assertions
- `_bmad-output/planning-artifacts/epics.md#Story-13.2`

---

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- `Cookie::withMaxAge()` doesn't exist in this Symfony version - used `withExpires(time() + TTL)` instead. `getMaxAge()` does exist and returns `max(0, expires - time())`.
- PHPStan: `assertNotNull()` on `getMaxAge()` (return type `int`) is always true - removed redundant assertion.
- CS Fixer: `use` imports were not in alphabetical order - `AuthenticateUser` must come before `AuthSessionSigner`.

### Completion Notes List

- `AuthSessionSigner::ACCESS_TOKEN_TTL = 900` (15 min). Access cookie has `withExpires(time() + 900)`.
- `AuthController::REFRESH_COOKIE_NAME = '__Secure-archilan_refresh'`, `REFRESH_COOKIE_PATH = '/api/v1/auth/refresh'`. Uses `__Secure-` prefix (not `__Host-`) because `Path` is not `/`.
- Login now persists a `RefreshToken` entity and issues both cookies. User-Agent from request is captured.
- `AuthSessionTest` now creates both `User` and `RefreshToken` schema tables (FK order respected on drop).
- 2 new assertions: dual-cookie presence/attributes + 15-min TTL range check.

### Validation Results

- `php bin/phpunit tests/Functional/AuthSessionTest.php`: 7 tests, 64 assertions - PASS
- `php bin/phpunit` (full suite): 384 tests, 3565 assertions - PASS
- `php vendor/bin/phpstan analyse src tests`: 0 errors - PASS
- CS Fixer: no diffs on modified files - PASS

### File List

- `api/src/Identity/Application/AuthSessionSigner.php`
- `api/src/Identity/Presentation/AuthController.php`
- `api/tests/Functional/AuthSessionTest.php`
- `_bmad-output/implementation-artifacts/13-2-dual-cookie-token-issuance.md`

### Change Log

- 2026-05-05: Reduced access token TTL to 15 min, added refresh token cookie issuance on login, updated auth tests.
