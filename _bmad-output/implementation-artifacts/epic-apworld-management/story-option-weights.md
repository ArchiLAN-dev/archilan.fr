# Story: Option weights - expose and consume probabilistic weights for choice/toggle options

## Story

**As a** PHP consumer of the orchestrateur API,
**I want** each choice and toggle option to expose its full weight distribution (not just the best default),
**So that** I can present randomisation sliders in the UI and send weighted values back to `configure` to generate a session that uses those weights.

## Context

Archipelago templates express all choice/toggle options as weighted distributions:

```yaml
rank_requirement:
  rank_h: 50
  rank_g: 0
  rank_f: 0
  rank_e: 0
```

The current parser picks the highest-weight value as `defaultValue` and drops the rest. This means:
- The frontend cannot build a weight-picker UI.
- Users cannot express "50% rank_h, 50% rank_f" - they can only pick a single value.

The configure endpoint already supports nested `map[string]any` values in `options.values`, so sending `{"rank_requirement": {"rank_h": 50, "rank_f": 50}}` to `configure` already works at the Go level - the YAML builder serialises it correctly.

## Status

done

## Acceptance Criteria

**AC1 - Weights returned in API responses:**
`GET /apworlds/{hash}/options` and `POST /apworlds` return a `weights` field on choice and toggle options:
```json
{
  "key": "rank_requirement",
  "type": "choice",
  "defaultValue": "rank_h",
  "validValues": ["rank_h", "rank_g", "rank_f", "rank_e"],
  "weights": {"rank_h": 50, "rank_g": 0, "rank_f": 0, "rank_e": 0}
}
```

**AC2 - PHP typed properties:**
`ChoiceTemplateOption` and `ToggleTemplateOption` expose `public array $weights` (`array<string, int>`).

**AC3 - Weights usable in configure:**
Sending `{"options": {"playerName": "Jean", "values": {"rank_requirement": {"rank_h": 50, "rank_f": 50}}}}` to `POST /sessions/{id}/configure` produces a valid Archipelago player YAML with the weighted distribution - no new server changes required (works by existing `buildPlayerYaml`).

**AC4 - Tests updated:**
Go parser tests verify weights extraction for choice and toggle options. PHP tests verify weights are deserialized correctly.

## Tasks

- [x] BMAD story created
- [x] Go: add `Weights map[string]int` to `templateparser.Option`
- [x] Go: `inferType` returns weights for choice/toggle options
- [x] Go: add `Weights map[string]int` to `api.TemplateOption` (omitempty)
- [x] Go: handlers populate `Weights` from parsed options
- [x] Go: parser tests for weights
- [x] PHP: add `$weights array<string, int>` to `ChoiceTemplateOption`
- [x] PHP: add `$weights array<string, int>` to `ToggleTemplateOption`
- [x] PHP: update `ApworldsClientTest`
