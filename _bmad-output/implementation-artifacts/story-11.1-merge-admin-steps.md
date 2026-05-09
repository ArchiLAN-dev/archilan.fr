---
story: "11.1"
title: "Merge Admin Validation + Generation Steps"
epic: "11 - Session Management UX/UI Overhaul"
status: "done"
requires: []
---

# Story 11.1: Merge Admin Validation + Generation Steps

As an admin,
I want to generate an Archipelago session with a single click (and optionally chain into launch),
So that I don't have to click "Valider" separately before generating.

## Context

Currently the admin flow has three separate buttons: "Valider" → "Générer" → "Lancer". This is a UX friction: validation is a technical pre-requisite for generation with no value in being exposed as its own manual step. This story removes "Valider" as a standalone action and adds a "Générer & Lancer" option that chains the full pipeline.

**Files likely affected:**
- `src/features/admin/admin-session-page.tsx` - main orchestration component
- The action buttons section that currently shows Valider/Générer/Lancer conditionally

**API endpoints used (unchanged):**
- `POST /api/v1/admin/sessions/{id}/validate`
- `POST /api/v1/admin/sessions/{id}/generate`
- `POST /api/v1/admin/sessions/{id}/launch`

The API calls themselves do not change - only the UX that triggers them. "Générer" now calls validate then generate sequentially in the frontend. "Générer & Lancer" calls validate → generate → launch.

## Acceptance Criteria

**Given** a session in `draft` or `ready` state
**When** the admin clicks "Générer"
**Then** the system automatically calls the validate endpoint before generate (no separate "Valider" button required)
**And** if validation errors exist, they are displayed per-slot inline and the pipeline stops

**Given** all slot validations pass
**When** the "Générer" action is triggered
**Then** the session transitions through `validating → ready → generating` automatically without additional user interaction
**And** the pipeline bar (Story 11.2) reflects each intermediate state in real time via SSE

**Given** a session in `draft` or `ready` state
**When** the admin clicks "Générer & Lancer"
**Then** the system automatically validates → generates → launches without any additional clicks
**And** the session reaches `running` state if no errors occur at any stage

**Given** a session that has been successfully generated (`generated` state)
**When** the admin views the action bar
**Then** a standalone "Lancer" button is available for manual launch control
**And** "Générer & Lancer" is also shown as an alternative

**Given** validation errors are returned from the runner
**When** they are displayed
**Then** each slot name and its list of errors are shown inline (accordion or expandable list per slot)
**And** no further action is possible until errors are resolved and the session is reset

## Tasks / Subtasks

- [x] Task 1: Remove "Valider" button et ajouter "Générer" pour le state draft
  - [ ] Supprimer l'`ActionButton` "Valider" du bloc `session.status === "draft"` (~line 782)
  - [ ] Ajouter `runValidateThenGenerate(withAutoLaunch: boolean)` : appelle `runAction("validate")`, puis attend que `session.status === "ready"` via un `useEffect` réactif (pas d'appel direct enchaîné - le backend passe par validating de manière async), puis appelle `runAction("generate")`
  - [ ] Implémenter ce pattern avec un état intermédiaire `pendingChain: "generate" | "generate-and-launch" | null` : quand le chain est actif et que SSE livre `ready`, déclencher generate ; quand SSE livre `generated` et chain = `"generate-and-launch"`, déclencher launch
  - [ ] Ajouter "Générer" ActionButton pour `draft` → appelle `runAction("validate")` + `setPendingChain("generate")`
  - [ ] Ajouter "Générer & Lancer" ActionButton pour `draft` → appelle `runAction("validate")` + `setPendingChain("generate-and-launch")`

- [x] Task 2: Add "Générer & Lancer" to ready state
  - [ ] Keep existing "Générer" button for `ready` state (calls `runAction("generate")`)
  - [ ] Add "Générer & Lancer" ActionButton for `ready` state → appelle `runAction("generate")` + `setPendingChain("generate-and-launch")`

- [x] Task 3: Add "Générer & Lancer" to generated state
  - [ ] Keep existing "Lancer" button for `generated` state unchanged
  - [ ] Add "Générer & Lancer" ActionButton for `generated` state → appelle `runAction("validate")` + `setPendingChain("generate-and-launch")` (re-generate + auto-launch)

- [x] Task 4: Implement `pendingChain` state and SSE-reactive chain effect
  - [ ] Remplacer le concept `autoLaunch` par `pendingChain: "generate" | "generate-and-launch" | null` state
  - [ ] Ajouter un `useEffect` unique watchant `[session.status, pendingChain]` :
    - Si `pendingChain !== null && session.status === "ready"` → appelle `runAction("generate")` (ne pas reset pendingChain ici)
    - Si `pendingChain === "generate-and-launch" && session.status === "generated"` → appelle `runAction("launch")` + `setPendingChain(null)`
    - Si `pendingChain === "generate" && session.status === "generated"` → `setPendingChain(null)` (chain terminé)
  - [ ] Ajouter un reset de `pendingChain` si session.status passe en error (`failed`, `crashed`) pour ne pas rester bloqué
  - [ ] Bouton "Générer & Lancer" : `disabled` si `pendingChain !== null || actionPending !== null`, spinner si l'un ou l'autre est actif

- [x] Task 5: Verify intermediate state display correctness
  - [ ] Confirm `isProcessing` array (line 716) includes `"validating"` so the spinner message shows during auto-validate phase
  - [ ] Confirm validation error display block still renders when `session.status === "draft"` with `validationErrors`
  - [ ] Track the combined loading state with a dedicated `actionPending` key like `"generate-chain"`

## Dev Notes

**Primary file:** `frontend/src/features/admin/admin-session-page.tsx`

- `SessionDetail` component starts at line 655. State vars at lines 664–666: `actionPending`, `copied`, `forceEndOpen`.
- Action buttons block: lines 780–846. The `draft` block: lines 782–789. The `ready` block: lines 791–798. The `generated` block: lines 800–807.
- `runAction()` function: lines 692–707 - sets/clears `actionPending` automatically.
- `isProcessing` array: line 716. Currently `["validating", "generating", "launching"]` - already correct.
- `ActionButton` component at line 948 already supports loading spinner via the `loading` prop - no changes needed there.
- **Timing critique** : `runAction("validate")` retourne après la réponse HTTP, mais le backend transite de manière async (draft→validating→ready via Symfony Messenger). Appeler `generate` immédiatement après peut échouer si la session est encore en `validating`. Le pattern correct est : déclencher validate, puis laisser le `useEffect` réactif au SSE appeler generate quand `session.status === "ready"` arrive.
- `pendingChain` remplace le concept `autoLaunch` précédent - c'est un seul `useEffect` qui réagit aux changements SSE et décide quoi appeler ensuite.
- Le SSE (`useSSE`) livre les mises à jour en temps réel. L'effet `pendingChain` n'a besoin d'aucun polling.
- Reset de `pendingChain` sur état d'erreur : `if (pendingChain !== null && ["failed", "crashed"].includes(session.status)) setPendingChain(null)`.
- Keep `DownloadZipButton` (line 979), `ForceEndDialog` logic (line 916+), and the `crashed` state block (lines 828–841) completely unchanged.

## Dev Agent Record

### Implementation Plan
_To be filled during implementation._

### Debug Log
_Issues encountered and resolutions._

### Completion Notes
_Summary of what was implemented and tested._

## File List

- `frontend/src/features/admin/admin-session-page.tsx`

## Change Log

| Date | Change | Author |
|------|--------|--------|
