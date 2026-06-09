# Story 27.6: Admin session-config form (frontend)

Status: ready-for-dev

## Story

As an ArchiLAN admin,
I want a form to view and edit the server/generation config for each session type,
so that I can set the policy applied to private, event and weekly runs without touching the database.

## Context

Frontend for the API from 27.2. An admin page with one editable profile per session type
(private / event / weekly), fields grouped into **Serveur** and **Génération**, validated, saved via the
config API. Depends on 27.2 (API contract). The per-session override UI is 27.7.

## Acceptance Criteria

1. An admin page (e.g. `/admin/sessions/config` under the `(admin)` layout) presents the three session
   types as tabs/sections: **Sessions privées**, **Événements**, **Weekly runs**.
2. Each profile shows two groups: **Serveur** (release / collect / remaining modes [selects with the AP
   value labels], disable item cheat [toggle], hint cost [0–100], location check points [int],
   countdown mode [select], auto shutdown [int seconds], compatibility [Casual 2 / Tournoi 0],
   join password [text, optional]) and **Génération** (plando options [multi-select bosses/items/texts/
   connections], race [toggle], spoiler [select 0–3]).
3. Loads the current profile via `GET /admin/session-config/{type}` and saves via
   `PUT /admin/session-config/{type}` with a TanStack mutation; on success the query is invalidated and
   a success state shown; on 422 the field-level domain error is surfaced inline.
4. Client-side validation mirrors the domain constraints (enum membership, ranges) so obvious mistakes
   are caught before the request; the server remains authoritative.
5. Data fetching/mutation follow frontend/AGENTS.md: typed `fetch*`/type guards in a feature API module
   (`src/features/admin/admin-session-config-api.ts`), `apiFetch`, no `process.env`, TanStack Query with
   explicit `staleTime`, no `any`, no API-boundary `as` casts.
6. Gates green: `pnpm typecheck`, `pnpm lint`, `pnpm build`.

## Tasks / Subtasks

- [ ] Task 1 — API module `admin-session-config-api.ts`: types + guards + `fetchSessionConfig(type)` /
  `updateSessionConfig(type, payload)` returning typed results (AC: 3, 5).
- [ ] Task 2 — Page route under `(admin)` + a client component with the three-tab layout (AC: 1).
- [ ] Task 3 — Server group form controls + Generation group controls, with labels matching AP semantics
  (AC: 2). Reuse existing admin form primitives/styles (see `admin-weekly-run-*`).
- [ ] Task 4 — Load + mutation wiring, invalidation, inline 422 errors, client validation (AC: 3, 4).
- [ ] Task 5 — Add a nav entry in the admin shell; run gates (AC: 6).

## Dev Notes

- **Mirror existing admin patterns:** `src/features/admin/admin-weekly-runs-api.ts` (typed fetch + guards
  + mutations) and `admin-weekly-run-*` components (form/state/spinner). No toast system in the app —
  use inline success/error state (as done for the per-template generate button, story 23.14).
- **Selects use the AP value strings** as values, French labels for display. Compatibility shown as
  "Casual" (2) / "Tournoi" (0). Spoiler 0–3 with short descriptions.
- The exact field set + defaults come from the 27.1 default table / 27.2 API response — render whatever
  the API returns; don't hardcode defaults client-side beyond validation bounds.
- frontend/AGENTS.md: Server Components by default, `"use client"` only for the interactive form;
  `staleTime` explicit; env via `src/lib/env.ts`.

### Project Structure Notes

- New: `src/app/(admin)/admin/sessions/config/page.tsx` (thin) + `src/features/admin/admin-session-config-*`.
- Override UI deliberately deferred to 27.7.

### References

- [Source: _bmad-output/planning-artifacts/epic-27-configurable-session-server-options.md]
- [Source: _bmad-output/implementation-artifacts/27-2-session-config-persistence-admin-api.md (API contract)]
- [Source: frontend/src/features/admin/admin-weekly-runs-api.ts (typed fetch + guards + mutation pattern)]
- [Source: frontend/AGENTS.md]

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

## Change Log

| Date       | Change |
|------------|--------|
| 2026-06-09 | Story created from epic 27 plan (admin form). |
