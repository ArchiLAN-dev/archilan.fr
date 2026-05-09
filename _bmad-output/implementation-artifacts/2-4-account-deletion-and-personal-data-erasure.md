# Story 2.4: Account Deletion and Personal Data Erasure

Status: done

## Story

As an authenticated user,
I want to delete my account and associated personal data,
so that I can exercise my RGPD erasure rights.

## Acceptance Criteria

1. Given a user is authenticated, when they request account deletion, then they must confirm the destructive action through AlertDialog.
2. Personal data associated with the account is removed or anonymized according to legal retention rules.
3. The user is logged out after deletion.
4. The system preserves non-personal aggregate event data where legally allowed.
5. The deletion action is auditable without retaining unnecessary personal data.

## Tasks / Subtasks

- [x] Implement backend account erasure (AC: 2, 3, 4, 5)
  - [x] Add deletion audit record without retaining raw personal data.
  - [x] Add user anonymization/soft-delete fields and migration.
  - [x] Add authenticated account deletion endpoint.
  - [x] Clear the session cookie after deletion.
- [x] Add backend tests (AC: 2, 3, 4, 5)
  - [x] Test unauthenticated deletion is rejected.
  - [x] Test authenticated deletion anonymizes personal fields.
  - [x] Test deletion creates audit record without raw email.
  - [x] Test deletion clears session cookie.
- [x] Implement frontend destructive confirmation (AC: 1, 3)
  - [x] Add account deletion control on `/compte`.
  - [x] Require explicit dialog confirmation before API call.
  - [x] Clear local profile state after successful deletion.
  - [x] Avoid inline destructive action without confirmation.
- [x] Validate and handoff
  - [x] Run backend PHPUnit/PHPStan/CS Fixer.
  - [x] Run frontend lint, type-check, and build.
  - [x] Confirm raw deleted email is not stored in audit and no token storage is added.

## Dev Notes

This story implements account erasure for the Identity user record only. Future registration/payment/content relationships should preserve non-personal aggregate data by referencing anonymized/deleted user records rather than keeping raw personal fields.

Formal legal retention rules are not fully modeled yet; the implementation stores a minimal audit record with user id, timestamp, reason, and keyed email hash, not the raw email/display name/password.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-2.4-Account-Deletion-and-Personal-Data-Erasure]
- [Source: _bmad-output/planning-artifacts/architecture.md#RGPD-CNIL-LCEN]
- [Source: _bmad-output/planning-artifacts/ux-design-specification.md#AlertDialog-destructive-confirmation]
- [Source: _bmad-output/implementation-artifacts/2-2-login-logout-and-authenticated-session.md]
- [Source: _bmad-output/implementation-artifacts/2-3-profile-view-and-edit.md]

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- Implemented deletion as anonymizing soft-delete so future non-personal event aggregates can keep stable user references without retaining raw identity fields.
- Added minimal audit record with user id, deletion timestamp, reason, and keyed email hash. Raw email/display name/password are not retained in audit.
- Reused Story 2.2 cookie clearing behavior so deletion also logs the user out.
- The frontend uses a local `role="alertdialog"` confirmation surface rather than an inline destructive submit.

### Completion Notes List

- Added `AccountDeletionAudit` entity and migration `Version20260425000300`.
- Added `deletedAt` marker and anonymization behavior to Identity `User`.
- Added `DELETE /api/v1/account`.
- Account deletion anonymizes email/display name/password, marks the account deleted, and leaves only `ROLE_USER`.
- Deleted accounts can no longer authenticate or resolve through the current-session provider.
- Deletion response clears the session cookie.
- Added account deletion section to `/compte` with destructive confirmation dialog.
- Frontend clears local profile state after successful deletion.

### Validation Results

- `composer test` - passed; 21 tests, 167 assertions.
- `composer phpstan` - passed with no errors.
- `composer cs-fixer` - passed; no diff required. The tool warned that local PHP runtime is 8.4 while `composer.json` minimum is 8.3.
- `pnpm lint` - passed.
- `pnpm typecheck` - passed.
- `pnpm build` - passed.
- Search confirmed no `localStorage` or `sessionStorage` usage.
- Search confirmed `role="alertdialog"` exists in the account deletion confirmation UI.
- Search confirmed deletion audit table/migration and anonymized `deleted-...@deleted.local` pattern exist; tests assert the audit email hash is not the raw email.

### File List

- `api/migrations/Version20260425000300.php`
- `api/src/Identity/Application/AuthenticateUser.php`
- `api/src/Identity/Application/DeleteAccount.php`
- `api/src/Identity/Domain/AccountDeletionAudit.php`
- `api/src/Identity/Domain/User.php`
- `api/src/Identity/Presentation/AccountDeletionController.php`
- `api/tests/Functional/AccountDeletionTest.php`
- `frontend/src/features/auth/account-profile.tsx`
- `_bmad-output/implementation-artifacts/2-4-account-deletion-and-personal-data-erasure.md`

### Change Log

- 2026-04-25: Implemented authenticated account deletion, identity anonymization, minimal deletion audit, session cookie clearing, and frontend destructive confirmation.
