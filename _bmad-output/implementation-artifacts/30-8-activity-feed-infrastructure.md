# Story 30.8: Activity feed infrastructure + signals

Status: ready-for-review

## Story

As the platform,
I want an append-only activity feed populated deterministically from existing data,
so that 30.9 can surface what members and their friends have been doing. Deps: 30.1.

`ActivityEntry` (no audience column - resolved at read), an idempotent recorder, a backfill command (the
source of truth), and a live in-context signal on friendship-accept.

## Acceptance Criteria

1. `ActivityEntry` is append-only, tagged with the **actor only** (no audience column - visibility is
   resolved at read from the actor's current profile, epic §H/review #2). `(actor, type, subjectRef)` is
   unique so recording is idempotent.
2. `RecordActivity` appends idempotently (skips an existing natural key, returns whether it added).
3. `community:activity:backfill` reconstructs `run_finished` entries from every user's finished-run
   history (the deterministic source of truth, epic §E.1); idempotent / re-runnable on a schedule.
4. Accepting a friendship records a `friendship` activity for the accepter (live in-context signal).
5. A repository read returns recent entries for a set of actors (for the 30.9 feed), newest first.
6. Gates green: phpstan / php-cs-fixer / phpunit (0 notices) / `app:architecture:ddd`.

## Tasks / Subtasks

- [x] **api/ Domain:** `ActivityEntry` (append-only, unique natural key, JSON payload) +
      `ActivityEntryRepositoryInterface` (`exists` / `save` / `recentForActors`).
- [x] **api/ Migration:** `community_activity_entry` (unique `(actor,type,subject_ref)` + `(actor,occurred_at)` index).
- [x] **api/ Infrastructure:** `DoctrineActivityEntryRepository`.
- [x] **api/ Application:** `RecordActivity` (idempotent) + `BackfillActivity` (run history -> entries);
      `FriendshipService` records a `friendship` entry on accept (both accept paths).
- [x] **api/ Presentation:** `community:activity:backfill` command.
- [x] **api/ tests:** unit `ActivityFeedTest` (record idempotency, backfill materialise + idempotent) +
      functional `CommunityActivityTest` (friendship accept records an entry).
- [x] **Gates** - all green (no frontend; surfacing is 30.9).

## Dev Notes

### Reuse, don't reinvent
- Backfill reuses the Epic-18 `PlayerHistoryQueryInterface` per user (finished runs) and the 30.4
  `CommunityUserIdsQueryInterface` to iterate users - no new reads. The unique natural key lets the
  backfill and any future live signal converge on the same fact without duplicates.

### Architecture guardrails
- The feed entry carries **no audience** - 30.9 resolves visibility per read against each actor's current
  profile + block/friend state (so changing a profile to `friends` retroactively hides past activity).
- `RecordActivity` is a small idempotent append; the recorder/backfill run off the request path.

### Scope boundaries / deviations (important)
- **Cross-context write-site dispatch deferred.** The epic's `Sessions\…\RunOutcomeRecorded` /
  `Registrations\…\EventAttendanceRecorded` fact messages + Community handlers are **not** wired here: the
  write sites are transaction-critical (`SessionLifecycleManager`, `RegistrationSubmission`) and the epic
  explicitly makes the **backfill the source of truth** with dispatch only "best-effort freshness". So we
  ship the deterministic backfill (schedule it like the other passes) + the Community-internal
  friendship signal now; the live Sessions/Registrations dispatch is a documented follow-up (wire when
  those sites are next touched, or in 30.9). No Symfony Scheduler entry added (ops wires the command).
- `run_finished` covers attendance implicitly (a finished run implies attendance); a distinct
  `event_attendance` type can be added later if needed.

### Project Structure Notes
- New api: `Community/Domain/{ActivityEntry,ActivityEntryRepositoryInterface}`,
  `Community/Application/{RecordActivity,BackfillActivity}`,
  `Community/Infrastructure/DoctrineActivityEntryRepository`,
  `Community/Presentation/BackfillActivityCommand`, migration, unit+functional tests.
- Modified: `FriendshipService` (live signal), `services.yaml` (binding).

### References
- Epic §E/§H + story 30.8 (Track 3). [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Append-only `ActivityEntry` + idempotent `RecordActivity`; `community:activity:backfill` rebuilds
  `run_finished` entries deterministically from run history; friendship-accept emits a live `friendship`
  entry. `recentForActors` is ready for the 30.9 feed read.
- Deviation: the cross-context Sessions/Registrations write-site Messenger dispatch is deferred (backfill
  is the source of truth per the epic); friendship is the one live signal wired now.

### Validation Results

- php-cs-fixer 0 ; phpstan 0 ; app:architecture:ddd exit 0 ; phpunit 1158 tests, 0 notices
  (incl. `ActivityFeedTest` + `CommunityActivityTest`).

### File List

**Added (api)**
- `api/src/Community/Domain/ActivityEntry.php`
- `api/src/Community/Domain/ActivityEntryRepositoryInterface.php`
- `api/src/Community/Application/RecordActivity.php`
- `api/src/Community/Application/BackfillActivity.php`
- `api/src/Community/Infrastructure/DoctrineActivityEntryRepository.php`
- `api/src/Community/Presentation/BackfillActivityCommand.php`
- `api/migrations/Version20260618130000.php`
- `api/tests/Unit/Community/ActivityFeedTest.php`
- `api/tests/Functional/CommunityActivityTest.php`

**Modified (api)**
- `api/src/Community/Application/FriendshipService.php` (live friendship signal)
- `api/config/services.yaml` (activity entry repository binding)
