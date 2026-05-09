# Story 2.5: Admin User Directory

Status: done

## Story

As an admin,
I want to search and filter user accounts,
so that I can manage community access efficiently.

## Acceptance Criteria

1. Given an admin is authenticated, when they open the user backoffice, then they can view users with email, display name, role, and account status.
2. Admins can search and filter users by role and text query.
3. No non-admin can access the user directory UI or API.
4. Empty and no-result states follow the UX spec.
5. API responses do not expose password hashes or sensitive auth internals.

## Tasks / Subtasks

- [x] Implement backend admin user directory (AC: 1, 2, 3, 5)
  - [x] Add admin guard based on authenticated user roles.
  - [x] Add list/search/filter query service.
  - [x] Add `/api/v1/admin/users` endpoint.
  - [x] Return only safe user fields.
- [x] Add backend tests (AC: 1, 2, 3, 5)
  - [x] Test unauthenticated access is rejected.
  - [x] Test lambda access is forbidden.
  - [x] Test admin can list users.
  - [x] Test role and text filters.
  - [x] Test response excludes password/session internals.
- [x] Implement frontend admin directory page (AC: 1, 2, 3, 4)
  - [x] Add `/admin/utilisateurs` route.
  - [x] Fetch user directory with credentials.
  - [x] Add text search and role filter controls.
  - [x] Render empty/no-result/access-denied states.
  - [x] Avoid exposing password/session fields.
- [x] Validate and handoff
  - [x] Run backend PHPUnit/PHPStan/CS Fixer.
  - [x] Run frontend lint, type-check, and build.
  - [x] Confirm no password hashes or sensitive auth internals in API/UI responses.

## Dev Notes

This story creates the admin directory and read-only filters. It must not implement role promotion/demotion; that belongs to Story 2.6. Admin test fixtures may create `ROLE_ADMIN` users directly for coverage, but production role changes are not introduced here.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-2.5-Admin-User-Directory]
- [Source: _bmad-output/implementation-artifacts/2-2-login-logout-and-authenticated-session.md]
- [Source: _bmad-output/implementation-artifacts/2-4-account-deletion-and-personal-data-erasure.md]

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- Added failing functional coverage for admin user directory role payload and semantic role filters before completing the service.
- Fixed PHPStan validation typing by extracting a small `ValidationErrors` helper used by existing Identity application services.

### Completion Notes List

- Implemented read-only admin user directory endpoint at `GET /api/v1/admin/users` with authenticated admin-only guard.
- Added text search and semantic role filtering for `lambda`, `member`, and `admin`, including deleted account status in safe response payloads.
- Added `/admin/utilisateurs` frontend page with credentialed fetch, search/filter controls, loading, empty, no-result, error, and access-denied states.
- Confirmed admin directory API/UI code does not expose password hashes, auth cookies, or session internals.

### Validation Results

- `composer test` passed: 28 tests, 283 assertions.
- `composer phpstan` passed with no errors.
- `composer cs-fixer` passed in dry-run mode. PHP CS Fixer emitted the existing PHP 8.4 runtime warning for a PHP 8.3 project.
- `pnpm lint` passed.
- `pnpm typecheck` passed.
- `pnpm build` passed.
- Sensitive-field grep passed: no `password`, `passwordHash`, `session`, `cookie`, `__Host`, or `AuthSession` references in admin directory API/UI files.

### File List

- api/src/Identity/Application/AdminUserDirectory.php
- api/src/Identity/Application/RegisterLambdaUser.php
- api/src/Identity/Application/UpdateUserProfile.php
- api/src/Identity/Application/ValidationErrors.php
- api/src/Identity/Presentation/AdminUserDirectoryController.php
- api/tests/Functional/AdminUserDirectoryTest.php
- api/tests/Functional/ProfileTest.php
- frontend/src/app/admin/utilisateurs/page.tsx
- frontend/src/features/admin/admin-user-directory.tsx

### Change Log

- 2026-04-25: Implemented Story 2.5 admin user directory API, UI, tests, and validation handoff.
