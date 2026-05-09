# Story 13.5: Logout with Server-Side Token Revocation

**Status:** review
**Epic:** 13 - Secure Token Lifecycle - Refresh Token
**Date:** 2026-05-05

---

## Story

As an authenticated user,
I want logout to invalidate my refresh token server-side,
So that stolen cookies cannot be used to obtain new tokens after I sign out.

---

## Acceptance Criteria

1. `POST /auth/logout` with a valid refresh token cookie: the token is revoked in the database (`revoked_at` set).
2. Both `access_token` and `refresh_token` cookies are cleared (Set-Cookie with `Max-Age=0`).
3. The response is 204 regardless of whether the token was found (idempotent).
4. Subsequent calls to `/auth/refresh` with the old cookie return 401.

---

## Tasks / Subtasks

- [x] Modify `AuthController::logout()` (AC: 1, 2, 3)
  - [x] Accept `Request $request` parameter
  - [x] Read `REFRESH_COOKIE_NAME` cookie, hash it, find token via `RefreshTokenRepository::findByTokenHash()`
  - [x] Call `$token->revoke(new \DateTimeImmutable())` + `$refreshTokenRepository->flush()` if token found
  - [x] Return `new JsonResponse(null, 204)` with both cookies cleared

- [x] Write functional tests `api/tests/Functional/AuthLogoutTest.php` (AC: 1, 2, 3, 4)
  - [x] Logout with valid token: returns 204, token revoked, both cookies cleared
  - [x] Logout without cookie: returns 204, both cookies cleared (idempotent)
  - [x] Logout with unknown token: returns 204 (idempotent)
  - [x] After logout, refresh with old cookie returns 401

- [x] Run quality checks
  - [x] `composer phpstan`
  - [x] `composer cs-fixer`
  - [x] `composer test`

---

## Dev Notes

### Implementation

The `logout()` method needs a `Request` parameter to read the refresh cookie.

Flow:
1. Read `__Secure-archilan_refresh` cookie value
2. If present: hash with `sha256`, call `findByTokenHash()`, call `revoke()` if found, `flush()`
3. Return 204 with both session + refresh cookies cleared

Both cookies must be cleared with the correct path and `Secure`/`HttpOnly`/`SameSite` attributes:
- Session cookie: path `/`, all attributes as in login
- Refresh cookie: path `/api/v1/auth/refresh`, all attributes as in login

### Idempotency

The endpoint returns 204 whether or not the cookie is present or the token exists. This prevents information leakage and makes client retry logic safe.

### References

- `api/src/Identity/Presentation/AuthController.php` - `logout()` method to modify
- `api/src/Identity/Application/RefreshTokenRepository.php` - `findByTokenHash()`, `flush()`
- `api/src/Identity/Domain/RefreshToken.php` - `revoke(\DateTimeImmutable)` (idempotent)
- `api/tests/Functional/AuthRefreshTest.php` - pattern for refresh token functional tests

---

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

None.

### Completion Notes List

- `REFRESH_COOKIE_PATH = '/api/v1/auth/refresh'` (existing constant) kept for the route URL used in `AuthRefreshTest`. Added `REFRESH_COOKIE_SCOPE = '/api/v1/auth'` to widen the refresh cookie's path attribute - necessary so the browser sends the cookie to `POST /api/v1/auth/logout` as well as `POST /api/v1/auth/refresh`.
- `logout()` returns `JsonResponse(null, 204)` regardless of whether the token was found (idempotent). No error body is emitted.
- Both the session cookie (path `/`) and the refresh cookie (now path `/api/v1/auth`) are cleared via `clearCookie()` which sets `Max-Age=0`/`expires=1`.
- `AuthSessionTest::testLoginSetsTwoCookiesWithoutReturningToken` updated to assert `REFRESH_COOKIE_SCOPE` instead of the old `REFRESH_COOKIE_PATH` for the cookie path attribute.
- `AuthRefreshTest` untouched - it sets test cookies with `REFRESH_COOKIE_PATH` (`/api/v1/auth/refresh`) which is still a valid sub-path of `REFRESH_COOKIE_SCOPE`, so all refresh tests still pass.

### Validation Results

- `composer phpstan`: 0 errors
- `composer cs-fixer`: 0 new errors (pre-existing CRLF issues in some test files)
- `composer test`: 392/392 tests pass, 3623 assertions

### File List

- `api/src/Identity/Presentation/AuthController.php` (modified - added `REFRESH_COOKIE_SCOPE`, updated `logout()`, `refreshCookie()`, `refreshError()`)
- `api/tests/Functional/AuthLogoutTest.php` (new)
- `api/tests/Functional/AuthSessionTest.php` (modified - assert against `REFRESH_COOKIE_SCOPE`)

### Change Log

- **2026-05-05**: Story implemented in full. `logout()` now accepts `Request`, revokes the refresh token, returns 204, clears both cookies. `REFRESH_COOKIE_SCOPE` constant introduced to widen cookie path from `/api/v1/auth/refresh` to `/api/v1/auth` so the logout endpoint receives the cookie.
