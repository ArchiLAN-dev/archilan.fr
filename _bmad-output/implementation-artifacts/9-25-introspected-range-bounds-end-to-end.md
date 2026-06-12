# Story 9.25: Authoritative range bounds (min/max/default) from introspection, end to end

**Status:** review
**Epic:** 9 - apworld management & structured options
**Date:** 2026-06-12

## Story

As the YAML option editor,
I want the **authoritative** bounds and default of every `range` option (from the apworld's option
class, via introspection),
so that I stop guessing bounds from hardcoded lists / template comments / a `{0,100}` fallback - which
lets invalid values (e.g. `0` below a min of `1`) slip through and crash generation.

## Context

Today the frontend (`archipelago-yaml.ts`) is **self-contained**: it parses the `default_yaml`
template and derives range bounds from (1) a hardcoded `KNOWN_SCALAR_RANGES` map (only
`progression_balancing`), (2) `extractRange` scraping template comments ("Minimum value is X"), and
(3) a `{ min: 0, max: 100 }` fallback. The orchestrateur already exposes `RangeMin`/`RangeMax` on
`GET /apworlds/{hash}/options`, but only from a Go template parser (comments), and the **introspected**
types (`OptionTypeOverride`) carry only `type` + `defaultWeights` - **no bounds/default**. The
authoritative source - the apworld's `Options.py` Range class (`range_start` / `range_end` /
`default`) - is never surfaced. A spike confirmed introspection can read it
(`song_difficulty_min` → start=1, end=11, default=4; `starting_song_count` → 3/10/5; …).

Decision (confirmed with Jean): deliver a **structured option-types payload** end to end -
introspection is the authoritative source; the frontend consumes it and drops the hardcoded map +
comment-scraping + `{0,100}` fallback.

## Acceptance Criteria

1. **archipelago** `introspect_options.py` emits, for each `range` option, `min` / `max` / `default`
   (from `range_start` / `range_end` / `default`). Existing fields (`type`, `defaultWeights`)
   unchanged. (Requires `archipelago:latest` redeploy.)
2. **orchestrateur** `OptionTypeOverride` carries `Min` / `Max` / `Default`; on
   `GET /apworlds/{hash}/options` the introspected bounds/default **override** the template-parsed
   `RangeMin`/`RangeMax`/`DefaultValue` when present. (Requires redeploy.)
3. **api** fetches the structured options at apworld upload (orchestrateur-client `getOptions($hash)`,
   already available) and stores them on the `Game` (`option_types` JSON) ; a backfill console command
   populates existing games. The slot/game payloads consumed by the editor expose `optionTypes`.
4. **frontend** passes `optionTypes` to `YamlOptionEditor`; `archipelago-yaml.ts` uses the provided
   `min`/`max`/`default` for `range` options. `KNOWN_SCALAR_RANGES` and the `{min:0,max:100}` fallback
   are removed (comment-scraping kept only as a last-resort when no structured data is present).
5. A `range` option always defaults to its authoritative default (never 0 when min>0) and clamps to
   `[min,max]`; the Muse Dash difficulty/song-count cases render correct bounds.
6. Gates green per repo: archipelago (py_compile/ruff), orchestrateur (go build/vet/test), api
   (phpstan/cs-fixer/phpunit/ddd), frontend (typecheck/lint/build). Verified live: option editor shows
   correct min/default for Muse Dash ranges.

## Tasks / Subtasks

- [ ] **Task 1 - archipelago** (AC: 1). `introspect_options.py`: when an option field's resolved type
  is a `Range` (has `range_start`/`range_end`), emit `min`/`max`/`default`. Keep the `weights`/`toggle`/
  `choice` classification intact.
- [ ] **Task 2 - orchestrateur** (AC: 2). `OptionTypeOverride` += `Min *int`, `Max *int`,
  `Default any`. In `handleGetApworldOptions`, when an override has bounds, set
  `opt.RangeMin/RangeMax/DefaultValue` from it (introspection wins over templateparser). `go test`.
- [ ] **Task 3 - api storage** (AC: 3). `Game.optionTypes` (JSON, nullable) + migration; setter on the
  aggregate. `RunnerGateway::uploadApworld` returns `optionTypes` via `getOptions($hash)`;
  `AdminGameLibrary` stores it. Backfill command `app:games:backfill-option-types`.
- [ ] **Task 4 - api exposure** (AC: 3). Add `optionTypes` to the payloads that already carry
  `defaultYaml` (`PersonalRunGameSelection`, the registration game-selection query).
- [ ] **Task 5 - frontend** (AC: 4,5). `YamlOptionEditor` takes an `optionTypes` prop and threads it
  into `parseDefaultYaml` / `buildOption`; use introspected bounds/default for ranges; remove
  `KNOWN_SCALAR_RANGES` + `{0,100}` fallback (comment-scrape last-resort only).
- [ ] **Task 6 - tests + gates + live** (AC: 6). Unit tests each layer; all gates; verify live.

## Dev Notes

- The orchestrateur-client already has `ApworldsClient::getOptions($hash)` → `/apworlds/{hash}/options`
  (returns per-option `type`, `rangeMin`, `rangeMax`, `defaultValue`, `weights`). **No package change.**
- Delivery order (dependencies): archipelago → orchestrateur (both redeploy) → api → frontend.
- Backfill needed because option types are captured at upload; existing games predate this. The
  backfill re-fetches `getOptions($hash)` per game (works once the orchestrateur is redeployed).
- Keep `extractRange`/comments as a graceful fallback for games whose `optionTypes` is null (not yet
  backfilled), so nothing regresses mid-rollout. Story 4.15 (all-zero-weight guard) stays as a
  complementary safety net.

### Project Structure Notes

- `archilan-archipelago/introspect_options.py`
- `archilan-orchestrateur/internal/service/service.go` (`OptionTypeOverride`), `internal/api/handlers.go`
- `api/src/GameSelection/Domain/Game.php` (+ migration), `Application/AdminGameLibrary.php`,
  `Sessions/Infrastructure/RunnerGateway.php`, `PersonalRuns/Application/PersonalRunGameSelection.php`,
  registration game-selection query, a backfill command
- `frontend/src/lib/archipelago-yaml.ts`, `frontend/src/features/events/yaml-option-editor.tsx` + the
  pages passing `optionTypes`

### References

- [Source: _bmad-output/implementation-artifacts/9-19-apworld-template-structured-options.md]
- [Source: _bmad-output/implementation-artifacts/4-15-weighted-option-zero-weight-validation.md (complementary guard)]
- [Source: orchestrateur/internal/api/handlers.go (handleGetApworldOptions, TemplateOption.RangeMin/Max)]
- [Source: frontend/src/lib/archipelago-yaml.ts (KNOWN_SCALAR_RANGES, extractRange, {0,100} fallback)]
- [Spike 2026-06-12: introspection reads range_start/range_end/default per Range option]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Completion Notes List

- **Layer 1 (archipelago, PR #5):** `introspect_options.py` emits `min`/`max`/`default` for range
  options. Verified on Muse Dash.
- **Layer 2 (orchestrateur, PR #9):** `OptionTypeOverride` carries the bounds; `/apworlds/{hash}/options`
  lets introspection override the template parse. `go test` + new unit test.
- **Layer 3 (api):** `Game.optionTypes` (JSON) + migration `Version20260611100004`;
  `RunnerGateway::fetchOptionTypes` (range bounds via `getOptions`, no package change) wired into
  `uploadApworld`; `AdminGameLibrary` stores it (with a phpstan-safe normalizer); `optionTypes` exposed
  in PersonalRun + Registration game-selection payloads and the admin game detail; backfill command
  `app:games:backfill-option-types` (+ service). Domain setter kept pure. All gates green; unit test
  for the backfill.
- **Layer 4 (frontend):** `parseDefaultYaml(yaml, optionTypes)` threads introspected bounds into
  `buildOption`; `KNOWN_SCALAR_RANGES` removed (a scalar known to introspection is now a range);
  introspected bounds preferred over `extractRange` (comments kept as fallback, `{0,100}` only as the
  un-backfilled last resort). `asOptionTypesMap` validator; `optionTypes` prop threaded through
  `YamlOptionEditor` and all three consumers (personal-run slot, registration gate, admin weekly
  template). typecheck/lint/build green; jest for bounds + validator.
- **Rollout:** merge PR #5 + #9, rebuild/redeploy `archipelago:latest` + orchestrateur, then run
  `php bin/console app:games:backfill-option-types`. Until backfilled, games fall back to comment
  bounds (no regression). Story 4.15 (all-zero-weight guard) is the complementary safety net.

### File List

- `archilan-archipelago/introspect_options.py` (PR #5)
- `archilan-orchestrateur/internal/service/service.go`, `internal/api/handlers.go`,
  `internal/service/option_bounds_test.go` (PR #9)
- `api/src/GameSelection/Domain/Game.php`, `api/migrations/Version20260611100004.php`,
  `api/src/GameSelection/Application/AdminGameLibrary.php`,
  `api/src/GameSelection/Application/BackfillGameOptionTypes.php`,
  `api/src/GameSelection/Presentation/BackfillGameOptionTypesCommand.php`,
  `api/src/Sessions/Application/RunnerGatewayInterface.php`,
  `api/src/Sessions/Infrastructure/RunnerGateway.php`, `api/src/Sessions/Infrastructure/NullRunnerGateway.php`,
  `api/src/PersonalRuns/Application/PersonalRunGameSelection.php`,
  `api/src/Registrations/Application/RegistrationGameSelection.php`,
  `api/tests/Unit/GameSelection/BackfillGameOptionTypesTest.php`
- `frontend/src/lib/archipelago-yaml.ts`, `frontend/src/features/events/yaml-option-editor.tsx`,
  `frontend/src/features/personal-runs/personal-run-slot-yaml-page.tsx`,
  `frontend/src/features/events/slot-yaml-gate.tsx`,
  `frontend/src/features/admin/admin-weekly-runs-api.ts`,
  `frontend/src/features/admin/admin-weekly-template-form.tsx`,
  `frontend/src/lib/archipelago-yaml-bounds.test.ts`

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-12 | Story created. Surface authoritative range bounds (min/max/default) from introspection through orchestrateur → api → frontend; drop the frontend's hardcoded map + comment-scraping + {0,100} fallback. Multi-repo + migration + backfill + redeploys. Status → in-progress. |
| 2026-06-12 | Implemented all 4 layers. archipelago PR #5, orchestrateur PR #9; api+frontend on `feature/epic-9-story-25-introspect-range-bounds` (migration + Game.optionTypes + RunnerGateway fetch + exposure + backfill command + frontend threading). All gates green per repo. Status → review. Requires microservice redeploys + backfill run. |
