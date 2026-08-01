# Story 9.39: Generation fails for games with a purely numeric name (2048)

**Status:** review
**Epic:** 9 - Sessions, bridge & slots
**Date:** 2026-07-30

## Story

As a player whose slot uses a game with a purely numeric Archipelago name (e.g. the custom apworld "2048"),
I want the multiworld generation to succeed,
so that my run launches like any other game instead of failing with
`No game options for selected game "2048" found.`

## Context

Production log (gen container): the world `2048` loads fine (`DEBUG worlds loaded: ['2048', ...]`) yet
`Generate.py` (Archipelago 0.6.7, `roll_settings`) raises
`Exception: No game options for selected game "2048" found.` because the player YAML that reaches the
container contains `game: '2048'` (string) but a section key `2048:` (int once parsed by PyYAML).

Root cause is a YAML round-trip on our side, not the apworld:

1. `RunnerGateway::buildPlayerYaml()` parses the stored player YAML and rebuilds it as a `PlayerYaml`
   object [Source: api/src/Sessions/Infrastructure/Http/RunnerGateway.php:250-292].
2. `PlayerYaml::toArray()` does `$data[$this->game] = $gameSection`. PHP coerces the canonical-integer
   string key `"2048"` to int `2048` (language behavior, unavoidable with arrays)
   [Source: packages/orchestrateur-client/src/Sessions/Yaml/PlayerYaml.php:45].
3. `toYamlString()` then `Yaml::dump()`s the array: Symfony quotes the string *value* (`game: '2048'`)
   but dumps the int *key* bare (`2048:`).
4. The orchestrateur writes that text verbatim as the slot YAML; PyYAML parses the bare key as an int;
   `ret.game` (`"2048"`, str) is never `in weights` and generation aborts.

The templates our site serves are healthy (produced by Archipelago's own `Options.generate_yaml_templates`,
which quotes numeric names); only the configure/launch re-serialization corrupts the key. Desktop
Archipelago never rewrites the YAML, which is why the same apworld works there.

## Goal / approach

Fix at the single corruption point, `PlayerYaml::toYamlString()` in the `archilan/orchestrateur-client`
package: when the game name is a canonical-integer string (PHP key-coercion case, detected with
`(string) (int) $game === $game`), re-quote the top-level section key after the dump
(`/^2048:/m` -> `'2048':`, first match only - option lines are indented so only the section key can
match at column 0). Non-numeric names are untouched. `toArray()` keeps the int key (PHP cannot hold a
numeric string array key); the wire format is the YAML string, which is what matters.

Package change only; the api consumes it via the VCS repo, so the monorepo side is a
`composer update archilan/orchestrateur-client` lock bump plus this story file.

## Acceptance Criteria

1. `PlayerYaml::toYamlString()` for `game: '2048'` with options emits a quoted section key (`'2048':`)
   and no bare `2048:` line at column 0; the `game:` value stays the string `'2048'`.
2. Non-numeric game names keep their current (unquoted) section key output.
3. A numeric game name without options still produces valid YAML (no section, no crash).
4. Package quality gates green (PHPStan level 9, PHPUnit).
5. Package version bumped to 1.3.1, tagged, and the api `composer.lock` updated to it; api gates green.

## Tasks

- [x] Add regression tests in `PlayerYamlTest` (numeric name with options, numeric name without options,
      non-numeric name unchanged).
- [x] Implement the key re-quoting in `PlayerYaml::toYamlString()`.
- [x] Bump package to 1.3.1, PR to the package repo, merge, tag `v1.3.1`.
- [x] `composer update archilan/orchestrateur-client` in `api/`, api gates, PR to `develop`.
