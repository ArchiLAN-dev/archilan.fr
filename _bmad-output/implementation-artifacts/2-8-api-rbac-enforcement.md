# Story 2.8: API RBAC Enforcement

Status: done

## Story

As the system,
I want every protected API endpoint to enforce roles server-side,
so that frontend route guards are never the security boundary.

## Acceptance Criteria

1. Given protected endpoints exist for account, admin users, content, events, and role changes, when unauthenticated or under-privileged users call them, then the API returns the correct unauthorized/forbidden response.
2. RBAC is enforced in Symfony, not only in Next.js.
3. Frontend redirects are treated as UX only.
4. Functional tests cover at least lambda, membre, admin, and anonymous access paths.
5. Error responses follow the documented API error format.

## Tasks / Subtasks

- [x] Add shared API RBAC guard (AC: 1, 2, 5)
  - [x] Add authenticated-user guard with documented `{ error }` response.
  - [x] Add admin-user guard with documented `{ error }` response.
  - [x] Refactor protected Identity controllers to use the shared guard.
- [x] Add RBAC matrix tests (AC: 1, 2, 4, 5)
  - [x] Cover anonymous access to account and admin endpoints.
  - [x] Cover lambda access to account and admin endpoints.
  - [x] Cover membre access to account and admin endpoints.
  - [x] Cover admin access to admin endpoints.
  - [x] Assert error response shape for 401 and 403.
- [x] Document current protected surface (AC: 3)
  - [x] Note that content/events admin APIs do not exist yet and must use the shared guard when added.
  - [x] Record that frontend redirects are UX only.
- [x] Validate and handoff
  - [x] Run backend PHPUnit/PHPStan/CS Fixer.
  - [x] Run frontend lint, type-check, and build.

## Dev Notes

Current protected Symfony API surface is Identity: account profile/deletion plus admin user directory, role changes, and admin account creation. Content/events backoffice pages currently have no Symfony API endpoints; future Epic 3 content/event endpoints must use the shared guard added here.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-2.8-API-RBAC-Enforcement]
- [Source: _bmad-output/implementation-artifacts/2-5-admin-user-directory.md]
- [Source: _bmad-output/implementation-artifacts/2-6-admin-role-promotion-and-demotion.md]
- [Source: _bmad-output/implementation-artifacts/2-7-admin-account-creation.md]

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- Replaced per-controller account/admin guard checks with a shared Symfony-side `ApiAccessGuard`.
- Added `RbacEnforcementTest` matrix to cover anonymous, lambda, membre, and admin access paths.

### Completion Notes List

- Added `ApiAccessGuard` with `requireUser()` and `requireAdmin()` helpers returning documented `{ error: { code, message, details } }` responses.
- Refactored account profile, account deletion, admin user directory, admin role change, and admin account creation controllers to use the shared guard.
- Verified protected Identity endpoints enforce RBAC server-side. Frontend redirects remain UX only.
- Content/events admin Symfony API endpoints do not exist yet; this story records that future endpoints must use the shared guard when added.

### Validation Results

- `composer test` passed: 45 tests, 482 assertions.
- `composer phpstan` passed with no errors.
- `composer cs-fixer` passed in dry-run mode. PHP CS Fixer emitted the existing PHP 8.4 runtime warning for a PHP 8.3 project.
- `pnpm lint` passed.
- `pnpm typecheck` passed.
- `pnpm build` passed.

### File List

- _bmad-output/implementation-artifacts/2-8-api-rbac-enforcement.md
- api/src/Identity/Presentation/AccountDeletionController.php
- api/src/Identity/Presentation/AdminCreateAdminAccountController.php
- api/src/Identity/Presentation/AdminUserDirectoryController.php
- api/src/Identity/Presentation/AdminUserRoleController.php
- api/src/Identity/Presentation/ProfileController.php
- api/src/Shared/Infrastructure/Http/ApiAccessGuard.php
- api/tests/Functional/RbacEnforcementTest.php

### Change Log

- 2026-04-25: Implemented Story 2.8 shared API RBAC guard, protected controller refactor, and cross-role functional RBAC matrix.
