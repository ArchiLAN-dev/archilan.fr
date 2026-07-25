# Story 17.22: Per-Field Masking for Event-Session and Weekly-Run Connection Panels

## Story

**As a** player (possibly streaming) on an event session or a weekly run,
**I want** the connection fields masked per field with always-available copy buttons,
just like on private runs,
**So that** the streamer-safe behaviour is consistent across every connection panel.

## Context

Follow-up to story 17.21 (owner request: "fait pareil pour les sessions d'événement et les
runs hebdo"). Stories 17.17/17.21 already flagged these two panels as separate inline
implementations left for a follow-up. The per-field row from 17.21 is extracted into a shared
`SecretField` component and applied to all three panels.

## Status

done

## Acceptance Criteria

**AC1:** `SecretField` (per-field mask with fixed-length bullet placeholder, Eye/EyeOff
toggle, copy button working in both states, masked default on every mount, value absent from
the markup while masked) is extracted to `src/components/secret-field.tsx` and reused by
`ConnectionDetails` (private runs) with no behaviour change there.

**AC2:** Event sessions - `RunningConnectionCard` in `session-connection-gate.tsx` renders
Adresse/Port/Mot de passe as masked `SecretField` rows. The "Tout copier" button is kept:
it copies without displaying anything, so it is streamer-safe and stays the fastest path.

**AC3:** Weekly runs - the connection info block in `weekly-run-slot-page.tsx` renders
Host/Port/Password as masked `SecretField` rows (password row still omitted when absent).

**AC4:** Non-secret fields are untouched: the event-session slot names (`SlotCard`) and every
other panel remain visible as before.

**AC5:** A regression test on `SecretField` asserts the initial render: value not in the
markup, placeholder present, copy and reveal controls present. The 17.21 `ConnectionDetails`
test keeps passing unchanged.

**AC6:** Frontend quality gates pass: `pnpm gates`.

## Tasks / Subtasks

- [x] Task 1: Extract `SecretField` + mask constant to `src/components/secret-field.tsx`;
  refactor `connection-details.tsx` to import it.
- [x] Task 2: `session-connection-gate.tsx` - replace `PlayerConnectionField` with
  `SecretField` (dropping the now-unused local component), keep `copyAll`.
- [x] Task 3: `weekly-run-slot-page.tsx` - replace the inline Host/Port/Password spans with
  `SecretField` rows (dropping the now-unused `CopyButton` if nothing else uses it).
- [x] Task 4: `secret-field.test.tsx` initial-render regression test.
- [x] Task 5: Frontend quality gates.

## Dev Notes

### Stacked on 17.21

Branched from `feature/epic-17-story-21-per-field-connection-reveal` (PR #396 still open) so
`SecretField` is extracted from the real 17.21 code instead of duplicated; the PR targets the
17.21 branch and retargets to `develop` when #396 merges.

### Why "Tout copier" survives on the event panel

It writes to the clipboard without rendering anything, so it cannot leak on stream - and for
an on-site event it is the fastest path to the client. Removing it would be a regression
orthogonal to this story's goal.

## File List

- `frontend/src/components/secret-field.tsx` - added
- `frontend/src/components/secret-field.test.tsx` - added
- `frontend/src/features/personal-runs/connection-details.tsx` - modified (import shared)
- `frontend/src/features/events/session-connection-gate.tsx` - modified
- `frontend/src/features/weekly-runs/weekly-run-slot-page.tsx` - modified

## Change Log

| Date | Change |
|------|--------|
| 2026-07-25 | Story created and implemented (owner request, follow-up to 17.21) |
