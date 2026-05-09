# Story 2.7: Admin Account Creation

Status: done

## Story

As an admin,
I want to create other admin accounts,
so that the association board can share backoffice responsibility.

## Acceptance Criteria

1. Given an admin is authenticated, when they create a new admin account, then the account receives admin role only through an admin-only API action.
2. Required account fields are validated server-side.
3. The action is logged.
4. Non-admin users cannot create admins.
5. The system prevents privilege escalation through client-side payload manipulation.

## Tasks / Subtasks

- [x] Implement backend admin account creation (AC: 1, 2, 3, 4, 5)
  - [x] Add admin creation audit record.
  - [x] Add admin-only creation service.
  - [x] Add `/api/v1/admin/users/admins` endpoint.
  - [x] Ignore client-provided role fields and assign admin roles server-side.
- [x] Add backend tests (AC: 1, 2, 3, 4, 5)
  - [x] Test unauthenticated and lambda access are rejected.
  - [x] Test admin creates admin with required fields.
  - [x] Test validation errors for invalid required fields.
  - [x] Test duplicate email rejection.
  - [x] Test client-provided roles cannot downgrade/escalate unexpectedly.
  - [x] Test creation is audited.
- [x] Implement frontend admin creation UI (AC: 1, 2, 4)
  - [x] Add create-admin form to `/admin/utilisateurs`.
  - [x] Submit with credentials to the admin-only endpoint.
  - [x] Show field-level/server validation feedback.
  - [x] Insert created admin into the directory when visible.
- [x] Validate and handoff
  - [x] Run backend PHPUnit/PHPStan/CS Fixer.
  - [x] Run frontend lint, type-check, and build.

## Dev Notes

This story creates admin accounts only through a dedicated admin-only endpoint. It must not allow public registration payloads to create elevated accounts, and it must not add generic role editing beyond the lambda/member transition implemented in Story 2.6.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-2.7-Admin-Account-Creation]
- [Source: _bmad-output/implementation-artifacts/2-5-admin-user-directory.md]
- [Source: _bmad-output/implementation-artifacts/2-6-admin-role-promotion-and-demotion.md]

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- Added `AdminAccountCreationTest` before final validation to cover admin-only access, validation, audit logging, duplicate emails, and client-provided role manipulation.

### Completion Notes List

- Added dedicated admin-only account creation endpoint: `POST /api/v1/admin/users/admins`.
- Added `AdminAccountCreationAudit` entity and migration `Version20260425000500`.
- Added server-side admin creation validation for email, password length, and required display name.
- The endpoint ignores client-provided `role`/`roles` fields and assigns `ROLE_USER` + `ROLE_ADMIN` server-side.
- Extended `/admin/utilisateurs` with a create-admin form that submits with credentials, displays field-level errors, and inserts created admins into the visible directory when applicable.

### Validation Results

- `composer test` passed: 41 tests, 385 assertions.
- `composer phpstan` passed with no errors.
- `composer cs-fixer` passed in dry-run mode. PHP CS Fixer emitted the existing PHP 8.4 runtime warning for a PHP 8.3 project.
- `pnpm lint` passed.
- `pnpm typecheck` passed.
- `pnpm build` passed.

### File List

- _bmad-output/implementation-artifacts/2-7-admin-account-creation.md
- api/migrations/Version20260425000500.php
- api/src/Identity/Application/AdminCreateAdminAccount.php
- api/src/Identity/Domain/AdminAccountCreationAudit.php
- api/src/Identity/Presentation/AdminCreateAdminAccountController.php
- api/tests/Functional/AdminAccountCreationTest.php
- frontend/src/features/admin/admin-user-directory.tsx

### Change Log

- 2026-04-25: Implemented Story 2.7 admin account creation with admin-only API, audit logging, validation, tests, and frontend form.
