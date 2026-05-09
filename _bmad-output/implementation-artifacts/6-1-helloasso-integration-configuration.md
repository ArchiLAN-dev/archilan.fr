# Story 6.1 - HelloAsso Integration Configuration

Status: done

## Review Findings

- `api/.env.example` did not document the server-side HelloAsso credentials, organization slug, form slugs, and sandbox mode required by the story.
- Admin payment sync accepted a HelloAsso form slug even when API credentials or organization slug were missing. The queued handler then skipped the sync in the background, which did not satisfy the operational-error requirement.
- `RegistrationSubmission.php` contained broken single-quoted French strings with unescaped apostrophes, preventing Symfony container compilation and blocking backend validation.

## Corrections

- Updated `api/.env.example` with non-secret HelloAsso server-side variables:
  - `HELLOASSO_CLIENT_ID`
  - `HELLOASSO_CLIENT_SECRET`
  - `HELLOASSO_ORGANIZATION_SLUG`
  - `HELLOASSO_SANDBOX`
  - `HELLOASSO_MEMBERSHIP_FORM_SLUG`
  - `HELLOASSO_SHOP_FORM_SLUG`
- Kept frontend configuration public-only; no private HelloAsso credentials are documented or consumed by `frontend/.env.example`.
- Added `HelloAssoConfig::assertApiAccessConfigured()` for explicit API credential checks.
- Updated admin sync triggering to return `helloasso_not_configured` with HTTP 503 and the missing variable name when HelloAsso API configuration is incomplete.
- Added/updated tests for missing HelloAsso operational configuration and full API config validation.
- Repaired `RegistrationSubmission.php` syntax so backend tests and static analysis can run.

## Validation

- `composer test -- tests/Unit/Payments/HelloAssoConfigTest.php tests/Functional/HelloAssoSyncTest.php tests/Functional/HelloAssoCheckoutTest.php tests/Functional/MembershipCheckoutTest.php tests/Functional/ShopCheckoutTest.php`
- `composer phpstan`
