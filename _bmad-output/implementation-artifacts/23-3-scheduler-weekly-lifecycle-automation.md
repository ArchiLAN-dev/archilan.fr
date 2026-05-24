# Story 23.3: Scheduler - Weekly Lifecycle Automation

## Story

**As a** developer,
**I want** automated weekly run lifecycle management driven by Symfony Scheduler,
**So that** a `WeeklyRun` is created every Monday at 00:00 UTC and all still-running player sessions are stopped every Sunday at 23:59 UTC - with zero manual intervention.

## Status

done

## Acceptance Criteria

**AC1:** Two scheduler entries in `src/Schedule.php`: `cron('0 0 * * 1', new GenerateWeeklyRunsMessage())` (Monday 00:00 - creates the weekly run records), `cron('59 23 * * 0', new StopWeeklyRunsMessage())` (Sunday 23:59 - stops all launched player sessions).

**AC2:** `GenerateWeeklyRunsMessageHandler`: for each `WeeklyTemplate` with `isActive = true`, checks via DBAL if a `WeeklyRun` already exists for the current ISO week (idempotent - skips if found). Creates a `WeeklyRun` with `status = 'active'`, `weekYear`, `weekNumber` (ISO), `seed = 'archilan-weekly-{weekYear}-{weekNumber:02d}'`, `startedAt = now`. Persists and flushes. **No runner call at this stage** - player sessions are generated on demand in Story 23.4.

**AC3:** `StopWeeklyRunsMessageHandler`: finds all `WeeklyRun` with `status = 'active'`. For each run, finds all its `WeeklyEntry` rows where `externalSessionId IS NOT NULL` and `goalReachedAt IS NULL` (still running, goal not reached). Calls `WeeklyRunnerGatewayInterface::terminate(externalSessionId)` for each such entry. After terminating all entries, calls `WeeklyRun::finish(now)` and flushes. On `terminate()` exception: logs error at `error` level with the `externalSessionId` context, continues processing remaining entries (best-effort stop).

**AC4:** Both messages and handlers are registered in `messenger.yaml`. Unit tests cover: idempotency in generate handler (second call on same ISO week creates no duplicate), stop handler continues after terminate error. All four quality gates pass.

## Tasks / Subtasks

- [ ] Task 1: Create `GenerateWeeklyRunsMessage` + `GenerateWeeklyRunsMessageHandler`
- [ ] Task 2: Create `StopWeeklyRunsMessage` + `StopWeeklyRunsMessageHandler`
- [ ] Task 3: Add two `RecurringMessage::cron(...)` entries to `src/Schedule.php`
- [ ] Task 4: Register both messages in `messenger.yaml`
- [ ] Task 5: Write unit tests for `GenerateWeeklyRunsMessageHandler` (idempotency)
- [ ] Task 6: Write unit tests for `StopWeeklyRunsMessageHandler` (best-effort error handling)
- [ ] Task 7: Run all four quality gates

## Dev Notes

### Architecture: no batch launch

There is **no** `LaunchPendingWeeklyRunsMessage`. Player sessions are created on demand when a member clicks "Lancer ma partie" (Story 23.4). The scheduler's only responsibility is:
1. Monday: create the `WeeklyRun` record so players can opt in and launch
2. Sunday: terminate any sessions still running

### Scheduler dispatch type

Both messages are dispatched **synchronously** by the Scheduler worker (same pattern as `CheckMembershipExpiryMessage` in the Membership context). They are fast: `GenerateWeeklyRunsMessage` is a few DBAL queries + entity persist; `StopWeeklyRunsMessage` makes HTTP calls to the runner but at a quiet time (Sunday 23:59).

Register as `sync` transport in `messenger.yaml`, or use the default scheduler bus - follow the existing pattern in `messenger.yaml` for scheduler messages.

### ISO week arithmetic

```php
$now = new \DateTimeImmutable();
$weekYear   = (int) $now->format('o'); // ISO year - 'o' not 'Y' (differs at year boundaries)
$weekNumber = (int) $now->format('W'); // ISO week, zero-padded
$seed = sprintf('archilan-weekly-%d-%02d', $weekYear, $weekNumber);
```

### Idempotency check in GenerateWeeklyRunsMessageHandler

```php
foreach ($templates as $template) {
    $count = $this->connection->createQueryBuilder()
        ->select('COUNT(wr.id)')
        ->from('weekly_runs', 'wr')
        ->where('wr.template_id = :templateId')
        ->andWhere('wr.week_year = :year')
        ->andWhere('wr.week_number = :week')
        ->setParameter('templateId', $template->getId())
        ->setParameter('year', $weekYear)
        ->setParameter('week', $weekNumber)
        ->executeQuery()
        ->fetchOne();

    if (0 < (int) $count) {
        continue;
    }

    // create and persist WeeklyRun
}
```

### StopWeeklyRunsMessageHandler

Terminate only sessions with `externalSessionId IS NOT NULL AND goal_reached_at IS NULL`. Sessions where the goal was already reached are already finished - no need to forcibly terminate (though terminating them would also be harmless, it's cleaner to leave them archived by the runner).

```php
$entryRows = $this->connection->createQueryBuilder()
    ->select('we.id', 'we.external_session_id AS externalSessionId')
    ->from('weekly_entries', 'we')
    ->where('we.weekly_run_id = :runId')
    ->andWhere('we.external_session_id IS NOT NULL')
    ->andWhere('we.goal_reached_at IS NULL')
    ->setParameter('runId', $run->getId())
    ->executeQuery()
    ->fetchAllAssociative();

foreach ($entryRows as $row) {
    try {
        $this->gateway->terminate($row['externalSessionId']);
    } catch (\Throwable $e) {
        $this->logger->error('Failed to terminate weekly entry session', [
            'externalSessionId' => $row['externalSessionId'],
            'error' => $e->getMessage(),
        ]);
    }
}

$run->finish($now);
$this->entityManager->flush();
```

### WeeklyRun.startedAt

Set to the creation time (Monday 00:00 UTC, i.e. the moment the scheduler fires). This is used as the **leaderboard time baseline** - `completionTimeSeconds = goalReachedAt - run.startedAt`. All players, regardless of when they personally launch their game, are measured from this shared reference point.

## File List

- `api/src/WeeklyRuns/Application/Message/GenerateWeeklyRunsMessage.php` - new
- `api/src/WeeklyRuns/Application/Handler/GenerateWeeklyRunsMessageHandler.php` - new
- `api/src/WeeklyRuns/Application/Message/StopWeeklyRunsMessage.php` - new
- `api/src/WeeklyRuns/Application/Handler/StopWeeklyRunsMessageHandler.php` - new
- `api/src/Schedule.php` - modified (2 new RecurringMessage entries)
- `api/config/packages/messenger.yaml` - modified (route the 2 new messages)
- `api/tests/Unit/WeeklyRuns/GenerateWeeklyRunsMessageHandlerTest.php` - new
- `api/tests/Unit/WeeklyRuns/StopWeeklyRunsMessageHandlerTest.php` - new

## Change Log

| Date       | Change                                                                                   |
|------------|------------------------------------------------------------------------------------------|
| 2026-05-17 | Story created                                                                            |
| 2026-05-17 | Revised: removed LaunchPendingWeeklyRunsMessage entirely. Scheduler only creates the WeeklyRun record; player sessions are on-demand (Story 23.4). StopWeeklyRunsMessage terminates per-entry externalSessionId, not a shared run session. |
