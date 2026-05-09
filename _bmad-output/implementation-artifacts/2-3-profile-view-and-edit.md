# Story 2.3: Profile View and Edit

Status: done

## Story

As an authenticated user,
I want to view and update my profile information,
so that my account details stay accurate.

## Acceptance Criteria

1. Given a user is authenticated, when they open their account page, then they can view their email, display name, role, and relevant account metadata.
2. They can update editable profile fields.
3. Email uniqueness and field validation are enforced server-side.
4. Form errors are shown inline and specifically.
5. Role fields cannot be changed by the user.

## Tasks / Subtasks

- [x] Extend Identity profile data (AC: 1, 2, 3, 5)
  - [x] Add `displayName` and update timestamp to the user model.
  - [x] Add migration for new profile columns.
  - [x] Keep roles server-owned and non-editable.
- [x] Implement backend profile API (AC: 1, 2, 3, 4, 5)
  - [x] Add authenticated profile read endpoint.
  - [x] Add authenticated profile update endpoint.
  - [x] Validate editable fields server-side and return field-level errors.
  - [x] Ensure attempted role updates are ignored/rejected.
- [x] Add backend tests (AC: 1, 2, 3, 4, 5)
  - [x] Test unauthenticated profile access is rejected.
  - [x] Test authenticated profile view returns email, display name, roles, metadata.
  - [x] Test display name update succeeds.
  - [x] Test invalid profile update returns field-level error.
  - [x] Test roles cannot be changed by profile update payload.
- [x] Implement frontend account page (AC: 1, 2, 4, 5)
  - [x] Add `/compte` page.
  - [x] Fetch profile with credentials included.
  - [x] Render email, display name, role, metadata.
  - [x] Provide editable display name form with inline errors.
  - [x] Render role as read-only.
- [x] Validate and handoff
  - [x] Run backend PHPUnit/PHPStan/CS Fixer.
  - [x] Run frontend lint, type-check, and build.
  - [x] Confirm no role-editing UI or token storage was added.

## Dev Notes

This story edits profile data only. It must not implement role management, account deletion, admin user directory, or email change workflow. Email uniqueness remains enforced by the existing unique canonical email column; changing email is deferred until a dedicated verified email-change flow exists.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-2.3-Profile-View-and-Edit]
- [Source: _bmad-output/implementation-artifacts/2-1-lambda-account-registration.md]
- [Source: _bmad-output/implementation-artifacts/2-2-login-logout-and-authenticated-session.md]

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- Reused the signed cookie/session reader from Story 2.2 through a new `CurrentUserProvider` service.
- Chose `displayName` as the only editable profile field for this story. Email changes require a dedicated verified email-change flow, so email remains read-only while uniqueness stays enforced by the existing canonical unique column.
- PHPStan required additional response-shape assertions in existing functional tests after profile payloads were expanded.

### Completion Notes List

- Added `displayName` and `updatedAt` fields to the Identity user model.
- Added migration `Version20260425000200` for profile columns.
- Added `GET /api/v1/account/profile` and `PATCH /api/v1/account/profile`.
- Profile API returns email, display name, roles, created timestamp, and updated timestamp.
- Profile update accepts only `displayName`; payload roles are ignored and never persisted.
- Server-side validation returns field-level `displayName` errors.
- Added `/compte` frontend page with profile loading, read-only email/role/metadata, editable display-name form, and inline errors.
- No role-editing UI, token storage, account deletion, or admin user management was added.

### Validation Results

- `composer test` - passed; 18 tests, 138 assertions.
- `composer phpstan` - passed with no errors.
- `composer cs-fixer` - passed; no diff required. The tool warned that local PHP runtime is 8.4 while `composer.json` minimum is 8.3.
- `pnpm lint` - passed.
- `pnpm typecheck` - passed.
- `pnpm build` - passed; build output includes static `/compte`.
- Search confirmed no `localStorage` or `sessionStorage` usage.
- Search confirmed no `name="roles"` field exists in frontend code.
- Search found `ROLE_ADMIN`/`ROLE_MEMBER` only in role-label rendering and the backend test that verifies role changes are ignored.

### File List

- `api/migrations/Version20260425000200.php`
- `api/src/Identity/Application/CurrentUserProvider.php`
- `api/src/Identity/Application/UpdateUserProfile.php`
- `api/src/Identity/Domain/User.php`
- `api/src/Identity/Presentation/AuthController.php`
- `api/src/Identity/Presentation/ProfileController.php`
- `api/src/Identity/Application/RegisterLambdaUser.php`
- `api/tests/Functional/AuthSessionTest.php`
- `api/tests/Functional/ProfileTest.php`
- `api/tests/Functional/RegisterLambdaUserTest.php`
- `frontend/src/app/compte/page.tsx`
- `frontend/src/features/auth/account-profile.tsx`
- `_bmad-output/implementation-artifacts/2-3-profile-view-and-edit.md`

### Change Log

- 2026-04-25: Implemented authenticated profile view/edit with display name, read-only role/email metadata, server validation, and frontend account page.
