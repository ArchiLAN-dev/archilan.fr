# Story 8.1 - Legal Footer and Static Legal Page Shell

Status: done

## Review findings

- The public shell already exposes footer links to Mentions Legales, Confidentialite, CGU, and CGV on every page.
- The four legal routes already render static article shells with semantic headings.
- Legal pages were crawlable by default, but crawlability was not explicit in metadata.
- Missing legal content placeholders were visible, but the label was weaker than the acceptance criterion's "content required" intent.

## Corrections

- Added explicit `robots: { index: true, follow: true }` metadata to the four legal pages.
- Reworked legal placeholders to say `Contenu requis` / `Requis` and expose a `role="note"` with an accessible label.
- Removed an unused import from the privacy page.

## Validation

- `pnpm lint -- src/components/public-shell.tsx src/components/legal-placeholder.tsx src/app/mentions-legales/page.tsx src/app/confidentialite/page.tsx src/app/cgu/page.tsx src/app/cgv/page.tsx`
- `pnpm typecheck`
