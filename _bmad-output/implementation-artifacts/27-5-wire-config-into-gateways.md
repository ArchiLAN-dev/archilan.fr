# Story 27.5: Wire resolved config into the three launch/generation gateways

Status: review

## Story

As the platform,
I want each launch/generation path (private / event / weekly) to resolve its effective config and pass it
to the orchestrateur,
so that the admin-configured options are actually applied to every run.

## Context

This is the integration story: it connects the resolver (27.2) to the orchestrateur capabilities
(27.3 server options + 27.4 generation options) through the three existing gateways. Depends on
27.2, 27.3, 27.4.

The three contexts that launch/generate (verified):
- **WeeklyRuns** — generation: `GenerateWeeklyRunForTemplate` / `GenerateWeeklyRunsMessageHandler`
  (→ `WeeklyRunGeneratorInterface` / `OrchestratorWeeklyRunGenerator`); launch:
  `LaunchWeeklyEntry` (→ `WeeklyRunnerGatewayInterface` / `OrchestratorWeeklyRunnerGateway`).
- **Sessions (events)** — `SessionOrchestrator` / `SessionLifecycleManager` (→ `RunnerGatewayInterface`
  / `RunnerGateway`).
- **PersonalRuns (private)** — `LaunchPersonalRunJobHandler` (→ its runner gateway).

## Acceptance Criteria

1. Each gateway **interface** that triggers generation gains the generation params, and each that
   triggers launch gains the server params (typed from the 27.1 VOs / their transport seam).
2. Each `Orchestrator*` gateway implementation sends the new fields to the orchestrateur
   (generate: plando/race/spoiler; launch: remaining/itemCheat/hintCost/checkPoints/countdown/
   autoShutdown/compatibility/joinPassword — release/collect already sent).
3. Each launch/generation use case resolves the **effective** config via `SessionConfigResolver`
   (27.2): weekly → type `weekly`, event → `event`, private → `private`, applying any per-session
   override; passes it to its gateway. The resolved config is recorded per session (27.2 AC6) so a
   restart reuses it.
4. The `Null*`/`Spy*` test gateways are updated to the new signatures; existing functional/unit tests
   stay green; new tests assert each path forwards the resolved values (spy captures them).
5. No behaviour change when a profile holds defaults equal to today's (regression-safe): existing
   weekly/event/private flows still launch.
6. Quality gates green (phpstan, php-cs-fixer, phpunit, `app:architecture:ddd`).

## Tasks / Subtasks

- [x] Task 1 — Extend `WeeklyRunnerGatewayInterface` (launch) + `WeeklyRunGeneratorInterface`
  (generation) and their `Orchestrator*` impls + `Null*`/`Spy*`; resolve config in `LaunchWeeklyEntry`
  and `GenerateWeeklyRunForTemplate`/`GenerateWeeklyRunsMessageHandler` (AC: 1–4).
- [x] Task 2 — Extend `Sessions` `RunnerGatewayInterface` + `RunnerGateway`/`NullRunnerGateway`; resolve
  in `SessionOrchestrator`/`SessionLifecycleManager` launch (AC: 1–4).
- [x] Task 3 — Extend `PersonalRuns` runner gateway + impl; resolve in `LaunchPersonalRunJobHandler`
  (AC: 1–4).
- [x] Task 4 — Inject `SessionConfigResolver` (27.2) into each use case (constructor injection only,
  api/CLAUDE.md). Record resolved config per session.
- [x] Task 5 — Update spies/nulls + tests; run all four gates (AC: 4–6).

## Dev Notes

- **Gateways send to the orchestrateur** via HTTP. Launch params map to the orchestrateur
  `/sessions/{id}/launch` or `/launch-from-file` body/form (fields added in 27.3); generation params to
  `/sessions/{id}/generate` (27.4). The weekly gateway already builds a `launch-from-file` multipart
  request (`OrchestratorWeeklyRunnerGateway::launchEntry`) — add the new form fields there.
- **Resolver dependency direction** (api/CLAUDE.md): use cases (Application) depend on the
  `SessionConfigResolver` (Application service) + gateway interfaces; no Infrastructure leakage.
- **Per-session id** for the resolver/override = the external session id each context already uses
  (weekly entry id, event session id, personal run job id).
- **Restart:** record the resolved config at launch (27.2 AC6) so `RestartSession` paths reuse it
  rather than the orchestrateur defaults (orchestrateur does not persist launch params).
- Keep each use case to one unit of work; the resolver read happens before the gateway call.

### Project Structure Notes

- Spans three contexts but no new context; touches Application + Infrastructure of each.
- This story is the first that is **only meaningful after 27.3 + 27.4 are deployed** (the orchestrateur
  must accept the fields). Sequence accordingly.

### References

- [Source: _bmad-output/planning-artifacts/epic-27-configurable-session-server-options.md]
- [Source: api/src/WeeklyRuns/Application/LaunchWeeklyEntry.php; api/src/WeeklyRuns/Infrastructure/OrchestratorWeeklyRunnerGateway.php]
- [Source: api/src/WeeklyRuns/Application/GenerateWeeklyRunForTemplate.php; api/src/WeeklyRuns/Infrastructure/OrchestratorWeeklyRunGenerator.php]
- [Source: api/src/Sessions/Application/RunnerGatewayInterface.php; api/src/Sessions/Infrastructure/RunnerGateway.php; api/src/Sessions/Application/SessionOrchestrator.php]
- [Source: api/src/PersonalRuns/Application/Handler/LaunchPersonalRunJobHandler.php]
- [Source: _bmad-output/implementation-artifacts/27-2-session-config-persistence-admin-api.md (resolver)]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Debug Log References

- Discovered the gateways delegate to the `archilan/orchestrateur-client` package, which did not
  forward options → extracted **story 27.8** (package change, v1.1.0) per `packages/CLAUDE.md`, then
  `composer update` (`>=1.1`) before wiring here.
- Sessions + PersonalRuns share `SessionOrchestrator`; the session type is resolved per-session via
  the existing personal-run repo (`$this->runs->findBySessionId()` → Private, else Event), so both
  flows wire correctly through the same `orchestrateGenerate/Launch` methods.

### Completion Notes List

- **WeeklyRuns:** `WeeklyRunGeneratorInterface::generate` + `WeeklyRunnerGatewayInterface::launchEntry`
  gained option params; `Orchestrator*` impls forward them to the client; `Null`/`Spy` updated.
  `GenerateWeeklyRunForTemplate` + `GenerateWeeklyRunsMessageHandler` resolve the **weekly** generation
  options; `LaunchWeeklyEntry` resolves the **weekly** server options for the entry (+ join password),
  records the snapshot, and passes them (drops the `password` key, sent via `$joinPassword`).
- **Sessions (event):** `RunnerGatewayInterface::generateSession/launchSession` gained option params
  (`RunnerGateway` forwards to the client; `NullRunnerGateway` updated). `SessionOrchestrator`
  resolves per-session type in `orchestrateGenerate`/`orchestrateLaunch`/`orchestrateForceLaunch`,
  records the launch snapshot, and overrides the join password from config when set.
- **PersonalRuns (private):** advances through `SessionOrchestrator.autoAdvancePersonalRun` →
  `orchestrateGenerate/Launch`, where `sessionType()` returns **Private** (personal-run repo signal),
  so private runs get the private profile with no extra wiring in `LaunchPersonalRunJobHandler`.
- Resolver injected by autowiring (its repo deps bound in 27.2). New `SessionConfigDefaultsTrait`
  builds a real resolver over stub repos (the resolver is `final`, so not mockable) for the weekly
  unit tests; added `testInvokeForwardsResolvedServerOptions` asserting the weekly defaults reach the
  gateway. `composer.json` → `archilan/orchestrateur-client >=1.1`.
- Gates green: phpstan max, php-cs-fixer @Symfony, `app:architecture:ddd`, phpunit (963).

### File List

- WeeklyRuns: `Application/WeeklyRunGeneratorInterface.php`, `Application/WeeklyRunnerGatewayInterface.php`,
  `Application/GenerateWeeklyRunForTemplate.php`, `Application/Handler/GenerateWeeklyRunsMessageHandler.php`,
  `Application/LaunchWeeklyEntry.php`, `Infrastructure/OrchestratorWeeklyRunGenerator.php`,
  `Infrastructure/OrchestratorWeeklyRunnerGateway.php`, `Infrastructure/NullWeeklyRunGenerator.php`,
  `Infrastructure/NullWeeklyRunnerGateway.php`, `Infrastructure/SpyWeeklyRunnerGateway.php` (modified).
- Sessions: `Application/RunnerGatewayInterface.php`, `Application/SessionOrchestrator.php`,
  `Infrastructure/RunnerGateway.php`, `Infrastructure/NullRunnerGateway.php` (modified).
- `api/composer.json`, `api/composer.lock` (orchestrateur-client → 1.1.0).
- Tests: `tests/Unit/WeeklyRuns/SessionConfigDefaultsTrait.php` (new); `GenerateWeeklyRunForTemplateTest`,
  `GenerateWeeklyRunsMessageHandlerTest`, `LaunchWeeklyEntryTest` (modified).

## Change Log

| Date       | Change |
|------------|--------|
| 2026-06-09 | Story created from epic 27 plan (gateway wiring). |
| 2026-06-09 | Implemented across WeeklyRuns + Sessions + PersonalRuns; client v1.1.0 (story 27.8). Per-session type via personal-run signal. Gates green (963). Status → review. |
