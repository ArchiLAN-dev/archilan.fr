# Story 9.38: Apworld Upload Preflight - Solo Test Generation

Status: review

## Story

As an **admin uploading a custom apworld**,
I want **the platform to run a solo test generation (the apworld alone, with its default
template YAML) and record a pass/fail verdict before the world can be used in sessions**,
so that **non-generable worlds (unimplemented rules, generation-time network calls, broken
item pools) are caught at upload time instead of crashing the generation of a full
multi-slot session**.

## Context - why now

Three real incidents on 2026-07-25, all discovered only when a 19-slot personal-run
generation crashed:

1. **Codeforces** - calls the codeforces.com API during `create_regions`. The generation
   container runs with `NetworkMode: "none"`, so it can never work. Deterministic failure.
2. **Dark Cloud 1** - prints `TODO: rules` during generation; unimplemented logic plus item
   pool trimming made the accessibility check fail on every seed. Deterministic failure.
3. **Crystal Project** - logic blind spot; fails the accessibility check on some seeds only.
   Probabilistic failure; a single-seed preflight may pass it (see AC7 / out-of-scope).

A solo preflight generation at upload catches classes 1 and 2 outright and gives the admin
an actionable verdict + error excerpt, instead of a crashed session at LAN time.

## Acceptance Criteria

**AC1 - Preflight run:** After a successful apworld upload (`POST /apworlds` on the
orchestrateur), a preflight generation runs **asynchronously** (same pattern as the
existing background `IntrospectOptions`): a one-shot Archipelago container executes
`generate_multiworld.py` with exactly one player YAML = the template generated at upload,
plus the uploaded apworld, with `NetworkMode: "none"` (identical to production generation,
so online-only worlds fail here exactly as they would in production). A hard timeout bounds
the run (default 300s); timeout counts as failure.

**AC2 - Verdict persisted:** The apworld's MinIO metadata (`storage.ApworldMeta`) is
extended with `preflight`: `status` (`pending` | `passed` | `failed` | `skipped`),
`checkedAt`, and on failure an `error` excerpt (bounded, last ~2000 chars of stderr - the
tail contains the Python traceback). `skipped` is used when no template YAML exists
(template generation already failed non-fatally at upload) - the check cannot run, which
the UI must show as "unknown", not as "passed".

**AC3 - Verdict exposed:** `GET /apworlds` (orchestrateur list) includes the preflight
verdict per entry; the Symfony `RunnerGateway` and the runner PHP client pass it through;
the admin apworld/game library UI shows a badge (passed / failed / pending / unknown) and,
for failures, the error excerpt in a details view.

**AC4 - Failed worlds are blocked with override:** An apworld whose preflight status is
`failed` cannot be attached to a session/game configuration (server-side check, not just
UI). An admin can explicitly override per apworld ("force allow", persisted in the same
meta), because a world may fail on its template defaults yet generate fine with real player
options. `pending`/`skipped` do NOT block (warn only) - upload UX must not depend on the
async check having finished.

**AC5 - Re-check:** The verdict can be recomputed on demand: an orchestrateur endpoint
(`POST /apworlds/{hash}/preflight`) re-runs the check, plus a Symfony console command that
sweeps the existing pool (backfill: the worlds that caused the incidents are already
uploaded). Re-upload of the same hash also re-runs it.

**AC6 - Failure isolation:** A preflight crash (Docker error, storage error) leaves status
`pending`/previous value and logs a warning; it never fails the upload itself. The upload
response keeps its current shape plus the initial preflight status.

**AC7 - Honest scope:** Single seed by default (seed count configurable in orchestrateur
config). The story does NOT claim to catch seed-dependent logic holes (Crystal Project
class) nor option-combination failures - the check validates template defaults only. This
limitation is stated in the admin UI copy ("vérifié avec les options par défaut").

**AC8 - Quality gates:** orchestrateur `go test ./...` green; api `composer gates` green;
frontend `pnpm gates` green.

## Tasks / Subtasks

- [x] Task 1: orchestrateur - preflight runner (AC1, AC6)
  - [x] `internal/docker/client.go`: `PreflightGenerate(ctx, apworldData, hash, templateYaml)`
        one-shot container modeled on `GenerateMultiworld` (lines ~561-645) + the tar-packing
        pattern of `GenerateTemplate` (lines ~461-505): pack `worlds/{hash}.apworld` +
        `yamls/preflight.yaml` (= template) into `/data`, run `generate_multiworld.py
        --player_files_path /data/yamls --outputpath /data/output --world_directory
        /data/worlds`, `NetworkMode: "none"`, wait with timeout, return exit code + stderr tail.
  - [x] `internal/service/service.go`: hook into `UploadApworld` after meta upload, async
        goroutine like the existing IntrospectOptions block (lines ~102-110); write verdict
        via storage.
- [x] Task 2: orchestrateur - meta + API (AC2, AC3, AC5)
  - [x] `internal/storage/client.go`: extend `ApworldMeta` (preflight status/error/checkedAt/
        overridden); read-modify-write helper.
  - [x] `internal/api`: verdict in `handleListApworlds` response; `POST /apworlds/{hash}/preflight`
        (re-run) + `POST /apworlds/{hash}/preflight-override` (AC4 override); router entries.
  - [ ] Go tests: service preflight flow with fake docker/storage (pass, fail, timeout,
        skipped-no-template, docker-error leaves pending).
- [x] Task 3: api - pass-through + blocking (AC3, AC4, AC5)
  - [ ] Runner PHP client (`packages/`): expose verdict in `apworlds()->list()` DTO + new
        `preflight()`/`overridePreflight()` calls.
  - [ ] `Sessions\Infrastructure\Http\RunnerGateway`: map verdict into the existing apworld
        list payload consumed by GameSelection.
  - [ ] Server-side block: reject attaching a `failed`+non-overridden apworld to a session
        config (Application layer of the context that owns that write; return the standard
        `['found','errors','data']` outcome, message includes the excerpt). Primary choke
        point: the PersonalRuns game-selection write path (tonight's incidents were personal
        runs); weekly/event flows reuse the same apworld list and inherit the flag display,
        their hard block may land as a follow-up if the write paths differ.
  - [ ] Console command (Presentation/Command) sweeping existing apworlds via the re-check
        endpoint (AC5 backfill).
  - [ ] Unit tests per AC-T3 (interface mocks; no live HTTP).
- [x] Task 4: frontend - admin surface (AC3, AC4, AC7)
  - [ ] Badge (passed/failed/pending/unknown) in the admin apworld/game library list; details
        panel showing the stderr excerpt; override action with confirmation.
  - [ ] API layer per AC-API1/TS3 (typed result + type guard); tests for the guard.
- [x] Task 5: gates + story wrap-up (AC8)

## Dev Notes

### Existing code to reuse - do NOT reinvent

- One-shot container run + log capture: `orchestrateur/internal/docker/client.go` -
  `GenerateTemplate` / `IntrospectOptions` (create, putArchiveTo, start, wait, containerLogs,
  Remove in defer). `containerLogs(ctx, id, false, true)` returns stderr.
- Async post-upload job + non-fatal logging: the IntrospectOptions goroutine in
  `internal/service/service.go` (~line 102).
- Production generation command line & network isolation to mirror:
  `GenerateMultiworld` (`internal/docker/client.go:561`) - `NetworkMode: "none"` is the
  behavior that makes online-only worlds fail; keep it identical.
- Meta storage: `storage.ApworldMeta` already exists (`UploadApworldMeta`); extend, do not
  create a parallel store.
- `generate_multiworld.py` needs no change: a directory with a single template YAML is a
  valid single-player generation input.

### Design decisions (settled)

- **Async, non-blocking upload** - a preflight can take minutes on heavy worlds; upload UX
  already waits for template generation and must not also wait for this (mirror of the
  introspection choice, story 9.33).
- **Block on `failed` with per-apworld override** - hard reject at upload is wrong because
  the check is async and because template defaults can fail where real options succeed;
  silent warn-only is wrong because tonight's incidents happen at session time. Blocking
  the attach step with an explicit override is the middle that keeps both properties.
- **stderr tail as the stored excerpt** - the Python traceback is at the end of stderr;
  2000 chars keeps MinIO meta small while containing the actionable part.

### Windows/dev note

Orchestrateur/bridge/archipelago are separate repos (gitignored in the monorepo); the
orchestrateur changes follow that repo's fix/feature branch + PR to master convention,
while api/frontend changes ride a monorepo `feature/epic-9-story-38-*` branch per Gitflow.

### Testing standards summary

- Go: table-driven tests with fake docker/storage interfaces (see existing service tests).
- api: PHPUnit unit tests, interface mocks, no kernel (AC-T1/T3); command services return
  records/void (epic 35 - no raw arrays from `Application/Command/`).
- frontend: jest via `renderToStaticMarkup` convention + type-guard tests; all four gates.

### References

- Incidents: this story's Context section; generation logs of 2026-07-25 (sessions
  `02234f7a...` Dark Cloud, Codeforces session, Crystal Project session).
- Upload pipeline: [Source: orchestrateur/internal/api/handlers.go#handleUploadApworld],
  [Source: orchestrateur/internal/service/service.go#UploadApworld]
- Production generation parity: [Source: orchestrateur/internal/docker/client.go#GenerateMultiworld]
- Related stories: 9.11 (apworld upload + pipeline), 9.33 (option-type introspection
  end-to-end - the async-job + pass-through pattern this story mirrors).

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List
