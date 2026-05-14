# Story 16.5: Frontend - Run Creation and Dashboard

**Status:** review
**Epic:** 16 - Personal Runs - Private User-Created Archipelago Games
**Date:** 2026-05-12

## Story

As an authenticated user,
I want a dashboard to create and manage my personal runs,
So that I can organize my private games from the site.

## Acceptance Criteria

1. `/runs` (auth required, redirect to `/login?redirect=/runs` if not): lists personal runs grouped by status (active first, then starting/stopping, then idle, then draft, then cancelled collapsed). "Créer une partie" button visible.
2. Creation form: title (required, **max 80 chars** - enforced both client-side and via API validation). On success → redirect to `/runs/{runId}`.
3. `/runs/{runId}` (owner, i.e. `isOwner === true` in payload):
   - Header: title, `PersonalRunStatusBadge`, "Copier le lien d'invitation" button (copies `{siteUrl}/runs/join/{inviteToken}`, toast confirmation).
   - If `draft`: game config section + "Démarrer la partie" button.
   - If `starting`: spinner + "Démarrage en cours..." (polling every 3s, button disabled).
   - If `active`: `ConnectionDetails` component (host, port, password - each with copy button).
   - If `stopping`: spinner + "Arrêt en cours..." (polling every 3s).
   - If `idle`: "Reprendre la partie" button (calls Story 17.3 restart endpoint).
4. "Arrêter la partie" (when active): AlertDialog confirmation → `POST .../stop` → status transitions to `stopping` (async, poll).
5. Frontend in `src/features/personal-runs/`.

## Tasks / Subtasks

- [x] Task 1: `/runs` list page (`src/app/runs/page.tsx`)
  - [x] Fetch `GET /api/v1/runs/mine` via useEffect/apiFetch
  - [x] Group and render runs by status
  - [x] Empty state: "Tu n'as pas encore de partie personnelle" + CTA

- [x] Task 2: Run detail page (`src/app/runs/[runId]/page.tsx`)
  - [x] Fetch `GET /api/v1/runs/{runId}`, show owner-specific view when `isOwner === true`
  - [x] Status-conditional panels (draft / starting / active / stopping / idle)
  - [x] Polling: setInterval 3000ms while status in ['starting', 'stopping'], cleared otherwise
  - [x] "Copier le lien" button with clipboard API + masked URL display
  - [x] "Démarrer" → `POST .../start` → expect 202, begin polling
  - [x] "Arrêter" → AlertDialog → `POST .../stop` → expect 202, begin polling
  - [x] "Reprendre" → placeholder button (disabled, Epic 17 placeholder)

- [x] Task 3: Feature components (`src/features/personal-runs/`)
  - [x] `PersonalRunCard` - list item with status badge, title, created date
  - [x] `PersonalRunStatusBadge` - maps all 7 statuses to label + color (draft: muted, starting: amber animated, active: green, stopping: amber animated, idle: amber, completed: blue, cancelled: red)
  - [x] `ConnectionDetails` - host, port, password fields with individual copy buttons
  - [x] `InviteLinkPanel` - shows masked invite URL + copy button + regenerate button

## Dev Notes

- **Title max 80 chars**: enforced in the creation form (maxLength on input) AND as API validation in `POST /api/v1/runs`. DB column stays `string 120` but the application layer rejects titles > 80 chars.
- Polling stops automatically when status leaves transitional states (`starting`, `stopping`). Use TanStack Query `refetchInterval` as a function: `(query) => ['starting','stopping'].includes(query.state.data?.status) ? 3000 : false`.
- `siteUrl`: read from `src/lib/env.ts` (never `process.env` directly).
- No reference to `restarting` status in this story - that belongs to Epic 17 (Story 17.4).

### References

- `frontend/src/features/admin/admin-event-form.tsx` - form pattern
- `frontend/src/lib/env.ts` - env var access
- Story 17.3: restart endpoint (`POST /api/v1/sessions/{sessionId}/restart`)
- Story 17.4: restart UI patterns (Reprendre button behavior)

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Completion Notes List

- No TanStack Query in this project - polling done with `setInterval`/`clearInterval` in `useEffect`, cleared whenever status leaves transitional states.
- Game config section fetches `/games?perPage=100` lazily (only when the "Modifier" panel is expanded), avoiding an unnecessary load on page mount.
- `InviteLinkPanel` displays a masked URL (first 8 chars + ellipsis) and writes the full URL to clipboard on copy.
- `StopDialog` is a custom modal (no Radix/shadcn in this codebase) matching the inline pattern used elsewhere.
- "Reprendre" button is intentionally disabled (placeholder for Epic 17 / Story 17.3 restart endpoint).
- `env.appUrl` used for invite link construction (never `process.env` directly).
- Pre-existing TypeScript build failure in `reachability/fanfare-picker.tsx` blocks `next build` - unrelated to this story; zero new errors introduced.

### Debug Log

(none)

### File List

- `frontend/src/features/personal-runs/types.ts` (new)
- `frontend/src/features/personal-runs/personal-run-status-badge.tsx` (new)
- `frontend/src/features/personal-runs/personal-run-card.tsx` (new)
- `frontend/src/features/personal-runs/connection-details.tsx` (new)
- `frontend/src/features/personal-runs/invite-link-panel.tsx` (new)
- `frontend/src/features/personal-runs/personal-runs-list-page.tsx` (new)
- `frontend/src/features/personal-runs/personal-run-detail-page.tsx` (new)
- `frontend/src/app/(public)/runs/page.tsx` (new)
- `frontend/src/app/(public)/runs/[runId]/page.tsx` (new)

### Change Log

- 2026-05-12: Story 16.5 implemented - 4 feature components, list page, detail page with full status-conditional UX and polling. Zero new TypeScript errors.
