# Story 4.1: Registration Eligibility and Start Flow

Status: done

## Story

As an authenticated user,
I want to start registration only when an event is available,
So that I understand whether I can register before entering details.

## Acceptance Criteria

1. Given a published event exists, when an authenticated user opens the registration page, then the system checks publication state, registration window, capacity, and access type.
2. Open public events allow the registration flow to start.
3. Closed, unpublished, completed, or unavailable events show a clear terminal or informational state.
4. Anonymous users are redirected to login/signup with return path preserved.
5. All eligibility decisions are enforced server-side.

## Tasks / Subtasks

- [x] Create RegistrationEligibility application service (AC: 1, 2, 3, 5)
  - [x] `check()`: returns eligibility result or null (event not found / not publicly visible).
  - [x] Reasons: `private_event`, `event_completed`, `registration_not_open_yet` (+ opensAt), `registration_closed`, `capacity_full`.
  - [x] Draft events return null (404), not an ineligible reason.
- [x] Add controller endpoint (AC: 1, 5)
  - [x] `GET /api/v1/events/{eventId}/registration-eligibility` - requires `ROLE_USER`.
- [x] Add backend tests (AC: 1, 2, 3, 4, 5)
  - [x] Anonymous gets 401.
  - [x] Authenticated user gets 404 for unknown event.
  - [x] Private event → not eligible, reason: `private_event`.
  - [x] Completed event → not eligible, reason: `event_completed`.
  - [x] Registration not open yet → not eligible, reason: `registration_not_open_yet` + `opensAt`.
  - [x] Registration closed → not eligible, reason: `registration_closed`.
  - [x] Capacity full → not eligible, reason: `capacity_full`.
  - [x] Open public event → eligible: true.
  - [x] Draft event → 404 (not publicly visible).
  - [x] Extend RBAC protectedRequests with new endpoint.
- [x] Frontend: registration start page (AC: 2, 3, 4)
  - [x] Route `/evenements/[eventSlug]/inscription` - dynamic, server-rendered shell + client gate.
  - [x] `RegistrationEligibilityGate` checks auth (redirects to `/connexion?returnTo=...` if 401) then fetches eligibility.
  - [x] Eligible state: shows capacity info + "Commencer l'inscription" (disabled placeholder, Story 4.3).
  - [x] Ineligible states: private, completed, not open yet (with date), closed, full - each with distinct copy and icon.
  - [x] Not found / error states.
- [x] Frontend: return path preserved on login (AC: 4)
  - [x] `connexion/page.tsx` reads `?returnTo` from searchParams, validates it starts with `/`.
  - [x] `LoginForm` accepts `returnTo?: string` and calls `router.push(returnTo)` after successful login.
- [x] Public event detail page: wire CTA for open events to `/evenements/{id}/inscription` (AC: 2).
- [x] Fix 3.8 review finding: update `PublicEventPayload` type in `public-events-api.ts` to include `vodUrl`, `recapPostSlug`, `hasRecap`.
- [x] Validate and handoff
  - [x] Run backend PHPUnit/PHPStan/CS Fixer.
  - [x] Run frontend lint, type-check, and build.

### Review Findings

- [x] [Review][Patch] Registration eligibility treats in-progress events as eligible when the registration window is still open [api/src/Events/Application/RegistrationEligibility.php:60]
- [x] [Review][Patch] Login returnTo validation accepts protocol-relative URLs before router.push [frontend/src/app/connexion/page.tsx:15]

## Dev Notes

The eligibility endpoint lives in the Events bounded context (read-only, no Registrations entity yet). The Registrations context will be introduced in Story 4.3 when seat reservation is implemented.

Draft events are treated as "not found" (404) - this prevents eligibility checks from leaking draft existence to authenticated users.

The `registration_not_open_yet` reason includes `opensAt` so the UI can show a specific date.

The "Commencer l'inscription" button is intentionally disabled in this story - the form is Story 4.3 (Atomic Event Registration Reservation).

Anonymous users: the registration page fetches `/account/profile` first. A 401 triggers an immediate `router.push` to `/connexion?returnTo=<current path>`. The login form then reads `returnTo` from page searchParams (server component) and passes it to the client `LoginForm`, which redirects after login.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-4.1]
- [Source: _bmad-output/implementation-artifacts/3-8-attach-recap-or-vod-to-completed-event.md]

## Dev Agent Record

### Agent Model Used

Claude Sonnet 4.6

### Completion Notes List

- Created `RegistrationEligibility` application service with `check()` and `computeReason()`.
- Added `GET /api/v1/events/{eventId}/registration-eligibility` endpoint (requireUser).
- Added `RegistrationEligibilityTest` with 9 test methods.
- Extended `RbacEnforcementTest.protectedRequests()` with eligibility endpoint.
- Created `RegistrationEligibilityGate` client component with auth redirect, eligibility states, and per-reason copy.
- Created `/evenements/[eventSlug]/inscription/page.tsx` route.
- Updated `LoginForm` with `returnTo` prop + `router.push` redirect after login.
- Updated `connexion/page.tsx` to read `?returnTo` from searchParams and pass it to `LoginForm`.
- Updated public event detail CTA for open events to point to `/inscription`.
- Fixed 3.8 review finding: `public-events-api.ts` `PublicEventPayload` now includes `vodUrl`, `recapPostSlug`, `hasRecap`.

### Validation Results

- `composer test` passed: 113 tests, 1478 assertions.
- `composer phpstan` passed with no errors.
- `composer cs-fixer` passed in dry-run mode.
- `pnpm lint` passed.
- `pnpm typecheck` passed.
- `pnpm build` passed.

### File List

- _bmad-output/implementation-artifacts/4-1-registration-eligibility-and-start-flow.md
- api/src/Events/Application/RegistrationEligibility.php
- api/src/Events/Presentation/AdminEventController.php
- api/tests/Functional/RegistrationEligibilityTest.php
- api/tests/Functional/RbacEnforcementTest.php
- frontend/src/features/events/registration-eligibility-gate.tsx
- frontend/src/features/events/public-events-api.ts
- frontend/src/app/evenements/[eventSlug]/inscription/page.tsx
- frontend/src/app/evenements/[eventSlug]/page.tsx
- frontend/src/app/connexion/page.tsx
- frontend/src/features/auth/login-form.tsx

### Change Log

- 2026-05-01: Implemented Story 4.1 - registration eligibility gate with backend enforcement, frontend state display, and anonymous redirect with return path.
