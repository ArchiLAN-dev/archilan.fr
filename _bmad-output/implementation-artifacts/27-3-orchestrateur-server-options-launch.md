# Story 27.3: Orchestrateur + archipelago - remaining server_options on launch

Status: done

## Story

As the orchestrateur,
I want to accept the full set of relevant server options on a session launch and pass them to the AP
server container,
so that launched runs enforce the admin-configured policy (not just release/collect).

## Context

Release/collect modes are already configurable per session end-to-end (shipped this session:
orchestrateur `LaunchRequest.ReleaseMode/CollectMode` → `CreateAPServer` env `RELEASE_MODE`/`COLLECT_MODE`;
archipelago `ap_server.sh`/`entrypoint.sh` `--release_mode`/`--collect_mode`). This story extends the
**same env→flag pattern** to the remaining server options. Cross-repo: `archilan-orchestrateur` (Go,
`master`) and `archilan-archipelago` (`master`).

## Acceptance Criteria

1. archipelago `ap_server.sh` **and** `entrypoint.sh` pass the additional `ArchipelagoServer` flags from
   env, each defaulting to a safe/disabled value and overridable: `--remaining_mode "${REMAINING_MODE:-goal}"`,
   `--disable_item_cheat` toggled by `DISABLE_ITEM_CHEAT`, `--hint_cost "${HINT_COST:-10}"`,
   `--location_check_points "${LOCATION_CHECK_POINTS:-1}"`, `--countdown "${COUNTDOWN_MODE:-auto}"`,
   `--auto_shutdown "${AUTO_SHUTDOWN:-0}"`, `--compatibility "${COMPATIBILITY:-2}"`. The join password
   already maps to `PASSWORD`.
2. Flag names/values match the bundled Archipelago `MultiServer.py` argparse exactly (verify
   `--disable_item_cheat` is a store-flag vs a value arg, and the exact `--location_check_points`/
   `--hint_cost` names). Empty/unset env → the script's documented default applies; no flag is emitted
   for booleans that are false where AP expects presence-only.
3. orchestrateur `LaunchRequest` + `APServerCreateConfig` gain the corresponding fields; `CreateAPServer`
   appends each `*_MODE`/value env var **only when set** (mirroring the release/collect handling), so the
   script defaults stand otherwise.
4. orchestrateur validates each value (reject unknown enum / out-of-range int) → `ErrInvalidMode`-style
   400 on the launch endpoints (`/sessions/{id}/launch` JSON and `/launch-from-file` form).
5. The launch API surface (JSON body + multipart form) accepts the new fields (`remainingMode`,
   `disableItemCheat`, `hintCost`, `locationCheckPoints`, `countdownMode`, `autoShutdown`,
   `compatibility`). Swagger `@Param` updated.
6. `go build/vet/test` green (extend the existing mode-validation test) and the archipelago image builds
   (`Build & Push` CI). Requires rebuilding/redeploying `archipelago:latest` to take effect.

## Tasks / Subtasks

- [x] Task 1 (archipelago) - Add the flags to `ap_server.sh` and `entrypoint.sh` with `${VAR:-default}`
  env indirection; verify exact argparse names/semantics against the bundled `MultiServer.py`. `sh -n`.
- [x] Task 2 (orchestrateur) - Extend `LaunchRequest` + `APServerCreateConfig` (internal/service,
  internal/docker) with the new fields; append env in `CreateAPServer` only when non-empty; thread
  through `Launch → startSession → CreateAPServer` (same shape as ReleaseMode/CollectMode).
- [x] Task 3 (orchestrateur) - Validation helpers (reuse/extend `validAPMode`; add range checks); map to
  400 in `writeSessionError`. Extend `internal/service/mode_test.go`.
- [x] Task 4 (orchestrateur) - API: `LaunchSessionRequest` JSON fields + `/launch-from-file` form values
  + swagger `@Param`.
- [x] Task 5 - `go build/vet/test`; PR to orchestrateur `master`; PR to archipelago `master`; both CI green.

## Dev Notes

- **Exact precedent to copy:** the release/collect implementation merged this session -
  archipelago `ap_server.sh`/`entrypoint.sh` (`--release_mode "${RELEASE_MODE:-disabled}"`), orchestrateur
  `internal/docker/client.go` `CreateAPServer` (env appended only when non-empty),
  `internal/service/session.go` `LaunchRequest`/`startSession`/`validAPMode`,
  `internal/api/{types.go,session_handlers.go}`.
- **Verify argparse** in `runner/docs archipelago/Archipelago-main/MultiServer.py`: `--disable_item_cheat`
  is `store_true` (presence-only) → only add the flag when true; `--hint_cost`, `--location_check_points`
  are value args; `--countdown` choices `enabled|disabled|auto`; `--compatibility` int.
- Defense in depth: validate in orchestrateur **and** (27.1) in the api/ domain.
- `host.yaml` is NOT baked into the image (no COPY) → CLI flags are authoritative; empty env keeps the
  AP built-in default, which for release/collect is `auto` - hence we always set safe defaults in scripts.

### Project Structure Notes

- Two separate repos, both `master`-only Gitflow (feature branch → PR → master). Deploy discipline: ship
  + redeploy both images before 27.5 wiring is meaningful (epic risk note / retro action #2).

### References

- [Source: _bmad-output/planning-artifacts/epic-27-configurable-session-server-options.md]
- [Source: archilan-archipelago ap_server.sh / entrypoint.sh (release/collect precedent)]
- [Source: archilan-orchestrateur internal/docker/client.go CreateAPServer; internal/service/session.go Launch/startSession]
- [Source: runner/docs archipelago/Archipelago-main/MultiServer.py argparse]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Debug Log References

- Verified exact flags in bundled `MultiServer.py` argparse: the countdown flag is
  `--countdown_mode` (not `--countdown`); `--disable_item_cheat` is `store_true` (presence-only);
  `--hint_cost`, `--location_check_points`, `--auto_shutdown`, `--compatibility` are value args;
  `--remaining_mode` choices `enabled|disabled|goal`.

### Completion Notes List

- **archipelago** (PR archilan-archipelago#3, merged to `master`): `ap_server.sh` + `entrypoint.sh`
  rebuilt with `set --` so the presence-only `--disable_item_cheat` is appended conditionally; value
  flags use `${VAR:-default}` with defaults = AP defaults (so unset env = no behaviour change);
  release/collect keep forced `disabled`.
- **orchestrateur** (PR archilan-orchestrateur#6, merged to `master`): `LaunchRequest` +
  `APServerCreateConfig` gained `RemainingMode`, `CountdownMode` (strings), `DisableItemCheat` (*bool),
  `HintCost`/`LocationCheckPoints`/`AutoShutdown`/`Compatibility` (*int - pointers so meaningful zeros
  like `compatibility=0` are distinct from unset). `CreateAPServer` emits each env only when set.
  `validateServerOptions` validates modes + int ranges → 400 (`ErrInvalidMode`). API: JSON
  `LaunchSessionRequest` + `/launch-from-file` form parsing (`parseOptInt`/`parseOptBool` +
  `serverOptionsFromForm`); swagger `@Param` updated. `startSession` refactored to take the
  `LaunchRequest`. `go build/vet/test` green (`TestValidateServerOptions`).
- Both `:latest` images built on `master` merge. **Requires redeploy** to take effect on launched
  servers; applies to new launches only.
- Not in this story: the api/ resolution + wiring (27.5) and the config store/UI (27.1/27.2/27.6) -
  this story only gives the orchestrateur/archipelago the *capability* to accept the options.

### File List

- archilan-archipelago: `ap_server.sh`, `entrypoint.sh` (modified).
- archilan-orchestrateur: `internal/service/session.go`, `internal/docker/client.go`,
  `internal/api/types.go`, `internal/api/session_handlers.go`, `internal/service/mode_test.go` (modified).

## Change Log

| Date       | Change |
|------------|--------|
| 2026-06-09 | Story created from epic 27 plan (orchestrateur/archipelago server_options). |
| 2026-06-09 | Implemented: archipelago#3 + orchestrateur#6 merged to `master`, CI green, `:latest` rebuilt. Status → review. |
