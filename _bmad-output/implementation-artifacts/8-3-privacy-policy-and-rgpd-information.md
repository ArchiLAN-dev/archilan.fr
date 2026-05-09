# Story 8.3 - Privacy Policy and RGPD Information

## Review

Status: done

Acceptance criteria reviewed:

- Privacy page describes controller identity, processing purposes, legal bases, retention periods, user rights, and CNIL complaint path.
- Footer links to `/confidentialite`.
- Account profile links to `/confidentialite` and exposes the RGPD request and account deletion flows.
- Page is built with responsive text/table layouts already used by the legal shell.

Findings:

- The privacy page referenced the account area, but the RGPD request and account deletion paths were only implicit.
- The Twitch consent banner could still be displayed on `/confidentialite`, adding a consent prompt before the visitor had finished reading the policy.

## Corrections

- Added explicit `/compte` references for:
  - RGPD rights request, section `Donnees et confidentialite`.
  - Account deletion, section `Suppression du compte`.
- Suppressed `CookieConsentBanner` on `/confidentialite` so the privacy policy can be read without a non-functional consent prompt on that page.

## Validation

- `pnpm lint -- src/app/confidentialite/page.tsx src/components/cookie-consent-banner.tsx src/components/public-shell.tsx src/features/auth/account-profile.tsx`
- `pnpm typecheck`

