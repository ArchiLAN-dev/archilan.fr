# Story 2.1: Lambda Account Registration

Status: done

## Story

As a visitor,
I want to create a lambda account with email and password,
so that I can register for public events.

## Acceptance Criteria

1. Given the public signup page is available, when a visitor submits a valid email and password, then a lambda user account is created with no member/admin privileges.
2. The password is hashed with the configured secure hasher.
3. Duplicate email registration is rejected with a field-level error.
4. CGU acceptance is required during account creation.
5. The signup form has labels, linked validation errors, and keyboard support.

## Tasks / Subtasks

- [x] Implement backend Identity registration (AC: 1, 2, 3, 4)
  - [x] Add Identity user entity with default lambda role.
  - [x] Add Doctrine mapping and migration.
  - [x] Add registration endpoint under `/api/v1`.
  - [x] Hash passwords through Symfony password hasher.
  - [x] Return field-level errors for duplicates and validation failures.
- [x] Add backend tests (AC: 1, 2, 3, 4)
  - [x] Test successful account creation with hashed password and `ROLE_USER` only.
  - [x] Test duplicate email field error.
  - [x] Test missing CGU acceptance field error.
- [x] Implement frontend signup page (AC: 4, 5)
  - [x] Add `/inscription` page.
  - [x] Add labeled email/password/CGU fields.
  - [x] Link validation errors with `aria-describedby`.
  - [x] Submit to registration API and display field-level API errors.
- [x] Validate and handoff
  - [x] Run backend PHPUnit/PHPStan/CS Fixer where practical.
  - [x] Run frontend lint, type-check, and build.
  - [x] Update this story file with commands run, validation results, and file list.

## Dev Notes

This story creates accounts only. It must not issue login cookies, store tokens, implement logout, implement profile management, or grant member/admin roles. Session/JWT issuance belongs to Story 2.2.

API success responses must follow `{ data, meta }`; API errors must follow `{ error: { code, message, details } }`.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-2.1-Lambda-Account-Registration]
- [Source: _bmad-output/planning-artifacts/architecture.md#Authentication-and-Security]
- [Source: _bmad-output/planning-artifacts/architecture.md#Coding-Standards-and-API-Conventions]

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- Added the route first and verified it with `php bin/console debug:router api_identity_register_lambda_user --env=test`.
- Initial functional test setup booted the Symfony kernel before `createClient()`; corrected setup to create the client first and then initialize the schema.
- PHPStan required explicit JSON payload narrowing and response decoding helpers for field-level error assertions.
- Added a small CORS subscriber after realizing the browser signup form calls the Symfony API from the Next.js origin.

### Completion Notes List

- Added `App\Identity\Domain\User` as a Doctrine/Security user with default lambda `ROLE_USER`.
- Added manual registration use case and `/api/v1/accounts/register` controller.
- Registration hashes passwords with Symfony's configured password hasher and never returns the hash.
- Duplicate canonical email, invalid email, short password, and missing CGU acceptance return `{ error: { code, message, details } }` with field keys.
- Success returns `{ data, meta }` with user id, email, and roles.
- Added PostgreSQL migration for `identity_users`.
- Added restricted API CORS handling for configured `CORS_ALLOW_ORIGIN`, including `OPTIONS` preflight.
- Added `/inscription` page and client signup form with labels, linked field errors, CGU checkbox, and keyboard-native controls.
- Public shell signup CTA now points to `/inscription`.
- No login cookie, JWT issuance, localStorage/sessionStorage token, member role, or admin role was added.

### Validation Results

- `php bin/console debug:router api_identity_register_lambda_user --env=test` - passed; route resolves to `POST /api/v1/accounts/register`.
- `composer test` - passed; 5 tests, 29 assertions.
- `composer phpstan` - passed with no errors.
- `composer cs-fixer` - passed; no diff required. The tool warned that the local PHP runtime is 8.4 while `composer.json` minimum is 8.3.
- `pnpm lint` - passed.
- `pnpm typecheck` - passed.
- `pnpm build` - passed; build output includes static `/inscription`.
- Search confirmed no `localStorage`, `sessionStorage`, `ROLE_ADMIN`, or `ROLE_MEMBER` usage was introduced in the registration flow.

### File List

- `api/.env.example`
- `api/.env.test`
- `api/config/packages/doctrine.yaml`
- `api/config/packages/security.yaml`
- `api/config/services.yaml`
- `api/migrations/Version20260425000100.php`
- `api/src/Identity/Application/RegisterLambdaUser.php`
- `api/src/Identity/Domain/User.php`
- `api/src/Identity/Presentation/RegisterLambdaUserController.php`
- `api/src/Shared/Infrastructure/Http/ApiCorsSubscriber.php`
- `api/tests/Functional/RegisterLambdaUserTest.php`
- `frontend/src/app/inscription/page.tsx`
- `frontend/src/components/public-shell.tsx`
- `frontend/src/features/auth/signup-form.tsx`
- `_bmad-output/implementation-artifacts/2-1-lambda-account-registration.md`

### Change Log

- 2026-04-25: Implemented lambda account registration API, Identity user persistence, frontend signup page, field-level validation, CGU acceptance, and CORS preflight support.
