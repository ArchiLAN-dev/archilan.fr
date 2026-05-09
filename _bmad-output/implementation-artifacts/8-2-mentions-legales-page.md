# Story 8.2 - Mentions Legales Page

Status: done

## Review findings

- The page already exposed ArchiLAN as the publisher and did not misrepresent the loi 1901 nonprofit status.
- Association address, publication director, RNA, and hosting identity fields were visible as required placeholders.
- Email and phone contact were combined into one placeholder, which made it possible to miss one required contact field.
- Static content cannot fail backoffice validation, so the page needed a clearer publication warning for required placeholders.
- The route is public, linked from the global footer, and indexable.

## Corrections

- Split association contact into separate required fields for email and phone.
- Added a required hosting provider immatriculation/legal identity field.
- Added a visible required-content note at the top of the page for all `Requis` placeholders.

## Validation

- `pnpm lint -- src/app/mentions-legales/page.tsx src/components/legal-placeholder.tsx src/components/public-shell.tsx`
- `pnpm typecheck`
