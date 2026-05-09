# Story 6.3 - Embedded Membership Fee Checkout

Status: done

## Review Findings

- The frontend membership checkout API parser accepted any payload that merely contained a `checkoutEmbedUrl` key, even if the value was not `string|null`.
- Backend coverage only asserted the disabled/default membership state; it did not prove that a configured HelloAsso membership form is exposed through the approved embed URL.

## Corrections

- Hardened `membership-api.ts` so `checkoutEmbedUrl` must be `string|null`; malformed API payloads now degrade to `null`.
- Added functional coverage for configured membership checkout URL generation using the HelloAsso sandbox membership widget path.
- Confirmed the payment flow does not promote users automatically; membership role changes remain in the admin role-management flow.

## Validation

- `composer test -- tests/Functional/MembershipCheckoutTest.php`
- `composer phpstan`
- `php vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php tests/Functional/MembershipCheckoutTest.php src/Payments/Application/MembershipCheckout.php`
- `pnpm lint -- src/app/adhesion/page.tsx src/features/payments/membership-checkout.tsx src/features/payments/membership-api.ts`
- `pnpm typecheck`
