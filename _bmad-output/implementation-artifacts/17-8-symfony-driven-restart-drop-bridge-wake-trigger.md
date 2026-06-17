# Story 17.8: api + frontend - drop the custom inactivity watchdog; idle via AP, manual resume

Status: done

Repo: `archilan.fr` (monorepo, `api/` + `frontend/`) - implemented on `feature/epic-17-restart-redesign-stories` (PR #102), in two commits (non-package, then the orchestrateur-client repoint after story 17.9 / v1.2.0).

## Story

As the platform,
I want Symfony to stop running its own inactivity watchdog and instead treat idle as a signal from
Archipelago (relayed by the orchestrateur), with a single manual resume that relaunches from the save,
so that there is one idle mechanism (AP's native `auto_shutdown`) and no misleading
"auto-restart on connect".

## Context

Final step of the consolidation (supersedes 17.5). Idle is Archipelago's native `auto_shutdown`
(wired in epic 27 via `SessionServerConfig::toServerFlags()`); the orchestrateur detects the clean
shutdown, persists the save, stops the bridge and emits `session.idle` (17.6); the bridge no longer
does pause/resume/wake (17.7). This story removes Symfony's redundant watchdog + pause/wake surface,
consumes the orchestrateur's `session.idle`, repoints resume onto the orchestrateur, and fixes the UI
copy. **Depends on 17.6 + 17.7 deployed** (else resume breaks), so it ships after them.

Today (to change):
- `Schedule.php:58` `RecurringMessage::every('5 minutes', InactivityWatchdogMessage)` → drop.
- `InactivityWatchdogHandler` → `PauseRunJob` → `PauseRunJobHandler` → bridge `/pause` → drop.
- `POST /api/v1/internal/sessions/{id}/restarting` + `markRestartingBridge` (wake trigger) → drop.
- `ResumeRunJobHandler` → bridge `/resume` → repoint to orchestrateur relaunch-from-save.
- Session goes idle today via the bridge-driven `recordPaused`; now it goes idle via the
  orchestrateur `session.idle` webhook.
- `autoShutdown` defaults to `0` on the type profiles (`SessionConfig.php:167,181`) → set a sane
  non-zero default so idle actually happens (AP owns the timeout now; 0 = never).
- Frontend IDLE panel still promises auto-restart-on-connect → manual-only copy.

## Acceptance Criteria

1. **Remove the custom watchdog:** delete the `InactivityWatchdogMessage` recurring entry from
   `Schedule.php`, `InactivityWatchdogHandler`, `InactivityWatchdogMessage`, `PauseRunJob`,
   `PauseRunJobHandler`, and their tests. Nothing in Symfony pauses a session on a timer.
2. **Remove the wake trigger:** delete `POST /api/v1/internal/sessions/{id}/restarting` and
   `SessionLifecycleManager::markRestartingBridge()`; drop the matching `BridgeLifecycleCallbackTest`
   cases (keep `/restart-failed` + `/restarted` - the manual flow uses them).
3. **Idle from the orchestrateur:** handle the new `session.idle` webhook (`{ sessionId, saveKey }`) →
   `recordPaused`-equivalent (mark session idle, set `lastSaveKey`, `pausedWithoutSave` when
   `saveKey` is null). The bridge no longer triggers idle.
4. **Resume = orchestrateur relaunch-from-save:** add
   `RunnerGatewayInterface::relaunchFromSave(string $sessionId): void` (impl in `RunnerGateway` via the
   orchestrateur client, no-op in `NullRunnerGateway`); `ResumeRunJobHandler` calls it instead of the
   bridge `/resume`. The orchestrateur's `session.ready` → existing `/restarted` callback →
   `restarting → running`; `session.crashed` → restart-failed.
5. **Manual restart unchanged for the user:** `POST /api/v1/sessions/{id}/restart` → `initiateRestart`
   still gates on owner/admin + save availability + idle, still moves idle→restarting; only the
   dispatched resume now lands on the orchestrateur (AC 4).
6. **Sane idle default:** the type-profile defaults set `autoShutdown` to a non-zero platform default
   (proposed **1800 s / 30 min**) so sessions actually idle; admins can still tune it per type
   (and owners cannot, per 27.9). Adjust the affected functional tests.
7. **Email + UI copy:** `SessionRestartFailedEmail` drops the "wake-on-connect" wording. The frontend
   IDLE panel copy becomes manual-only (e.g. *"La partie est en pause après une période d'inactivité.
   Relance-la pour reprendre - la dernière sauvegarde sera chargée."*); the `pausedWithoutSave`
   disabled-button branch is unchanged.
8. Gates green: API (`phpstan`, `php-cs-fixer`, `phpunit`, `app:architecture:ddd`), Frontend
   (`typecheck`, `lint`, `build`).

## Tasks / Subtasks

- [ ] Task 1 - Remove watchdog + pause job + cron + tests (AC 1).
- [ ] Task 2 - Remove `/restarting` + `markRestartingBridge` + tests (AC 2).
- [ ] Task 3 - `session.idle` webhook handler → mark idle (AC 3).
- [ ] Task 4 - `relaunchFromSave` gateway method + repoint `ResumeRunJobHandler` (AC 4, 5).
- [ ] Task 5 - Non-zero `autoShutdown` profile default + test adjustments (AC 6).
- [ ] Task 6 - Email reword + frontend IDLE copy (AC 7).
- [ ] Task 7 - Gates (AC 8).

## Dev Notes

### DDD / package boundary

`relaunchFromSave` is added to `RunnerGatewayInterface` (`Sessions/Application`), implemented in
`Infrastructure/RunnerGateway` over `Archilan\OrchestratorClient`. If the installed client lacks a
`sessions()->relaunchFromSave()` method, that's a **separate package story** (per `packages/CLAUDE.md`:
adapt to the package, bump it, `composer update`) - flag during implementation.

### Where the `session.idle` webhook is consumed

Reuse the existing orchestrateur webhook receiver controller (the one already handling
`session.ready`/`session.crashed`). `session.idle` → `SessionLifecycleManager::recordPaused()` (which
already exists and does the idle transition + `SessionPausedWithoutSaveMessage` on no-save).

### Safe-early split

AC 1, 2, 6, 7 (remove watchdog/wake, set default, fix copy/email) don't depend on the orchestrateur
endpoint and can land in a first PR to kill the misleading promise and the duplicate watchdog
immediately. AC 3, 4 (idle webhook + resume repoint) need 17.6 deployed → second PR.

### State machine

No new session states. `restarting` + `/restarted` + `/restart-failed` retained for the manual path.

### References

- `api/src/Schedule.php:58`; `api/src/Sessions/Application/ScheduledTask/InactivityWatchdog*`;
  `api/src/Sessions/Application/{Message/PauseRunJob,Handler/PauseRunJobHandler,Handler/ResumeRunJobHandler}.php`.
- `api/src/Sessions/Presentation/SessionRestartController.php` (prune `/restarting`).
- `api/src/Sessions/Application/SessionLifecycleManager.php` (`markRestartingBridge` delete;
  `recordPaused`/`initiateRestart`/`recordRestarted`/`markRestartFailed` keep).
- `api/src/SessionConfig/Domain/SessionConfig.php:167,181` (autoShutdown default).
- `api/src/Sessions/Application/RunnerGatewayInterface.php` + `Infrastructure/{RunnerGateway,NullRunnerGateway}.php`.
- `frontend/src/features/personal-runs/personal-run-detail-page.tsx` (IDLE panel ~L810).
- Orchestrateur: story 17.6 (`session.idle` webhook, `POST /sessions/{id}/relaunch-from-save`).

## Dev Agent Record

### Implementation notes

- **`session.idle` → resumable:** mapped to `recordPaused($sessionId, 'orchestrateur:volume', false)`.
  Under the new model the save lives in the orchestrateur session volume (not MinIO), so a marker
  save key is stored only to satisfy the existing `initiateRestart` "has a save" gate - keeping that
  gate and `testNullSaveKeyReturns422NoSaveAvailable` intact rather than relaxing the domain.
- **`session.ready` after relaunch needs no special-casing:** `restarting → running` is already an
  allowed transition, so the existing `session.ready` handler (`transitionToRunningFromOrchestrateur`)
  resumes the session correctly (and does not re-notify players, since `isNotified` stays true).
- **autoShutdown default:** Private → 1800 s; Event/Weekly kept at 0 (auto-shutting-down a live event
  is risky; admin-tunable per type, owners can't change it per 27.9).
- **Resume repoint** depended on a package method → split out as **story 17.9**
  (`orchestrateur-client` v1.2.0, `relaunchFromSave()`), per `packages/CLAUDE.md`. Done in commit 2.
- `SessionPausedController` (`POST /sessions/{id}/paused`) left as now-dead code (the bridge no longer
  calls it); harmless, removable in a follow-up.

### File List

- `api/src/Schedule.php`, `api/config/packages/messenger.yaml`, `api/config/services.yaml` - watchdog removed.
- Deleted: `InactivityWatchdogHandler`, `InactivityWatchdogMessage`, `PauseRunJob`, `PauseRunJobHandler`,
  `InactivityWatchdogTest`.
- `SessionRestartController.php`, `SessionLifecycleManager.php` - removed `/restarting` + `markRestartingBridge`.
- `BridgeLifecycleCallbackTest.php` - dropped `/restarting` cases.
- `OrchestratorWebhookController.php` - `session.idle` handler.
- `SessionConfig.php` - Private autoShutdown default 1800.
- `SessionRestartFailedEmail.php` - reworded.
- `RunnerGatewayInterface.php` / `RunnerGateway.php` / `NullRunnerGateway.php` - `relaunchFromSave`.
- `Handler/ResumeRunJobHandler.php` - repointed to the gateway; `tests/Unit/Sessions/ResumeRunJobHandlerTest.php`.
- `api/composer.lock` - orchestrateur-client 1.2.0.
- `frontend/.../personal-run-detail-page.tsx` - IDLE copy.

## Change Log

- 2026-06-10 - Story created.
- 2026-06-10 - Revised: also remove the redundant Symfony `InactivityWatchdog` + `PauseRunJob`; idle now
  arrives via the orchestrateur `session.idle` webhook; set a non-zero `autoShutdown` profile default.
