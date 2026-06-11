# Epic 27 - Configurable session server & generation options (admin)

Status: planned (not started)
Date: 2026-06-09

## Goal

Give admins a single place to configure the **relevant** Archipelago options applied to launched
sessions, **per session type** (private / event / weekly), with an **optional per-session override**.
Launched runs use the resolved config. No game-specific client settings are exposed - only the
server policy + a few generation options that matter competitively.

## Decisions (locked)

- **Config model:** type-level **profiles** (Private / Event / Weekly) editable by admins, **+ optional
  per-session override** (the type profile is the default; a session may override individual fields).
- **Two enforcement points** (important): the chosen options split across two moments -
  - **Generation-time** (Archipelago `generator` section, `generate_multiworld.py`): `plando_options`,
    `race`, `spoiler`.
  - **Server launch-time** (`server_options`, `ap_server.sh` → `ArchipelagoServer` flags): the rest.
- **Admin-only.** ROLE-gated by `ApiAccessGuard::requireAdmin`.

## Options in scope ("pertinentes")

### Server (launch-time → `ArchipelagoServer` flags via env)
| Option | Values | Notes |
|--------|--------|-------|
| `release_mode` | disabled/enabled/goal/auto/auto-enabled | ✅ already plumbed orchestrateur+archipelago (this session) |
| `collect_mode` | idem | ✅ already plumbed |
| `remaining_mode` | enabled/disabled/goal | to add |
| `disable_item_cheat` | bool (`!getitem`) | anti-cheat for competitive |
| `hint_cost` | int % | hint economy |
| `location_check_points` | int | points per check |
| `countdown_mode` | enabled/disabled/auto | |
| `auto_shutdown` | int seconds (0 = off) | ops |
| `compatibility` | 2 (casual) / 0 (tournament) | |
| join **password** (`password`) | string | the *player* join password (NOT the admin `server_password`, already per-session) |

### Generation (generation-time → generator config)
| Option | Values | Notes |
|--------|--------|-------|
| `plando_options` | subset of bosses/items/texts/connections | |
| `race` | bool (0/1) | encrypted race roms + race mode |
| `spoiler` | 0/1/2/3 | spoiler detail level |

Explicitly **out of scope**: `host`, `port`, `multidata`, `savefile`, `disable_save`, log levels, and
all per-game `*_options` (ROM paths, emulator paths…) - managed by the orchestrateur or irrelevant.

## Affected systems (verified)

- **archipelago** (separate repo `archilan-archipelago`, `master`): `ap_server.sh` + `entrypoint.sh`
  (server flags via env, same pattern as `RELEASE_MODE`/`COLLECT_MODE`), `generate_multiworld.py`
  (generator options).
- **orchestrateur** (separate repo, `master`): `LaunchRequest` + `CreateAPServer` env (server opts;
  release/collect already done), `GenerateRequest` + generation flow (generation opts).
- **api/** (Sf/DDD): new config domain + persistence + admin endpoints + resolution service; wire into
  the three gateways - `WeeklyRuns` (`OrchestratorWeeklyRunnerGateway`, `GenerateWeeklyRun*`),
  `Sessions` (`RunnerGateway`/`SessionOrchestrator`), `PersonalRuns` (`LaunchPersonalRunJobHandler`).
- **frontend**: admin form (per-type tabs, grouped fields) + optional per-session override UI.

## Proposed stories

- **27.1 - Config domain + validation (api/).** `SessionServerConfig` / `SessionGenerationConfig`
  value objects (enums + ranges, pure domain). Type-profile + override model. Sensible defaults
  (weekly/event = competitive: release/collect/item_cheat disabled; private = laxer). Unit tests.
- **27.2 - Config persistence + admin API (api/).** Migration (`session_config_profiles` keyed by
  type; per-session override storage), Doctrine repo + DBAL query, `GET/PUT /admin/session-config/{type}`,
  and a **resolution service** (effective = profile ⊕ session override). Functional tests.
- **27.3 - Orchestrateur server_options on launch (orchestrateur + archipelago).** Extend
  `LaunchRequest`/`CreateAPServer` + `ap_server.sh`/`entrypoint.sh` for `remaining_mode`,
  `disable_item_cheat`, `hint_cost`, `location_check_points`, `countdown_mode`, `auto_shutdown`,
  `compatibility`, join `password`. Validate; safe defaults when unset. (release/collect already done.)
- **27.4 - Orchestrateur generation options (orchestrateur + archipelago).** `GenerateRequest` +
  `generate_multiworld.py` accept `plando_options`, `race`, `spoiler`, written into the generator
  config at generation. Validate.
- **27.5 - Wire config into the 3 gateways (api/).** Each launch/generation path resolves the
  effective config for its type (+ override) and passes it to the orchestrateur gateway. Extend the
  three gateway interfaces + their `Orchestrator*` implementations. Functional tests with spies.
- **27.6 - Admin form (frontend).** Admin page with per-type tabs (Privé / Événement / Weekly),
  fields grouped **Serveur** / **Génération**, client+server validation, TanStack mutation to the
  config API. Gates: typecheck/lint/build.
- **27.7 - Per-session override UI + E2E (frontend + test).** Override controls where each session
  type is created/launched; extend the weekly E2E smoke (story 23.13) to assert a configured mode
  (e.g. `release_mode`) is actually applied on the launched AP server.

## Sequencing

Infra first (so the API has something to call), then API, then UI:
`27.3 + 27.4` (orchestrateur/archipelago, deployable independently) → `27.1` → `27.2` → `27.5` →
`27.6` → `27.7`.

## Risks / notes

- **Two repos to deploy** (archipelago + orchestrateur) before the api/ wiring is meaningful - same
  cross-repo deploy discipline as epic 23 (retro action item #2).
- **Override resolution** must be unambiguous (per-field merge, not whole-object replace) - define in 27.1.
- The orchestrateur does **not** persist launch params across restarts (see release/collect note); a
  crashed-session restart will reuse defaults unless 27.2 also persists the resolved config per session.
- Validation must reject bad values **before** launch (a bad flag crashes `ArchipelagoServer`); validate
  in the api/ domain (27.1) **and** the orchestrateur (defense in depth).
- Foundation already in place: release/collect modes are configurable per session end-to-end
  (archipelago `ap_server.sh`/`entrypoint.sh` + orchestrateur `LaunchRequest`/`CreateAPServer`).

## Change Log

| Date       | Change |
|------------|--------|
| 2026-06-09 | Epic planned. Model = per-type profiles + per-session override. Options + two enforcement points (generation + server launch) captured. Stories 27.1–27.7 proposed. |
