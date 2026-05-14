# Story 16.6: Frontend - Join via Invite Link and Participant View

**Status:** review
**Epic:** 16 - Personal Runs - Private User-Created Archipelago Games
**Date:** 2026-05-12

## Story

As an invited user,
I want to join a personal run by following the invite link,
So that I can participate in the game and see connection details.

## Acceptance Criteria

1. **Unauthenticated** visitor navigates to `/runs/join/{inviteToken}`:
   - Frontend calls `GET /api/v1/runs/invite/{inviteToken}/preview` (public, no auth) → receives `{ title, ownerName, participantCount, status }`.
   - Page displays the run title, owner name, participant count, and a "Se connecter / créer un compte" CTA.
   - After authentication, auto-redirects to `/runs/join/{inviteToken}` to complete the join.
   - If the preview endpoint returns 404, show "Lien invalide ou partie annulée" with homepage link.

2. **Authenticated** user navigates to `/runs/join/{inviteToken}`:
   - Frontend calls `GET /api/v1/runs/join/{inviteToken}` client-side on mount (not SSR, to avoid crawler joins).
   - On 200 → redirect to `/runs/{runId}`.
   - On 404 → show "Lien invalide ou partie annulée" with homepage link.

3. **Participant view** of `/runs/{runId}` (when `isOwner === false`):
   - Shows title, status badge, participant list (`{ userId, joinedAt }`), and connection details when `active`.
   - No game config section, no Démarrer/Arrêter buttons, no invite link panel.
   - "Reprendre" button absent (only owner can restart).

4. Join API call triggered client-side (not SSR) to avoid crawler joins.

## Tasks / Subtasks

- [x] Task 1: Public preview endpoint `GET /api/v1/runs/invite/{inviteToken}/preview`
  - [x] No authentication required
  - [x] Returns `{ title, ownerName, participantCount, status }` - no sensitive fields (no connectionPassword, no inviteToken)
  - [x] 404 if token not found or run is `cancelled`

- [x] Task 2: Join page (`src/app/runs/join/[inviteToken]/page.tsx`)
  - [x] Always call preview endpoint first (public, SSR-safe) to get title for display
  - [x] If unauthenticated: render invite card (title, ownerName, participantCount) + login/register CTA with `redirect` param
  - [x] If authenticated: additionally trigger join call client-side on mount; redirect to `/runs/{runId}` on success

- [x] Task 3: Participant view of run detail page (`src/app/runs/[runId]/page.tsx`)
  - [x] Use `isOwner` from payload to conditionally show/hide owner-only controls
  - [x] Show participant list (userId + joinedAt; avatars if available)
  - [x] Show connection details when status `active`, otherwise status-appropriate message

## Dev Notes

- The preview endpoint is designed to be public and safe: it returns no sensitive data. It allows the join page to be informative before authentication.
- Authentication state detection: use auth context / cookie presence check client-side; do not rely on SSR session for the join trigger.
- `isOwner` is already part of the `GET /api/v1/runs/{runId}` payload (defined in Story 16.2) - no additional computation needed in the frontend.
- Participant avatars: if user profile has an avatar URL (from user account), include it in the participants payload. Otherwise show initials. Graceful degradation required.

### References

- Story 16.2: join API endpoint + `isOwner` in payload + preview endpoint (add to Story 16.2 tasks)
- Story 16.5: run detail page (owner view - extend for participant view)

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Completion Notes List

- Task 1 (preview endpoint) was already implemented as part of the backend work for Story 16.2/16.4 - endpoint existed at `GET /api/v1/runs/invite/{inviteToken}/preview`.
- Join page uses a two-`useEffect` pattern: first fetches preview (public, always), second triggers join client-side only when authenticated - prevents crawler joins.
- Route page (`page.tsx`) is a server component that awaits `params` and passes scalar `inviteToken` to `JoinPage` client component.
- Participant view: `ParticipantList` sub-component renders initials-based avatar (first 2 chars of `userId`) with `joinedAt` date in French locale; no avatars in `PersonalRunParticipant` type - graceful degradation via initials.
- Non-active status messages replace connection details with context-appropriate text.
- Pre-existing TypeScript build failures in `reachability/fanfare-picker.tsx` - zero new errors introduced.

### Debug Log

(none)

### File List

- `frontend/src/features/personal-runs/join-page.tsx` (new)
- `frontend/src/app/(public)/runs/join/[inviteToken]/page.tsx` (new)
- `frontend/src/features/personal-runs/personal-run-detail-page.tsx` (modified - participant view enhanced)

### Change Log

- 2026-05-12: Story 16.6 implemented - join page with auth-aware flow, participant list with initials avatars, status-conditional connection details for non-owners.
