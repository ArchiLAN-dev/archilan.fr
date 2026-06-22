# Story 30.19: Profile customization editor redesign (UI/UX)

Status: done (retroactively documented)

## Story

As a member,
I want the profile customization editor organised into clear sections with an obvious save affordance,
so that editing my profile is legible instead of one long form. Deps: 30.3 (profile customization form).

The customization editor is restructured into card sections with a sticky save bar, plus shared banner-preset
metadata extracted for reuse by the editor and the public profile.

## Acceptance Criteria

1. The customization form is grouped into card sections (identity, à propos, apparence, vitrine, liens…)
   instead of a flat field list.
2. A sticky save bar stays visible while scrolling the editor; saving applies the whole form as one update.
3. Banner-preset metadata is extracted into a shared module (`banner-presets.ts`) consumed by both the editor
   (preview/choices) and the public profile (rendering) - one source of truth.
4. No behavioural change to what is persisted - purely presentation/UX over the existing update path.
5. Gates green: typecheck / lint / build / jest (frontend-only change).

## Tasks / Subtasks

- [x] **frontend:** restructure `community-profile-customization-form.tsx` into card sections + sticky save
      bar.
- [x] **frontend:** extract `banner-presets.ts` (preset keys + labels/metadata) shared by editor + profile.
- [x] **frontend:** align `player-profile-page.tsx` to the shared preset metadata.
- [x] **Gates** - typecheck / lint / build / jest green.

## Dev Notes

### Reuse, don't reinvent
- The single shared `banner-presets.ts` removes the duplicated preset list that previously lived in both the
  editor and the profile renderer.

### Architecture guardrails
- Frontend-only, presentation layer: no API/contract change; the existing single-update save path is reused
  (one form submit = one profile update).
- Components stay pure render functions (CLAUDE.md "No side effects at boundaries").

### Scope boundaries / deviations
- This story is the editor's information architecture; the concrete banner *visuals* land in 30.22 and avatar
  frames in 30.23 (this commit seeded `banner-presets.ts`, later expanded by 30.22).

### Project Structure Notes
- Added frontend: `features/community/banner-presets.ts`.
- Modified frontend: `features/community/community-profile-customization-form.tsx`,
  `features/players/player-profile-page.tsx`.

### References
- Epic §E + story 30.3. [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Customization editor reorganised into card sections with a sticky save bar; banner-preset metadata
  extracted into a shared module.
- Implemented in commit `380db5e`.

### Validation Results

- Gates green at merge: typecheck / lint / build / jest clean (no API change).

### File List

**Added (frontend)**
- `frontend/src/features/community/banner-presets.ts`

**Modified (frontend)**
- `frontend/src/features/community/community-profile-customization-form.tsx`
- `frontend/src/features/players/player-profile-page.tsx`
