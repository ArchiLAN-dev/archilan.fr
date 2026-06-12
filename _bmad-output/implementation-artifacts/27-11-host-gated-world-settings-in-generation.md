# Story 27.11: Auto-derive host.yaml for host-gated world settings at generation

**Status:** review
**Epic:** 27 - Session configuration & Archipelago generator options
**Date:** 2026-06-12

## Story

As a player configuring a slot,
I want options that Archipelago gates behind a `host.yaml` setting (e.g. Vampire Survivors'
`allow_unfair_characters`) to actually work,
so that enabling such an option in my YAML does not make the whole multiworld generation fail.

## Context

Some Archipelago worlds gate a **player** option behind a **host** setting of the same concept:
the world raises an `OptionError` during `generate_early` unless the host has opted in via
`host.yaml`. Example (the bug that triggered this story):

```
Vampire Survivors: <slot> `allow_unfair_characters` can not be enabled unless the
host.yaml setting 'allow_unfair_characters' is also enabled, PLEASE FIX YOUR YAML
```

These are **two distinct switches**: the player option ("I want it") and the host setting ("this
host permits it"). Both must be `true`. Today the platform generates **without any `host.yaml`**
(the run log shows `Could not find host.yaml to load options. Creating a new one.`), so every
host-gated setting defaults to `false` and any seed where a player enables such an option **fails
generation hard**. There is currently no way to enable these from the site.

Story 27.4 handles the `generator:` section options (plando/race/spoiler) via `Generate.py` **CLI
args** - that path does **not** cover per-world host settings, which are read from
`world.settings` (i.e. `get_settings()[world.settings_key]`) and have no CLI equivalent. So this
story must produce an actual `host.yaml` consumed by the generation run.

### Spike findings (done 2026-06-12 - data this story is built on)

Ran an introspection of `world.settings` (a `settings.Group` subclass) across Vampire Survivors +
8 community apworlds, inside `archipelago:latest`:

- **The mechanism, confirmed end-to-end.** VS `Settings.py` declares
  `class VampireSurvivorsSettings(Group)` with `allow_unfair_characters = False` (a `Bool`); the
  gate (`Options.py::check_options`) is
  `if not settings.allow_unfair_characters and options.allow_unfair_characters: raise`. The host.yaml
  that satisfies it:
  ```yaml
  vampire_survivors_options:
    allow_unfair_characters: true
  ```
- **Host settings are introspectable per-world**, exactly like options: `world.settings_key`
  (the host.yaml section name) + the `Group`'s members (name, type, default). No catalog to
  hand-maintain - it works for any present/future apworld.
- **The host-gated surface is small and is `Bool`** (often `allow_*`, default `False`):
  - VS → `allow_unfair_characters` (False)
  - Stardew Valley → `allow_chaos_er`, `allow_jojapocalypse` (False) (+ other `allow_*` already True)
  - DOOM / Inscryption / Noita / Subnautica / Terraria → none
- **Non-bool host settings exist and MUST NOT be touched**: Super Metroid `rom_file` (`RomFile`),
  Factorio `executable` (`Executable`) + `server_settings`, and non-`allow_` server toggles
  (Factorio `filter_*`). These are infra/paths, not permission gates.
- **Decisive: name-mirroring is NOT reliable.** Only VS has `option_name == host_setting_name`
  (`allow_unfair_characters`). Stardew's host gates are named **differently** from the player options
  they gate (`overlap: []`). So "copy the player option onto a same-named host key" does **not**
  generalise - the design must enable the host gate without depending on a name match.

### Decision (confirmed with Jean)

- **Auto-derive a `host.yaml` at generation time** from the worlds loaded in the seed, enabling the
  host **permission gates**, with **no hand-maintained list** and **no per-option name mapping**.
- **Predicate:** for each loaded world, enable (`true`) every host setting that is a **`Bool`** whose
  **default is `False`** and whose name matches the permission convention (`allow_*` / `enable_*`).
  Skip everything else (non-bool: `RomFile`/`Executable`/paths; non-`allow_` server toggles;
  already-`True` gates). Only emit a section for worlds that actually have such a gate (tiny file).
- **Why this is safe even for gates no player requested:** a host setting only **permits**; the world
  still requires the player's own option to be on for any content to appear (VS `check_options`: host
  `true` + player `false` ⇒ normal generation, nothing added). So enabling all `allow_*` gates of the
  worlds in a seed never changes a seed unless a player opted in - it only removes the hard failure.
- **Implementation lives in `generate_multiworld.py`** (archipelago repo): it already loads every
  world in-process right before `Generate.main()`, so it can introspect `settings` and write the
  `host.yaml` there. **No orchestrateur/API change** is required.

## Acceptance Criteria

1. A seed containing a slot with a host-gated option set to `true` (e.g. Vampire Survivors
   `allow_unfair_characters: true`) **generates successfully** instead of failing with the
   host.yaml OptionError.
2. Generation writes a `host.yaml` (at the path Archipelago's generator reads it) **before** invoking
   generation, containing `<settings_key>: { <gate>: true }` for every loaded world that declares a
   `Bool` permission gate (`allow_*`/`enable_*`, default `False`). The `Could not find host.yaml`
   warning is gone.
3. **No non-bool host setting is written** (`RomFile`, `Executable`, `server_settings`, paths) and **no
   non-`allow_`/`enable_` toggle** is flipped - verified against Super Metroid (`rom_file` untouched)
   and Factorio (`filter_*`/`executable` untouched).
4. Enabling a gate the player did **not** request does not change the produced seed (host setting only
   permits) - a control generation without the player option yields the same result as before.
5. Defaults preserved: a seed with **no** host-gated options still generates exactly as today (the
   emitted host.yaml is either empty/absent of world sections or inert).
6. The derivation is **introspection-based** - it adds no per-game hardcoded list and works for a newly
   uploaded community apworld with an `allow_*` gate without code changes.
7. Gates green: archipelago image builds; orchestrateur `go build/vet/test` unaffected (no change
   there expected). Requires redeploy of `archipelago:latest`. Verified live: the failing VS run
   regenerates successfully; a SNES/ROM world (Super Metroid) still generates (rom path untouched).

## Tasks / Subtasks

- [ ] **Task 1 - Confirm the host.yaml read path** (AC: 2). In the generation container, determine
  where Archipelago's generator loads `host.yaml` (`Utils.user_path("host.yaml")` vs cwd; `settings.py`
  emitted the "Could not find host.yaml" warning). Write the derived file to that exact location so
  `get_settings()` picks it up. (Quick in-container check; `Utils.local_path`/`user_path` already
  imported by the introspection tooling.)
- [ ] **Task 2 - Derive + write host.yaml in `generate_multiworld.py`** (AC: 1-5). After worlds are
  loaded (the existing `_load_apworlds_from` / `AutoWorldRegister` step) and **before** `Generate.main()`:
  for each registered world, locate its `settings.Group` subclass, collect members that are `Bool`
  with default `False` and name `allow_*`/`enable_*`, and build a dict `{settings_key: {gate: True}}`.
  Merge with any existing generator config (don't clobber the 27.4 `generator:` section if that path
  ever writes host.yaml) and dump to the Task-1 path. Skip worlds with no such gate.
- [ ] **Task 3 - Introspection helper** (AC: 6). Factor the settings-group introspection into a small
  reusable function/module (mirrors `introspect_options.py`'s world-loading scaffold: stub finder,
  `settings.Group`/`settings.Bool` detection). Optionally ship a standalone `introspect_settings.py`
  (the spike script) as a diagnostic tool, but the generation path must not shell out to it - it runs
  in-process.
- [ ] **Task 4 - Guardrails** (AC: 3,4). Explicitly exclude non-`Bool` settings and non-permission
  toggles; unit/inline-test the predicate against the spike fixtures (VS → enable
  `allow_unfair_characters`; Stardew → enable `allow_chaos_er`/`allow_jojapocalypse`; Super Metroid →
  emit nothing; Factorio → emit nothing).
- [ ] **Task 5 - Live verification + redeploy** (AC: 7). Rebuild/redeploy `archipelago:latest`;
  re-run the originally failing VS seed → generation succeeds; run a Super Metroid seed → still
  succeeds (rom path untouched); a no-gate seed → unchanged.

## Dev Notes

- **Predicate (authoritative):** a host **permission gate** = member of a world's `settings.Group`
  that is (a) a `settings.Bool` subclass / `bool`-valued, (b) default `False`, (c) name starts with
  `allow_` or `enable_`. Set those to `True`. This deliberately ignores `RomFile`/`UserFilePath`/
  `Executable`/`server_settings` and behaviour toggles like Factorio `filter_*`.
- **Section key = `world.settings_key`** (spike-confirmed: VS = `vampire_survivors_options`,
  Stardew = `stardew_valley_options`, Super Metroid = `sm_options`).
- **Safety:** host setting only *permits*. VS `check_options`: `host=true, player=false` ⇒ no unfair
  characters added. So enabling gates for worlds in a seed is a no-op unless a player opted in.
- **Why not in the orchestrateur:** `generate_multiworld.py` already holds the loaded worlds in-process
  immediately before `Generate.main()`; deriving there avoids a second world-loading pass and keeps the
  blast radius to one repo. The orchestrateur generation request is unchanged.
- **Relation to 27.4:** orthogonal. 27.4 = `generator:` section via `Generate.py` CLI args. This =
  per-world `<settings_key>:` host sections via a written `host.yaml`. If both ever need to coexist in
  one host.yaml, merge rather than overwrite.
- **Per-world scope:** enabling gates only for worlds present in the seed is ideal but determining
  "used worlds" needs parsing the player YAMLs; enabling for **all loaded** worlds with a gate is
  equally safe (permission-only) and simpler - and the file stays tiny because only a handful of worlds
  have `allow_*` gates. Either is acceptable; prefer the simpler all-loaded form unless trivial.

### Project Structure Notes

- `archilan-archipelago/generate_multiworld.py` (derive + write host.yaml before `Generate.main()`)
- `archilan-archipelago/introspect_settings.py` (optional diagnostic tool - the spike script)
- No change expected in `archilan-orchestrateur` or `api/` (call out if Task 1 proves otherwise).
- Redeploy: `archipelago:latest`.

### References

- [Source: _bmad-output/implementation-artifacts/27-4-orchestrateur-generation-options.md (generator-section options via Generate.py args; host.yaml mechanism notes)]
- [Source: _bmad-output/implementation-artifacts/27-3-orchestrateur-server-options-launch.md (host.yaml NOT baked into the image)]
- [Source: archilan-archipelago/generate_multiworld.py (world loading + `Generate.main()` invocation)]
- [Source: archilan-archipelago/introspect_options.py (world-loading scaffold reused by the settings introspection)]
- [Spike: Vampire Survivors `Settings.py` (`VampireSurvivorsSettings(Group)`, `allow_unfair_characters=False`) + `Options.py::check_options` gate]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Spike Findings

See "Spike findings" under Context (introspection of `world.settings` across VS + 8 community
apworlds): host gates are introspectable `Bool` members (often `allow_*`, default `False`); name
mirroring is unreliable (Stardew); non-bool settings (`rom_file`, `executable`) must be skipped;
enabling a gate is permission-only and safe.

### Completion Notes List

- `generate_multiworld.py`: added `derive_host_gate_settings(world_types)` (pure introspection →
  `{settings_key: {gate: True}}`), `_find_settings_groups` / `_collect_host_gates` helpers, and
  `write_host_gate_yaml(host_settings, path)` (merge into host.yaml, create if absent). Called right
  after worlds load and **before** `Generate.main()`, writing to `Utils.user_path("host.yaml")`
  (= `/app/ArchipelagoSrc/host.yaml`, Task-1 confirmed).
- Predicate implemented exactly as specified: `Bool` member, default `False`, name `allow_*`/`enable_*`;
  MRO walk stops at Archipelago's `settings` base classes; non-bool (`RomFile`/`Executable`/paths) and
  non-permission toggles ignored.
- **No orchestrateur/API change** (the derivation runs in-process where worlds are already loaded).
- **Verified live in `archipelago:latest`** (mounted the modified script):
  - VS seed with `allow_unfair_characters: true` → **generates** (`AP_*.zip` produced); control
    without the fix → fails with the host.yaml `OptionError` (same message as the reported bug).
  - `DEBUG host gates enabled: {vampire_survivors_options: {allow_unfair_characters: True},
    stardew_valley_options: {allow_chaos_er: True, allow_jojapocalypse: True}}`.
  - Super Metroid `rom_file` and Factorio `filter_*`/`executable` untouched (not in the derived set).
  - `python -m py_compile` + `ruff check` clean.
- Spike confirmed the no-player rewrite dropping `vampire_survivors_options` was a **false negative**:
  with a real VS player, `world.settings` is accessed → the group attaches → reads the pre-written
  host.yaml value → `check_options` passes.
- Diagnostic `introspect_settings.py` (the spike script) **not shipped** - the logic is factored as
  reusable functions inside `generate_multiworld.py`; a standalone tool can be added later if needed.

### File List

- `archilan-archipelago/generate_multiworld.py` (derive + write host.yaml before `Generate.main()`)
- Redeploy required: `archipelago:latest`.
- Delivered via archilan-archipelago PR #4 (branch `feature/epic-27-story-11-host-gated-world-settings`).

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-12 | Story created from spike. Auto-derive a `host.yaml` in `generate_multiworld.py` enabling `allow_*`/`enable_*` `Bool` host permission gates (default False) for loaded worlds, so host-gated player options (e.g. VS `allow_unfair_characters`) stop failing generation. Introspection-based, no per-game list, no name mapping; non-bool/path settings excluded; permission-only (safe). Status → ready-for-dev. |
| 2026-06-12 | Implemented in `generate_multiworld.py` (archilan-archipelago PR #4). Verified live: VS gated seed generates; control fails; SM/Factorio untouched; py_compile + ruff clean. Status → review. Requires `archipelago:latest` redeploy. |
