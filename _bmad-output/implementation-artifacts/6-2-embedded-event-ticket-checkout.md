# Story 6.2 - Embedded Event Ticket Checkout

Status: done

## Review Findings

- The public API exposed `checkoutEmbedUrl` whenever an event had a HelloAsso form slug, even if the event was private, completed, full, or outside its registration window.
- The event detail primary CTA still pointed to the internal registration route when HelloAsso ticketing was available, so the embedded checkout was not the clear primary ticketing action.

## Corrections

- Restricted event checkout exposure in `PublicEventCatalog` to public, published, non-full events whose registration window is currently open.
- Added functional coverage for:
  - configured public/open event returns the HelloAsso sandbox widget URL;
  - private event returns `checkoutEmbedUrl: null`;
  - completed event returns `checkoutEmbedUrl: null`;
  - full event returns `checkoutEmbedUrl: null`;
  - future registration window returns `checkoutEmbedUrl: null`.
- Updated the event detail CTA to target the embedded `#billetterie` section when a checkout URL is present.
- Added the `billetterie` anchor on the embedded event checkout component.

## Validation

- `composer test -- tests/Functional/HelloAssoCheckoutTest.php`
- `composer phpstan`
- `php vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php src/Events/Application/PublicEventCatalog.php tests/Functional/HelloAssoCheckoutTest.php`
- `pnpm lint -- src/app/evenements/[eventSlug]/page.tsx src/features/events/event-checkout.tsx`
- `pnpm typecheck`
