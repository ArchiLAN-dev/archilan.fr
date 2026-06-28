# Story 4.19: Render literal dict options in the read-only YAML view (no "[object Object]")

**Status:** review
**Epic:** 4 - Registration & per-game randomizer option configuration
**Date:** 2026-06-27

## Story

As anyone viewing a player's config in the read-only visual YAML view (participant detail, weekly-run
game page),
I want a literal dict option like `game_options` to show its named sub-settings,
so that I see the actual values instead of the string "[object Object]".

## Context

The shared read-only renderer `components/yaml/yaml-options-view.tsx` (separate from the editor fixed in
4.17/4.18) classifies each option value: weighted dict → distribution bars, `[n, n]` → range, boolean →
Oui/Non, else `String(value)`. A literal dict (`game_options`: mixed string/number sub-values) is none of
the first three, so it hit `String(value)` → **"[object Object]"**. The `ApOptionValue` type didn't even
model a nested dict. (Reported by Jean on a participant page.)

## Acceptance Criteria

1. A non-weighted object value renders as a list of `Sub-setting  value` pairs (formatted key + scalar
   value), never "[object Object]". An empty object renders "-".
2. A non-range array renders as a comma-separated list (empty → "-").
3. Existing renderings are unchanged: weighted dicts → distribution bars, `[n,n]` → "entre a et b",
   booleans → Oui/Non, scalars → as-is.
4. `formatScalar` is a pure exported helper (boolean → Oui/Non, array → comma list, nested dict →
   `Key: value` pairs, scalar → String) and `ApOptionValue` is widened to allow nested lists/dicts.
5. Gates green: frontend `typecheck` / `lint` / `build`; jest for `formatScalar` + `parseGameOptions`.

## Tasks / Subtasks

- [x] **Task 1** (AC 4). Widen `ApOptionValue` (recursive list/dict); add exported pure `formatScalar`.
- [x] **Task 2** (AC 1,2,3). Add list + literal-dict branches to `OptionValue` before the scalar fallback.
- [x] **Task 3** (AC 5). Jest (`yaml-options-view.test.ts`): dict/array/boolean/scalar formatting +
  `parseGameOptions` preserves a literal dict. typecheck / lint / build green; verified live in the
  read-only config modal (Game Options shows `Default Player Name player_name`, no "[object Object]").

## Dev Notes

- Companion to 4.17 (editor parsing) and 4.18 (editor info modal): same `game_options` root cause, but
  this is the **read-only** view, which has its own independent parse/render path.
- The all-numeric-dict ambiguity persists (a literal dict whose values are all numbers still renders as a
  weighted distribution) - only authoritative introspection (story 9.33) could disambiguate. `game_options`
  carries string sub-values, so it is now rendered as a literal dict.

### Project Structure Notes

- `frontend/src/components/yaml/yaml-options-view.tsx` (`OptionValue`, `formatScalar`, `ApOptionValue`)
- `frontend/src/components/yaml/yaml-options-view.test.ts` (new)

### References

- [Source: _bmad-output/implementation-artifacts/4-17-literal-dict-options-not-weighted.md (editor-side literal dict handling)]
- [Source: frontend/src/components/yaml/yaml-options-view.tsx (read-only renderer)]
- [Source: frontend/src/features/personal-runs/personal-run-yaml-viewer-dialog.tsx (consumer)]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Completion Notes List

- `OptionValue` now renders literal dicts as labelled `key value` pairs and non-range arrays as comma
  lists; `String(value)` no longer reached for objects/arrays.
- Pure `formatScalar` extracted + exported; `ApOptionValue` widened to recursive lists/dicts.
- 4 jest cases; typecheck / lint / build green; verified live on the participant config modal.

### File List

- `frontend/src/components/yaml/yaml-options-view.tsx`
- `frontend/src/components/yaml/yaml-options-view.test.ts`

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-27 | Created + implemented. The read-only YAML view rendered `game_options` (literal dict) as "[object Object]" via `String(value)`. Added list + dict branches to `OptionValue` and an exported `formatScalar`; widened `ApOptionValue`. Frontend-only; tested; gates green; verified live. Status → review. |
