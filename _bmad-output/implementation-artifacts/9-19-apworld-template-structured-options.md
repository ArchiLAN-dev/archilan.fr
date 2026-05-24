# Story 9.19: Apworld Template — Structured Options with Descriptions

## Story

**As a** PHP consumer of the orchestrateur API,
**I want** the apworld upload endpoint to return structured option metadata (including the description extracted from YAML template comments) instead of a raw YAML string,
**So that** I can build a typed `PlayerYaml` without having to parse raw YAML, and display meaningful option descriptions in the UI without any comment-stripping loss.

## Context

When an apworld is uploaded, the orchestrateur runs `generate_template.py` and returns the resulting YAML in `UploadApworldResponse.Yaml`. This template contains rich metadata in comments:

```yaml
  # How many crystals are needed to access Ganon's Tower.
  # Range 0 to 7
  crystals_needed_for_gt:
    7: 50
    ...
```

Now that the PHP client uses typed option classes (`RangeOption`, `ChoiceOption`, etc.) via `PlayerYaml`, the raw YAML string is no longer usable as-is — and the comments (the only source of option descriptions and type hints) are stripped by every standard YAML parser.

The orchestrateur must parse the template itself and expose structured option data.

## Status

done

## Acceptance Criteria

**AC1 — New response shape for `POST /apworlds`:**
`UploadApworldResponse` is extended with an `options` field. The `yaml` field is removed.
```json
{
  "hash": "0fd8936...",
  "options": [
    {
      "key": "crystals_needed_for_gt",
      "description": "How many crystals are needed to access Ganon's Tower.\nRange 0 to 7",
      "type": "range",
      "defaultValue": 7,
      "validValues": null,
      "rangeMin": 0,
      "rangeMax": 7
    },
    {
      "key": "smallkey_shuffle",
      "description": "Control where small keys can be found.",
      "type": "choice",
      "defaultValue": "original_dungeon",
      "validValues": ["original_dungeon", "any_world", "own_world", "different_world"],
      "rangeMin": null,
      "rangeMax": null
    },
    {
      "key": "swordless",
      "description": "Enable swordless mode.",
      "type": "toggle",
      "defaultValue": false,
      "validValues": null,
      "rangeMin": null,
      "rangeMax": null
    }
  ]
}
```

**AC2 — Template parser in Go:**
A `templateparser` package (or function in the `storage` package) reads a YAML template line by line and produces `[]TemplateOption`. Rules:
- Consecutive comment lines (`# ...`) immediately above a key are the option's description.
- Type inference from comment content or value structure:
  - `range_start` / `range_end` keys in comments or value → `range`; extract `rangeMin`, `rangeMax`
  - Boolean-only values (`0`/`1` or `true`/`false`) → `toggle`
  - String keys with integer weights → `choice`; list the choice names as `validValues`
  - Otherwise → `text`
- Universal options (`accessibility`, `progression_balancing`, `local_items`, `start_inventory`, etc.) are excluded from the response — the PHP client already knows them via typed fields in `PlayerYaml`.
- Top-level keys (`name`, `game`, `description`, `requires`, `quantity`) are excluded.

**AC3 — `SlotOption.description` in preflight:**
`SlotOption` (used in the preflight request/response) gains a `description string` field. The preflight handler populates it when returning option validation results, by reading the stored template for the relevant apworld.

**AC4 — PHP client — `TemplateOption` DTO:**
`UploadApworldResult` is updated:
- Remove `yaml: string`
- Add `options: TemplateOption[]`

`TemplateOption` has:
```php
final readonly class TemplateOption {
    public function __construct(
        public string $key,
        public string $description,
        public string $type,        // range | choice | toggle | text | weighted
        public mixed $defaultValue,
        public ?array $validValues,
        public ?int $rangeMin,
        public ?int $rangeMax,
    ) {}
}
```

**AC5 — PHP client — `SlotOption.description`:**
`SlotOption` gains `public string $description` (default `''`).

**AC6 — Backward compatibility:**
The `yaml` field removal in `UploadApworldResponse` is a breaking change. Any existing caller that reads `->yaml` must be migrated to use `->options` before this story ships. Identify all callers in `api/` before starting.

## Tasks / Subtasks

- [ ] Task 1: Implement `internal/templateparser` package in Go — line-by-line template parser producing `[]TemplateOption`
- [ ] Task 2: Add `TemplateOption` type to `api/types.go`; update `UploadApworldResponse` (remove `Yaml`, add `Options []TemplateOption`)
- [ ] Task 3: Update apworld upload handler to call the parser and populate `Options`
- [ ] Task 4: Add `description string` to `SlotOption` in `api/types.go`; populate from stored template in preflight handler
- [ ] Task 5: Audit and migrate all `api/` callers of `UploadApworldResponse.Yaml`
- [ ] Task 6: Rebuild orchestrateur Docker image
- [ ] Task 7: Add `TemplateOption` DTO and update `UploadApworldResult` in PHP client
- [ ] Task 8: Add `description` to PHP `SlotOption`
- [ ] Task 9: Update `test.php` in `orchestrateur-client-test` to print option descriptions

## Dev Notes

### Template parser strategy

The Archipelago template format is consistent enough for line-by-line parsing. Key patterns:

```
# Description line 1        → accumulate comment
# Description line 2        → accumulate comment
option_key:                 → flush comment as description for this key
  choice_a: weight          → indicates choice type
  choice_b: weight
```

The parser does NOT need to be a full YAML parser — it only reads the template structure. Use a state machine: `idle → collecting_comment → reading_value`.

### Type inference heuristic

| Observation | Inferred type |
|---|---|
| Sub-keys are `0` and `1` only | `toggle` |
| Comment contains `Range X to Y` | `range`; extract min/max |
| Sub-keys are strings with integer weights | `choice` |
| Key has a bare string value | `text` |

### Universal options exclusion

Universal options are defined in Archipelago's `worlds/generic/Rules.py`. The fixed exclusion list includes: `accessibility`, `progression_balancing`, `local_items`, `non_local_items`, `start_inventory`, `start_hints`, `start_location_hints`, `exclude_locations`, `priority_locations`, `item_links`, `plando_items`, `plando_connections`, `plando_bosses`, `plando_texts`, `triggers`.

### Why remove `yaml` from the response?

Keeping `yaml` alongside `options` would create two sources of truth for the same information and encourage callers to bypass the structured API. A clean break forces consumers to use the typed representation.
