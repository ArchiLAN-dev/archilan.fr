# Story 2.9: RGPD Rights Request Support

Status: done

## Story

As an authenticated user,
I want a clear way to exercise RGPD rights,
so that I can request access, rectification, erasure, portability, or opposition.

## Acceptance Criteria

1. Given a user is authenticated, when they open account privacy settings, then they see the available RGPD rights and how to exercise them.
2. They can initiate or access the documented process for each right.
3. Privacy policy links are available from the flow.
4. Admin/contact handling requirements are recorded for follow-up.
5. The implementation does not promise automated portability unless that capability exists.

## Tasks / Subtasks

- [x] Implement backend RGPD request capture (AC: 2, 4, 5)
  - [x] Add privacy rights request entity.
  - [x] Add authenticated request creation service.
  - [x] Add `/api/v1/account/privacy-requests` endpoint.
  - [x] Return manual-review status and avoid promising automated export/portability.
- [x] Add backend tests (AC: 2, 4, 5)
  - [x] Test unauthenticated access is rejected.
  - [x] Test authenticated user can submit each supported right type.
  - [x] Test invalid right type and excessive details are rejected.
  - [x] Test created request stores user id, type, status, and timestamps.
- [x] Implement account privacy UI (AC: 1, 2, 3, 5)
  - [x] Add account privacy section listing access, rectification, erasure, portability, opposition.
  - [x] Link to `/confidentialite`.
  - [x] Add authenticated form to initiate a manual RGPD request.
  - [x] Make portability wording explicitly manual review, not automatic export.
  - [x] Keep account deletion as the direct erasure action already available.
- [x] Validate and handoff
  - [x] Run backend PHPUnit/PHPStan/CS Fixer.
  - [x] Run frontend lint, type-check, and build.

## Dev Notes

This story creates a request intake and user-facing instructions. It does not implement automated data export/portability, admin processing screens, legal-policy content, or a formal legal advice workflow. Admin/contact handling remains a follow-up operational process and is represented by stored pending/manual-review requests.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-2.9-RGPD-Rights-Request-Support]
- [Source: _bmad-output/implementation-artifacts/2-3-profile-view-and-edit.md]
- [Source: _bmad-output/implementation-artifacts/2-4-account-deletion-and-personal-data-erasure.md]
- [Source: _bmad-output/implementation-artifacts/2-8-api-rbac-enforcement.md]

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- Added `PrivacyRightsRequestTest` to lock the backend intake behavior and manual-review portability wording.
- Extended `RbacEnforcementTest` to include the new authenticated account privacy request endpoint.

### Completion Notes List

- Added `PrivacyRightsRequest` entity and migration `Version20260425000600`.
- Added authenticated `POST /api/v1/account/privacy-requests` endpoint with supported rights: access, rectification, erasure, portability, opposition.
- Stored RGPD requests with `received` status and `manual_review` handling mode for admin/contact follow-up.
- Updated account UI with a “Données et confidentialité” section, rights explainer, `/confidentialite` link, and manual RGPD request form.
- Kept deletion as the direct erasure action and explicitly avoided promising automated portability/export.

### Validation Results

- `composer test` passed: 51 tests, 575 assertions.
- `composer phpstan` passed with no errors.
- `composer cs-fixer` passed in dry-run mode. PHP CS Fixer emitted the existing PHP 8.4 runtime warning for a PHP 8.3 project.
- `pnpm lint` passed.
- `pnpm typecheck` passed.
- `pnpm build` passed.

### File List

- _bmad-output/implementation-artifacts/2-9-rgpd-rights-request-support.md
- api/migrations/Version20260425000600.php
- api/src/Identity/Application/CreatePrivacyRightsRequest.php
- api/src/Identity/Domain/PrivacyRightsRequest.php
- api/src/Identity/Presentation/PrivacyRightsRequestController.php
- api/tests/Functional/PrivacyRightsRequestTest.php
- api/tests/Functional/RbacEnforcementTest.php
- frontend/src/features/auth/account-profile.tsx

### Change Log

- 2026-04-25: Implemented Story 2.9 RGPD rights request support with authenticated request intake, manual-review status, account privacy UI, and tests.
