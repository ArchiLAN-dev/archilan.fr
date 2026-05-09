# Story 6.6 - Payment Status Visibility in Admin Registration View

Status: done

## Review Findings

- The admin registration dashboard did not include any payment status summary, so admins had to open each registration detail to verify HelloAsso payment state.
- The registration detail payment badge only handled `processed` and `refunded`, leaving pending, failed, and unknown states as raw technical strings.
- Dashboard functional coverage did not assert payment summary exposure.

## Corrections

- Added HelloAsso payment lookup to `AdminRegistrationDashboard` rows.
- Added dashboard functional coverage for matched payment status, amount, and stale flag.
- Added payment status display in the admin registration dashboard table.
- Normalized admin payment labels in the UI:
  - confirmed/processed/paid/succeeded -> `Confirme`
  - pending/authorized/waiting/created -> `En attente`
  - failed/refused/canceled/cancelled/error -> `Echec`
  - refunded/refund -> `Rembourse`
  - fallback -> `Inconnu`
- Preserved read-only payment status; no manual payment editing path was added.

## Validation

- `composer test -- tests/Functional/AdminPaymentStatusTest.php tests/Functional/AdminRegistrationDashboardTest.php`
- `composer phpstan`
- `php vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php src/Registrations/Application/AdminRegistrationDashboard.php tests/Functional/AdminRegistrationDashboardTest.php tests/Functional/AdminPaymentStatusTest.php`
- `pnpm lint -- src/features/admin/admin-registration-dashboard.tsx src/features/admin/admin-registration-detail.tsx`
- `pnpm typecheck`
