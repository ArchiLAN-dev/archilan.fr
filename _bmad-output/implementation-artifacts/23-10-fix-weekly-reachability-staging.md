# Story 23.10 (bugfix): Restore weekly reachability after generate-once

## Status

done

## Context

Story 23.8 switched the per-player weekly launch from `configure + generate + launch`
to `launchFromFile` (reuse the pre-generated `.archipelago`). That removed the step
that populated the player session **volume** with `/data/yamls` and `/data/worlds`
(previously done by `buildDataTar` inside `Generate`).

The AP server still runs (it loads the multidata from `/data/output`), but the
**reachability tracking** (page « ma-run ») runs `archipelago/reachable.py` via the
session's bridge, which **rebuilds the world from the player YAML** and loads the
session-specific apworld. With an empty `/data/yamls` it fails:

```
{"error": "no yaml found in /data/yamls"}
```

(Observed live, 2026-06-08.) A secondary, pre-existing log line —
`KeyError: 'POKEDEX_REWARD_001'` while loading the **official** bundled
`pokemon_emerald.apworld` — is only a warning: `reachable.py` loads official apworlds
first, then **session-specific** ones that override them. Once the correct session
apworld is staged (this fix), reachability uses it and the official-version mismatch
is harmless. (Rebuilding the AP image so bundled apworlds match the core stays an ops
concern, out of scope here.)

## Acceptance Criteria

**AC1 (orchestrator):** `LaunchFromFile` stages the session's `/data/yamls` + `/data/worlds`
from MinIO (`buildDataTar`) into the volume **in addition** to injecting the pre-generated
output. No Archipelago generation is run.

**AC2 (API):** `OrchestratorWeeklyRunnerGateway::launchEntry` first `configure`s the entry
session (uploads the template YAML + manifest to `sessions/{entryId}/` so `buildDataTar`
has something to stage), then downloads the run's output (MinIO `outputKey`) and calls
`launchFromFile`. Still **zero** generation. `WeeklyRunnerGatewayInterface::launchEntry`
signature becomes `(entryId, apworldHash, templateYaml, outputKey)`.

**AC3 (API):** `LaunchWeeklyEntry` resolves the apworld hash from the run's game
(`Game::getApworldHash()`, which is the orchestrator content hash) and passes it through.
`reachable.py` already tolerates apworld drift (prefers the session datapackage), so using
the current game hash is safe.

**AC4:** After a player launches a weekly entry, the « ma-run » page computes reachability
with no `no yaml found` error (validated live; automated coverage via the spy gateway
asserting `configure` happens before `launchFromFile`).

**AC5:** All quality gates pass — orchestrator (`go build/vet/test`) and API (`phpstan`,
`php-cs-fixer`, `phpunit`, `app:architecture:ddd`).

## Tasks / Subtasks

- [ ] Task 1: Orchestrator — extract `docker.PutDataToVolume(ctx, sessionID, tar)` from
  `InjectFileToVolume`; `LaunchFromFile` stages `buildDataTar` then injects the output.
- [ ] Task 2: API — `launchEntry(entryId, apworldHash, templateYaml, outputKey)`:
  configure → download output → `launchFromFile`. Re-add httpClient/baseUrl/apiKey deps.
- [ ] Task 3: API — `LaunchWeeklyEntry` fetches the game, passes `apworldHash`/`templateYaml`/`outputKey`.
- [ ] Task 4: API — update `Null`/`Spy` gateways + unit/functional tests (spy asserts configure-before-launchFromFile; launch still works).
- [ ] Task 5: Quality gates (orchestrator + API).

## Dev Notes

- `game.apworld_hash` is set by `AdminGameLibrary` from the runner's content hash
  (`configureApworld($storageKey, $hash, …)`), and the apworld lives in the orchestrator
  apworlds bucket under that hash — so `configure`'s `ApworldExists(hash)` passes and
  `buildDataTar` can `DownloadApworld(hash)`.
- `buildDataTar(sessionID)` reads `sessions/{sessionID}/manifest.json` + `yamls/` and stages
  `worlds/` + `yamls/` (+ the Bridge observer slot). `configure` is what writes those MinIO
  objects for the entry session.

## File List

### Orchestrator (`archilan-orchestrateur`)
- `internal/docker/client.go` — `PutDataToVolume`; `InjectFileToVolume` reuses it
- `internal/service/session.go` — `LaunchFromFile` stages `buildDataTar`

### API (`api/`)
- `src/WeeklyRuns/Application/WeeklyRunnerGatewayInterface.php` — signature
- `src/WeeklyRuns/Infrastructure/OrchestratorWeeklyRunnerGateway.php` — configure + launchFromFile
- `src/WeeklyRuns/Infrastructure/{Null,Spy}WeeklyRunnerGateway.php` — signature
- `src/WeeklyRuns/Application/LaunchWeeklyEntry.php` — resolve apworld hash from game
- `config/services.yaml` — gateway deps
- tests — `LaunchWeeklyEntryTest`, `WeeklyRunLaunchTest`

## Change Log

| Date       | Change |
|------------|--------|
| 2026-06-08 | Bugfix story created — restore weekly reachability broken by 23.8's launchFromFile (volume missing /data/yamls + /data/worlds). |
