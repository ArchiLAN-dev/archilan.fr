# Story 3.4: Private Event Password Configuration

Status: done

## Story

As an admin,
I want to configure private event access passwords,
so that member-only or invitation-only events can be protected.

## Acceptance Criteria

1. Given an event exists, when an admin marks it private, then they can set or update an event password.
2. The password is never exposed in plain text after saving.
3. Public event cards show a members-only/private access state where appropriate.
4. Only admins can configure private access.
5. This story only manages admin password configuration and does not implement registrant password entry.

## Tasks / Subtasks

- [x] Extend event private access model (AC: 1, 2)
  - [x] Add nullable private access password hash to the event entity.
  - [x] Add migration for the private password hash column.
  - [x] Add domain method for configuring a private access hash.
  - [x] Expose only a boolean `hasPrivateAccessPassword` in API payloads.
- [x] Add backend private access API (AC: 1, 2, 4, 5)
  - [x] Add admin-only `PATCH /api/v1/admin/events/{eventId}/private-access`.
  - [x] Require event to be private before password configuration.
  - [x] Validate minimum password length.
  - [x] Hash the password before persistence.
  - [x] Do not implement registrant password entry.
- [x] Add backend tests (AC: 1, 2, 3, 4, 5)
  - [x] Test admin can configure a private event password without plain text exposure.
  - [x] Test public events reject private password configuration.
  - [x] Test short passwords are rejected.
  - [x] Test lambda users cannot configure private access.
  - [x] Test public payloads mark protected private events without exposing password data.
- [x] Update frontend admin UI (AC: 1, 2, 4)
  - [x] Show private/protected status in admin event list.
  - [x] Add private access configuration dialog for private events.
  - [x] Use password input and avoid displaying saved password values.
  - [x] Refresh admin list state after configuration.
- [x] Update public event state mapping (AC: 3)
  - [x] Include private/protected flags in public API mapping.
  - [x] Render private API events as members-only/private cards.
- [x] Validate and handoff
  - [x] Run backend PHPUnit/PHPStan/CS Fixer.
  - [x] Run frontend lint, type-check, and build.

## Dev Notes

This story stores only a password hash. API responses expose `hasPrivateAccessPassword` and never expose the clear password or hash. Registrant-side password entry is deliberately deferred to a later registration/access story.

Private published events remain visible in public listings, but are mapped to the existing members-only/private card state so visitors can understand access is restricted.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-3.4-Private-Event-Password-Configuration]
- [Source: _bmad-output/implementation-artifacts/3-3-publish-unpublish-and-lifecycle-transitions.md]

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- Added `AdminEventPrivateAccessTest` for private password configuration, RBAC, validation, and public payload secrecy.
- Cleaned `AdminEventDrafts` and `AdminEventController` after detecting typographic quote corruption in PHP source.
- Updated public catalog visibility behavior so private published events remain listed as restricted access events.

### Completion Notes List

- Added `private_access_password_hash` persistence and migration.
- Added admin-only private access configuration endpoint with server-side validation and password hashing.
- Added `hasPrivateAccessPassword` to admin/public payloads without exposing hash or clear password.
- Added private access configuration dialog in the admin events dashboard.
- Updated public event API mapping so private events render as members-only/private access cards.

### Validation Results

- `composer test` passed: 75 tests, 882 assertions.
- `composer phpstan` passed with no errors.
- `composer cs-fixer` passed in dry-run mode. PHP CS Fixer emitted the existing PHP 8.4 runtime warning for a PHP 8.3 project.
- `pnpm lint` passed.
- `pnpm typecheck` passed.
- `pnpm build` passed.

### File List

- _bmad-output/implementation-artifacts/3-4-private-event-password-configuration.md
- api/migrations/Version20260425000900.php
- api/src/Events/Application/AdminEventDrafts.php
- api/src/Events/Application/PublicEventCatalog.php
- api/src/Events/Domain/Event.php
- api/src/Events/Presentation/AdminEventController.php
- api/tests/Functional/AdminEventEditTest.php
- api/tests/Functional/AdminEventLifecycleTest.php
- api/tests/Functional/AdminEventPrivateAccessTest.php
- api/tests/Functional/RbacEnforcementTest.php
- frontend/src/features/admin/admin-event-dashboard.tsx
- frontend/src/features/events/public-events-api.ts

### Change Log

- 2026-04-25: Implemented Story 3.4 private event password configuration with hashed storage, admin API/UI, public restricted-state mapping, and validation coverage.
