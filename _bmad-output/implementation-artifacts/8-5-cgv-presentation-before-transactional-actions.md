# Story 8.5 - CGV Presentation Before Transactional Actions

## Review

Status: done

Acceptance criteria reviewed:

- Footer exposes `/cgv`.
- Ticketing, membership, and shop flows link to CGV before loading the HelloAsso iframe.
- CGV acceptance is not pre-checked.
- HelloAsso iframe is not rendered before acceptance.

Findings:

- Each checkout flow implemented its own CGV acceptance block, increasing the risk of inconsistent legal gating.
- The previous checkbox label contained CGV and HelloAsso links inside the clickable label area.
- Checking the box immediately rendered the HelloAsso iframe; there was no explicit second confirmation action before the transactional embed loaded.
- The CGV page did not expose a dated version aligned with the transactional acceptance copy.

## Corrections

- Added a shared `CgvAcceptanceGate` component.
- Reused the gate for event ticketing, membership checkout, and shop checkout.
- Required two explicit actions before the iframe loads: check acceptance, then click the display button.
- Kept the display button disabled until acceptance is checked.
- Kept CGV/CGU/HelloAsso links outside the checkbox label target.
- Added a dated CGV version in the `/cgv` page header.

## Validation

- `pnpm lint -- src/features/payments/cgv-acceptance-gate.tsx src/features/events/event-checkout.tsx src/features/payments/membership-checkout.tsx src/features/payments/shop-checkout.tsx src/app/cgv/page.tsx src/app/adhesion/page.tsx src/app/boutique/page.tsx src/app/evenements/[eventSlug]/page.tsx`
- `pnpm typecheck`

