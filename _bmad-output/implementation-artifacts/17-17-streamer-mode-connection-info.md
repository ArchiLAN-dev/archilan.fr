# Story 17.17: Streamer Mode — Hide Private-Run Connection Info by Default

## Story

**As a** player streaming their private (personal) run,
**I want** the Archipelago connection details (host, port, password) hidden by default with a button to
reveal them,
**So that** I don't accidentally expose my server credentials on stream.

## Context

Reported as feature #1 in `FEATURE-BACKLOG.md`. In a private run, the connection info is rendered by
`ConnectionDetails` (`frontend/src/features/personal-runs/connection-details.tsx`), shown on the
personal-run detail page for both the owner and participants while the run is `active`. The host/port/
password were always visible — a hazard for streamers.

## Status

done

## Acceptance Criteria

**AC1:** In `ConnectionDetails`, the host/port/password/admin-password values are **hidden by default**
(not present in the rendered output), behind an "Afficher les options de connexion" button.

**AC2:** Clicking the reveal button shows the connection fields (with their existing copy-to-clipboard
buttons); a "Masquer" control hides them again.

**AC3:** The hidden state is the default on **every** page load (not persisted), so a streamer is never
accidentally exposed after a reload or navigation.

**AC4:** Applies to both the owner and participant views of a private run (both render the same
`ConnectionDetails` component — no per-call change needed).

**AC5:** Scope is the private-run connection panel only. Event-session and weekly-run connection panels
are out of scope (separate components; noted as follow-ups).

**AC6:** Frontend quality gates pass: `pnpm typecheck`, `pnpm lint`, `pnpm build`.

## Tasks / Subtasks

- [x] Task 1: `connection-details.tsx` — add a `revealed` state (default `false`); render the fields
  only when revealed, otherwise show an explanatory note + the reveal button.
- [x] Task 2: Add a "Masquer" toggle (EyeOff) in the header when revealed; reveal button uses an Eye
  icon, design-token styling (border-border / hover:border-accent).
- [x] Task 3: Frontend quality gates.

## Dev Notes

### Why not persisted

A localStorage "remember revealed" would defeat the purpose: a streamer who revealed once would be
exposed on every later load. Defaulting to hidden on each mount (`useState(false)`) is the safe choice;
the small click cost for non-streamers is acceptable and is the whole point of the feature.

### Reuse

`ConnectionDetails` is used by both the owner and participant views in `personal-run-detail-page.tsx`,
so the change covers both with no call-site edits. The event-session (`session-connection-gate.tsx`)
and weekly-run (`weekly-run-slot-page.tsx`) panels are separate, inline implementations; extending the
streamer toggle to them is a follow-up.

## File List

- `frontend/src/features/personal-runs/connection-details.tsx` — modified

## Change Log

| Date | Change |
|------|--------|
| 2026-06-21 | Story created and implemented (feature #1 from FEATURE-BACKLOG) |
