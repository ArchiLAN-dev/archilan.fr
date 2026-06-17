# Story 17.9: orchestrateur-client - add `relaunchFromSave()`

Status: done

Repo: `archilan-orchestrateur-client` (`packages/orchestrateur-client/`) - PR + tag `v1.2.0`.

## Story

As the `api/` integration of the epic-17 restart redesign,
I want `SessionsClient` to expose `relaunchFromSave(sessionId)`,
so that `ResumeRunJobHandler` (story 17.8) can resume an idle session via the orchestrateur instead
of the removed bridge `/resume`.

## Context

Per `packages/CLAUDE.md`, a gap found while integrating a package becomes a **dedicated story +
separate PR** on the package (never an ad-hoc edit during integration). Wiring 17.8's resume path
revealed that `SessionsClient` had `restart()` (crash recovery, re-launch from the generated seed) but
no verb for the new **relaunch-from-save** endpoint added to the orchestrateur in story 17.6
(`POST /sessions/{id}/relaunch-from-save`). This story closes that gap so 17.8 can adapt to the
package (never the reverse). User approved the package change on 2026-06-10.

## Acceptance Criteria

1. `SessionsClient::relaunchFromSave(string $sessionId): void` issues
   `POST /sessions/{sessionId}/relaunch-from-save` (no body), mirroring `restart()`/`stop()`.
2. The client stays agnostic of the response body (fire-and-forget 202; the orchestrateur drives the
   subsequent `session.ready`/`session.crashed` webhooks).
3. Package gates green: PHPStan level 9 (src + tests), PHPUnit ^11. A test asserts the request URL.
4. Version bumped to **1.2.0** (additive, backward-compatible); tag `v1.2.0` pushed so `api/` can
   `composer update archilan/orchestrateur-client`.

## Tasks / Subtasks

- [x] Task 1 - `relaunchFromSave()` on `SessionsClient` (AC 1, 2).
- [x] Task 2 - `testRelaunchFromSave_void` asserts the URL (AC 3).
- [x] Task 3 - bump `composer.json` to 1.2.0; PR + tag (AC 4).

## Dev Notes

- `api/composer.json` consumes the package via a VCS repository with constraint `>=1.1`, so it will
  pick up `1.2.0` on `composer update archilan/orchestrateur-client` after the tag is pushed.

### References

- Orchestrateur endpoint: story 17.6, `POST /sessions/{id}/relaunch-from-save`.
- Consumer: story 17.8, `ResumeRunJobHandler` + `RunnerGatewayInterface::relaunchFromSave`.

## Dev Agent Record

- Mirrors `restart()` (`transport->postVoid`). PHPStan level 9 clean; 18 tests pass.

## Change Log

- 2026-06-10 - Story created and implemented (status: review).
