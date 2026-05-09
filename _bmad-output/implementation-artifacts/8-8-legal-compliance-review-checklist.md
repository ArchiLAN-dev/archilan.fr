# Story 8.8 - Legal Compliance Review Checklist

**Status:** done  
**Scope:** Epic 8 - Legal Compliance, Consent & Trust  
**Disclaimer:** This checklist documents implementation coverage and remaining launch actions. It is not legal advice.

## Legend

- OK: implemented in the product.
- Required content: association-specific data must be supplied before launch.
- Legal review: a qualified person should review or approve the content/process.

## 1. Mentions Legales (`/mentions-legales`)

| Item | Status | Reference |
|---|---|---|
| Public legal notice page exists | OK | `frontend/src/app/mentions-legales/page.tsx` |
| Footer links to the page | OK | `frontend/src/components/public-shell.tsx` |
| Association identity shell exists | OK | `frontend/src/app/mentions-legales/page.tsx` |
| Association postal address | Required content | `LegalField` in `frontend/src/app/mentions-legales/page.tsx` |
| RNA number | Required content | `LegalField` in `frontend/src/app/mentions-legales/page.tsx` |
| Publication director | Required content | `LegalField` in `frontend/src/app/mentions-legales/page.tsx` |
| Contact email/phone | Required content | `LegalField` in `frontend/src/app/mentions-legales/page.tsx` |
| Hosting provider identity, address, phone, site | Required content | `LegalField` blocks in `frontend/src/app/mentions-legales/page.tsx` |
| Intellectual property and third-party license text | Legal review | `LegalPlaceholder` in `frontend/src/app/mentions-legales/page.tsx` |

## 2. Politique de Confidentialite (`/confidentialite`)

| Item | Status | Reference |
|---|---|---|
| Privacy page exists | OK | `frontend/src/app/confidentialite/page.tsx` |
| Footer links to the page | OK | `frontend/src/components/public-shell.tsx` |
| Page can be read without consent banner prompt | OK | `frontend/src/components/cookie-consent-banner.tsx` |
| Controller identity shell exists | OK | `frontend/src/app/confidentialite/page.tsx` |
| Controller postal address and RGPD contact email | Required content | `LegalField` blocks in `frontend/src/app/confidentialite/page.tsx` |
| Processing purposes and legal bases | OK | `frontend/src/app/confidentialite/page.tsx` |
| Retention periods for account, logs, HelloAsso accounting data | OK/Required content | `frontend/src/app/confidentialite/page.tsx` |
| Retention periods still requiring association decision | Required content | `LegalField` blocks in `frontend/src/app/confidentialite/page.tsx` |
| User rights and CNIL complaint path | OK | `frontend/src/app/confidentialite/page.tsx` |
| RGPD rights request path references `/compte` | OK | `frontend/src/app/confidentialite/page.tsx` |
| Account deletion path references `/compte` | OK | `frontend/src/app/confidentialite/page.tsx` |
| Cookie/localStorage table documents functional session cookie separately from Twitch consent | OK | `frontend/src/app/confidentialite/page.tsx` |
| Full privacy text | Legal review | Entire `frontend/src/app/confidentialite/page.tsx` |

## 3. CGU During Account Creation

| Item | Status | Reference |
|---|---|---|
| CGU page exists and is linked from footer | OK | `frontend/src/app/cgu/page.tsx`, `frontend/src/components/public-shell.tsx` |
| Signup form links to `/cgu` | OK | `frontend/src/features/auth/signup-form.tsx` |
| CGU checkbox is not pre-checked | OK | `frontend/src/features/auth/signup-form.tsx` |
| API rejects registration without CGU acceptance | OK | `api/src/Identity/Application/RegisterLambdaUser.php` |
| Field-level validation exists for `acceptedCgu` | OK | `api/src/Identity/Application/RegisterLambdaUser.php` |
| Acceptance timestamp is stored | OK | `api/src/Identity/Domain/User.php` |
| Accepted CGU version is stored | OK | `api/src/Identity/Domain/User.php`, `api/migrations/Version20260502000009.php` |
| Functional test covers missing CGU acceptance and stored version | OK | `api/tests/Functional/RegisterLambdaUserTest.php` |
| CGU legal content | Legal review | `LegalPlaceholder` blocks in `frontend/src/app/cgu/page.tsx` |

## 4. CGV Before Transactional Actions

| Item | Status | Reference |
|---|---|---|
| CGV page exists and is linked from footer | OK | `frontend/src/app/cgv/page.tsx`, `frontend/src/components/public-shell.tsx` |
| Event checkout presents CGV before HelloAsso iframe | OK | `frontend/src/features/events/event-checkout.tsx` |
| Membership checkout presents CGV/CGU before HelloAsso iframe | OK | `frontend/src/features/payments/membership-checkout.tsx` |
| Shop checkout presents CGV before HelloAsso iframe | OK | `frontend/src/features/payments/shop-checkout.tsx` |
| Shared gate prevents silent bypass | OK | `frontend/src/features/payments/cgv-acceptance-gate.tsx` |
| Acceptance is not pre-checked | OK | `frontend/src/features/payments/cgv-acceptance-gate.tsx` |
| Checkout iframe loads only after checkbox plus explicit display action | OK | `frontend/src/features/payments/cgv-acceptance-gate.tsx` |
| CGV legal content: tariffs, payment process, refund/cancellation, liability, mediator | Legal review | `LegalPlaceholder` blocks in `frontend/src/app/cgv/page.tsx` |

## 5. Cookie Consent and Persistent Consent

| Item | Status | Reference |
|---|---|---|
| Initial banner appears when no choice is stored | OK | `frontend/src/components/cookie-consent-banner.tsx` |
| Visitor can accept, reject, or configure | OK | `frontend/src/components/cookie-consent-banner.tsx` |
| Rejection is as easy as acceptance | OK | `frontend/src/components/cookie-consent-banner.tsx` |
| Configuration keeps Twitch disabled by default | OK | `frontend/src/components/cookie-consent-banner.tsx` |
| Consent choice is stored in `localStorage` | OK | `frontend/src/lib/consent.ts` |
| Twitch embed does not load before consent | OK | `frontend/src/features/streaming/consent-gated-twitch-embed.tsx` |
| Persistent footer control shows current Twitch state | OK | `frontend/src/components/consent-footer-control.tsx` |
| Footer control can authorize or withdraw consent without login | OK | `frontend/src/components/consent-footer-control.tsx` |
| UI confirms updates | OK | `frontend/src/components/consent-footer-control.tsx` |
| Cross-tab consent changes are reflected | OK | `frontend/src/components/consent-footer-control.tsx` |
| Functional/session cookies are separated from non-functional consent | OK | `frontend/src/app/confidentialite/page.tsx`, `frontend/src/components/cookie-consent-banner.tsx` |

## 6. Footer and Insertion Points

| Item | Status | Reference |
|---|---|---|
| Footer contains Mentions Legales, Confidentialite, CGU, CGV | OK | `frontend/src/components/public-shell.tsx` |
| Footer contains persistent consent control after a choice | OK | `frontend/src/components/public-shell.tsx`, `frontend/src/components/consent-footer-control.tsx` |
| Account flow links to privacy/RGPD management | OK | `frontend/src/features/auth/account-profile.tsx` |
| Account deletion flow exists | OK | `api/src/Identity/Presentation/AccountDeletionController.php`, `frontend/src/features/auth/account-profile.tsx` |
| RGPD rights request flow exists | OK | `api/src/Identity/Presentation/PrivacyRightsRequestController.php`, `frontend/src/features/auth/account-profile.tsx` |
| Transaction flows use CGV gate | OK | `frontend/src/features/events/event-checkout.tsx`, `frontend/src/features/payments/membership-checkout.tsx`, `frontend/src/features/payments/shop-checkout.tsx` |

## 7. Required Before Launch

Priority launch blockers:

1. Fill all `LegalField` values for association identity, postal address, RNA number, publication director, contact channels, hosting provider, RGPD contact, and retention periods.
2. Replace CGU placeholders with final association-approved terms.
3. Replace CGV placeholders with final association-approved sales terms, including prices, payment process, cancellation/refund rules, liability, and mediator details where applicable.
4. Validate the full privacy policy with the association board, DPO, or qualified legal reviewer.
5. Configure and test the real RGPD contact mailbox and operational handling process.
6. Confirm infrastructure log retention/rotation matches the documented 12-month technical log retention.
7. Maintain an internal RGPD processing register if required for the association's processing activities.

## Review Corrections

- Updated stale references after stories 8.3 to 8.7.
- Replaced old per-checkout CGV component references with the shared `CgvAcceptanceGate`.
- Replaced the incorrect RGPD controller reference with `PrivacyRightsRequestController.php`.
- Added CGU accepted version storage and migration to the checklist.
- Added cookie consent configuration and persistent footer current-state behavior.
- Kept the document in implementation artifacts and preserved the non-legal-advice disclaimer.

## Validation

- `pnpm lint -- src/components/public-shell.tsx src/components/cookie-consent-banner.tsx src/components/consent-footer-control.tsx src/features/payments/cgv-acceptance-gate.tsx src/features/events/event-checkout.tsx src/features/payments/membership-checkout.tsx src/features/payments/shop-checkout.tsx src/features/auth/signup-form.tsx src/features/auth/account-profile.tsx src/app/mentions-legales/page.tsx src/app/confidentialite/page.tsx src/app/cgu/page.tsx src/app/cgv/page.tsx`
- `pnpm typecheck`

