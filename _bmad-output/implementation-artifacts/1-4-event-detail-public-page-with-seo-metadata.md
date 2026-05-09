# Story 1.4: Event Detail Public Page with SEO Metadata

Status: done

## Story

As a visitor,
I want a detailed public event page,
so that I can understand an event before registering or reading its recap.

## Acceptance Criteria

1. Given an event exists and is published, when a visitor opens its public detail page, then the page shows event title, type, dates, location, description, availability, and appropriate CTA.
2. Completed events can show recap and VOD links when available.
3. Unpublished draft events are not publicly accessible.
4. The page includes Open Graph, Twitter Card, canonical metadata, and `schema.org/Event` where applicable.
5. Invalid or missing event slugs return a proper not-found state.

## Tasks / Subtasks

- [x] Extend mocked public event source for detail pages (AC: 1, 2, 3)
  - [x] Add typed detail fields to public event data.
  - [x] Add lookup helpers that only return published public mock events.
  - [x] Avoid backend/API and admin draft implementation.
- [x] Implement event detail route (AC: 1, 2, 5)
  - [x] Add `/evenements/[eventSlug]` route.
  - [x] Render title, type, date, location, description, availability, capacity, and CTA.
  - [x] Render recap and VOD links for completed events when available.
  - [x] Add a local not-found state for invalid event slugs.
- [x] Implement SEO metadata and structured data (AC: 4)
  - [x] Add canonical, Open Graph, and Twitter Card metadata.
  - [x] Add `schema.org/Event` JSON-LD for public events.
  - [x] Generate static params from published public mock events.
- [x] Validate and handoff (AC: 1, 2, 3, 4, 5)
  - [x] Run frontend lint, type-check, and build.
  - [x] Confirm no backend files were changed.
  - [x] Update this story file with commands run, validation results, and file list.

## Dev Notes

This story builds on Story 1.3 mock public event data. It must not introduce real API calls, persistence, admin event management, registration forms, draft editing, or authentication behavior. Draft events are represented by absence from the exported public event collection.

Use the existing public shell and design tokens from Story 1.1, the landing tone from Story 1.2, and the `EventCard` data contract from Story 1.3.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-1.4-Event-Detail-Public-Page-with-SEO-Metadata]
- [Source: _bmad-output/implementation-artifacts/1-1-public-shell-navigation-and-design-tokens.md]
- [Source: _bmad-output/implementation-artifacts/1-2-landing-page-with-archilan-identity-and-archipelago-explainer.md]
- [Source: _bmad-output/implementation-artifacts/1-3-public-event-listings-and-event-cards.md]

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- Story 1.3 follow-up state was revalidated with `pnpm lint`, `pnpm typecheck`, and targeted searches before starting Story 1.4.
- Used the existing Story 1.3 mock event source as the published public collection; draft/unpublished behavior is modeled by absence from `publicEvents`.
- Implemented the dynamic route with async `params` to match the current Next.js App Router contract.
- Production build confirmed `/evenements/[eventSlug]` is SSG and generated from `generateStaticParams`.

### Completion Notes List

- Added `/evenements/[eventSlug]` public event detail pages.
- Extended `PublicEvent` mock data with descriptions, optional recap text, optional VOD URL, and public lookup helpers.
- Detail pages render title, type, date, location, description, availability, capacity, status copy, and state-appropriate CTA.
- Completed events render recap copy and VOD action when available.
- Invalid or unpublished slugs resolve through `notFound()` and a local not-found page.
- Added canonical, Open Graph, Twitter Card metadata, and `schema.org/Event` JSON-LD.
- No API, backend, persistence, admin, draft editing, or registration implementation was added.

### Validation Results

- `pnpm lint` - passed.
- `pnpm typecheck` - passed.
- `pnpm build` - passed.
- Build output includes SSG `/evenements/[eventSlug]` with six generated public event paths.
- Search confirmed metadata, Twitter/Open Graph, JSON-LD, `schema.org`, `generateStaticParams`, `generateMetadata`, and `notFound()` are present.
- Search confirmed no `fetch(`, `axios`, `api/v1`, old members-only CTA, old 1.3 date, or old `xl:grid-cols-3` remains in event feature/page files.
- No backend/API files were changed for this story.

### File List

- `frontend/src/app/evenements/[eventSlug]/page.tsx`
- `frontend/src/app/evenements/[eventSlug]/not-found.tsx`
- `frontend/src/features/events/event-types.ts`
- `frontend/src/features/events/mock-events.ts`
- `_bmad-output/implementation-artifacts/1-4-event-detail-public-page-with-seo-metadata.md`

### Change Log

- 2026-04-25: Implemented public event detail route with SEO metadata, JSON-LD structured data, local not-found state, and enriched public mock event data.
