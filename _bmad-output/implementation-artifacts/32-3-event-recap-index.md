# Story 32.3: Per-event recap index ("toutes les parties de cet event")

Status: review

## Story

As a visitor of a public event page,
I want the list of that event's finished multiworld sessions with links to their recaps,
so that I can browse every party of the event and share them - the discovery surface Story 32.1
deliberately deferred.

Third story of Epic 32 - *Récap de partie*. Completes 32.1's AC #7 (the "link from the event recap
surface" half that was deferred because an event has N sessions and the surface needed an index).

Depends on: 32.1 (recap projection + `/parties/[sessionId]` page). 32.2 (share card) is independent.

## Context

- The public event detail endpoint is `GET /api/v1/events/{eventId}` (`AdminEventController::publicShow`
  → `PublicEventCatalog::get`, resolves via `EventRepositoryInterface::findById`). The frontend event
  page (`src/app/(public)/evenements/[eventSlug]/page.tsx`, Server Component, `revalidate = 300`)
  already holds the event's real `id` in its `PublicEvent` payload - the new index endpoint is keyed
  by that same `eventId`, exactly like the existing `GET /api/v1/events/{eventId}/session/results`
  (`SessionResultsController`, Sessions context).
- Everything needed already exists in the `Sessions` context:
  `SessionRepositoryInterface::findByEventId`, `SessionRecapRepositoryInterface::findBySessionId`,
  `RunResultsQuery` (ranked slots, handles released/invalidated), `Session::STATUS_FINISHED`,
  `EventRepositoryInterface::findById` + `Event::isPublic()`.
- `SessionRecapQuery` (32.1) is the privacy model to mirror: recap surfaces exist only for a
  **finished** session attached to a **public** event; sessions without a projection stay invisible.

### Architecture decisions (locked)

1. **New query in `Sessions/Application/Query`: `EventRecapIndexQuery`** (CQRS naming: noun +
   context). `execute(string $eventId): ?array` - `null` when the event is unknown or not public
   (controller 404s); otherwise the list (possibly empty) of the event's finished sessions that
   **have a recap projection**, sorted by `finishedAt` desc. Entry shape:
   `{sessionId, startedAt, finishedAt, durationSeconds, playerCount, winner: {playerName, game}|null}`.
2. **Reuse `RunResultsQuery` per session** for duration/slots (an event has a handful of sessions -
   no bulk query needed, and ranking semantics stay identical to the recap page). `playerCount` =
   count of its `slots`; `winner` = the first ranked slot whose `goalReachedAt` is non-null, else null.
3. **New public controller in `Sessions/Presentation/Controller`:
   `EventRecapIndexController` at `GET /api/v1/events/{eventId}/parties`** - no auth, one
   Application call (AC-P3/P4), `JsonResponse` only: 404 (`not_found`) when the query returns null,
   else `{"data": [...]}`.
4. **Frontend fetch lives in `features/recap/recap-api.ts`** (AC-API1, same module as the other
   recap reads): `getEventRecapIndex(eventId)` with a type-guard, returns `[]` on any error (an
   event page must never break because the index failed). Server-side fetch from the event page
   (AC-NX1 - no client fetching), inheriting the page's ISR.
5. **The section renders only when the list is non-empty.** No empty-state block on the event page
   (most events have no recap yet - pre-32.1 sessions have no projection, story 32.1 decision).

## Acceptance Criteria

1. **Query.** `EventRecapIndexQuery` (final readonly, Application/Query, repositories + reused
   `RunResultsQuery` injected - no DBAL/EntityManager per AC-A2) behaves exactly as decision #1/#2:
   null for unknown or non-public event; excludes non-finished sessions and finished sessions
   without a recap projection; `finishedAt` desc ordering; winner/playerCount from the ranked slots.
2. **Endpoint.** `GET /api/v1/events/{eventId}/parties`: 404 with the standard error envelope for
   unknown/non-public event; otherwise 200 `{"data": list}` (empty list allowed). No auth,
   `ROLE_MEMBER` gates nothing.
3. **Frontend fetcher.** `getEventRecapIndex` in `features/recap/recap-api.ts`: typed
   `EventRecapIndexEntry[]`, full-shape type guard (AC-TS3/TS4), returns `[]` on error/invalid
   shape, `cache: "no-store"` like `getSessionRecap` (the page's ISR provides the caching).
4. **Event page section.** On `/evenements/[eventSlug]`, when at least one entry exists: a
   "Les parties de cet événement" section listing each entry - date (fr-FR), winner (player + game)
   when present, player count, duration (shared `formatDuration` from `recap-format.ts`) - each row
   linking to `/parties/{sessionId}`. Section absent when the list is empty. Layout follows the
   page's existing section idiom (`rounded-lg border border-border`, design tokens, mobile-first).
5. **Tests.** api/: functional test for the endpoint - 200 with only finished+projected sessions
   (a non-finished session and a finished-but-projection-less session in fixtures must be
   excluded), entry shape, ordering, 404 unknown event, 404 non-public event. frontend/: jest test
   for the payload type guard (valid entry, invalid entry dropped/rejected, malformed payload).
6. **Gates green** on both sides: `composer gates` (api) and `pnpm gates` (frontend).

## Tasks / Subtasks

- [x] **T1 - `EventRecapIndexQuery` (AC #1).** Sessions/Application/Query. Mirror
  `SessionRecapQuery`'s event gating (`isPublic()`, not `isVisiblePublicly()`). Return typed
  array-shape documented in phpdoc (phpstan level max).
- [x] **T2 - `EventRecapIndexController` (AC #2).** Sessions/Presentation/Controller, modeled on
  `SessionResultsController` (same route family `events/{eventId}/...`, same error envelope via
  `ApiAccessGuard::errorResponse`).
- [x] **T3 - Functional test (AC #5 api).** Model on `tests/Functional/SessionRecapEndpointTest.php`
  (it already builds a finished session + public event + persisted projection - reuse its setup
  recipe, including the schema entity list). Run via `api/scripts/test-isolated.sh` locally.
- [x] **T4 - Frontend fetcher + guard + test (AC #3, #5 frontend).** `EventRecapIndexEntry` type,
  `isEventRecapIndexPayload` guard, `getEventRecapIndex`; jest test colocated
  (`recap-api.test.ts` style: call the guard directly, no fetch mocking needed).
- [x] **T5 - Event page section (AC #4).** Server-side call with `event.id`, render the section
  between the practical-details section and the photo gallery. Reuse `formatDuration`
  (`features/recap/recap-format.ts`) and the fr-FR date idiom already used on the page.
- [x] **T6 - Gates (AC #6).** `composer gates` + `pnpm gates`, fix everything red.

## Dev Notes

### API specifics

- Controller obeys AC-P1/P2/P3/P4/P5: no EM/Connection, no business logic, exactly one Application
  call, `JsonResponse`.
- `RunResultsQuery::execute($sessionId)` returns
  `array{eventName, startedAt, finishedAt, durationSeconds, slots}` or null - treat a null result
  as "skip this session" (defensive; it should not happen for a finished session).
- Date strings: format with `\DateTimeInterface::ATOM` like the rest of the recap surface.
- cs-fixer: Yoda conditions, `declare(strict_types=1)` in every new file.
- phpstan level max: no mixed casts; narrow every array access from `RunResultsQuery` output per
  its documented shape.
- Functional tests: schema is built per test class via `SchemaTool` - copy the entity-class list
  from `SessionRecapEndpointTest` (it includes `Session`, `SessionSlot`, `SessionRecap`, `Event`,
  and friends; a missing class means silent FK failures, AC-T8).
- Local phpunit: shared-DB flake exists (memory + CLAUDE.md) - use `api/scripts/test-isolated.sh`
  for the full-suite run; CI is authoritative.

### Frontend specifics

- The event page is a **Server Component** with `export const revalidate = 300` - call
  `getEventRecapIndex(event.id)` from the page after `getPublicEvent` resolves; no `useEffect`, no
  TanStack (AC-NX1/API4 applies to client fetching - this is a server read like `getSessionRecap`).
- `PublicEvent.id` is the identifier the API expects (the `[eventSlug]` URL param resolves through
  `findById` - do not invent a slug lookup).
- Type guards live next to the fetch (AC-TS4), `type` over `interface` (AC-TS5), no `any`, no `as`
  at the boundary (AC-TS2/TS3).
- Keys: `sessionId` is the natural stable key for rows (AC-KEY1/KEY2).
- Styling: Tailwind tokens only (`bg-surface`, `border-border`, `text-foreground`,
  `text-muted-foreground`, `font-heading`), mobile-first (AC-CSS1-3).
- fr-FR dates: the page already formats dates - follow its local idiom
  (`toLocaleDateString("fr-FR", ...)`); this is server-rendered so locale output is deterministic
  for ISR.

### Previous story intelligence (32.1 / 32.2)

- Pre-existing finished sessions have **no projection** → they must simply not appear in the index
  (decision #5); no backfill in this story.
- The unconditional "Voir le récap" link on `/resultats` (32.1 deferred note) is **out of scope**
  here - it needs a flag on the results payload, distinct surface.
- `formatDuration` is shared in `features/recap/recap-format.ts` since 32.2 - reuse, do not
  duplicate (the copies in admin/community/runs modules stay untouched).
- Typography: no em-dashes anywhere (copy, code, docs).

### Project Structure Notes

- api/ layer sub-folder taxonomy is enforced: query in `Application/Query/`, controller in
  `Presentation/Controller/` - no flat files.
- frontend/ additions stay inside `features/recap/` + the existing event page file; no default
  exports in `features/` (AC-CO3).

### References

- [Source: _bmad-output/planning-artifacts/epics/epic-32-session-recap.md#Proposed stories] (32.3)
- [Source: _bmad-output/implementation-artifacts/32-1-public-session-recap.md#Dev Agent Record] (deferred AC #7, projection model)
- [Source: api/src/Sessions/Application/Query/SessionRecapQuery.php] (privacy gating to mirror)
- [Source: api/src/Sessions/Presentation/Controller/SessionResultsController.php] (controller model)
- [Source: frontend/src/app/(public)/evenements/[eventSlug]/page.tsx] (target page + section idiom)
- [Source: frontend/src/features/recap/recap-api.ts] (fetcher + guard idiom)
- [Source: api/CLAUDE.md] / [Source: frontend/AGENTS.md] (standards + gates)

## Dev Agent Record

### Agent Model Used

claude-fable-5 (Claude Code)

### Debug Log References

### Completion Notes List

- **TDD both sides.** api/: functional test written first (red: "No route found"), then query +
  controller (green: 4 tests, 29 assertions, isolated DB `archilan_test_recapidx`). frontend/:
  guard test written first (red: module has no export), then type + guard + fetcher (green).
- **Ordering is computed from the entity timestamps**, not the ATOM strings: entries are built as
  `[timestamp, entry]` tuples, usorted desc, then mapped - avoids any string-comparison timezone
  trap and keeps the phpdoc shape clean.
- **Winner resolution is defensive at the phpstan-max level:** `RunResultsQuery` slots are
  `array<string, mixed>`, so `goalReachedAt`/`playerName`/`game` are narrowed with `is_string`
  before use; first ranked slot with a non-null goal wins (RunResultsQuery pre-sorts by completion).
- **Section label edge case:** a party without any date renders "Partie de l'événement" instead of
  a broken "Partie du" prefix.
- The section sits between the news-recap block and the photo gallery on the event page, renders
  nothing when the index is empty, and the fetcher returns `[]` on any failure so the event page
  can never break because of the index.

### File List

**api/ (new)**
- `src/Sessions/Application/Query/EventRecapIndexQuery.php`
- `src/Sessions/Presentation/Controller/EventRecapIndexController.php`
- `tests/Functional/EventRecapIndexEndpointTest.php`

**frontend/ (new)**
- `src/features/recap/recap-api.test.ts`

**frontend/ (modified)**
- `src/features/recap/recap-api.ts` (`EventRecapIndexEntry`, `isEventRecapIndexPayload`, `getEventRecapIndex`)
- `src/app/(public)/evenements/[eventSlug]/page.tsx` (section "Les parties de cet événement")

## Change Log

| Date       | Change |
|------------|--------|
| 2026-07-25 | Story implemented: public per-event recap index endpoint (`GET /api/v1/events/{eventId}/parties`, Sessions context) + event page section linking each finished-and-projected session to its `/parties/{id}` recap. TDD on both sides. |
