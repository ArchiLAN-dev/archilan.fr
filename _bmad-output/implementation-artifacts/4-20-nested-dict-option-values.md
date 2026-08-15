# Story 4.20: Preserve nested block values in dict options (advanced_characters)

**Status:** review
**Epic:** 4 - Registration & per-game randomizer option configuration
**Date:** 2026-08-15

## Story

As a player configuring a game whose YAML has a **dict option holding nested blocks** (e.g. Slay the
Spire's `advanced_characters`, which maps a character name to a block of its own settings),
I want the option editor to preserve those blocks,
so that my config passes the preflight test instead of failing with a schema error.

## Context

`buildOption` in `archipelago-yaml.ts` classifies an object-valued option by the shape of its values.
Story 4.17 added the literal-dict branch (`keys.some((k) => typeof obj[k] !== "number")`) so that
Pokemon's `game_options` stops being read as a weighted distribution. That branch - like the
`start_inventory` branch above it - flattens every sub-value with `String(obj[k] ?? "")`, because a
freeform entry is a single text input.

That holds for scalars. It does not hold for a **nested block**: `String({...})` yields the literal
`"[object Object]"`, and the serializer's `yaml.load` reads that back as the flow sequence
`['object Object']`. Slay the Spire's default template contains:

```yaml
  advanced_characters:
    ironclad:
      ascension: 1
      ascension_down: 0
      downfall: 0
      final_act: 1
      key_sanity: 0
```

so every saved config carried:

```yaml
  advanced_characters:
    ironclad:
      - object Object
```

The apworld's `OptionDict` schema expects a mapping and got a list, so `Options.py` raised
`schema.SchemaError: Key 'ironclad' error:` and the slot preflight ("Tester ma config") reported
**Échec du test**. The same template generates fine in a local Archipelago install, because it never
passes through our editor. (Reported by Jean from the game selection page; confirmed by diffing the
stored `game.default_yaml` against a template that generates locally - identical apart from the
`requires.game` block - then reproducing the flattening with the project's own js-yaml.)

The defect is generic: any apworld exposing an `OptionDict` of nested blocks was affected, not just
Slay the Spire. The read-only viewer was already fixed for its own variant of this in story 4.19;
this is the **editor** path, which owns parse *and* serialize.

## Acceptance Criteria

1. A dict option whose sub-value is a nested block (mapping or list) is parsed into an entry whose
   text is single-line YAML flow (`{ascension: 1, downfall: 0}`), never `"[object Object]"`.
2. Round-trip (`parseDefaultYaml` -> `serializeToYaml`) restores that sub-value as a mapping, with
   numeric sub-values still numbers. An empty dict (`{}`) round-trips as an empty mapping.
3. Scalar sub-values are unchanged: `default_player_name` stays the string `player_name`,
   `text_frame: 1` stays the number 1 (story 4.17 behaviour preserved).
4. List options get the same treatment: a nested block in a list round-trips as a mapping, while a
   plain item/location name is returned verbatim - a location named `12` must stay the string `"12"`,
   not become the number 12. Only text opening a flow collection (`{` or `[`) is parsed back.
5. Weighted options (toggle / choice / range), `plando_items` and `item_links` are untouched.
6. Gates green: frontend `typecheck` / `lint` / `test` / `build`.

## Tasks / Subtasks

- [x] **Task 1** (AC 1,3). Add `dumpFreeformValue` (scalar -> `String`, object -> YAML flow) and use it
  in the three freeform parse sites: the `start_inventory`/empty branch, the literal-dict branch, and
  the list branch.
- [x] **Task 2** (AC 2,4,5). Add `parseFreeformValue` (dict entries: any scalar or flow collection) and
  `parseFreeformItem` (list items: flow collections only, bare names verbatim); use them in
  `serializeToYaml`'s freeform branch.
- [x] **Task 3** (AC 1,2,3,4,6). Jest in `archipelago-yaml-dict-option.test.ts`: nested block parse,
  nested block round-trip, empty dict round-trip, list of blocks + numeric-looking location name.
  Verified end-to-end against the real 14 Ko Slay the Spire template (temporary test, removed).

## Dev Notes

- The list-side fix (AC 4) is defensive: no apworld in the catalogue currently ships a bare list of
  blocks (`plando_items` and `item_links` have dedicated parsers). It closes the same defect class in
  the sibling code path at the cost of three lines. The `{`/`[` guard is what keeps item and location
  names - which are arbitrary user text - from being reinterpreted by the YAML loader.
- `serializeToYaml`'s dict branch previously inlined its own try/catch `yaml.load`; that logic is now
  `parseFreeformValue`, unchanged in behaviour for scalars.
- Players whose config was already saved with the corrupted value must re-open the editor and save
  again: the parse side now reads `['object Object']` as a list entry, so the fix is not retroactive on
  stored YAML. Simpler for them is to reset the slot config from the template.

### Project Structure Notes

- `frontend/src/lib/archipelago-yaml.ts` (`dumpFreeformValue`, `parseFreeformValue`,
  `parseFreeformItem`, `buildOption`, `serializeToYaml`)
- `frontend/src/lib/archipelago-yaml-dict-option.test.ts`

### References

- [Source: _bmad-output/implementation-artifacts/4-17-literal-dict-options-not-weighted.md (literal dict branch this extends)]
- [Source: _bmad-output/implementation-artifacts/4-19-yaml-view-literal-dict-rendering.md (same defect class, read-only viewer)]
- [Source: frontend/src/lib/archipelago-yaml.ts (editor parse + serialize)]
- [Source: api/src/Sessions/Infrastructure/Http/RunnerGateway.php (startSlotPreflight - sends the player YAML verbatim, so the corruption came from the editor)]

## Dev Agent Record

### Agent Model Used

claude-opus-5 (Claude Code).

### Completion Notes List

- Root cause isolated by diff: the stored `game.default_yaml` for Slay the Spire is byte-identical to a
  template that generates in a local Archipelago install (bar `requires.game`), so the template was
  sound and the corruption had to come from the editor round-trip.
- Reproduced exactly: `String({...})` -> `"[object Object]"` -> `yaml.load` -> `['object Object']`.
- 4 new jest cases (10 in the file); full frontend gates green.

### File List

- `frontend/src/lib/archipelago-yaml.ts`
- `frontend/src/lib/archipelago-yaml-dict-option.test.ts`

### Change Log

| Date       | Change |
|------------|--------|
| 2026-08-15 | Created + implemented. Dict/list options flattened nested block values to "[object Object]", so Slay the Spire configs failed the slot preflight with `schema.SchemaError: Key 'ironclad' error:`. Added `dumpFreeformValue` / `parseFreeformValue` / `parseFreeformItem` for a lossless round-trip. Frontend-only; tested; gates green. Status -> review. |
