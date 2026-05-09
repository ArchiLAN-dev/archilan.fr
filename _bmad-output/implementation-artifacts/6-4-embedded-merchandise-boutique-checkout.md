# Story 6.4 - Embedded Merchandise Boutique Checkout

Status: done

## Review Findings

- The frontend shop checkout API parser accepted any payload containing a `checkoutEmbedUrl` key, even when the value was not `string|null`.
- Backend coverage only asserted the disabled/default shop state; it did not prove that a configured HelloAsso shop form is exposed through the approved embed URL.
- The unavailable shop state described retrying but did not expose a clear retry action.
- A pre-existing PHPStan issue in `AdminGameLibraryTest` blocked full backend static analysis.

## Corrections

- Hardened `shop-api.ts` so `checkoutEmbedUrl` must be `string|null`; malformed API payloads now degrade to `null`.
- Added functional coverage for configured boutique checkout URL generation using the HelloAsso sandbox shop widget path.
- Added an explicit retry link on the boutique unavailable state.
- Kept the page focused on HelloAsso checkout only; no local inventory management was introduced.
- Typed the existing `AdminGameLibraryTest` assertion before reading nested error details, restoring full PHPStan validation.

## Validation

- `composer test -- tests/Functional/ShopCheckoutTest.php`
- `composer test -- tests/Functional/AdminGameLibraryTest.php tests/Functional/ShopCheckoutTest.php`
- `composer phpstan`
- `php vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php tests/Functional/ShopCheckoutTest.php tests/Functional/AdminGameLibraryTest.php src/Payments/Application/ShopCheckout.php`
- `pnpm lint -- src/app/boutique/page.tsx src/features/payments/shop-checkout.tsx src/features/payments/shop-api.ts`
- `pnpm typecheck`
