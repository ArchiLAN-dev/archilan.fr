# Story 4.15: Block saving a YAML where a weighted option has all weights at 0

**Status:** review
**Epic:** 4 - Registration & per-game randomizer option configuration
**Date:** 2026-06-12

## Story

As a player configuring a slot in the YAML option editor,
I want the editor to stop me from saving an option whose weights are all 0,
so that I don't produce a YAML that crashes generation ("option can never be rolled").

## Context

Archipelago expresses choice / toggle / range options as **weighted distributions**. If every
value of such an option has weight 0, the distribution sums to 0 and Archipelago cannot roll a
value: generation fails (e.g. Muse Dash `song_difficulty_min` reported by Jean -
`0 is lower than minimum 1` / an all-zero weighted option). Investigation showed the **default**
template is valid (`song_difficulty_min` default = 4); the invalid `0` is introduced **in the
editor** when a user zeroes out every weight of an option. Nothing currently prevents saving such a
YAML - the failure only surfaces later, as a cryptic generation crash.

This adds a guard at save time in the shared `YamlOptionEditor` (used by event registrations **and**
personal runs): a weighted option must have **at least one value with weight > 0**.

### Decisions

- Rule applies to the **weighted** option kinds only: `toggle` (weightFalse+weightTrue),
  `choice` (sum of choice weights), `range` (sum of entry weights). `text` / `freeform` /
  `plando_items` / `item_links` are not weighted and are never flagged (a 0 there is legitimate).
- **Block the save** (don't auto-fix) and tell the user **which** options are offending, so they
  fix the intent themselves.
- Enforced where the editor actually persists: the API-save path (`saveUrl`/`slotId`, used by
  personal runs and registrations) and the template `onChange` path. Validates the **final YAML**
  being saved, so it also covers raw/advanced edits.

## Acceptance Criteria

1. `findZeroWeightOptions(options)` (in `archipelago-yaml.ts`) returns the `toggle`/`choice`/`range`
   options whose weights sum to `<= 0`; non-weighted options are never returned.
2. Saving in `YamlOptionEditor` is **blocked** when any weighted option has all-zero weights; an
   inline error names the offending options (by label) and explains the fix. No PUT / `onChange`
   fires in that case.
3. A valid config (every weighted option has at least one weight > 0) saves normally - no regression.
4. The reported Muse Dash case (a difficulty/song option zeroed to all-0) is caught at save instead
   of failing generation.
5. Gates green: frontend `typecheck` / `lint` / `build`; unit test for `findZeroWeightOptions`.

## Tasks / Subtasks

- [x] **Task 1 - Validation helper** (AC: 1). Add `findZeroWeightOptions(options: GameOption[])` to
  `frontend/src/lib/archipelago-yaml.ts` (sum weights per weighted kind; return `{key,label}[]`).
- [x] **Task 2 - Wire into the editor** (AC: 2,3). In `YamlOptionEditor.handleSave`, validate the
  final YAML before saving; on offence set an error state and return (no save). Render an inline
  danger banner listing the offending option labels.
- [x] **Task 3 - Test** (AC: 1,5). Jest: all-zero toggle/choice/range flagged; one weight > 0 passes;
  non-weighted ignored.
- [x] **Task 4 - Gates** (AC: 5). typecheck / lint / build green.

## Dev Notes

- The editor is shared (`features/events/yaml-option-editor.tsx`); personal runs consume it via
  `personal-run-slot-yaml-page.tsx` with `saveUrl`/`slotId` (API-save path) - so the guard covers the
  reported private-run scenario.
- Validation parses the final `yamlToSave` (`parsed ?? parseDefaultYaml(yamlToSave)`) so advanced/raw
  edits are covered, not just the simple-mode model.
- **Out of scope / possible follow-up:** server-side enforcement (reject in `saveSlotYaml` /
  registration yaml save) as defence-in-depth; and the broader range-bounds-from-introspection work
  (`introspect_options.py` should expose `min`/`max`/`default`) that would remove the frontend's
  hardcoded bounds + `{min:0,max:100}` fallback. Both tracked separately.

### Project Structure Notes

- `frontend/src/lib/archipelago-yaml.ts` (`findZeroWeightOptions`)
- `frontend/src/features/events/yaml-option-editor.tsx` (validate + banner)
- `frontend/src/lib/archipelago-yaml-validation.test.ts` (new)

### References

- [Source: frontend/src/features/events/yaml-option-editor.tsx (shared editor, handleSave)]
- [Source: frontend/src/features/personal-runs/personal-run-slot-yaml-page.tsx (private-run consumer)]
- [Source: _bmad-output/implementation-artifacts/4-5-per-game-randomizer-option-configuration.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Completion Notes List

- `findZeroWeightOptions` flags toggle/choice/range options whose weights sum to <= 0; text/freeform/
  plando/item-links ignored.
- `YamlOptionEditor.handleSave` blocks the save (API + onChange paths) and shows an inline banner
  naming the offending options; validates the final YAML so advanced edits are covered.
- Jest (3 cases) + typecheck/lint/build green.

### File List

- `frontend/src/lib/archipelago-yaml.ts`
- `frontend/src/features/events/yaml-option-editor.tsx`
- `frontend/src/lib/archipelago-yaml-validation.test.ts` (new)

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-12 | Created + implemented. Block saving a YAML where a weighted option (toggle/choice/range) has all weights at 0 - prevents the Muse Dash all-zero-difficulty generation crash at the source. Frontend-only guard in the shared editor; unit-tested; gates green. Status → review. |
