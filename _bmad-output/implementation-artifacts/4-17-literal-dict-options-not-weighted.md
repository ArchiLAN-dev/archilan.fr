# Story 4.17: Parse literal dict options (game_options) as freeform dicts, not weighted choices

**Status:** review
**Epic:** 4 - Registration & per-game randomizer option configuration
**Date:** 2026-06-27

## Story

As a player whose YAML contains a **literal dict option** (e.g. Pokemon Platinum's `game_options`,
a mapping of named sub-settings like `default_player_name: player_name`, `text_speed: mid`),
I want the option editor to preserve those literal key/value settings,
so that the run actually generates instead of crashing the whole multiworld at generation time.

## Context

`game_options` (and similar `OptionDict` settings) is a literal mapping of sub-settings to values
- read the template comment: `default_player_name: player_name/custom/random/vanilla`. It is **not**
a weighted distribution.

`buildOption` in `archipelago-yaml.ts` classifies an object-valued option by shape: toggle (keys are
`true`/`false`), range (numeric/alias keys), else **choice** (the catch-all). `game_options` matched
none of the first two, so it fell into `choice`, which runs every value through `clampWeight`.
`clampWeight` coerces non-numbers to `0`, so on serialize the literal settings were destroyed:

```yaml
game_options:
  default_player_name: 0   # was 'player_name' → clampWeight('player_name') = 0 (an int)
  text_frame: 1
  ...
```

The orchestrateur then shipped `default_player_name = 0` (int) to the apworld, and Pokemon Platinum's
`encode_name` did `for c in name` → `TypeError: 'int' object is not iterable`, failing generation for
**every** slot in the session, not just the offending one. (Reported by Jean, from an orchestrateur
generation log.)

## Acceptance Criteria

1. An object-valued option with **any non-numeric value** is parsed as a `freeform` **dict** (literal
   key/value entries), never as a weighted `choice`. Weighted options (toggle/choice/range) - whose
   values are always numeric weights - are unaffected.
2. Round-trip (`parseDefaultYaml` → `serializeToYaml`) preserves literal values: `default_player_name`
   stays the string `player_name`; numeric sub-values (`text_frame: 1`) stay numbers.
3. Genuine weighted options still classify correctly: `goal: { champion: 50 }` → choice;
   `hms: { 'false': 0, 'true': 50 }` → toggle; numeric/alias dicts → range.
4. Gates green: frontend `typecheck` / `lint` / `build`; jest for the new parse + round-trip cases.

## Tasks / Subtasks

- [x] **Task 1** (AC 1,2). In `buildOption`, after the known-dict/empty-object branch and before the
  toggle/range/choice classification, route objects with any non-numeric value to a `freeform` dict.
- [x] **Task 2** (AC 1,2,3). Jest: `game_options`-style dict → freeform dict; round-trip keeps
  `default_player_name` a string; `goal` still choice, `hms` still toggle.
- [x] **Task 3** (AC 4). typecheck / lint / build green.

## Dev Notes

- The fix is shape-based (non-numeric value ⇒ literal dict). It cannot disambiguate a literal dict
  whose values happen to be **all numeric** (e.g. an item-quantity dict) from a weighted choice - that
  remains an inherent ambiguity only authoritative introspection (`optionTypes`, story 9.25) could
  resolve. `game_options` always carries string sub-values, so the heuristic covers the reported crash.
  Known all-numeric literal dicts are already pinned via `FREEFORM_DICT_KEYS` (`start_inventory`...).
- Known adjacent nuance (not in scope, pre-existing for all freeform dicts): the serializer dumps with
  js-yaml `CORE_SCHEMA`, so a value like `battle_scene: on` is emitted unquoted; PyYAML (YAML 1.1) on
  the orchestrateur reads bare `on`/`off`/`yes`/`no` as booleans. This predates this story and affects
  every freeform dict round-trip; flagged as a possible follow-up (quote 1.1-bool-like scalars on dump).
- Self-healing for already-saved corrupted YAMLs: a saved `game_options` with all-zero numeric values
  re-parses as a weighted type, which mismatches the (now freeform-dict) base in `mergePlayerValues`, so
  the merge falls back to the correct default - the corruption does not persist.

### Project Structure Notes

- `frontend/src/lib/archipelago-yaml.ts` (`buildOption` - literal dict detection)
- `frontend/src/lib/archipelago-yaml-dict-option.test.ts` (new)

### References

- [Source: _bmad-output/implementation-artifacts/4-16-range-value-bounds-validation.md (option classification in archipelago-yaml.ts)]
- [Source: _bmad-output/implementation-artifacts/9-25-introspected-range-bounds-end-to-end.md (optionTypes - authoritative type disambiguation)]
- [Source: frontend/src/lib/archipelago-yaml.ts (buildOption, serializeOption freeform dict)]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Completion Notes List

- Added a literal-dict guard in `buildOption`: an object option with any non-numeric value becomes a
  `freeform` dict instead of falling into the `choice` catch-all (which zeroed string values).
- Root cause of the production crash: `game_options.default_player_name` serialized as `0` (int) →
  apworld `encode_name` iterated an int. Reproduced and covered by a round-trip test.
- New jest file (3 tests); existing archipelago-yaml suites still green (17 total). typecheck/lint/build
  green. Frontend-only change.

### File List

- `frontend/src/lib/archipelago-yaml.ts`
- `frontend/src/lib/archipelago-yaml-dict-option.test.ts`

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-27 | Created + implemented. `game_options` (literal OptionDict) was misclassified as a weighted choice, coercing `default_player_name: player_name` → `0` (int) and crashing generation ("'int' object is not iterable"). Route non-numeric-valued object options to freeform dict. Frontend-only; tested; gates green. Status → review. |
