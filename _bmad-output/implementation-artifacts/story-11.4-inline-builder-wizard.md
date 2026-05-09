---
story: "11.4"
title: "Inline Session Builder Wizard"
epic: "11 - Session Management UX/UI Overhaul"
status: "done"
requires: ["11.1", "11.3"]
---

# Story 11.4: Inline Session Builder Wizard

As an admin,
I want to create a session from a single inline form without a separate preflight step,
So that I can move from registrations to session creation in fewer interactions.

## Context

The current builder UI has two screens: (1) a list of registrations with a "Générer les noms" button, (2) a preflight table where names can be edited before "Créer". This story collapses both into a single inline table with real-time client-side validation, removing the round-trip to the runner for preflight validation.

**Current flow:** List → click "Générer les noms" → Preflight table → click "Créer la session"
**New flow:** Single table with editable names + real-time validation → click "Créer & Générer"

**Key behavioral changes:**
- Slot name validation (max 16 chars, uniqueness, non-empty) is done client-side in real time
- "Créer & Générer" calls create session + triggers the Story 11.1 generation flow immediately
- The "Régénérer les noms" button is in the table header (not a separate step)
- If zero confirmed registrations: show an empty state, not an empty table

**Files likely affected:**
- `src/features/admin/admin-session-page.tsx` - builder and preflight views
- The builder and preflight wizard states (`wizard-builder`, `wizard-preflight`) are merged into a single `wizard` state

**API calls (unchanged):**
- `GET /api/v1/admin/events/{eventId}/sessions/builder` - loads registrations
- `POST /api/v1/admin/events/{eventId}/sessions` - creates session
- No more call to `POST /api/v1/admin/events/{eventId}/sessions/preflight` from the wizard (validation is client-side)

## Acceptance Criteria

**Given** the admin opens the session builder for an event with confirmed registrations
**When** builder data loads (after skeleton per Story 11.3)
**Then** confirmed registrations display in a single table with an editable slot name input per row
**And** there is no separate preflight screen or wizard step transition

**Given** the admin types in a slot name input
**When** the value changes
**Then** a character counter "(X/16)" appears beside the input in real time
**And** if the value exceeds 16 characters, the input has `border-danger` class and "Maximum 16 caractères" error appears below
**And** if the value is a duplicate of another slot name, "Nom déjà utilisé" error appears below
**And** if the value is empty, "Nom requis" error appears below

**Given** all slot names are valid (length ≤ 16, all unique, all non-empty)
**When** the admin clicks "Créer & Générer"
**Then** the button shows an inline spinner (Story 11.3 pattern) during the API call
**And** the session is created and the Story 11.1 generation flow begins immediately on success

**Given** the event has no confirmed registrations
**When** the builder renders
**Then** an empty state displays with a `Users` lucide icon, "Aucune inscription confirmée" heading
**And** a link/button to navigate to the event registrations list is shown

**Given** the admin clicks "Régénérer les noms" in the table header
**When** the action completes
**Then** all slot name inputs reset to auto-generated values (same SlotNameGenerator algorithm as current)
**And** real-time validation runs immediately on the regenerated values

## Tasks / Subtasks

- [ ] Task 1: Merge `wizard_builder` and `wizard_preflight` into a single `wizard` state
  - [ ] Remove `wizard_preflight` from `PageState` union type (~line 50): delete the `{ kind: "wizard_preflight"; ... }` variant
  - [ ] Add `slots: WizardSlot[]` and `slotErrors: Record<string, string[]>` to the `wizard_builder` state (or rename to `wizard`)
  - [ ] Remove `runPreflight()` function entirely - slot names are now generated client-side immediately in `startWizard()`
  - [ ] Update `startWizard()` to generate slot names from registrations directly (same algorithm as current `runPreflight`) and set wizard state with slots

- [ ] Task 2: Remove `WizardPreflight` component and its render branch
  - [ ] Delete the `state.kind === "wizard_preflight"` render block (~lines 329–343)
  - [ ] Delete the `WizardPreflight` component function (~lines 544–651)

- [ ] Task 3: Redesign `WizardBuilder` to include inline editable slot names
  - [ ] Add `slots: WizardSlot[]` and `onSlotsChange` and `onCreate` props to `WizardBuilder`
  - [ ] Add a new column "Nom de slot" to the existing table (after the game column)
  - [ ] Render an `<input>` in each slot row with current `slotName` value
  - [ ] Add character counter `(X/16)` beside or below each input, updating on change
  - [ ] Add inline error message below the input for validation errors
  - [ ] Apply `border-danger` class to input when validation error exists

- [ ] Task 4: Implement real-time client-side validation
  - [ ] Write `validateSlots(slots: WizardSlot[]): Record<string, string[]>` pure function
  - [ ] Validate: non-empty (error: "Nom requis"), max 16 chars (error: "Maximum 16 caractères"), uniqueness (error: "Nom déjà utilisé")
  - [ ] Call `validateSlots` on every `onSlotsChange` event and store errors in component state
  - [ ] "Créer & Générer" button: `disabled` when any validation error exists or any name is empty

- [ ] Task 5: Add "Régénérer les noms" button to table header
  - [ ] Add button in the header row of `WizardBuilder` (next to the title)
  - [ ] On click: regenerate all slot names using existing `SlotNameGenerator` algorithm
  - [ ] Trigger re-validation immediately after regeneration

- [ ] Task 6: Replace "Générer les noms →" with "Créer & Générer" button
  - [ ] Remove the old "Générer les noms →" button (line ~529) that triggered the preflight step
  - [ ] Add "Créer & Générer" primary button at the bottom of the table
  - [ ] On click: call `createSession(slots)` (existing function) - on success, trigger generate automatically (Story 11.1 pattern)

- [ ] Task 7: Add empty state for zero registrations
  - [ ] When `registrations.length === 0`, render an illustrated empty state instead of the table
  - [ ] Use `<Users aria-hidden="true" className="size-8 text-muted-foreground" />` icon
  - [ ] Show "Aucune inscription confirmée" heading and a link/button to the event registrations list

## Dev Notes

**Primary file:** `frontend/src/features/admin/admin-session-page.tsx`

- `PageState` union type: lines ~30–80. The `wizard_builder` state at ~line 52 has `builderLoading` and `registrations`.
- `WizardBuilder` component: lines 453–540. `WizardPreflight` component: lines 544–651.
- `startWizard()` function: loads builder data, sets `wizard_builder` state, then calls `runPreflight()` which calls the preflight API. After this story: `startWizard()` generates slot names client-side instead of calling preflight API.
- `createSession()` function: called from `WizardPreflight.onCreate`. It calls `POST /sessions` and sets state to `creating`. After this story, it should be called from `WizardBuilder.onCreate`.
- **Algorithme de génération des noms de slots** : cet algorithme est actuellement côté backend uniquement. Avant d'implémenter "Régénérer les noms", lire la source backend pour extraire la logique exacte :
  - Chercher dans `api/src/Sessions/Application/` le handler qui traite le preflight (probablement `RunPreflightJobHandler.php` ou similaire)
  - Reproduire exactement le même algo côté client pour que les noms générés soient cohérents avec ce que le backend produirait
  - Si l'algo est trop complexe à dupliquer (ex: dépendance à des règles métier internes), envisager de garder un appel au preflight endpoint uniquement pour "Régénérer" (la création reste client-side)
- `WizardSlot` type (search in file): has `slotId`, `playerName`, `gameName`, `slotName`, `errors` fields. After the refactor, `errors` field can be removed from the type (validation is now separate state).
- The `onNext` prop of the old `WizardBuilder` triggered `runPreflight()`. This prop can be replaced by `onCreate` (renamed) that calls `createSession`.
- Existing duplicate/error validation in `WizardPreflight` (~lines 557–564) can be moved client-side to the new `validateSlots()` pure function.
- After `createSession()` succeeds, the Story 11.1 autoLaunch mechanism kicks in - no changes needed here if 11.1 is already done.

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
