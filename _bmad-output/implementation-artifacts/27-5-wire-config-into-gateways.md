# Story 27.5: Wire resolved config into the three launch/generation gateways

Status: ready-for-dev

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

- [ ] Task 1 — Extend `WeeklyRunnerGatewayInterface` (launch) + `WeeklyRunGeneratorInterface`
  (generation) and their `Orchestrator*` impls + `Null*`/`Spy*`; resolve config in `LaunchWeeklyEntry`
  and `GenerateWeeklyRunForTemplate`/`GenerateWeeklyRunsMessageHandler` (AC: 1–4).
- [ ] Task 2 — Extend `Sessions` `RunnerGatewayInterface` + `RunnerGateway`/`NullRunnerGateway`; resolve
  in `SessionOrchestrator`/`SessionLifecycleManager` launch (AC: 1–4).
- [ ] Task 3 — Extend `PersonalRuns` runner gateway + impl; resolve in `LaunchPersonalRunJobHandler`
  (AC: 1–4).
- [ ] Task 4 — Inject `SessionConfigResolver` (27.2) into each use case (constructor injection only,
  api/CLAUDE.md). Record resolved config per session.
- [ ] Task 5 — Update spies/nulls + tests; run all four gates (AC: 4–6).

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

### Debug Log References

### Completion Notes List

### File List

## Change Log

| Date       | Change |
|------------|--------|
| 2026-06-09 | Story created from epic 27 plan (gateway wiring). |
