# Story 1.3: Public Event Listings and Event Cards

Status: done

## Story

As a visitor,
I want to browse upcoming and past events,
so that I can understand what ArchiLAN organizes and decide whether to join.

## Acceptance Criteria

1. Given event listing data is available from the API or mocked public endpoint, when a visitor opens the event listing or landing event section, then upcoming events display title, type, date, location, availability state, and CTA.
2. Past events display recap availability and key statistics where available.
3. `EventCard` supports open, upcoming, full, completed, and members-only states.
4. Empty event lists show the documented empty-state copy and Twitch action.
5. Event cards are responsive as 1-up mobile, 2-up tablet, and 3-up desktop.

## Tasks / Subtasks

- [x] Create mocked public event source (AC: 1, 2, 3)
  - [x] Add typed event listing data in the frontend.
  - [x] Include examples for open, upcoming, full, completed, and members-only states.
  - [x] Avoid backend/API changes.
- [x] Implement EventCard (AC: 1, 2, 3, 5)
  - [x] Display title, event type, date, location, availability state, and CTA.
  - [x] Display past event recap availability and key statistics where available.
  - [x] Use color plus text/icon labels for states.
- [x] Implement event listing page (AC: 1, 2, 4, 5)
  - [x] Add `/evenements` public route.
  - [x] Render upcoming and past event sections.
  - [x] Add empty-state component with Twitch action.
  - [x] Use responsive grid: 1-up mobile, 2-up tablet, 3-up desktop.
- [x] Validate and handoff (AC: 1, 2, 3, 4, 5)
  - [x] Run frontend lint, type-check, and build.
  - [x] Confirm no backend files were changed.
  - [x] Update this story file with commands run, validation results, and file list.

## Dev Notes

This story implements public listing UI only. It must not implement real API calls, persistence, admin event management, registration flow, event detail SEO, or unpublished/draft access checks.

### Required States

- `open`: CTA `Choisir mes jeux`
- `upcoming`: CTA `Voir l'événement`
- `full`: CTA `Liste d'attente`
- `completed`: CTA `Voir le récap`
- `members-only`: label `Réservé aux membres`

### Empty State Copy

Use documented copy: `Aucun événement prévu pour le moment - suis-nous sur Twitch pour être le premier informé`.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-1.3-Public-Event-Listings-and-Event-Cards]
- [Source: _bmad-output/planning-artifacts/ux-design-specification.md#Empty-States]
- [Source: _bmad-output/planning-artifacts/ux-design-specification.md#EventCard]
- [Source: _bmad-output/implementation-artifacts/1-1-public-shell-navigation-and-design-tokens.md]
- [Source: _bmad-output/implementation-artifacts/1-2-landing-page-with-archilan-identity-and-archipelago-explainer.md]

## Dev Agent Record

### Agent Model Used

Codex GPT-5

### Debug Log References

- Before starting Story 1.3, `pnpm lint` exposed Story 1.2 review corrections with unescaped apostrophes. Rewrote `frontend/src/app/page.tsx` with JSX-safe entities and revalidated lint/typecheck.
- Implemented event data as frontend mock data only; no backend/API files were changed.
- Centralized the empty-state Twitch URL through `src/lib/external-links.ts` instead of hardcoding it in the component.

### Completion Notes List

- Added `/evenements` public route.
- Added typed event data and mock public listings for upcoming and past events.
- Added `EventCard` with support for `open`, `upcoming`, `full`, `completed`, and `members-only` states.
- Event cards display title, type, date, location, status label, CTA, and capacity when available.
- Past events display recap availability and key statistics.
- Added documented empty-state copy with Twitch action.
- Event grids use `grid`, `md:grid-cols-2`, and `xl:grid-cols-3`, giving 1-up mobile, 2-up tablet, and 3-up desktop.
- No API calls, persistence, admin behavior, registration flow, or backend code were added.

### Validation Results

- `pnpm lint` - passed.
- `pnpm typecheck` - passed.
- `pnpm build` - passed.
- Build output includes static `/evenements` route.
- Search confirmed all event states exist in mock data and card status mapping.
- Search confirmed empty-state Twitch action and responsive grid classes.
- Search for `fetch(`, `axios`, `api/v1`, and `use client` in event feature/page files returned no matches.
- Recent API file scan returned no files modified by this story.

### File List

- `frontend/src/app/page.tsx`
- `frontend/src/app/evenements/page.tsx`
- `frontend/src/features/events/event-types.ts`
- `frontend/src/features/events/mock-events.ts`
- `frontend/src/features/events/event-card.tsx`
- `_bmad-output/implementation-artifacts/1-3-public-event-listings-and-event-cards.md`

### Change Log

- 2026-04-25: Implemented public event listing route, typed mock event data, EventCard state support, responsive grids, and event empty state.
