# Story 16.13: Connection info - per-field masking, copy without revealing

**Status:** review
**Epic:** 16 - Personal runs frontend
**Date:** 2026-07-30

## Story

As a player (often streaming) opening my run page,
I want each connection field masked on its own and copiable without revealing it,
so that I can paste host/port/password into my Archipelago client without ever showing them on
stream - and reveal just the one field I need to read.

## Context (user request 2026-07-30)

`ConnectionDetails` hid everything behind ONE global "Afficher les options de connexion" button:
nothing was listed until revealed, and the copy buttons only existed in the revealed state - so
connecting from a stream forced showing all four values at once.

## Acceptance Criteria

1. The four fields (hôte, port, mot de passe, mot de passe admin when present) are always listed,
   each with its value masked as dots and its own eye toggle - no global reveal.
2. The copy button is always present and copies the real value even while masked.
3. Defaults stay streamer-safe: every load starts fully masked (nothing persisted).
4. Gates green.

## Dev Notes

Rewrote the component: `CopyField` becomes `SecretField` (per-field `revealed` state, eye + copy
side by side, `aria-pressed`/`aria-live` for the toggle and the value swap). The card keeps its
header and gains a one-line explanation that copy works while masked. Only consumer:
`personal-run-detail-page.tsx` - untouched (same props).
