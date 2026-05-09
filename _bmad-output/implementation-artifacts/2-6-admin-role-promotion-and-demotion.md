# Story 2.6: Admin Role Promotion and Demotion

Status: done

## Story

As an admin,
I want to promote and demote users between lambda and membre,
so that member-only access is controlled by the association.

## Acceptance Criteria

1. Given an admin is viewing a user profile, when they promote a lambda user to membre, then the user's role changes to membre after explicit confirmation.
2. The action is logged for auditability.
3. Demoting a membre to lambda also requires explicit confirmation.
4. Admins cannot accidentally remove their own last admin capability.
5. Role changes are reflected in the UI with optimistic update and rollback on API failure.

## Tasks / Subtasks

- [x] Implement backend role mutation (AC: 1, 2, 3, 4)
  - [x] Add domain methods for lambda/member role transitions.
  - [x] Add role change audit record.
  - [x] Add admin-only role mutation service.
  - [x] Add `/api/v1/admin/users/{id}/role` endpoint with explicit confirmation.
- [x] Add backend tests (AC: 1, 2, 3, 4)
  - [x] Test unauthenticated and lambda access are rejected.
  - [x] Test lambda can be promoted to member with confirmation.
  - [x] Test member can be demoted to lambda with confirmation.
  - [x] Test missing confirmation is rejected.
  - [x] Test admin/self/admin-account mutations are rejected.
  - [x] Test role changes are audited.
- [x] Implement frontend role actions (AC: 1, 3, 5)
  - [x] Add promote/demote controls to `/admin/utilisateurs`.
  - [x] Require explicit confirmation before sending mutation.
  - [x] Apply optimistic update and rollback on API failure.
  - [x] Keep admin/deleted accounts non-actionable.
- [x] Validate and handoff
  - [x] Run backend PHPUnit/PHPStan/CS Fixer.
  - [x] Run frontend lint, type-check, and build.

## Dev Notes

This story only covers lambda ↔ membre changes. Admin account creation and admin role management remain in Story 2.7 and later RBAC hardening stories. The “last admin capability” AC is handled conservatively by refusing role mutation on admin accounts and on the current admin user in this story.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-2.6-Admin-Role-Promotion-and-Demotion]
- [Source: _bmad-output/implementation-artifacts/2-5-admin-user-directory.md]

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- Added targeted `AdminUserRoleTest` coverage before final validation.
- Kept admin role management out of scope by rejecting admin and self role mutation in this story.

### Completion Notes List

- Added `User::promoteToMember()` and `User::demoteToLambda()` for lambda/member transitions.
- Added `RoleChangeAudit` entity and migration `Version20260425000400`.
- Added admin-only `PATCH /api/v1/admin/users/{id}/role` endpoint requiring `confirmed: true`.
- Updated `/admin/utilisateurs` with promote/demote buttons, explicit confirmation, optimistic update, and rollback on API failure.
- Admin and deleted accounts are shown as non-modifiable in the UI; admin/self/admin-account mutations are rejected by the API.

### Validation Results

- `composer test` passed: 35 tests, 337 assertions.
- `composer phpstan` passed with no errors.
- `composer cs-fixer` passed in dry-run mode. PHP CS Fixer emitted the existing PHP 8.4 runtime warning for a PHP 8.3 project.
- `pnpm lint` passed.
- `pnpm typecheck` passed.
- `pnpm build` passed.

### File List

- _bmad-output/implementation-artifacts/2-6-admin-role-promotion-and-demotion.md
- api/migrations/Version20260425000400.php
- api/src/Identity/Application/AdminChangeUserRole.php
- api/src/Identity/Domain/RoleChangeAudit.php
- api/src/Identity/Domain/User.php
- api/src/Identity/Presentation/AdminUserRoleController.php
- api/tests/Functional/AccountDeletionTest.php
- api/tests/Functional/AdminUserRoleTest.php
- frontend/src/features/admin/admin-user-directory.tsx

### Change Log

- 2026-04-25: Implemented Story 2.6 admin lambda/member promotion and demotion with audit log, tests, and frontend optimistic role actions.
