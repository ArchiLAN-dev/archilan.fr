# Story 2.2: Login, Logout and Authenticated Session

Status: done

## Story

As a registered user,
I want to log in and out securely,
so that I can access authenticated features.

## Acceptance Criteria

1. Given a lambda account exists, when the user logs in with valid credentials, then the API issues authentication using httpOnly Secure SameSite cookies.
2. No token is stored in localStorage or JS-accessible storage.
3. Invalid credentials return a generic authentication error.
4. Logout clears the authenticated session cookie.
5. Authenticated frontend state updates without exposing token contents.

## Tasks / Subtasks

- [x] Implement backend session cookie auth (AC: 1, 2, 3, 4)
  - [x] Add login endpoint under `/api/v1`.
  - [x] Validate credentials with Symfony password hasher.
  - [x] Issue httpOnly Secure SameSite cookie without returning token contents.
  - [x] Add current-session endpoint that reads the cookie server-side.
  - [x] Add logout endpoint that clears the cookie.
- [x] Add backend tests (AC: 1, 2, 3, 4)
  - [x] Test valid login sets secure httpOnly SameSite cookie and does not return token.
  - [x] Test invalid credentials return generic error.
  - [x] Test current session reads cookie.
  - [x] Test logout clears cookie.
- [x] Implement frontend login page (AC: 2, 5)
  - [x] Add `/connexion` page.
  - [x] Add labeled email/password fields.
  - [x] Submit with credentials included and display generic errors.
  - [x] Update local React authenticated state from API user data only.
  - [x] Add logout action that clears frontend state after API logout.
- [x] Validate and handoff
  - [x] Run backend PHPUnit/PHPStan/CS Fixer.
  - [x] Run frontend lint, type-check, and build.
  - [x] Confirm no localStorage/sessionStorage token storage.

## Dev Notes

This story implements cookie-based authentication. It must not store tokens in localStorage, sessionStorage, or any JS-readable persistence. The API owns the cookie value. Frontend state may keep only non-sensitive user data returned by `/api/v1/auth/me`.

Lexik JWT is installed but RSA keys are not configured in committed test/dev files yet. This implementation uses a signed opaque cookie as a safe local baseline; a later auth-hardening story can swap the signer for Lexik JWT issuance once operational key management is finalized.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-2.2-Login-Logout-and-Authenticated-Session]
- [Source: _bmad-output/planning-artifacts/architecture.md#Authentication-and-Security]
- [Source: _bmad-output/implementation-artifacts/2-1-lambda-account-registration.md]

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- Lexik JWT is installed, but committed dev/test JWT keys are intentionally absent. Implemented a signed opaque cookie baseline so the story can satisfy httpOnly/Secure/SameSite behavior without committing private keys.
- First PHPStan pass required narrowing nullable `SameSite` in the cookie assertion.
- CS Fixer required fully qualified `\JsonException` in the auth controller.

### Completion Notes List

- Added `POST /api/v1/auth/login`, `GET /api/v1/auth/me`, and `POST /api/v1/auth/logout`.
- Login validates credentials with Symfony's password hasher against the Identity user repository.
- Successful login sets `__Host-archilan_session` as an httpOnly, Secure, SameSite=Lax cookie.
- Login response returns user data only; no token/JWT value is returned.
- Invalid credentials return generic `invalid_credentials` without revealing which field failed.
- `/api/v1/auth/me` reads the cookie server-side and returns non-sensitive user data.
- Logout clears the session cookie.
- Added `/connexion` frontend page with labeled fields, credentialed fetch calls, in-memory user state, and logout action.
- Frontend state stores only `id`, `email`, and `roles`; no localStorage/sessionStorage token storage was added.

### Validation Results

- `composer test` - passed; 9 tests, 68 assertions.
- `composer phpstan` - passed with no errors.
- `composer cs-fixer` - passed; no diff required. The tool warned that the local PHP runtime is 8.4 while `composer.json` minimum is 8.3.
- `pnpm lint` - passed.
- `pnpm typecheck` - passed.
- `pnpm build` - passed; build output includes static `/connexion`.
- Search confirmed no `localStorage` or `sessionStorage` usage in frontend/API auth code.
- Search confirmed API auth routes and cookie constant are present; token/JWT strings only appear in tests asserting they are absent.

### File List

- `api/config/services.yaml`
- `api/src/Identity/Application/AuthenticateUser.php`
- `api/src/Identity/Application/AuthSessionSigner.php`
- `api/src/Identity/Presentation/AuthController.php`
- `api/tests/Functional/AuthSessionTest.php`
- `frontend/src/app/connexion/page.tsx`
- `frontend/src/components/public-shell.tsx`
- `frontend/src/features/auth/login-form.tsx`
- `_bmad-output/implementation-artifacts/2-2-login-logout-and-authenticated-session.md`

### Change Log

- 2026-04-25: Implemented login/logout/current-session API, signed httpOnly Secure SameSite cookie handling, frontend login state, and auth validation tests.
