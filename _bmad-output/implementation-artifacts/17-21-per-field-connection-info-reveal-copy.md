# Story 17.21: Per-Field Reveal and Copy for Private-Run Connection Info

## Story

**As a** player (possibly streaming) on my private run's detail page,
**I want** each connection field (host, port, password, admin password) to be masked
independently, with a copy button that works even while the field is masked,
**So that** I can connect (copy/paste the values) without ever displaying them on stream.

## Context

Follow-up to story 17.17 (streamer mode), requested by the owner: the all-or-nothing global
reveal forces a streamer to display every credential just to copy one of them. The new layout
always renders the field rows; each row masks its value independently (bullet placeholder)
and offers a per-field reveal/hide toggle plus a copy button available in both states - copy
reads the value from props, never from the DOM.

## Status

done

## Acceptance Criteria

**AC1:** `ConnectionDetails` always renders one row per field (Hôte, Port, Mot de passe,
Mot de passe admin when present); each value is **masked by default** with a fixed-length
bullet placeholder - the real value is absent from the rendered markup while masked.

**AC2:** Each row has its own reveal/hide toggle (Eye/EyeOff); revealing one field does not
reveal the others.

**AC3:** Each row has a copy-to-clipboard button that works in both states (masked and
revealed), with the existing copied feedback (Check icon, 2s).

**AC4:** Masked is the default on every mount for every field (not persisted) - same
streamer-safe default as 17.17 AC3.

**AC5:** Applies to both the owner and participant views (both render `ConnectionDetails`;
no call-site change, props unchanged).

**AC6:** A regression test asserts the initial render: masked values not in the markup,
bullet placeholders present, per-field copy and reveal controls present.

**AC7:** Frontend quality gates pass: `pnpm gates`.

## Tasks / Subtasks

- [x] Task 1: `connection-details.tsx` - replace the global `revealed` state with a
  `SecretField` row component (per-field `revealed` + `copied` state, Eye/EyeOff toggle,
  always-available copy button); drop the global reveal/hide UI.
- [x] Task 2: `connection-details.test.tsx` - initial-render regression test
  (`renderToStaticMarkup`, matching the repo's component-test convention).
- [x] Task 3: Frontend quality gates.

## Dev Notes

### Masked value never rendered

The mask is a constant `••••••••` (fixed length, does not leak the value's length). The copy
handler closes over the `value` prop, so clipboard works while masked without the value ever
appearing in the DOM - `renderToStaticMarkup` in the test proves the masked markup does not
contain the secrets.

### Why no global toggle anymore

A "reveal all" button would recreate the 17.17 hazard the per-field design removes; the
per-field toggles cover the non-streamer convenience case at the cost of one extra click.

## File List

- `frontend/src/features/personal-runs/connection-details.tsx` - modified
- `frontend/src/features/personal-runs/connection-details.test.tsx` - added

## Change Log

| Date | Change |
|------|--------|
| 2026-07-25 | Story created and implemented (owner request, follow-up to 17.17) |
