# Story 4.16: Block saving a range value outside its [min, max] bounds

**Status:** review
**Epic:** 4 - Registration & per-game randomizer option configuration
**Date:** 2026-06-13

## Story

As a player editing a slot's YAML (simple **or** advanced mode),
I want the editor to stop me from saving a `range` value outside the option's allowed `[min, max]`,
so that I don't ship a YAML that Archipelago rejects at generation (e.g. `progression_balancing: 100`
when the max is 99).

## Context

Story 9.25 surfaced authoritative range bounds (`min`/`max`/`default`) from introspection into the
editor (`optionTypes` → `RangeOption.min`/`max`). In **simple** mode the value picker (`NumberStepper`)
already clamps to `[min, max]`. But in **advanced** mode the YAML is edited freely, and the only
save-time guard so far (story 4.15) checks *"a weighted option has ≥1 weight > 0"* — it does **not**
check that range values respect their bounds. So `progression_balancing: 100` (max 99) passes the
frontend and only fails later, opaquely, at generation. (Reported by Jean.)

This adds a save-time bounds check, complementing 4.15, using the same authoritative `optionTypes`.

## Acceptance Criteria

1. `findOutOfBoundsRangeOptions(options)` returns the `range` options whose **numeric** value(s) fall
   outside `[min, max]`; random aliases (`random`, `random-range-…`) are ignored; non-range options are
   never returned.
2. `YamlOptionEditor.handleSave` **blocks** the save (API + `onChange` paths) when any range value is
   out of bounds, and shows an inline error naming the option, the offending value(s) and the allowed
   range. No PUT / `onChange` fires.
3. Works in **advanced/raw** mode too: the final YAML is parsed **with `optionTypes`** so range options
   carry their real bounds (fixes a gap where the 4.15 re-parse dropped `optionTypes`).
4. A valid config saves normally; the 4.15 zero-weight guard still applies (checked first).
5. Gates green: frontend `typecheck` / `lint` / `build`; unit tests for `findOutOfBoundsRangeOptions`.

## Tasks / Subtasks

- [x] **Task 1** (AC 1). `findOutOfBoundsRangeOptions` + `OutOfBoundsRange` type in `archipelago-yaml.ts`.
- [x] **Task 2** (AC 2,3,4). Wire into `handleSave` after the zero-weight check; parse the final YAML
  with `optionTypes`; block + inline banner. Reset both error states on a clean save.
- [x] **Task 3** (AC 1,5). Jest: value > max and < min flagged with the right values; in-bounds +
  random aliases accepted; non-range ignored.
- [x] **Task 4** (AC 5). typecheck / lint / build green.

## Dev Notes

- Depends on 9.25 `optionTypes` for authoritative bounds. For games not yet backfilled, bounds fall
  back to template comments / `{0,100}`; the check still applies with whatever bounds the option has.
- The validation parses `parsed ?? parseDefaultYaml(yamlToSave, optionTypes)` so both the simple model
  and raw/advanced edits are covered. Also fixed: the 4.15 re-parse was missing `optionTypes` (auto-merge
  gap between the 4.15 and 9.25 branches).
- Server-side enforcement (reject out-of-bounds in `saveSlotYaml`) remains a possible defence-in-depth
  follow-up; AP still rejects at generation as the ultimate backstop.

### Project Structure Notes

- `frontend/src/lib/archipelago-yaml.ts` (`findOutOfBoundsRangeOptions`)
- `frontend/src/features/events/yaml-option-editor.tsx` (validate + banner)
- `frontend/src/lib/archipelago-yaml-validation.test.ts` (extended)

### References

- [Source: _bmad-output/implementation-artifacts/4-15-weighted-option-zero-weight-validation.md]
- [Source: _bmad-output/implementation-artifacts/9-25-introspected-range-bounds-end-to-end.md (optionTypes)]
- [Source: frontend/src/features/events/yaml-option-editor.tsx (handleSave, advanced mode)]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Completion Notes List

- `findOutOfBoundsRangeOptions` flags numeric range values outside `[min,max]` (aliases ignored).
- `handleSave` blocks the save with an inline "valeurs hors limites" banner naming option/value/range;
  validates the final YAML with `optionTypes` (also fixes the 4.15 re-parse missing `optionTypes`).
- Jest extended (6 cases total); typecheck/lint/build green. Frontend-only.

### File List

- `frontend/src/lib/archipelago-yaml.ts`
- `frontend/src/features/events/yaml-option-editor.tsx`
- `frontend/src/lib/archipelago-yaml-validation.test.ts`

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-13 | Created + implemented. Block saving a range value outside [min,max] (advanced mode let `progression_balancing: 100` through). Reuses 9.25 `optionTypes`; complements 4.15. Frontend-only; tested; gates green. Status → review. |
