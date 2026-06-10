# Story 17.8: api + frontend — Symfony-driven restart; drop the bridge wake-on-connect trigger

Status: ready-for-dev

Repo: `archilan.fr` (monorepo, `api/` + `frontend/`) — branch from `develop`.

## Story

As a player and as the platform,
I want resume to be a Symfony → orchestrateur "stop then relaunch-from-save" flow with a single manual
trigger,
so that the misleading "auto-restart on connect" promise is gone and the live system no longer relies
on a bridge that outlives its AP server.

## Context

Final step of the wake-on-connect removal (supersedes story 17.5). The orchestrateur gains
stop + relaunch-from-save (17.6) and the bridge drops the wake listener and `/resume` (17.7). This
story repoints Symfony's pause/resume jobs onto those, removes the bridge-triggered auto-restart
surface, and fixes the UI copy. **Depends on 17.6 + 17.7 being deployed** (otherwise the resume path
breaks), so it merges/ships after them.

Today (to be changed):
- `PauseRunJobHandler` → bridge `POST /pause` only (container left alive).
- `ResumeRunJobHandler` → bridge `POST /resume` (in-place relaunch).
- `POST /api/v1/internal/sessions/{id}/restarting` → `markRestartingBridge` (the bridge's
  wake-on-connect calls this when a player connects) — **auto-restart, to remove**.
- Manual restart `POST /api/v1/sessions/{id}/restart` → `initiateRestart` (idle→restarting,
  dispatches `ResumeRunJob`) → `/restarted` callback → running. **Keep**, but its resume must now go
  through the orchestrateur.
- Frontend IDLE panel: *"La partie redémarre automatiquement dès qu'un joueur tente de se connecter.
  Vous pouvez aussi la relancer manuellement."* — the auto sentence is false under the new model.

## Acceptance Criteria

1. **Remove the auto-restart trigger:** delete the `POST /api/v1/internal/sessions/{id}/restarting`
   route and `SessionLifecycleManager::markRestartingBridge()`. Remove the corresponding cases from
   `BridgeLifecycleCallbackTest` (the `/restart-failed` and `/restarted` callbacks **stay** — the
   manual flow uses them).
2. **Pause = save then stop:** `PauseRunJobHandler` calls the bridge `POST /pause` (save+upload, story
   17.7) and, on success, calls the orchestrateur `stopSession()` so the container is removed. On a
   bridge failure/timeout it still records the pause (`recordPaused`) — never leave a container
   orphaned silently (log + best-effort stop).
3. **Resume = relaunch-from-save via orchestrateur:** `ResumeRunJobHandler` calls the orchestrateur's
   new `relaunch-from-save` (17.6) instead of the bridge `/resume`. Add
   `RunnerGatewayInterface::relaunchFromSave(string $sessionId): void` implemented in `RunnerGateway`
   (orchestrateur client) and `NullRunnerGateway`. The orchestrateur fires `session.ready` → existing
   `/restarted` callback transitions `restarting → running`; `session.crashed` → restart-failed.
4. **Manual restart unchanged for the user:** `POST /api/v1/sessions/{id}/restart` →
   `initiateRestart` still gates on owner/admin + save availability and still moves idle→restarting;
   only the dispatched resume now lands on the orchestrateur (AC 3).
5. **Email copy:** `SessionRestartFailedEmail` no longer mentions "wake-on-connect" (the failure is a
   generic automatic-restart failure).
6. **Frontend:** the IDLE panel drops the auto-restart sentence; copy becomes manual-only (e.g. *"La
   partie est en pause. Relance-la pour reprendre — la dernière sauvegarde sera chargée."*). The
   `pausedWithoutSave` disabled-button branch is unchanged.
7. Quality gates green: API (`phpstan`, `php-cs-fixer`, `phpunit`, `app:architecture:ddd`), Frontend
   (`typecheck`, `lint`, `build`). `bridgeInternalToken` wiring that becomes unused (resume side) is
   cleaned up only if it leaves no other consumer (the pause path still uses it).

## Tasks / Subtasks

- [ ] Task 1 — Remove `/restarting` route + `markRestartingBridge` + its tests (AC 1).
- [ ] Task 2 — `RunnerGatewayInterface::relaunchFromSave` + impls (RunnerGateway via orchestrateur
      client `sessions()->relaunchFromSave()`, NullRunnerGateway no-op) (AC 3).
- [ ] Task 3 — Repoint `ResumeRunJobHandler` to `relaunchFromSave`; repoint `PauseRunJobHandler` to
      bridge `/pause` then `stopSession` (AC 2, 3).
- [ ] Task 4 — Reword `SessionRestartFailedEmail` (AC 5).
- [ ] Task 5 — Frontend IDLE copy (AC 6).
- [ ] Task 6 — Tests: pause-then-stop handler test (mock gateway + bridge http), resume→relaunch
      handler test, functional restart flow still green; gates (AC 7).

## Dev Notes

### DDD / boundaries

`RunnerGatewayInterface` lives in `Sessions/Application`; the orchestrateur call is added to the
Infrastructure `RunnerGateway` (it wraps `Archilan\OrchestratorClient`). The package may need a
`sessions()->relaunchFromSave()` method — if the installed `archilan/orchestrateur-client` lacks it,
that is a **separate package story** (per `packages/CLAUDE.md`: adapt to the package, never the
reverse), bumping the client and `composer update`. Flag it during implementation.

### Sequencing / safety

Do **not** merge this before 17.6 + 17.7 are deployed: flipping `ResumeRunJobHandler` to
`relaunchFromSave` against an orchestrateur that lacks the endpoint would break manual restart. The
`/restarting` removal and the UI copy (AC 1, 5, 6) are safe earlier and could be split into a first PR
if we want the misleading promise gone immediately.

### State machine

No new session states. `restarting` stays (used by the manual path and the `/restarted` callback).
`recordRestarted` / `markRestartFailed` / `/restarted` / `/restart-failed` are retained.

### References

- `api/src/Sessions/Presentation/SessionRestartController.php` (routes to prune/keep).
- `api/src/Sessions/Application/SessionLifecycleManager.php` (`markRestartingBridge` to delete;
  `initiateRestart`, `recordRestarted`, `markRestartFailed` to keep).
- `api/src/Sessions/Application/Handler/{PauseRunJobHandler,ResumeRunJobHandler}.php`.
- `api/src/Sessions/Application/RunnerGatewayInterface.php` + `Infrastructure/RunnerGateway.php`,
  `NullRunnerGateway.php`.
- `api/tests/Functional/BridgeLifecycleCallbackTest.php`.
- `frontend/src/features/personal-runs/personal-run-detail-page.tsx` (IDLE panel ~L810).
- Orchestrateur endpoint: story 17.6 `POST /sessions/{id}/relaunch-from-save`.

## Dev Agent Record

## Change Log

- 2026-06-10 — Story created (supersedes the api/frontend half of 17.5).
