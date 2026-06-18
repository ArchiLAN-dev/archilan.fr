# Story 30.21: Two-level grouped navigation for /compte

Status: done (retroactively documented)

## Story

As a member,
I want the many `/compte` tabs grouped into a small number of clear categories with a polished switcher,
so that the account area is navigable instead of a long flat tab strip. Deps: the existing `/compte` tabs
(account area).

The flat tab list is reorganised into a two-level navigation (three groups), rendered as a segmented control
with icons.

## Acceptance Criteria

1. The `/compte` tabs are grouped into three categories: **Communauté** (profil, amis, activité), **Jeux**
   (inscriptions, parties), **Compte** (adhésion, confidentialité, "Connexions & sécurité").
2. The top level is a segmented control with an icon per group (Users / Gamepad2 / Settings); selecting a
   group reveals its sub-tabs.
3. The active group/sub-tab is reflected in the UI; existing tab routes/content are unchanged.
4. Gates green: typecheck / lint / build / jest (frontend-only change).

## Tasks / Subtasks

- [x] **frontend:** group the tabs into a two-level nav (`account-tabs.tsx`).
- [x] **frontend:** render the group level as a segmented control with icons (`account-tabs.tsx`).
- [x] **Gates** — typecheck / lint / build / jest green.

## Dev Notes

### Reuse, don't reinvent
- Pure reorganisation of the existing tab definitions — no new pages, no routing change; each leaf still
  points at its current tab.

### Architecture guardrails
- Frontend presentation only; `account-tabs.tsx` stays a pure render component.

### Scope boundaries / deviations
- Iterated from a first "chips" attempt to the segmented control on user feedback ("les chips c'est pas
  terrible") — only the segmented control is in the final state.
- The "compte" leaf was relabelled "Connexions & sécurité" for clarity.

### Project Structure Notes
- Modified frontend: `features/auth/account-tabs.tsx`.

### References
- Account area (`/compte`); adjacent to epic 30 community surfaces.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- `/compte` reorganised into a three-group, two-level navigation rendered as an icon segmented control.
- Implemented in commits `e9a1492` (two-level grouping) and `cd528aa` (segmented control with icons).

### Validation Results

- Gates green at merge: typecheck / lint / build / jest clean (no API change).

### File List

**Modified (frontend)**
- `frontend/src/features/auth/account-tabs.tsx`
