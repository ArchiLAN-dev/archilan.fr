# Story 9.42: "Tester ma config" - solo preflight generation with the player's real YAML

Status: review
Depends on: 9.38 (apworld upload preflight - provides the one-shot preflight container infra)
Related: 9.40 (failure parsing - reused for the verdict message)

## Story

As a **player configuring my slot for a run**,
I want **to test-generate my configuration alone (my real YAML + the game's apworld) and see
the verdict on my slot**,
so that **option-level failures (2048 case, A Bug's Life LEVEL_CAPS case, invalid option
combos) are caught when I save my config - days before launch - instead of blocking the whole
multiworld at LAN time**.

## Context

Story 9.38 preflights an apworld at UPLOAD time with its default template - it validates the
world, not the player's choices. Both 2026-07-30 incidents would have passed a template
preflight and did fail on real player options. The missing piece is the same one-shot solo
generation, but fed with the player's actual YAML, on demand.

This complements (not replaces) 9.38: upload preflight gates broken worlds; this story gates
broken configs. Both reuse the identical container path so a "passed" verdict here means the
production generation would accept this slot (single-slot caveat aside).

## Acceptance Criteria

**AC1 - Orchestrateur endpoint:** A one-shot solo generation check reusing the 9.38
`PreflightGenerate` container path (same image, same `NetworkMode: "none"`, same timeout
model), but taking a caller-provided player YAML (and the game's apworld hash when custom;
none for official worlds). Async job semantics consistent with the orchestrateur's existing
patterns; the result (passed / failed + stderr tail) reaches the API via webhook or polling -
follow whichever pattern 9.38 lands.

**AC2 - API + persistence:** A slot-level preflight verdict (`pending` | `passed` | `failed`
+ message + checkedAt) persisted on the slot config (PersonalRuns `RunParticipant` /
Registrations slot), reset to none whenever the player's YAML or game changes. The
Application layer triggers the check; the DDD boundary is a `Port` interface implemented in
Infrastructure via the runner client.

**AC3 - Trigger UX:** The check runs automatically on config save (async, non-blocking -
saving never waits on it), and a "Tester ma config" button allows an explicit re-run. Verdict
shown as a badge on the slot (in the player's config page and in the owner's slot list):
"Config testée ✓" / "Échec de génération ✗" (with the parsed message, 9.40 parser reused
API-side) / "Test en cours…".

**AC4 - Advisory, not blocking:** A `failed` verdict does NOT block launching (an option set
can fail solo yet pass in a multiworld context and vice versa; the check is single-seed). The
launch UI shows a warning when slots have a failed/stale verdict. No hard gate.

**AC5 - Honest scope in copy:** UI copy states the check is a solo, single-seed test
("testé seul avec une seed - la génération complète peut encore différer"). Seed-dependent
fill failures and cross-slot interactions are explicitly out of scope.

**AC6 - Cost control:** One in-flight check per slot (a re-trigger while pending is a no-op
or supersedes); orchestrateur bounds concurrent preflight containers (shared limit with 9.38,
config value). Weekly templates and event defaults MAY reuse the endpoint later - out of
scope here.

**AC7 - Quality gates:** orchestrateur `go test ./...` green; api `composer gates` green;
frontend `pnpm gates` green.

## Tasks / Subtasks

- [x] Task 1: orchestrateur - extend the 9.38 preflight runner to accept an arbitrary player
      YAML (+ optional apworld hash); endpoint + async job + result delivery; Go tests
      (pass/fail/timeout/concurrency cap).
- [x] Task 2: runner PHP client (`packages/`, own repo + version bump per packages/CLAUDE.md)
      - expose the new call(s); typed DTOs.
- [x] Task 3: api - Port + Infrastructure adapter; verdict persistence on
      `RunParticipant`/Registration slot (+ migration); trigger on YAML save + explicit
      re-run command; reset on config change; reuse the 9.40 parser for the failed-verdict
      message; Mercure publish so badges update live; unit tests.
- [x] Task 4: frontend - badge + "Tester ma config" button on the slot config page; warning
      strip on the launch panel when verdicts are failed/stale; API layer typed result +
      type-guard tests.
- [x] Task 5: gates.

## Dev Notes

- Implementation should start AFTER 9.38 is merged - Task 1 is an extension of its runner,
  not a parallel reimplementation. If 9.38 slips, re-scope Task 1 to carry the shared
  container plumbing itself.
- Official (non-custom) worlds: the gen image already bundles official apworlds; the check
  needs only the YAML. Custom worlds: pass the hash exactly like session generation does.
- `generate_multiworld.py` needs no change (a single-YAML directory is a valid input -
  9.38 Dev Notes).
- The verdict must be keyed to the exact YAML content tested (hash the YAML) so a stale
  "passed" badge cannot survive a config edit unnoticed.
- Related incidents: 2026-07-30 (2048 - fixed by 9.39; A Bug's Life LEVEL_CAPS - player
  config still to fix); 2026-07-25 (9.38's Codeforces / Dark Cloud / Crystal Project).

## Dev Agent Record

### Scope notes (delivered vs deferred)

- Delivered: PersonalRuns slots end-to-end (auto-run on yaml save, "Tester ma config" re-run,
  verdict badge + polling, launch warning via failedPreflightCount, verdict keyed to yaml sha,
  stale results dropped). Orchestrateur PR #15, client v1.5.0.
- Deferred: event-registration slots (AC2's Registration side) - the write path differs; reuse
  RunSlotPreflightJob's pattern when needed.
- Polling is Messenger-delay based (DelayStamp re-dispatch, no sleep), run_server transport.

### Review fixes (post-merge adversarial review)

- failedPreflightCount is computed for draft runs only (was an extra findByRunId per row in
  listMine, including finished runs).
- getParticipantSlots now exposes the per-slot preflight verdict + badge on the owner's
  participant detail page (AC3 owner-side was missing).


### Agent Model Used

### Debug Log References

### Completion Notes List

### File List
